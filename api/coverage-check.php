<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

use App\Services\Coverage\CoverageCheckService;
use App\Services\Coverage\CoverageRequest;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non consentito.']);
    exit;
}

require_role('Collaboratore', 'Admin', 'Manager', 'Operatore');

$raw = file_get_contents('php://input') ?: '';
$payload = [];
if ($raw !== '') {
    try {
        /** @var array<string,mixed>|null $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            $payload = $decoded;
        }
    } catch (JsonException $exception) {
        http_response_code(400);
        echo json_encode(['error' => 'JSON non valido: ' . $exception->getMessage()]);
        exit;
    }
}

try {
    $request = CoverageRequest::fromArray($payload);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()]);
    exit;
}

try {
    $service = new CoverageCheckService();
    $result = $service->check($request);

    echo json_encode([
        'status' => 'ok',
        'data' => $result,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    error_log('Coverage check failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Automazione non disponibile: ' . $exception->getMessage()]);
}
