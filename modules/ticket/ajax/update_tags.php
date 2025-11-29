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
$tagsRaw = (string) ($_POST['tags'] ?? '');

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

$tags = array_filter(array_map(static function (string $tag): string {
    return strtoupper(trim($tag));
}, explode(',', $tagsRaw)));

$pdo->prepare('UPDATE tickets SET tags = :tags WHERE id = :id')->execute([
    ':tags' => $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null,
    ':id' => $ticketId,
]);

echo json_encode(['success' => true, 'tags' => $tags]);
