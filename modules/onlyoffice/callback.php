<?php

declare(strict_types=1);

use Modules\Onlyoffice as OnlyOffice;

require __DIR__ . '/config.php';

header('Content-Type: application/json');

try {
    $id = $_GET['id'] ?? '';
    if ($id === '') {
        throw new RuntimeException('Missing file identifier');
    }

    $rawBody = file_get_contents('php://input') ?: '{}';
    $payload = json_decode($rawBody, true) ?: [];

    if (Modules\Onlyoffice\DOCUMENT_SERVER_USE_JWT && Modules\Onlyoffice\DOCUMENT_SERVER_SECRET !== '') {
        $token = $payload['token'] ?? ($_GET['token'] ?? null);
        if (!$token && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $parts = explode(' ', $_SERVER['HTTP_AUTHORIZATION']);
            if (strcasecmp($parts[0], 'Bearer') === 0) {
                $token = $parts[1] ?? null;
            }
        }

        if ($token) {
            $decoded = OnlyOffice\validateIncomingJwt($token);
            $payload = $decoded['payload'] ?? $decoded;
        }
    }

    $response = OnlyOffice\saveCallback($id, $payload);
    echo json_encode($response, JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(400);
    echo json_encode([
        'error' => $exception->getMessage(),
    ]);
}
