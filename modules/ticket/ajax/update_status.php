<?php

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/ticket_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo non consentito']);
    exit;
}

$ticketId = (int) ($_POST['ticket_id'] ?? 0);
if ($ticketId <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Ticket non valido']);
    exit;
}

$ticket = ticket_find($pdo, $ticketId);
if (!$ticket) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Ticket non trovato']);
    exit;
}

$status = strtoupper((string) ($_POST['status'] ?? $ticket['status']));
$priority = strtoupper((string) ($_POST['priority'] ?? $ticket['priority']));
$assignedTo = (int) ($_POST['assigned_to'] ?? 0) ?: null;
$slaDueAt = trim((string) ($_POST['sla_due_at'] ?? ''));
$normalizedSla = null;
if ($slaDueAt !== '') {
    $normalizedSla = str_replace('T', ' ', $slaDueAt);
    if (strlen($normalizedSla) === 16) {
        $normalizedSla .= ':00';
    }
}

$statusOptions = array_keys(ticket_status_options());
$priorityOptions = array_keys(ticket_priority_options());

if (!in_array($status, $statusOptions, true)) {
    $status = $ticket['status'];
}

if (!in_array($priority, $priorityOptions, true)) {
    $priority = $ticket['priority'];
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('UPDATE tickets SET status = :status, priority = :priority, assigned_to = :assigned_to, sla_due_at = :sla_due_at, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        ':status' => $status,
        ':priority' => $priority,
        ':assigned_to' => $assignedTo,
        ':sla_due_at' => $normalizedSla,
        ':id' => $ticketId,
    ]);

    if ($status !== $ticket['status'] || $priority !== $ticket['priority']) {
        $authorName = trim(((string) ($_SESSION['cognome'] ?? '')) . ' ' . ((string) ($_SESSION['nome'] ?? '')));
        if ($authorName === '') {
            $authorName = (string) ($_SESSION['username'] ?? 'Operatore');
        }

        ticket_insert_message($pdo, [
            'ticket_id' => $ticketId,
            'author_id' => (int) ($_SESSION['user_id'] ?? 0),
            'author_name' => $authorName,
            'body' => sprintf('Aggiornamento ticket: stato %s, priorità %s.', $status, $priority),
            'attachments' => json_encode([], JSON_UNESCAPED_UNICODE),
            'is_internal' => 1,
            'visibility' => 'system',
            'status_snapshot' => $status,
            'notified_client' => 0,
            'notified_admin' => 0,
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'status' => $status,
        'priority' => $priority,
        'assigned_to' => $assignedTo,
        'status_badge' => ticket_status_badge($status),
        'priority_badge' => ticket_priority_badge($priority),
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}
