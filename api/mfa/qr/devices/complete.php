<?php
declare(strict_types=1);

use App\Services\Security\MfaQrService;

session_start();
require_once __DIR__ . '/../../../../includes/db_connect.php';
require_once __DIR__ . '/../../../../includes/helpers.php';

function log_mfa_qr_event(string $level, string $message, array $context = []): void
{
    $rootPath = dirname(__DIR__, 4);
    $logDir = $rootPath . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/mfa-qr-scanner.log';
    $payload = [
        'time' => date('c'),
        'level' => $level,
        'message' => $message,
        'context' => $context,
    ];
    @file_put_contents($logFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

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

$token = preg_replace('/[^a-f0-9]/i', '', (string) ($payload['token'] ?? $payload['provisioning_token'] ?? ''));
if ($token === '' || strlen($token) < 32) {
    log_mfa_qr_event('reject', 'Token di provisioning non valido', [
        'ip' => $clientIp,
        'user_agent' => $userAgent,
    ]);
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Token di provisioning non valido.'], JSON_THROW_ON_ERROR);
    exit;
}

log_mfa_qr_event('attempt', 'Verifica token scanner', [
    'ip' => $clientIp,
    'user_agent' => $userAgent,
    'token_prefix' => substr($token, 0, 8),
]);

$service = new MfaQrService($pdo);
$device = $service->activateDeviceByToken($token);
if ($device === null) {
    log_mfa_qr_event('reject', 'Token scaduto o già utilizzato', [
        'ip' => $clientIp,
        'token_prefix' => substr($token, 0, 8),
    ]);
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Token scaduto o già utilizzato.'], JSON_THROW_ON_ERROR);
    exit;
}

$userStmt = $pdo->prepare('SELECT username, email, nome, cognome FROM users WHERE id = :id LIMIT 1');
$userStmt->execute([':id' => $device['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$response = [
    'ok' => true,
    'device' => [
        'device_uuid' => $device['device_uuid'],
        'label' => $device['device_label'],
        'status' => $device['status'],
        'user_id' => $device['user_id'],
    ],
    'user' => [
        'display' => format_user_display_name($user['username'] ?? '', $user['email'] ?? null, $user['nome'] ?? null, $user['cognome'] ?? null),
        'username' => $user['username'] ?? null,
    ],
];

log_mfa_qr_event('success', 'Dispositivo attivato tramite scanner', [
    'ip' => $clientIp,
    'device_uuid' => $device['device_uuid'] ?? null,
    'user_id' => $device['user_id'] ?? null,
]);

echo json_encode($response, JSON_THROW_ON_ERROR);
