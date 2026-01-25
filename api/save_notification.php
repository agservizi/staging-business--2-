<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo non consentito.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['role'] ?? '');

$type = normalize_notification_type($payload['type'] ?? 'info');
$title = trim((string) ($payload['title'] ?? ''));
$message = trim((string) ($payload['message'] ?? ''));
$metadata = $payload['metadata'] ?? null;

if ($message === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Messaggio mancante.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($type === 'bug' && !notification_can_view_bug($role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Permessi insufficienti.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$scope = isset($payload['scope']) ? strtolower(trim((string) $payload['scope'])) : 'user';
if ($scope === 'role' && !current_user_can('Admin', 'Manager')) {
    $scope = 'user';
}

try {
    $id = create_notification($pdo, [
        'type' => $type,
        'title' => $title !== '' ? $title : notification_type_label($type),
        'message' => $message,
        'metadata' => $metadata,
        'scope' => $scope,
        'role' => $payload['role'] ?? $role,
    ], $userId, $role);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Impossibile salvare la notifica.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $item = fetch_notification_by_id($pdo, $id, $userId, $role);
    echo json_encode([
        'success' => true,
        'data' => $item,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Errore nel salvataggio.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}