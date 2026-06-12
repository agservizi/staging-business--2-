<?php
declare(strict_types=1);

namespace App\Services\Automata;

use RuntimeException;

final class AutomataClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $timeoutSeconds;

    public function __construct(?string $baseUrl = null, ?string $apiKey = null, int $timeoutSeconds = 45)
    {
        $resolvedBase = $baseUrl ?? (string) (function_exists('env') ? env('AUTOMATA_BASE_URL', 'https://automa.coresuite.it') : 'https://automa.coresuite.it');
        $this->baseUrl = rtrim(trim($resolvedBase), '/');
        $this->apiKey = trim((string) ($apiKey ?? (function_exists('env') ? env('AUTOMATA_API_KEY', '') : '')));
        $this->timeoutSeconds = max(5, $timeoutSeconds);
    }

    public function isConfigured(): bool
    {
        return $this->automataConfigured() || $this->openRouterConfigured();
    }

    private function automataConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->apiKey !== '';
    }

    private function openRouterConfigured(): bool
    {
        if (!$this->openRouterFallbackEnabled()) {
            return false;
        }

        return $this->openRouterApiKey() !== '';
    }

    private function openRouterFallbackEnabled(): bool
    {
        if (!function_exists('env')) {
            return true;
        }

        $value = env('AUTOMATA_FALLBACK_OPENROUTER', 'true');
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        return !in_array($normalized, ['0', 'false', 'no', 'off'], true);
    }

    private function openRouterApiKey(): string
    {
        return function_exists('env') ? trim((string) env('OPENROUTER_API_KEY', '')) : '';
    }

    private function resolveCaBundle(): ?string
    {
        $candidates = [];
        if (function_exists('env')) {
            $configured = trim((string) env('BRT_CA_BUNDLE_PATH', ''));
            if ($configured !== '') {
                $candidates[] = $configured;
            }
        }
        $candidates[] = dirname(__DIR__, 3) . '/certs/cacert.pem';

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_file($resolved)) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * @param resource $ch
     */
    private function applySslOptions($ch): void
    {
        $caBundle = $this->resolveCaBundle();
        if ($caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, $caBundle);
        }
    }

    private function openRouterModel(): string
    {
        if (function_exists('env')) {
            $model = trim((string) env('OPENROUTER_MODEL', ''));
            if ($model !== '') {
                return $model;
            }
        }

        return 'deepseek/deepseek-r1-0528';
    }

    /**
     * @return array<int,string>
     */
    private function openRouterModelCandidates(): array
    {
        $models = [$this->openRouterModel()];
        if (function_exists('env')) {
            $fallback = trim((string) env('OPENROUTER_FALLBACK_MODELS', ''));
            if ($fallback !== '') {
                foreach (explode(',', $fallback) as $model) {
                    $model = trim($model);
                    if ($model !== '') {
                        $models[] = $model;
                    }
                }
            }
        }

        return array_values(array_unique($models));
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function post(string $path, array $payload): array
    {
        if (!$this->automataConfigured()) {
            throw new RuntimeException('Automata non configurato: imposta AUTOMATA_BASE_URL e AUTOMATA_API_KEY.');
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL non disponibile per Automata.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Impossibile inizializzare la richiesta Automata.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->apiKey,
                'X-Coresuite-Client: business-crm',
            ],
        ]);
        $this->applySslOptions($ch);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Automata non raggiungibile: ' . ($error !== '' ? $error : 'errore di rete'));
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Risposta Automata non valida (HTTP ' . $status . ').');
        }

        if ($status >= 400) {
            $message = (string) ($decoded['error'] ?? $decoded['message'] ?? 'Errore Automata HTTP ' . $status);
            throw new RuntimeException($message, $status);
        }

        return $decoded;
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $options
     */
    public function chat(array $messages, array $options = []): string
    {
        if ($this->automataConfigured()) {
            try {
                return $this->extractChatContent($this->requestAutomata($messages, $options));
            } catch (RuntimeException $exception) {
                if (!$this->openRouterConfigured()) {
                    throw $exception;
                }
                error_log('Automata fallback OpenRouter: ' . $exception->getMessage());
            }
        }

        if ($this->openRouterConfigured()) {
            return $this->chatViaOpenRouter($messages, $options);
        }

        throw new RuntimeException('Nessun provider AI configurato (Automata o OpenRouter).');
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function requestAutomata(array $messages, array $options): array
    {
        $payload = array_merge([
            'messages' => $messages,
            'stream' => false,
        ], $options);

        try {
            return $this->post('/api/v1/chat', $payload);
        } catch (RuntimeException $exception) {
            return $this->post('/v1/chat/completions', [
                'model' => $options['model'] ?? (function_exists('env') ? env('AUTOMATA_MODEL', 'automata-default') : 'automata-default'),
                'messages' => $messages,
                'temperature' => $options['temperature'] ?? 0.2,
            ]);
        }
    }

    /**
     * @param array<string,mixed> $response
     */
    private function extractChatContent(array $response): string
    {
        if (isset($response['choices'][0]['message']['content'])) {
            return trim((string) $response['choices'][0]['message']['content']);
        }

        $content = trim((string) ($response['content'] ?? $response['text'] ?? $response['result'] ?? ''));
        if ($content !== '') {
            return $content;
        }

        throw new RuntimeException('Risposta Automata vuota.');
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $options
     */
    private function chatViaOpenRouter(array $messages, array $options): string
    {
        $apiKey = $this->openRouterApiKey();
        $models = $this->openRouterModelCandidates();
        if (isset($options['model']) && is_string($options['model']) && trim($options['model']) !== '') {
            array_unshift($models, trim($options['model']));
            $models = array_values(array_unique($models));
        }

        $lastError = 'OpenRouter non disponibile.';
        foreach ($models as $model) {
            try {
                return $this->requestOpenRouter($apiKey, $model, $messages, $options);
            } catch (RuntimeException $exception) {
                $lastError = $exception->getMessage();
            }
        }

        throw new RuntimeException($lastError);
    }

    /**
     * @param array<int,array{role:string,content:string}> $messages
     * @param array<string,mixed> $options
     */
    private function requestOpenRouter(string $apiKey, string $model, array $messages, array $options): string
    {
        $body = json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => $options['temperature'] ?? 0.2,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        if ($ch === false) {
            throw new RuntimeException('Impossibile contattare OpenRouter.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'HTTP-Referer: https://business.coresuite.it',
                'X-Title: Coresuite Business Automata',
            ],
        ]);
        $this->applySslOptions($ch);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('OpenRouter non raggiungibile: ' . ($error !== '' ? $error : 'errore di rete'));
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Risposta OpenRouter non valida (HTTP ' . $status . ').');
        }

        if ($status >= 400) {
            $message = (string) ($decoded['error']['message'] ?? $decoded['error'] ?? 'Errore OpenRouter HTTP ' . $status);
            throw new RuntimeException($message, $status);
        }

        return $this->extractChatContent($decoded);
    }
}
