<?php
declare(strict_types=1);

namespace App\Services\Certi;

use PDO;

final class CertiApiLogger
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed>|string|null $response
     */
    public function log(?int $requestId, string $provider, string $endpoint, array $payload = [], array|string|null $response = null, ?int $statusCode = null, bool $success = true, ?string $error = null, int $retry = 0): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO certi_api_logs (request_id, provider, endpoint, payload, response, status_code, success, error_message, retry_attempt) VALUES (:request_id, :provider, :endpoint, :payload, :response, :status_code, :success, :error_message, :retry_attempt)');
        $stmt->execute([
            ':request_id' => $requestId,
            ':provider' => $provider,
            ':endpoint' => $endpoint,
            ':payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':response' => $response ? (is_array($response) ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $response) : null,
            ':status_code' => $statusCode,
            ':success' => $success ? 1 : 0,
            ':error_message' => $error,
            ':retry_attempt' => $retry,
        ]);
    }
}
