<?php
declare(strict_types=1);

namespace App\Services\ServiziWeb;

use JsonException;
use RuntimeException;

final class OpenApiAutomotiveClient
{
    private string $token;
    private string $baseUri;
    private int $timeout;
    private bool $verifySsl;
    private ?string $caBundle;

    public function __construct(?string $token = null, ?string $baseUri = null, ?array $options = null)
    {
        $options = $options ?? [];

        if ($token === null && function_exists('env')) {
            $primary = (string) (env('OPENAPI_AUTOMOTIVE_TOKEN', '') ?: '');
            $fallback = (string) (env('OPENAPI_AUTOMOTIVE_SANDBOX_TOKEN', '') ?: '');
            $token = $primary !== '' ? $primary : $fallback;
        }

        $token = trim((string) $token);
        if ($token === '') {
            throw new RuntimeException('Token OpenAPI Automotive mancante.');
        }

        $defaultBase = 'https://test.automotive.openapi.com';
        if ($baseUri === null && function_exists('env')) {
            $defaultBase = (string) (env('OPENAPI_AUTOMOTIVE_BASE_URI', $defaultBase) ?: $defaultBase);
        }

        $this->token = $token;
        $this->baseUri = rtrim($baseUri !== null ? $baseUri : $defaultBase, '/');

        $timeout = $options['timeout'] ?? null;
        if ($timeout === null && function_exists('env')) {
            $timeout = env('OPENAPI_AUTOMOTIVE_TIMEOUT', 20);
        }
        $timeout = (int) ($timeout ?? 20);
        $this->timeout = $timeout > 0 ? $timeout : 20;

        $verify = $options['verify_ssl'] ?? null;
        if ($verify === null && function_exists('env')) {
            $verify = env('OPENAPI_AUTOMOTIVE_VERIFY_SSL', true);
        }
        $this->verifySsl = !is_bool($verify) ? (filter_var($verify, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true) : $verify;

        $bundle = $options['ca_bundle'] ?? null;
        if ($bundle === null && function_exists('env')) {
            $bundle = env('OPENAPI_AUTOMOTIVE_CA_BUNDLE');
        }
        $bundle = $bundle !== null ? trim((string) $bundle) : '';
        $this->caBundle = $bundle !== '' ? $bundle : null;
    }

    /**
     * @return array{status:int,pending:bool,check_id:?string,retry_after:?int,found:bool,data:array<string,mixed>|null,message:string}
     */
    public function lookupItCar(string $plate): array
    {
        return $this->normalizeVehicleResponse($this->request('GET', '/IT-car/' . rawurlencode($plate)));
    }

    /**
     * @return array{status:int,pending:bool,check_id:?string,retry_after:?int,found:bool,data:array<string,mixed>|null,message:string}
     */
    public function lookupItBike(string $plate): array
    {
        return $this->normalizeVehicleResponse($this->request('GET', '/IT-bike/' . rawurlencode($plate)));
    }

    /**
     * @return array{status:int,pending:bool,check_id:?string,retry_after:?int,found:bool,data:array<string,mixed>|null,message:string}
     */
    public function lookupItInsurance(string $plate): array
    {
        return $this->normalizeVehicleResponse($this->request('GET', '/IT-insurance/' . rawurlencode($plate)));
    }

    /**
     * @return array{status:int,pending:bool,check_id:?string,retry_after:?int,found:bool,data:array<string,mixed>|null,message:string}
     */
    public function checkId(string $checkId): array
    {
        return $this->normalizeVehicleResponse($this->request('GET', '/check_id/' . rawurlencode($checkId)));
    }

    /**
     * @return array{status:int,headers:array<string,string>,json:array<string,mixed>|null,raw:string}
     */
    private function request(string $method, string $path): array
    {
        $method = strtoupper($method);
        $url = $this->baseUri . '/' . ltrim($path, '/');

        if (!function_exists('curl_init')) {
            throw new RuntimeException('Estensione cURL non disponibile.');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Impossibile inizializzare la richiesta OpenAPI Automotive.');
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];

        $responseHeaders = [];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, $headerLine) use (&$responseHeaders): int {
                $len = strlen($headerLine);
                $headerLine = trim($headerLine);
                if ($headerLine === '' || strpos($headerLine, ':') === false) {
                    return $len;
                }
                [$name, $value] = explode(':', $headerLine, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
                return $len;
            },
        ];

        if ($this->verifySsl === false) {
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        } elseif ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        if ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        curl_setopt_array($handle, $options);
        $response = curl_exec($handle);
        if ($response === false) {
            $error = curl_error($handle) ?: 'Errore sconosciuto';
            curl_close($handle);
            throw new RuntimeException('Errore richiesta OpenAPI Automotive: ' . $error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $decoded = null;
        if ($response !== '') {
            try {
                $decoded = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $decoded = null;
            }
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'json' => $decoded,
            'raw' => $response,
        ];
    }

    /**
     * @param array{status:int,headers:array<string,string>,json:array<string,mixed>|null,raw:string} $response
     * @return array{status:int,pending:bool,check_id:?string,retry_after:?int,found:bool,data:array<string,mixed>|null,message:string}
     */
    private function normalizeVehicleResponse(array $response): array
    {
        $status = $response['status'];
        $headers = $response['headers'];
        $payload = is_array($response['json']) ? $response['json'] : null;

        if ($status === 302) {
            $location = $headers['location'] ?? '';
            $retryAfter = isset($headers['retry-after']) ? (int) $headers['retry-after'] : null;
            $checkId = $this->extractCheckId($payload, $location);

            return [
                'status' => $status,
                'pending' => true,
                'check_id' => $checkId,
                'retry_after' => $retryAfter,
                'found' => false,
                'data' => null,
                'message' => 'Richiesta in elaborazione.',
            ];
        }

        $message = $this->extractMessage($payload);

        if ($status === 404) {
            return [
                'status' => $status,
                'pending' => false,
                'check_id' => null,
                'retry_after' => null,
                'found' => false,
                'data' => null,
                'message' => $message !== '' ? $message : 'Veicolo non trovato.',
            ];
        }

        if ($status >= 400) {
            $message = $message !== '' ? $message : 'Errore OpenAPI Automotive.';
            throw new RuntimeException($message, $status);
        }

        $data = $payload['data'] ?? null;
        $data = is_array($data) ? $data : null;

        return [
            'status' => $status,
            'pending' => false,
            'check_id' => null,
            'retry_after' => null,
            'found' => $data !== null,
            'data' => $data,
            'message' => $message,
        ];
    }

    /**
     * @param array<string,mixed>|null $payload
     */
    private function extractMessage(?array $payload): string
    {
        if (!is_array($payload)) {
            return '';
        }

        $message = $payload['message'] ?? '';
        return is_string($message) ? trim($message) : '';
    }

    private function extractCheckId(?array $payload, string $location): ?string
    {
        if (is_array($payload)) {
            $data = $payload['data'] ?? null;
            if (is_array($data) && isset($data['id']) && is_string($data['id'])) {
                $candidate = trim($data['id']);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        if ($location !== '' && preg_match('#/check_id/([A-Za-z0-9._-]+)#', $location, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
