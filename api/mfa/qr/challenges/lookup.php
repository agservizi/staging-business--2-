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

$token = preg_replace('/[^a-f0-9]/i', '', (string) ($payload['token'] ?? $payload['challenge_token'] ?? ''));
if ($token === '' || strlen($token) < 32) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Token challenge non valido.'], JSON_THROW_ON_ERROR);
    exit;
}

$service = new MfaQrService($pdo);
$challenge = $service->getChallengeByToken($token);
if ($challenge === null || (int) $challenge['user_id'] !== $userId) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Richiesta non trovata per questo account.'], JSON_THROW_ON_ERROR);
    exit;
}

$response = [
    'ok' => true,
    'challenge' => [
        'token' => $challenge['challenge_token'],
        'status' => $challenge['status'],
        'ip_address' => $challenge['ip_address'],
        'user_agent' => $challenge['user_agent'],
        'created_at' => $challenge['created_at'],
        'expires_at' => $challenge['expires_at'],
        'approved_at' => $challenge['approved_at'],
        'denied_at' => $challenge['denied_at'],
    ],
];

echo json_encode($response, JSON_THROW_ON_ERROR);
