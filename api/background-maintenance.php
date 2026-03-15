<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/background_maintenance.php';

header('Content-Type: application/json; charset=utf-8');

try {
    run_background_maintenance($pdo);
    echo json_encode([
        'success' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Background maintenance failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
