<?php
declare(strict_types=1);

namespace App\Services\Certi\Clients;

use JsonException;
use RuntimeException;

final class DocuEngineClient extends HttpJsonClient
{
    protected string $baseEnvKey = 'DOCUENGINE_BASE_URL';
    protected string $defaultBaseUrl = 'https://test.docuengine.openapi.com';

    protected function buildHeaders(): array
    {
        $apiKey = (string) env('DOCUENGINE_API_KEY', '');
        $token = (string) env('DOCUENGINE_TOKEN', '');
        if ($apiKey === '' || $token === '') {
            throw new RuntimeException('Credenziali DocuEngine mancanti.');
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
        return $this->sendJson('POST', '/certificates', $payload);
    }

    /**
     * @return array<string,mixed>
     */
    public function fetchRequest(string $requestId): array
    {
        return $this->sendJson('GET', '/certificates/' . rawurlencode($requestId));
    }

    /**
     * @return array{content:string,content_type:?string}
     */
    public function downloadDocument(string $requestId): array
    {
        $response = $this->rawRequest('GET', '/certificates/' . rawurlencode($requestId) . '/document');
        $body = $response['body'] ?? '';
        if ($body === '') {
            throw new RuntimeException('Documento DocuEngine vuoto.');
        }

        return [
            'content' => $body,
            'content_type' => $response['content_type'] ?? 'application/pdf',
        ];
    }
}
