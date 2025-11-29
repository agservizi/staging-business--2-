<?php
declare(strict_types=1);

use App\Services\Security\MfaQrService;

require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/db_connect.php';
require_once __DIR__ . '/../../../../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non supportato.'], JSON_THROW_ON_ERROR);
    exit;
}

require_valid_csrf();

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sessione non valida.'], JSON_THROW_ON_ERROR);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$deviceUuid = trim((string) ($payload['device_uuid'] ?? $payload['uuid'] ?? ''));
if ($deviceUuid === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Seleziona un dispositivo da disattivare.'], JSON_THROW_ON_ERROR);
    exit;
}

$service = new MfaQrService($pdo);
if (!$service->revokeDevice($userId, $deviceUuid)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Dispositivo non trovato o già revocato.'], JSON_THROW_ON_ERROR);
    exit;
}

$response = [
    'ok' => true,
    'message' => 'Dispositivo disattivato correttamente.',
];

echo json_encode($response, JSON_THROW_ON_ERROR);
