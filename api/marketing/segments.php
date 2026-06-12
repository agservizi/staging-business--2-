<?php
declare(strict_types=1);

use App\Services\Marketing\BehaviorSegmentService;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');

if (!current_user_can('Admin', 'Manager')) {
    http_response_code(403);
    echo json_encode(['error' => 'Accesso negato.']);
    exit;
}

$limit = max(1, min(100, (int) ($_GET['limit'] ?? 50)));
$service = new BehaviorSegmentService($pdo);
echo json_encode(['segments' => $service->buildSegments($limit)], JSON_UNESCAPED_UNICODE);
