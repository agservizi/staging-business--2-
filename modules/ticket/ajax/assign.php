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
$assignedTo = (int) ($_POST['assigned_to'] ?? 0);

if ($ticketId <= 0 || $assignedTo <= 0) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Parametri non validi']);
    exit;
}

$ticket = ticket_find($pdo, $ticketId);
if (!$ticket) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Ticket non trovato']);
    exit;
}

$assignStmt = $pdo->prepare('UPDATE tickets SET assigned_to = :assigned_to, updated_at = NOW() WHERE id = :id');
$assignStmt->execute([
    ':assigned_to' => $assignedTo,
    ':id' => $ticketId,
]);

echo json_encode(['success' => true]);
