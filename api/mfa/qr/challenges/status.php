<?php
declare(strict_types=1);

use App\Services\Security\MfaQrService;

session_start();
require_once __DIR__ . '/../../../../includes/db_connect.php';
require_once __DIR__ . '/../../../../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non supportato.'], JSON_THROW_ON_ERROR);
    exit;
}

$pendingLogin = $_SESSION['mfa_challenge'] ?? null;
if (!is_array($pendingLogin) || empty($pendingLogin['user']['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sessione MFA non valida.'], JSON_THROW_ON_ERROR);
    exit;
}

$token = trim((string) ($_GET['token'] ?? ($_SESSION['mfa_qr_challenge']['token'] ?? '')));
if ($token === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Token challenge mancante.'], JSON_THROW_ON_ERROR);
    exit;
}

$service = new MfaQrService($pdo);
$challenge = $service->getChallengeByToken($token);
if ($challenge === null || (int) $challenge['user_id'] !== (int) ($pendingLogin['user']['id'] ?? 0)) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Richiesta non trovata.'], JSON_THROW_ON_ERROR);
    exit;
}

$response = [
    'ok' => true,
    'challenge' => [
        'token' => $challenge['challenge_token'],
        'status' => $challenge['status'],
        'expires_at' => $challenge['expires_at'],
        'approved_at' => $challenge['approved_at'],
        'denied_at' => $challenge['denied_at'],
    ],
];

echo json_encode($response, JSON_THROW_ON_ERROR);
