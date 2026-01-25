<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['role'] ?? '');

$limit = isset($_GET['limit']) && is_numeric($_GET['limit']) ? (int) $_GET['limit'] : 10;
$beforeId = isset($_GET['before_id']) && ctype_digit((string) $_GET['before_id']) ? (int) $_GET['before_id'] : null;

try {
    $payload = fetch_notifications($pdo, $userId, $role, $limit, $beforeId);
    echo json_encode([
        'success' => true,
        'data' => $payload,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Errore nel recupero delle notifiche.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}