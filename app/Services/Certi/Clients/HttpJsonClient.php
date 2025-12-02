<?php
declare(strict_types=1);

namespace App\Services\Certi\Clients;

use JsonException;
use RuntimeException;

abstract class HttpJsonClient
{
    protected string $defaultBaseUrl = '';
    protected string $baseEnvKey = '';
    protected int $timeout = 30;

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    protected function sendJson(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $response = $this->rawRequest($method, $path, $body, $query);
        $raw = $response['body'] ?? '';
        if ($raw === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Risposta JSON non valida da provider certificati: ' . $exception->getMessage(), 0, $exception);
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     * @return array{body:string,content_type:?string,status:int}
     */
    protected function rawRequest(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $method = strtoupper($method);
        $url = $this->buildUrl($path, $query);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Impossibile inizializzare la richiesta HTTP.');
        }

        $headers = $this->buildHeaders();
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $this->formatHeaders($headers),
        ];

        if ($body !== null) {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                curl_close($ch);
                throw new RuntimeException('Impossibile serializzare il payload JSON.');
            }
            $options[CURLOPT_POSTFIELDS] = $encoded;
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch) ?: 'Errore sconosciuto';
            curl_close($ch);
            throw new RuntimeException('Richiesta HTTP fallita: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Provider certificati status ' . $status . ': ' . $raw, $status);
        }

        return [
            'body' => (string) $raw,
            'content_type' => is_string($contentType) ? $contentType : null,
            'status' => $status,
        ];
    }

    /**
     * @param array<string,string> $headers
     * @return array<int,string>
     */
    private function formatHeaders(array $headers): array
    {
        $result = [];
        foreach ($headers as $name => $value) {
            $result[] = $name . ': ' . $value;
        }

        return $result;
    }

    /**
     * @return array<string,string>
     */
    protected function buildHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'Certi3Client/1.0',
        ];
    }

    /**
     * @param array<string,mixed> $query
     */
    private function buildUrl(string $path, array $query): string
    {
        $base = (string) env($this->baseEnvKey, $this->defaultBaseUrl);
        $url = rtrim($base, '/') . '/' . ltrim($path, '/');
        if ($query) {
            $q = http_build_query($query);
            if ($q !== '') {
                $url .= (str_contains($url, '?') ? '&' : '?') . $q;
            }
        }

        return $url;
    }
}
