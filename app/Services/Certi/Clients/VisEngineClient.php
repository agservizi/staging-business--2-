<?php
declare(strict_types=1);

namespace App\Services\Certi\Clients;

use RuntimeException;

final class VisEngineClient extends HttpJsonClient
{
    protected string $baseEnvKey = 'VISENGINE_BASE_URL';
    protected string $defaultBaseUrl = 'https://console.openapi.com/it/apis/visengine';

    protected function buildHeaders(): array
    {
        $apiKey = (string) env('VISENGINE_API_KEY', '');
        if ($apiKey === '') {
            throw new RuntimeException('API key VisEngine mancante.');
        }

        return array_merge(parent::buildHeaders(), [
            'x-api-key' => $apiKey,
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    public function createRequest(array $payload): array
    {
        return $this->sendJson('POST', '/requests', $payload);
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchRequest(string $requestId): array
    {
        return $this->sendJson('GET', '/requests/' . rawurlencode($requestId));
    }

    /**
     * @return array{content:string,content_type:?string}
     */
    public function downloadDocument(string $requestId): array
    {
        $response = $this->rawRequest('GET', '/requests/' . rawurlencode($requestId) . '/document');
        $body = $response['body'] ?? '';
        if ($body === '') {
            throw new RuntimeException('Documento VisEngine vuoto.');
        }

        return [
            'content' => $body,
            'content_type' => $response['content_type'] ?? 'application/pdf',
        ];
    }
}
