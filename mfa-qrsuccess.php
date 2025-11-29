<?php
declare(strict_types=1);

use App\Security\SecurityAuditLogger;
use App\Services\Security\MfaQrService;

session_start();

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/db_connect.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metodo non supportato.'], JSON_THROW_ON_ERROR);
    exit;
}

require_valid_csrf();

$pendingLogin = $_SESSION['mfa_challenge'] ?? null;
if (!$pendingLogin || empty($pendingLogin['user']['id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Sessione MFA non valida.'], JSON_THROW_ON_ERROR);
    exit;
}

$token = trim((string) (($_POST['token'] ?? null) ?: ($_SERVER['HTTP_X_CHALLENGE_TOKEN'] ?? '')));
if ($token === '') {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $payload = json_decode($raw, true);
        if (is_array($payload) && !empty($payload['token'])) {
            $token = trim((string) $payload['token']);
        }
    }
}

if ($token === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Token challenge mancante.'], JSON_THROW_ON_ERROR);
    exit;
}

$service = new MfaQrService($pdo);
$challenge = $service->getChallengeByToken($token);
if ($challenge === null || (int) $challenge['user_id'] !== (int) $pendingLogin['user']['id']) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Challenge non trovata.'], JSON_THROW_ON_ERROR);
    exit;
}

if (($challenge['status'] ?? '') !== 'approved') {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'La richiesta non risulta approvata.'], JSON_THROW_ON_ERROR);
    exit;
}

$userStmt = $pdo->prepare('SELECT id, username, email, nome, cognome, ruolo, theme_preference FROM users WHERE id = :id LIMIT 1');
$userStmt->execute([':id' => (int) $challenge['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Utente non trovato.'], JSON_THROW_ON_ERROR);
    exit;
}

$auditLogger = new SecurityAuditLogger($pdo);
$sessionUser = build_user_session_payload($user);
complete_user_login(
    $pdo,
    $auditLogger,
    $sessionUser,
    (string) ($pendingLogin['ip'] ?? request_ip()),
    (string) ($pendingLogin['user_agent'] ?? request_user_agent()),
    !empty($pendingLogin['remember']),
    'mfa_qr'
);

unset($_SESSION['mfa_challenge'], $_SESSION['mfa_failed_attempts'], $_SESSION['mfa_qr_challenge']);

$redirect = base_url('dashboard.php');

echo json_encode([
    'ok' => true,
    'redirect' => $redirect,
], JSON_THROW_ON_ERROR);
