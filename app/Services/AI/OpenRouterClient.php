<?php
declare(strict_types=1);

namespace App\Services\AI;

use RuntimeException;

final class OpenRouterClient
{
    private const DEFAULT_BASE_URI = 'https://openrouter.ai/api/v1';

    private string $apiKey;
    private string $baseUri;
    private string $defaultModel;
    private int $timeout;

    public function __construct(?string $apiKey = null, ?string $model = null, ?string $baseUri = null, int $timeoutSeconds = 45)
    {
        $resolvedKey = trim((string) ($apiKey ?? env('OPENROUTER_API_KEY', '')));
        if ($resolvedKey === '') {
            throw new RuntimeException('Chiave API OpenRouter mancante.');
        }

        $this->apiKey = $resolvedKey;
        $this->defaultModel = trim((string) ($model ?? env('OPENROUTER_MODEL', 'deepseek/deepseek-r1-0528:free')));
        if ($this->defaultModel === '') {
            throw new RuntimeException('Modello OpenRouter non configurato.');
        }

        $this->baseUri = rtrim($baseUri ?? self::DEFAULT_BASE_URI, '/');
        $this->timeout = max(10, $timeoutSeconds);
    }

    /**
     * @param array<int, array{role:string,content:string}> $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function chat(array $messages, array $options = []): array
    {
        if ($messages === []) {
            throw new RuntimeException('Nessun messaggio da inviare a OpenRouter.');
        }

        $payload = [
            'model' => $options['model'] ?? $this->defaultModel,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.4,
            'top_p' => $options['top_p'] ?? 0.95,
            'stream' => false,
        ];

        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = (int) $options['max_tokens'];
        }

        if (isset($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        $response = $this->sendRequest('/chat/completions', $payload);
        if (!is_array($response)) {
            throw new RuntimeException('Risposta OpenRouter non valida.');
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sendRequest(string $path, array $payload): array
    {
        $url = $this->baseUri . $path;
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Impossibile inizializzare la richiesta OpenRouter.');
        }

        $appUrl = trim((string) env('APP_URL', 'https://business.coresuite.it'));
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $this->apiKey,
            'X-Title: Coresuite Business AI Advisor',
        ];
        if ($appUrl !== '') {
            $headers[] = 'HTTP-Referer: ' . $appUrl;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE) ?: 0;
        curl_close($ch);

        if ($rawResponse === false) {
            throw new RuntimeException('Errore di connessione OpenRouter: ' . ($curlError ?: 'sconosciuto'));
        }

        if ($statusCode >= 400) {
            $preview = mb_substr($rawResponse, 0, 500);
            throw new RuntimeException(sprintf('OpenRouter ha risposto con %d: %s', $statusCode, $preview));
        }

        $decoded = json_decode($rawResponse, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Impossibile decodificare la risposta OpenRouter.');
        }

        return $decoded;
    }
}
