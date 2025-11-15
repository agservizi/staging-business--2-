<?php
declare(strict_types=1);

namespace App\Services\Brt;

use function implode;
use function is_array;
use function is_string;
use function rawurlencode;
use function sprintf;
use function strtoupper;
use function strlen;
use function substr;
use function trim;

final class BrtTrackingService
{
    private BrtConfig $config;

    private BrtHttpClient $client;

    public function __construct(?BrtConfig $config = null)
    {
        $this->config = $config ?? new BrtConfig();
        $defaultHeaders = [
            'userID: ' . $this->config->getAccountUserId(),
            'password: ' . $this->config->getAccountPassword(),
        ];
        $this->client = new BrtHttpClient($this->config->getRestBaseUrl(), $defaultHeaders, $this->config->getCaBundlePath());
    }

    /**
     * @return array<string, mixed>
     */
    public function trackingByParcelId(string $parcelId): array
    {
        $parcelId = trim($parcelId);
        if ($parcelId === '') {
            throw new BrtException('Il parcelID è obbligatorio per il tracking BRT.');
        }

        $response = $this->client->request('GET', '/tracking/parcelID/' . rawurlencode($parcelId));
        if ($response['status'] < 200 || $response['status'] >= 300) {
            $message = $this->extractErrorMessage($response['body']) ?: sprintf('Tracking BRT fallito (HTTP %d).', $response['status']);
            throw new BrtException($message);
        }

        $body = $response['body'];
        $result = $this->normalizeResult($body);
        if ($result === null) {
            $message = $this->extractErrorMessage($body);
            if (!$message && isset($response['raw']) && is_string($response['raw'])) {
                $message = $this->truncateMessage(trim($response['raw']));
            }

            throw new BrtException($message ?: 'Il servizio tracking BRT non ha restituito dati validi.');
        }

        if (isset($result['executionMessage']) && is_array($result['executionMessage'])) {
            $code = isset($result['executionMessage']['code']) ? (int) $result['executionMessage']['code'] : 0;
            if ($code < 0) {
                $message = $this->extractErrorMessage($body);
                throw new BrtException($message ?? 'Tracking BRT non disponibile per il parcelID indicato.');
            }
        }

        return $result;
    }

    /**
     * @param mixed $body
     */
    private function extractErrorMessage($body): ?string
    {
        if (is_string($body)) {
            $trimmed = trim($body);
            return $trimmed !== '' ? $this->truncateMessage($trimmed) : null;
        }

        if (!is_array($body)) {
            return null;
        }

        if (isset($body['executionMessage']) && is_array($body['executionMessage'])) {
            $message = $this->buildExecutionMessage($body['executionMessage']);
            if ($message !== null) {
                return $message;
            }
        }

        if (isset($body['parcelIDResult']) && is_array($body['parcelIDResult'])) {
            $result = $body['parcelIDResult'];
            if (isset($result['executionMessage']) && is_array($result['executionMessage'])) {
                return $this->buildExecutionMessage($result['executionMessage']);
            }
        }

        if (isset($body['ttParcelIdResponse']) && is_array($body['ttParcelIdResponse'])) {
            $response = $body['ttParcelIdResponse'];
            if (isset($response['executionMessage']) && is_array($response['executionMessage'])) {
                return $this->buildExecutionMessage($response['executionMessage']);
            }
        }

        if (isset($body['message'])) {
            return $this->truncateMessage((string) $body['message']);
        }

        return null;
    }

    /**
     * @param mixed $body
     * @return array<string, mixed>|null
     */
    private function normalizeResult($body): ?array
    {
        if (is_array($body)) {
            if (isset($body['parcelIDResult']) && is_array($body['parcelIDResult'])) {
                return $body['parcelIDResult'];
            }

            if (isset($body['parcelIDResults']) && is_array($body['parcelIDResults'])) {
                return $body['parcelIDResults'];
            }

            if (isset($body['ttParcelIdResponse']) && is_array($body['ttParcelIdResponse'])) {
                return $body['ttParcelIdResponse'];
            }

            if ($this->looksLikeResult($body)) {
                return $body;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function looksLikeResult(array $payload): bool
    {
        return isset($payload['parcelID'])
            || isset($payload['trackingList'])
            || isset($payload['trackingEvents'])
            || isset($payload['executionMessage'])
            || isset($payload['bolla']);
    }

    private function truncateMessage(string $message, int $length = 400): string
    {
        $trimmed = trim($message);
        if ($trimmed === '' || strlen($trimmed) <= $length) {
            return $trimmed;
        }

        return substr($trimmed, 0, max(0, $length - 1)) . '…';
    }

    /**
     * @param array<string, mixed> $executionMessage
     */
    private function buildExecutionMessage(array $executionMessage): ?string
    {
        $message = isset($executionMessage['message']) ? trim((string) $executionMessage['message']) : '';
        $codeDesc = isset($executionMessage['codeDesc']) ? trim((string) $executionMessage['codeDesc']) : '';
        $code = isset($executionMessage['code']) ? (int) $executionMessage['code'] : null;

        if ($message !== '') {
            return $this->truncateMessage($message);
        }

        if ($codeDesc !== '') {
            $translated = $this->translateCodeDescription($codeDesc);
            if ($code !== null && $code < 0 && $translated !== '') {
                return sprintf('%s (codice %d).', $translated, $code);
            }
            return $translated !== '' ? $translated : $codeDesc;
        }

        if ($code !== null && $code < 0) {
            return sprintf('Richiesta tracking non riuscita (codice %d).', $code);
        }

        return null;
    }

    private function translateCodeDescription(string $codeDesc): string
    {
        $map = [
            'SHIPMENT NOT FOUND' => 'Spedizione non trovata',
            'PARCELID NOT FOUND' => 'ParcelID non trovato',
            'AUTHENTICATION FAILED' => 'Autenticazione fallita',
        ];

        $normalized = strtoupper(trim($codeDesc));
        return $map[$normalized] ?? $codeDesc;
    }
}
