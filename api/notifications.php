<?php
declare(strict_types=1);

use App\Services\Notifications\UnifiedNotificationService;

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autenticato.']);
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = (string) ($_SESSION['role'] ?? 'Operatore');
$sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
$limit = isset($_GET['limit']) ? min(50, max(1, (int) $_GET['limit'])) : 25;

$service = new UnifiedNotificationService($pdo, project_root_path());
$payload = $service->listForStaff($userId, $role, $sinceId, $limit);

echo json_encode([
    'items' => $payload['items'],
    'unread' => $payload['unread'],
    'lastId' => $payload['lastId'],
]);
