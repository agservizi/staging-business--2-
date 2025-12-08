<?php
declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

try {
    require_valid_csrf();
} catch (Throwable $exception) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF non valido']);
    exit;
}

$role = $_SESSION['role'] ?? '';
$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);

if ($collaboratorId <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Non autenticato']);
    exit;
}

if ($role !== 'Collaboratore') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Azione non consentita']);
    exit;
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Impossibile aggiornare lo stato delle notifiche']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || ($payload['action'] ?? '') !== 'mark_read') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Richiesta non valida']);
    exit;
}

$lastReadKey = 'collab_notifications_last_read_' . $collaboratorId;
$lastSeenKey = 'collab_notifications_seen_' . $collaboratorId;
$now = date('Y-m-d H:i:s');
$lastStatusAt = isset($payload['last_status_at']) && $payload['last_status_at'] ? date('Y-m-d H:i:s', (int) $payload['last_status_at']) : null;
$lastTicketMessageId = isset($payload['last_ticket_message_id']) ? (int) $payload['last_ticket_message_id'] : 0;

$seenPayload = [];
if ($lastStatusAt !== null) {
    $seenPayload['last_status_at'] = $lastStatusAt;
}
if ($lastTicketMessageId > 0) {
    $seenPayload['last_ticket_message_id'] = $lastTicketMessageId;
}

try {
    $stmt = $pdo->prepare('INSERT INTO configurazioni (chiave, valore) VALUES (:key, :value)
        ON DUPLICATE KEY UPDATE valore = VALUES(valore), updated_at = NOW()');
    $stmt->execute([
        ':key' => $lastReadKey,
        ':value' => $now,
    ]);

    if ($seenPayload) {
        $seenStmt = $pdo->prepare('INSERT INTO configurazioni (chiave, valore) VALUES (:key, :value)
            ON DUPLICATE KEY UPDATE valore = VALUES(valore), updated_at = NOW()');
        $seenStmt->execute([
            ':key' => $lastSeenKey,
            ':value' => json_encode($seenPayload),
        ]);
    }

    // Session fallback for immediate consistency (in caso di cache db/config)
    $_SESSION['collab_notifications_last_read'] = $now;
    if ($lastStatusAt !== null) {
        $_SESSION['collab_notifications_last_status_at'] = $lastStatusAt;
    }
    if ($lastTicketMessageId > 0) {
        $_SESSION['collab_notifications_last_ticket_message_id'] = $lastTicketMessageId;
    }

    echo json_encode(['success' => true, 'read_at' => $now]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Errore durante il salvataggio']);
    exit;
}
