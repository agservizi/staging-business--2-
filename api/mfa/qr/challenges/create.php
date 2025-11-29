<?php
declare(strict_types=1);

use App\Services\Security\MfaQrService;

session_start();
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

$pendingLogin = $_SESSION['mfa_challenge'] ?? null;
if (!is_array($pendingLogin) || empty($pendingLogin['user']['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Non ci sono accessi in attesa di verifica.'], JSON_THROW_ON_ERROR);
    exit;
}

$userId = (int) ($pendingLogin['user']['id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sessione MFA non valida.'], JSON_THROW_ON_ERROR);
    exit;
}

$service = new MfaQrService($pdo);
if (!$service->hasActiveDevices($userId)) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Nessun dispositivo QR disponibile per questo account.'], JSON_THROW_ON_ERROR);
    exit;
}

try {
    $challenge = $service->createChallenge(
        $userId,
        (string) ($pendingLogin['ip'] ?? request_ip()),
        (string) ($pendingLogin['user_agent'] ?? request_user_agent())
    );
} catch (Throwable $exception) {
    error_log('MFA QR challenge creation failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Impossibile creare una nuova richiesta.'], JSON_THROW_ON_ERROR);
    exit;
}

$_SESSION['mfa_qr_challenge'] = [
    'token' => $challenge['challenge_token'],
    'created_at' => time(),
];

$response = [
    'ok' => true,
    'challenge' => [
        'token' => $challenge['challenge_token'],
        'status' => $challenge['status'],
        'expires_at' => $challenge['expires_at'],
    ],
];

echo json_encode($response, JSON_THROW_ON_ERROR);
