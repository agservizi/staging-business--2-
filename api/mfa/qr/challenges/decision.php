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

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$token = trim((string) ($payload['token'] ?? $payload['challenge_token'] ?? ''));
$deviceUuid = trim((string) ($payload['device_uuid'] ?? ''));
$pin = preg_replace('/\s+/', '', (string) ($payload['pin'] ?? ''));
$action = strtolower(trim((string) ($payload['action'] ?? 'approve')));

if ($token === '' || $deviceUuid === '' || $pin === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Dati incompleti per completare l\'operazione.'], JSON_THROW_ON_ERROR);
    exit;
}

if (!in_array($action, ['approve', 'deny'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Azione non supportata.'], JSON_THROW_ON_ERROR);
    exit;
}

$service = new MfaQrService($pdo);
$device = $service->getDeviceByUuid($deviceUuid);
if ($device === null || $device['status'] !== 'active') {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Dispositivo non valido o non attivo.'], JSON_THROW_ON_ERROR);
    exit;
}

if ($service->isDeviceLocked($device)) {
    $lockUntil = null;
    try {
        $lockUntil = empty($device['pin_locked_until']) ? null : new DateTimeImmutable((string) $device['pin_locked_until']);
    } catch (Throwable) {
        $lockUntil = null;
    }

    $waitSeconds = $lockUntil ? max(0, $lockUntil->getTimestamp() - time()) : $service->getPinLockSeconds();
    http_response_code(423);
    echo json_encode([
        'ok' => false,
        'error' => 'PIN temporaneamente bloccato. Riprova più tardi.',
        'locked_until' => $lockUntil?->format('Y-m-d H:i:s'),
        'wait_seconds' => $waitSeconds,
    ], JSON_THROW_ON_ERROR);
    exit;
}

if (!$service->verifyDevicePin($device, $pin)) {
    $updatedDevice = $service->registerFailedPinAttempt((int) $device['id']);
    $failedAttempts = (int) ($updatedDevice['failed_pin_attempts'] ?? 0);
    $limit = $service->getPinAttemptLimit();
    $remaining = max(0, $limit - $failedAttempts);
    $lockUntil = null;

    try {
        $lockUntil = empty($updatedDevice['pin_locked_until']) ? null : new DateTimeImmutable((string) $updatedDevice['pin_locked_until']);
    } catch (Throwable) {
        $lockUntil = null;
    }

    $response = [
        'ok' => false,
        'error' => 'PIN non corretto.',
        'attempts_left' => $remaining,
    ];

    if ($lockUntil !== null && $lockUntil > new DateTimeImmutable('now')) {
        $waitSeconds = max(0, $lockUntil->getTimestamp() - time());
        $response['error'] = 'Troppi tentativi errati. PIN temporaneamente bloccato.';
        $response['locked_until'] = $lockUntil->format('Y-m-d H:i:s');
        $response['wait_seconds'] = $waitSeconds;
        http_response_code(423);
    } else {
        http_response_code(422);
    }

    echo json_encode($response, JSON_THROW_ON_ERROR);
    exit;
}

$challenge = $service->getChallengeByToken($token);
if ($challenge === null || (int) $challenge['user_id'] !== (int) $device['user_id']) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Challenge non trovata.'], JSON_THROW_ON_ERROR);
    exit;
}

try {
    if ($action === 'approve') {
        $updated = $service->approveChallenge($token, $device);
    } else {
        $updated = $service->denyChallenge($token, $device['id']);
    }
} catch (Throwable $exception) {
    error_log('MFA QR decision failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Errore durante l\'aggiornamento della richiesta.'], JSON_THROW_ON_ERROR);
    exit;
}

if ($updated === null) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Impossibile aggiornare la richiesta.'], JSON_THROW_ON_ERROR);
    exit;
}

if ($action === 'approve' && $updated['status'] !== 'approved') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'La challenge risulta già gestita.'], JSON_THROW_ON_ERROR);
    exit;
}

if ($action === 'deny' && $updated['status'] !== 'denied') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'Non è stato possibile annullare la richiesta.'], JSON_THROW_ON_ERROR);
    exit;
}

$response = [
    'ok' => true,
    'challenge' => [
        'token' => $updated['challenge_token'],
        'status' => $updated['status'],
        'approved_at' => $updated['approved_at'],
        'denied_at' => $updated['denied_at'],
    ],
];

echo json_encode($response, JSON_THROW_ON_ERROR);
