<?php
declare(strict_types=1);

use App\Services\Security\MfaQrService;

require_once __DIR__ . '/../../../../includes/auth.php';
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

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sessione scaduta. Effettua nuovamente l\'accesso.'], JSON_THROW_ON_ERROR);
    exit;
}

$service = new MfaQrService($pdo);
$pinAttemptLimit = $service->getPinAttemptLimit();
$pinLockSeconds = $service->getPinLockSeconds();

$devices = array_map(static function (array $device) use ($pinAttemptLimit): array {
    unset($device['pin_hash'], $device['provisioning_token']);
    $failedAttempts = (int) ($device['failed_pin_attempts'] ?? 0);
    $attemptsLeft = max(0, $pinAttemptLimit - $failedAttempts);
    $lockUntil = $device['pin_locked_until'] ?: null;
    $lockSecondsRemaining = null;
    $isLocked = false;

    if ($lockUntil) {
        $timestamp = strtotime($lockUntil);
        if ($timestamp !== false && $timestamp > time()) {
            $isLocked = true;
            $lockSecondsRemaining = $timestamp - time();
        }
    }

    return [
        'device_uuid' => $device['device_uuid'],
        'label' => $device['device_label'],
        'status' => $device['status'],
        'last_used_at' => $device['last_used_at'],
        'created_at' => $device['created_at'],
        'revoked_at' => $device['revoked_at'],
        'failed_attempts' => $failedAttempts,
        'attempts_left' => $attemptsLeft,
        'pin_attempt_limit' => $pinAttemptLimit,
        'pin_locked_until' => $lockUntil,
        'pin_lock_eta_seconds' => $lockSecondsRemaining,
        'pin_locked' => $isLocked,
    ];
}, $service->listDevices($userId));

$response = [
    'ok' => true,
    'pin_policy' => [
        'attempt_limit' => $pinAttemptLimit,
        'lock_seconds' => $pinLockSeconds,
    ],
    'devices' => $devices,
];

echo json_encode($response, JSON_THROW_ON_ERROR);
