<?php
declare(strict_types=1);

namespace App\Services\ServiziWeb;

use RuntimeException;

final class UfficioPostaleClient
{
    private string $baseUri;
    private string $token;
    private int $timeout;
    private bool $verifySsl;
    private ?string $caBundle;
    private array $defaultHeaders;

    public function __construct(?string $token = null, ?string $baseUri = null, ?array $options = null)
    {
        $options = $options ?? [];

        if ($token === null && function_exists('env')) {
            $primary = (string) (env('UFFICIO_POSTALE_TOKEN', '') ?: '');
            $fallback = (string) (env('UFFICIO_POSTALE_SANDBOX_TOKEN', '') ?: '');
            $token = $primary !== '' ? $primary : $fallback;
        }

        $token = trim((string) $token);
        if ($token === '') {
            throw new RuntimeException('Token API Ufficio Postale mancante.');
        }

        $defaultBase = 'https://ws.ufficiopostale.com';
        if (function_exists('env')) {
            $envBase = (string) (env('UFFICIO_POSTALE_BASE_URI', '') ?: '');
            if ($envBase !== '') {
                $defaultBase = $envBase;
            }
        }

        $this->token = $token;
        $this->baseUri = rtrim($baseUri !== null ? $baseUri : $defaultBase, '/');

        $timeout = $options['timeout'] ?? null;
        if ($timeout === null && function_exists('env')) {
            $timeout = env('UFFICIO_POSTALE_TIMEOUT', 30);
        }
        $timeout = (int) ($timeout ?? 30);
        $this->timeout = $timeout > 0 ? $timeout : 30;

        $verify = $options['verify_ssl'] ?? null;
        if ($verify === null && function_exists('env')) {
            $verify = env('UFFICIO_POSTALE_VERIFY_SSL', true);
        }
        $this->verifySsl = !is_bool($verify) ? filter_var($verify, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true : $verify;

        $caBundleOption = $options['ca_bundle'] ?? null;
        if ($caBundleOption === null && function_exists('env')) {
            $caBundleOption = env('UFFICIO_POSTALE_CA_BUNDLE');
        }
        $caBundleOption = $caBundleOption !== null ? trim((string) $caBundleOption) : '';
        $this->caBundle = $caBundleOption !== '' ? $caBundleOption : null;

        $this->defaultHeaders = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];
    }

    /**
     * @param array<string,mixed>|null $payload
     * @param array<string,mixed>|null $query
     * @return array{status:int,data:array<string,mixed>|array<int,mixed>|null,raw:string}
     */
    public function request(string $method, string $path, ?array $payload = null, ?array $query = null): array
    {
        $url = $this->baseUri . '/' . ltrim($path, '/');
        $method = strtoupper($method);

        if ($method === 'GET' && $payload !== null && $query === null) {
            $query = $payload;
            $payload = null;
        }

        if ($query) {
            $queryString = http_build_query($this->stringifyQuery($query));
            if ($queryString !== '') {
                $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
            }
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Impossibile inizializzare la richiesta verso Ufficio Postale.');
        }

        $headers = $this->defaultHeaders;
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($this->verifySsl === false) {
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        } elseif ($this->caBundle !== null) {
            $options[CURLOPT_CAINFO] = $this->caBundle;
        }

        switch ($method) {
            case 'GET':
                $options[CURLOPT_HTTPGET] = true;
                break;
            case 'POST':
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $this->encodePayload($payload);
                $headers[] = 'Content-Type: application/json';
                $options[CURLOPT_HTTPHEADER] = $headers;
                break;
            default:
                $options[CURLOPT_CUSTOMREQUEST] = $method;
                if ($payload !== null) {
                    $options[CURLOPT_POSTFIELDS] = $this->encodePayload($payload);
                    $headers[] = 'Content-Type: application/json';
                    $options[CURLOPT_HTTPHEADER] = $headers;
                }
                break;
        }

        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        if ($response === false) {
            $error = curl_error($handle) ?: 'Errore sconosciuto';
            curl_close($handle);
            throw new RuntimeException('Richiesta Ufficio Postale fallita: ' . $error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $decoded = null;
        if ($response !== '') {
            $decoded = json_decode($response, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Risposta Ufficio Postale non valida (JSON).');
            }
        }

        if ($status >= 400) {
            $message = $this->extractErrorMessage($decoded);
            throw new RuntimeException('Errore Ufficio Postale: ' . $message, $status);
        }

        return [
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : null,
            'raw' => $response,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int,data:array<string,mixed>|array<int,mixed>|null,raw:string}
     */
    public function createTelegram(array $payload): array
    {
        return $this->request('POST', '/telegrammi/', $payload);
    }

    /**
     * @param array<string,mixed> $query
     * @return array{status:int,data:array<string,mixed>|array<int,mixed>|null,raw:string}
     */
    public function listTelegram(array $query = []): array
    {
        return $this->request('GET', '/telegrammi/', null, $query === [] ? null : $query);
    }

    /**
     * @param array<string,mixed>|null $query
     * @return array{status:int,data:array<string,mixed>|array<int,mixed>|null,raw:string}
     */
    public function getTelegram(string $id, ?array $query = null): array
    {
        return $this->request('GET', '/telegrammi/' . rawurlencode($id), null, $query);
    }

    /**
     * @return array{status:int,data:array<string,mixed>|array<int,mixed>|null,raw:string}
     */
    public function confirmTelegram(string $id, bool $confirmed = true): array
    {
        return $this->request('PATCH', '/telegrammi/' . rawurlencode($id), ['confirmed' => $confirmed]);
    }

    /**
     * @return array{status:int,data:array<string,mixed>|array<int,mixed>|null,raw:string}
     */
    public function getPricing(?string $service = null): array
    {
        $path = $service === null ? '/pricing/' : '/pricing/' . rawurlencode($service);
        return $this->request('GET', $path);
    }

    /**
     * @return array{status:int,data:array<string,mixed>|array<int,mixed>|null,raw:string}
     */
    public function getCountries(?string $service = null): array
    {
        $path = $service === null ? '/nazioni/' : '/nazioni/' . rawurlencode($service);
        return $this->request('GET', $path);
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function stringifyQuery(array $query): array
    {
        $result = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_bool($value)) {
                $result[$key] = $value ? 'true' : 'false';
                continue;
            }
            if (is_array($value)) {
                $result[$key] = implode(',', array_map(static fn ($item) => (string) $item, $value));
                continue;
            }
            $result[$key] = (string) $value;
        }
        return $result;
    }

    /**
     * @param array<string,mixed>|null $payload
     */
    private function encodePayload(?array $payload): string
    {
        if ($payload === null) {
            return '';
        }

        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new RuntimeException('Impossibile serializzare il payload della richiesta Ufficio Postale.');
        }

        return $encoded;
    }

    /**
     * @param array<string,mixed>|array<int,mixed>|null $decoded
     */
    private function extractErrorMessage($decoded): string
    {
        if (is_array($decoded)) {
            if (isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== '') {
                return $decoded['message'];
            }
            if (isset($decoded['error']) && is_string($decoded['error']) && $decoded['error'] !== '') {
                return $decoded['error'];
            }
            if (isset($decoded['data']['wrong_fields']) && is_array($decoded['data']['wrong_fields']) && $decoded['data']['wrong_fields']) {
                return 'Campi non validi: ' . implode(', ', array_map('strval', $decoded['data']['wrong_fields']));
            }
        }

        return 'Status non valido';
    }
}
