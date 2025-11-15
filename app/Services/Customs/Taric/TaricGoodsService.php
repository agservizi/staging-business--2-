<?php
declare(strict_types=1);

namespace App\Services\Customs\Taric;

use DateTimeImmutable;
use SoapClient;
use SoapFault;
use SoapVar;
use stdClass;
use Throwable;

use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function is_array;
use function is_bool;
use function is_dir;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_replace;
use function str_pad;
use function strlen;
use function strtolower;
use function substr;
use function time;
use function trim;

use const DIRECTORY_SEPARATOR;
use const JSON_THROW_ON_ERROR;
use const SOAP_ENC_OBJECT;
use const STR_PAD_RIGHT;
use const WSDL_CACHE_DISK;
use const XSD_DATE;
use const XSD_STRING;

final class TaricGoodsService
{
    private const WSDL_ENDPOINT = 'https://ec.europa.eu/taxation_customs/dds2/taric/services/goods?wsdl';
    private const CACHE_DIRECTORY = __DIR__ . '/../../../../data/taric/cache';

    private const CACHE_TTL_SECONDS = 604800; // 7 days

    private SoapClient $client;

    public function __construct(?SoapClient $client = null)
    {
        if ($client instanceof SoapClient) {
            $this->client = $client;
        } else {
            try {
                $this->client = new SoapClient(self::WSDL_ENDPOINT, [
                    'cache_wsdl' => WSDL_CACHE_DISK,
                    'exceptions' => true,
                    'trace' => false,
                ]);
            } catch (SoapFault $fault) {
                throw new TaricException('Impossibile inizializzare il client TARIC: ' . $fault->getMessage(), (int) $fault->getCode(), $fault);
            } catch (Throwable $exception) {
                throw new TaricException('Errore durante l\'inizializzazione del client TARIC: ' . $exception->getMessage(), 0, $exception);
            }
        }
        $this->ensureCacheDirectory();
    }

    /**
     * @return array{code:string,description:string,declarable:bool,reference_date:string,language:string}
     */
    public function describe(string $code, string $language = 'it', ?DateTimeImmutable $referenceDate = null): array
    {
        $normalizedCode = $this->normalizeCode($code);
        $language = strtolower(trim($language));
        if ($language === '') {
            $language = 'it';
        }

        $referenceDate = $referenceDate ?? new DateTimeImmutable('today');
        $referenceDateString = $referenceDate->format('Y-m-d');

        $cacheKey = $this->buildCacheKey($normalizedCode, $language, $referenceDateString);
        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $payload = $this->buildRequestPayload($normalizedCode, $language, $referenceDateString);
            /** @var stdClass $response */
            $response = $this->client->__soapCall('goodsDescrForWs', [$payload]);
        } catch (SoapFault $fault) {
            throw new TaricException('Errore TARIC: ' . $fault->getMessage(), (int) $fault->getCode(), $fault);
        } catch (Throwable $exception) {
            throw new TaricException('Impossibile contattare il servizio TARIC: ' . $exception->getMessage(), 0, $exception);
        }

        $result = $this->extractResult($response, $normalizedCode, $language, $referenceDateString);
        $this->writeCache($cacheKey, $result);

        return $result;
    }

    private function normalizeCode(string $code): string
    {
        $trimmed = trim($code);
        $onlyDigits = preg_replace('/[^0-9]/', '', $trimmed) ?? '';
        if ($onlyDigits === '') {
            throw new TaricException('Codice TARIC non valido.');
        }

        if (strlen($onlyDigits) < 10) {
            $onlyDigits = str_pad($onlyDigits, 10, '0', STR_PAD_RIGHT);
        }

        return substr($onlyDigits, 0, 10);
    }

    private function buildRequestPayload(string $code, string $language, string $referenceDate): SoapVar
    {
        $namespace = 'http://goodsNomenclatureForWS.ws.taric.dds.s/';

        $struct = new stdClass();
        $struct->goodsCode = new SoapVar($code, XSD_STRING, null, null, 'goodsCode', $namespace);
        $struct->languageCode = new SoapVar($language, XSD_STRING, null, null, 'languageCode', $namespace);
        $struct->referenceDate = new SoapVar($referenceDate, XSD_DATE, null, null, 'referenceDate', $namespace);

        return new SoapVar($struct, SOAP_ENC_OBJECT, null, null, 'goodsDescrForWs', $namespace);
    }

    /**
     * @return array{code:string,description:string,declarable:bool,reference_date:string,language:string}
     */
    private function extractResult(stdClass $response, string $code, string $language, string $referenceDate): array
    {
        $return = $response->return ?? null;
        if (!($return instanceof stdClass)) {
            throw new TaricException('Risposta TARIC non valida (payload mancante).');
        }

        $error = $return->errorDescription ?? null;
        if (is_string($error) && trim($error) !== '') {
            throw new TaricException('Servizio TARIC ha restituito un errore: ' . $error);
        }

        $result = $return->result ?? null;
        if (!($result instanceof stdClass)) {
            throw new TaricException('Risposta TARIC non valida (struttura risultato assente).');
        }

        $data = $result->data ?? null;
        if (!($data instanceof stdClass)) {
            throw new TaricException('Risposta TARIC non valida (dati assenti).');
        }

        $description = $data->description ?? '';
        if (!is_string($description) || trim($description) === '') {
            throw new TaricException('Descrizione TARIC non disponibile per il codice specificato.');
        }

        $declarable = $data->declarable ?? false;
        if (!is_bool($declarable)) {
            $declarable = (bool) $declarable;
        }

        return [
            'code' => $code,
            'description' => trim($description),
            'declarable' => $declarable,
            'reference_date' => $referenceDate,
            'language' => $language,
        ];
    }

    /**
     * @return array{code:string,description:string,declarable:bool,reference_date:string,language:string}|null
     */
    private function readCache(string $cacheKey): ?array
    {
        $path = $this->cacheFilePath($cacheKey);
        if (!file_exists($path)) {
            return null;
        }

        $modified = filemtime($path);
        if ($modified === false || (time() - $modified) > self::CACHE_TTL_SECONDS) {
            return null;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return null;
        }

        $payload = json_decode($contents, true);
        if (!is_array($payload)) {
            return null;
        }

        return $payload;
    }

    private function writeCache(string $cacheKey, array $payload): void
    {
        $path = $this->cacheFilePath($cacheKey);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function cacheFilePath(string $cacheKey): string
    {
        return self::CACHE_DIRECTORY . DIRECTORY_SEPARATOR . $cacheKey . '.json';
    }

    private function buildCacheKey(string $code, string $language, string $referenceDate): string
    {
        return $language . '_' . $referenceDate . '_' . $code;
    }

    private function ensureCacheDirectory(): void
    {
        if (!is_dir(self::CACHE_DIRECTORY)) {
            mkdir(self::CACHE_DIRECTORY, 0775, true);
        }
    }
}
