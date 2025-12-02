<?php
declare(strict_types=1);

namespace App\Services\Certi\Clients;

use RuntimeException;

final class CatastoClient extends HttpJsonClient
{
    protected string $baseEnvKey = 'CATASTO_BASE_URL';
    protected string $defaultBaseUrl = 'https://console.openapi.com/it/apis/catasto';

    protected function buildHeaders(): array
    {
        $apiKey = (string) env('CATASTO_API_KEY', '');
        $token = (string) env('CATASTO_TOKEN', '');
        if ($apiKey === '' || $token === '') {
            throw new RuntimeException('Credenziali Catasto mancanti.');
        }

        return array_merge(parent::buildHeaders(), [
            'x-api-key' => $apiKey,
            'Authorization' => 'Bearer ' . $token,
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
            throw new RuntimeException('Documento Catasto vuoto.');
        }

        return [
            'content' => $body,
            'content_type' => $response['content_type'] ?? 'application/pdf',
        ];
    }
}
