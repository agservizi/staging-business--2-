<?php
declare(strict_types=1);

namespace App\Services\Brt;

use CurlHandle;
use JsonException;
use Throwable;

use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt;
use function http_build_query;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function rtrim;
use function sprintf;
use function stripos;
use function strlen;
use function trim;

use const CURLINFO_HTTP_CODE;
use const CURLOPT_CAINFO;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_FAILONERROR;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HEADERFUNCTION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_URL;
use const CURLOPT_USERAGENT;
use const PHP_QUERY_RFC3986;

final class BrtHttpClient
{
    private string $baseUrl;

    /**
     * @var array<int, string>
     */
    private array $defaultHeaders;

    private ?string $caBundlePath;

    private int $timeout;

    /**
     * @param array<int, string> $defaultHeaders
     */
    public function __construct(string $baseUrl, array $defaultHeaders = [], ?string $caBundlePath = null, int $timeout = 30)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->defaultHeaders = $defaultHeaders;
        $this->caBundlePath = $caBundlePath;
        $this->timeout = $timeout > 0 ? $timeout : 30;
    }

    /**
     * @param array<string, string>|null $query
     * @param array<mixed>|string|null $body
     * @param array<int, string> $headers
     * @return array{status:int,body:mixed,raw:string,headers:array<int,string>}
     */
    public function request(string $method, string $path, ?array $query = null, $body = null, array $headers = []): array
    {
        $method = strtoupper($method);
        $url = $this->buildUrl($path, $query);

        $handle = curl_init($url);
        if (!($handle instanceof CurlHandle)) {
            throw new BrtException('Impossibile inizializzare la richiesta cURL verso BRT.');
        }

        $allHeaders = $this->prepareHeaders($headers, $body);

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $allHeaders);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($handle, CURLOPT_USERAGENT, 'CoresuiteBRT/1.0 (+https://coresuite.agservizi.it)');
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_FAILONERROR, false);

        if ($this->caBundlePath && is_string($this->caBundlePath)) {
            curl_setopt($handle, CURLOPT_CAINFO, $this->caBundlePath);
        }

        if ($body !== null) {
            $encoded = $this->encodeBody($body);
            curl_setopt($handle, CURLOPT_POSTFIELDS, $encoded);
        }

        $responseHeaders = [];
        curl_setopt($handle, CURLOPT_HEADERFUNCTION, static function ($ch, string $header) use (&$responseHeaders): int {
            $length = strlen($header);
            $trimmed = trim($header);
            if ($trimmed !== '') {
                $responseHeaders[] = $trimmed;
            }
            return $length;
        });

        $raw = curl_exec($handle);
        if ($raw === false) {
            $error = curl_error($handle);
            $errno = curl_errno($handle);
            curl_close($handle);
            throw new BrtException(sprintf('Richiesta BRT fallita (%d): %s', $errno, $error ?: 'Errore sconosciuto'));
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $decoded = $this->decodeBody($raw);

        return [
            'status' => $status,
            'body' => $decoded,
            'raw' => $raw,
            'headers' => $responseHeaders,
        ];
    }

    /**
     * @param array<string, string>|null $query
     */
    private function buildUrl(string $path, ?array $query = null): string
    {
        $trimmedPath = trim($path);
        if ($trimmedPath === '') {
            $trimmedPath = '/';
        }
        if ($trimmedPath[0] !== '/') {
            $trimmedPath = '/' . $trimmedPath;
        }

        $url = $this->baseUrl . $trimmedPath;
        if ($query && $query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        return $url;
    }

    /**
     * @param array<int, string> $headers
     * @param array<mixed>|string|null $body
     * @return array<int, string>
     */
    private function prepareHeaders(array $headers, $body): array
    {
        $normalized = $this->defaultHeaders;
        foreach ($headers as $header) {
            $normalized[] = $header;
        }

        $hasContentType = false;
        foreach ($normalized as $header) {
            if (stripos($header, 'content-type:') === 0) {
                $hasContentType = true;
                break;
            }
        }

        if (!$hasContentType && $body !== null) {
            $normalized[] = 'Content-Type: application/json';
        }

        $hasAccept = false;
        foreach ($normalized as $header) {
            if (stripos($header, 'accept:') === 0) {
                $hasAccept = true;
                break;
            }
        }

        if (!$hasAccept) {
            $normalized[] = 'Accept: application/json, application/pdf;q=0.5, */*;q=0.2';
        }

        return $normalized;
    }

    /**
     * @param array<mixed>|string $body
     */
    private function encodeBody($body): string
    {
        if (is_string($body)) {
            return $body;
        }

        try {
            return json_encode($body, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new BrtException('Impossibile serializzare la richiesta JSON per BRT: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return mixed
     */
    private function decodeBody(string $raw)
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
            return $decoded;
        } catch (JsonException $exception) {
            // Non JSON response (e.g. plain text). Return raw string.
            return $raw;
        } catch (Throwable $exception) {
            return $raw;
        }
    }
}
