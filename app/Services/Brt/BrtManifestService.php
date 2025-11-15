<?php
declare(strict_types=1);

namespace App\Services\Brt;

use DateTimeImmutable;

use function array_filter;
use function array_map;
use function array_unique;
use function base64_decode;
use function bin2hex;
use function date;
use function dirname;
use function file_put_contents;
use function implode;
use function is_array;
use function is_dir;
use function is_string;
use function mkdir;
use function random_bytes;
use function sprintf;
use function str_replace;
use function strlen;
use function trim;
use function preg_replace;
use function substr;

use const DIRECTORY_SEPARATOR;

final class BrtManifestService
{
    private BrtConfig $config;

    private BrtHttpClient $client;

    public function __construct(?BrtConfig $config = null)
    {
        $this->config = $config ?? new BrtConfig();
        $defaultHeaders = [];
        $apiKey = $this->config->getRestApiKey();
        if ($apiKey === null || $apiKey === '') {
            throw new BrtException('Configurare BRT_API_KEY per richiedere il borderò ufficiale BRT.');
        }

        $defaultHeaders[] = 'X-API-Key: ' . $apiKey;

        $this->client = new BrtHttpClient(
            $this->config->getRestBaseUrl(),
            $defaultHeaders,
            $this->config->getCaBundlePath()
        );
    }

    /**
     * @param array<int, array<string, mixed>> $shipments
     * @param array<string, mixed> $context
     * @return array{manifest_number?:string,document_url?:string,pdf_path?:string}
     */
    public function generateOfficialManifest(array $shipments, array $context = []): array
    {
        if ($shipments === []) {
            throw new BrtException('Nessuna spedizione disponibile per la generazione del borderò ufficiale.');
        }

        $parcelIds = $this->extractParcelIds($shipments);
        $numericReferences = $this->extractNumericReferences($shipments);

        if ($parcelIds === [] && $numericReferences === []) {
            throw new BrtException('Impossibile richiedere il borderò ufficiale: mancano Parcel ID o riferimenti mittente.');
        }

        $payload = [
            'account' => $this->buildAccountPayload(),
            'manifestData' => array_filter([
                'senderCustomerCode' => $this->config->getSenderCustomerCode(),
                'departureDepot' => $context['departureDepot'] ?? $this->config->getDepartureDepot(),
                'pickupDate' => $context['pickupDate'] ?? $this->resolvePickupDate($shipments),
                'parcelIds' => $parcelIds ?: null,
                'numericSenderReferences' => $numericReferences ?: null,
            ], static fn ($value) => $value !== null && $value !== '' && $value !== []),
        ];

        $response = $this->client->request('POST', $this->config->getManifestEndpoint(), null, $payload);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']);
            if ($message === null) {
                $message = sprintf('Richiesta borderò ufficiale fallita (HTTP %d).', $response['status']);
            }
            throw new BrtException($message);
        }

        $body = $response['body'];
        if (!is_array($body)) {
            throw new BrtException('Risposta inattesa dal webservice borderò BRT.');
        }

        $manifestNumber = $this->extractString($body['manifestNumber'] ?? null);
        $documentUrl = $this->extractString($body['documentUrl'] ?? $body['downloadUrl'] ?? null);
        $pdfPath = null;

        if ($this->config->shouldStoreOfficialManifestPdf()) {
            $pdfPayload = $this->extractPdfPayload($body);
            if ($pdfPayload !== null) {
                $pdfPath = $this->storePdfDocument($pdfPayload, $manifestNumber);
            }
        }

        return array_filter([
            'manifest_number' => $manifestNumber,
            'document_url' => $documentUrl,
            'pdf_path' => $pdfPath,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param array<int, array<string, mixed>> $shipments
     * @return array<int, string>
     */
    private function extractParcelIds(array $shipments): array
    {
        $values = [];
        foreach ($shipments as $shipment) {
            $parcelId = $this->extractString($shipment['parcel_id'] ?? $shipment['parcelID'] ?? null);
            if ($parcelId !== null && $parcelId !== '') {
                $values[] = $parcelId;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<int, array<string, mixed>> $shipments
     * @return array<int, int>
     */
    private function extractNumericReferences(array $shipments): array
    {
        $values = [];
        foreach ($shipments as $shipment) {
            $reference = $shipment['numeric_sender_reference'] ?? $shipment['numericSenderReference'] ?? null;
            $normalized = (int) $reference;
            if ($normalized > 0) {
                $values[] = $normalized;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param array<int, array<string, mixed>> $shipments
     */
    private function resolvePickupDate(array $shipments): string
    {
        foreach ($shipments as $shipment) {
            $candidate = $this->extractString($shipment['pickup_date'] ?? $shipment['pickupDate'] ?? null);
            if ($candidate !== null && $candidate !== '') {
                return substr($candidate, 0, 10);
            }
        }

        return (new DateTimeImmutable('today'))->format('Y-m-d');
    }

    /**
     * @return array<string, string>
     */
    private function buildAccountPayload(): array
    {
        return [
            'userID' => $this->config->getAccountUserId(),
            'password' => $this->config->getAccountPassword(),
            'senderCustomerCode' => $this->config->getSenderCustomerCode(),
        ];
    }

    /**
     * @param mixed $candidate
     */
    private function extractString($candidate): ?string
    {
        if ($candidate === null) {
            return null;
        }

        $value = is_string($candidate) ? $candidate : (string) $candidate;
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function extractPdfPayload(array $body): ?string
    {
        $candidates = [
            $body['documentPdf'] ?? null,
            $body['pdfDocument'] ?? null,
            $body['pdf'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = $this->extractString($candidate);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function storePdfDocument(string $base64, ?string $manifestNumber): ?string
    {
        $binary = base64_decode($base64, true);
        if ($binary === false) {
            throw new BrtException('Documento PDF borderò ufficiale non valido: base64 corrotto.');
        }

        $directory = $this->resolveManifestDirectory();
        $manifestSuffix = $manifestNumber !== null ? '_' . preg_replace('/[^A-Za-z0-9\-]/', '', $manifestNumber) : '';
        $random = bin2hex(random_bytes(4));
        $filename = sprintf('bordero_brt_official_%s%s_%s.pdf', date('Ymd'), $manifestSuffix, $random);

        $absolutePath = $directory . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($absolutePath, $binary) === false) {
            throw new BrtException('Impossibile salvare il file PDF del borderò ufficiale.');
        }

        return $this->toRelativeManifestPath($filename);
    }

    private function resolveManifestDirectory(): string
    {
        $root = dirname(__DIR__, 3);
        $path = $root . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'brt' . DIRECTORY_SEPARATOR . 'manifests';
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new BrtException('Impossibile creare la cartella per i borderò ufficiali.');
        }
        return $path;
    }

    private function toRelativeManifestPath(string $filename): string
    {
        return str_replace('\\', '/', 'uploads/brt/manifests/' . $filename);
    }

    /**
     * @param mixed $body
     */
    private function extractErrorMessage($body): ?string
    {
        if (is_array($body)) {
            $candidates = [
                $body['message'] ?? null,
                $body['errorMessage'] ?? null,
                $body['error']['message'] ?? null,
                $body['errors']['message'] ?? null,
            ];

            foreach ($candidates as $candidate) {
                $value = $this->extractString($candidate);
                if ($value !== null) {
                    return $this->truncate($value);
                }
            }

            if (isset($body['errors']) && is_array($body['errors'])) {
                $messages = [];
                foreach ($body['errors'] as $error) {
                    $value = $this->extractString($error['message'] ?? $error ?? null);
                    if ($value !== null) {
                        $messages[] = $value;
                    }
                }
                if ($messages !== []) {
                    return $this->truncate(implode('; ', $messages));
                }
            }
        }

        if (is_string($body)) {
            $value = trim($body);
            if ($value !== '') {
                return $this->truncate($value);
            }
        }

        return null;
    }

    private function truncate(string $message): string
    {
        return strlen($message) > 400 ? substr($message, 0, 397) . '...' : $message;
    }
}
