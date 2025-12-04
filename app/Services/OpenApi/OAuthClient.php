<?php
declare(strict_types=1);

namespace App\Services\OpenApi;

use RuntimeException;

final class OAuthClient
{
    private string $baseUri;
    private string $username;
    private string $apiKey;
    private int $timeout;
    private bool $verifySsl;
    private ?string $caBundle;
    private string $basicToken;

    public function __construct(?array $config = null)
    {
        $config = $config ?? [];

        $baseUri = $config['base_uri'] ?? env('OPENAPI_OAUTH_BASE_URI', 'https://oauth.openapi.it');
        $this->baseUri = rtrim((string) $baseUri, '/');
        if ($this->baseUri === '') {
            throw new RuntimeException('OPENAPI_OAUTH_BASE_URI non configurato.');
        }

        $this->username = trim((string) ($config['username'] ?? env('OPENAPI_ACCOUNT_EMAIL', '')));
        $this->apiKey = trim((string) ($config['api_key'] ?? env('OPENAPI_API_KEY', '')));

        if ($this->username === '' || $this->apiKey === '') {
            throw new RuntimeException('Credenziali Openapi mancanti. Configura OPENAPI_ACCOUNT_EMAIL e OPENAPI_API_KEY.');
        }

        $timeout = $config['timeout'] ?? env('OPENAPI_OAUTH_TIMEOUT', 20);
        $timeout = (int) ($timeout ?? 20);
        $this->timeout = $timeout > 0 ? $timeout : 20;

        $verify = $config['verify_ssl'] ?? env('OPENAPI_OAUTH_VERIFY_SSL', true);
        $this->verifySsl = is_bool($verify)
            ? $verify
            : (filter_var($verify, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true);

        $caBundle = $config['ca_bundle'] ?? env('OPENAPI_OAUTH_CA_BUNDLE');
        $caBundle = $caBundle !== null ? trim((string) $caBundle) : '';
        $this->caBundle = $caBundle !== '' ? $caBundle : null;

        $this->basicToken = base64_encode($this->username . ':' . $this->apiKey);
    }

    /**
     * @param array<int,string> $scopes
     * @return array<string,mixed>
     */
    public function createToken(array $scopes, ?int $ttlSeconds = null): array
    {
        $payload = ['scopes' => $this->normalizeScopes($scopes)];
        if ($payload['scopes'] === []) {
            throw new RuntimeException('Specificare almeno uno scope per creare un token Openapi.');
        }

        if ($ttlSeconds !== null) {
            $ttlSeconds = max(60, (int) $ttlSeconds);
            $payload['ttl'] = $ttlSeconds;
        }

        $response = $this->request('POST', '/token', $payload);
        $decoded = $response['json'];
        if (!is_array($decoded)) {
            throw new RuntimeException('Risposta token Openapi non valida.');
        }

        if (empty($decoded['success'])) {
            $message = isset($decoded['message']) ? (string) $decoded['message'] : 'Richiesta token non riuscita.';
            throw new RuntimeException($message, (int) ($decoded['error'] ?? 0));
        }

        return $decoded;
    }

    /**
     * @return array<string,mixed>
     */
    public function listTokens(?string $scope = null): array
    {
        $query = $scope !== null ? ['scope' => trim($scope)] : null;
        $response = $this->request('GET', '/token', null, $query);
        return $response['json'] ?? [];
    }

    public function deleteToken(string $token): bool
    {
        $token = trim($token);
        if ($token === '') {
            throw new RuntimeException('Token Openapi non valido.');
        }

        $response = $this->request('DELETE', '/token/' . rawurlencode($token));
        $decoded = $response['json'];
        if (!is_array($decoded)) {
            return false;
        }

        if (isset($decoded['success'])) {
            return (bool) $decoded['success'];
        }

        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function getScopes(): array
    {
        $response = $this->request('GET', '/scopes');
        return $response['json'] ?? [];
    }

    public function getCredit(): ?float
    {
        $response = $this->request('GET', '/credit');
        $decoded = $response['json'];
        if (!is_array($decoded)) {
            return null;
        }

        if (isset($decoded['data']['credit'])) {
            return (float) $decoded['data']['credit'];
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $payload
     * @param array<string,string|int>|null $query
     * @return array{status:int,body:string,json:array<string,mixed>|array<int,mixed>|null}
     */
    public function request(string $method, string $path, ?array $payload = null, ?array $query = null): array
    {
        $method = strtoupper($method);

        if ($method === 'GET' && $payload !== null && $query === null) {
            $query = [];
            foreach ($payload as $key => $value) {
                if ($value === null) {
                    continue;
                }
                $query[$key] = $value;
            }
            $payload = null;
        }

        $url = $this->buildUrl($path, $query);
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Impossibile inizializzare la richiesta verso Openapi OAuth.');
        }

        $headers = [
            'Accept: application/json',
            'Authorization: Basic ' . $this->basicToken,
        ];

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
            throw new RuntimeException('Richiesta Openapi OAuth fallita: ' . $error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);

        $decoded = null;
        if ($response !== '') {
            $decoded = json_decode($response, true);
            if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Risposta Openapi OAuth non valida (JSON).');
            }
        }

        if ($status >= 400) {
            $message = $this->extractErrorMessage($decoded);
            throw new RuntimeException('Errore Openapi OAuth: ' . $message, $status);
        }

        return [
            'status' => $status,
            'body' => $response,
            'json' => $decoded,
        ];
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
            throw new RuntimeException('Impossibile serializzare il payload della richiesta Openapi.');
        }

        return $encoded;
    }

    private function buildUrl(string $path, ?array $query): string
    {
        $url = $this->baseUri . '/' . ltrim($path, '/');
        if ($query === null || $query === []) {
            return $url;
        }

        $normalized = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            $normalized[$key] = (string) $value;
        }

        if ($normalized === []) {
            return $url;
        }

        $queryString = http_build_query($normalized);
        return $queryString === '' ? $url : $url . '?' . $queryString;
    }

    /**
     * @param array<int,string> $scopes
     * @return array<int,string>
     */
    private function normalizeScopes(array $scopes): array
    {
        $normalized = [];
        foreach ($scopes as $scope) {
            $value = trim((string) $scope);
            if ($value === '') {
                continue;
            }
            $normalized[$value] = $value;
        }

        return array_values($normalized);
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
            if (isset($decoded['error']) && is_scalar($decoded['error'])) {
                return (string) $decoded['error'];
            }
        }

        return 'Status HTTP non valido';
    }
}
