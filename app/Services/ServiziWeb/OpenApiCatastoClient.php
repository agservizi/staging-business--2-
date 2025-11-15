<?php

namespace App\Services\ServiziWeb;

use JsonException;
use RuntimeException;

class OpenApiCatastoClient
{
    private string $apiKey;
    private string $token;
    private string $baseUri;
    private int $timeout;
    private bool $verifySsl;
    private ?string $caBundle;

    public function __construct(?string $apiKey = null, ?string $token = null, ?array $options = null)
    {
        if ($apiKey === null) {
            if (function_exists('env')) {
                $primary = env('OPENAPI_CATASTO_API_KEY');
                $fallback = env('OPENAPI_SANDBOX_API_KEY');
                $apiKey = (string) (($primary !== null && $primary !== '') ? $primary : ($fallback ?? ''));
            } else {
                $apiKey = '';
            }
        }

        if ($token === null) {
            if (function_exists('env')) {
                $primary = env('OPENAPI_CATASTO_TOKEN');
                $fallback = env('OPENAPI_CATASTO_SANDBOX_TOKEN');
                $token = (string) (($primary !== null && $primary !== '') ? $primary : ($fallback ?? ''));
            } else {
                $token = '';
            }
        }

        $apiKey = trim($apiKey);
        $token = trim($token);

        if ($apiKey === '') {
            throw new RuntimeException('API key OpenAPI Catasto mancante.');
        }

        if ($token === '') {
            throw new RuntimeException('Token OpenAPI Catasto mancante.');
        }

        $options = $options ?? [];

        $defaultBase = 'https://test.catasto.openapi.it';
        if (function_exists('env')) {
            $override = env('OPENAPI_CATASTO_BASE_URI');
            if (is_string($override) && $override !== '') {
                $defaultBase = $override;
            }
        }

        $this->apiKey = $apiKey;
        $this->token = $token;
        $this->baseUri = rtrim((string) ($options['base_uri'] ?? $defaultBase), '/');
        $timeout = (int) ($options['timeout'] ?? (function_exists('env') ? (int) (env('OPENAPI_CATASTO_TIMEOUT') ?: 30) : 30));
        $this->timeout = $timeout > 0 ? $timeout : 30;

        $verify = $options['verify_ssl'] ?? (function_exists('env') ? env('OPENAPI_CATASTO_VERIFY_SSL') : null);
        if (is_string($verify)) {
            $verify = filter_var($verify, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }
        $this->verifySsl = $verify === null ? true : (bool) $verify;

        $bundle = $options['ca_bundle'] ?? (function_exists('env') ? env('OPENAPI_CATASTO_CA_BUNDLE') : null);
        $bundle = is_string($bundle) ? trim($bundle) : '';
        $this->caBundle = $bundle !== '' ? $bundle : null;
    }

    public function listVisure(): array
    {
        $payload = $this->request('GET', '/visura_catastale');
        $data = $payload['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    public function getVisura(string $visuraId): array
    {
        $visuraId = trim($visuraId);
        if ($visuraId === '') {
            throw new RuntimeException('ID della visura non valido.');
        }

        $payload = $this->request('GET', '/visura_catastale/' . rawurlencode($visuraId));
        $data = $payload['data'] ?? [];
        return is_array($data) ? $data : [];
    }

    /**
     * @return array{content:string, content_type:string, request_id:string, content_length:int}
     */
    public function downloadVisuraDocument(string $visuraId): array
    {
        $visuraId = trim($visuraId);
        if ($visuraId === '') {
            throw new RuntimeException('ID della visura non fornito.');
        }

        $content = $this->request('GET', '/visura_catastale/' . rawurlencode($visuraId) . '/documento', null, ['Accept' => 'application/pdf'], false);

        if (!is_array($content) || !isset($content['body'])) {
            throw new RuntimeException('Risposta non valida durante il download della visura.');
        }

        return [
            'content' => $content['body'],
            'content_type' => $content['content_type'] ?? 'application/pdf',
            'request_id' => $visuraId,
            'content_length' => $content['content_length'] ?? strlen($content['body'])
        ];
    }

    public function createVisura(array $payload): array
    {
        if (!isset($payload['entita'])) {
            $payload['entita'] = 'immobile';
        }

        $response = $this->request('POST', '/visura_catastale', $payload);
        $data = $response['data'] ?? null;

        if (!is_array($data) || !isset($data['id'])) {
            throw new RuntimeException('Risposta non valida dal servizio Visura Catastale.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|array{body:string,content_type:?string,content_length:?int}
     */
    private function request(string $method, string $path, ?array $payload = null, array $additionalHeaders = [], bool $expectJson = true)
    {
        $method = strtoupper($method);
        $uri = $this->buildUrl($path, $method === 'GET' ? $payload : null);

        $handle = curl_init($uri);
        if ($handle === false) {
            throw new RuntimeException('Impossibile inizializzare la richiesta verso OpenAPI Catasto.');
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'x-api-key: ' . $this->apiKey,
            'User-Agent: CoresuiteCatastoClient/1.0',
            'Accept: application/json',
        ];

        foreach ($additionalHeaders as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

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

        if ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
            $encoded = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
            if ($encoded === false) {
                curl_close($handle);
                throw new RuntimeException('Impossibile serializzare il payload della richiesta verso OpenAPI.');
            }
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }

        curl_setopt_array($handle, $options);

        $response = curl_exec($handle);
        if ($response === false) {
            $error = curl_error($handle) ?: 'Errore sconosciuto';
            curl_close($handle);
            throw new RuntimeException('Richiesta OpenAPI Catasto fallita: ' . $error);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        $contentLength = curl_getinfo($handle, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
        curl_close($handle);

        if ($status < 200 || $status >= 300) {
            $message = 'Status ' . $status;
            $decoded = json_decode($response, true);
            if (is_array($decoded)) {
                $message = (string) ($decoded['message'] ?? $decoded['error'] ?? $message);
            }
            throw new RuntimeException('Errore OpenAPI Catasto: ' . $message, $status);
        }

        if (!$expectJson) {
            return [
                'body' => $response,
                'content_type' => is_string($contentType) ? $contentType : null,
                'content_length' => is_numeric($contentLength) ? (int) $contentLength : null,
            ];
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = $response === '' ? [] : json_decode($response, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Risposta JSON non valida ricevuta da OpenAPI: ' . $exception->getMessage(), $status, $exception);
        }

        return $decoded;
    }

    private function buildUrl(string $path, ?array $query = null): string
    {
        $normalized = str_starts_with($path, '/') ? $path : '/' . $path;
        $url = $this->baseUri . $normalized;

        if ($query) {
            $queryString = http_build_query($query);
            if ($queryString !== '') {
                $url .= (str_contains($url, '?') ? '&' : '?') . $queryString;
            }
        }

        return $url;
    }
}

