<?php
declare(strict_types=1);

// Public health endpoint: no auth/session required
if (!defined('BYPASS_AUTH')) {
    define('BYPASS_AUTH', true);
}

require_once __DIR__ . '/../bootstrap/autoload.php';
require_once __DIR__ . '/../includes/env.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

load_env(__DIR__ . '/../.env');

$status = 'ok';
$checks = [];

try {
    require_env(['DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']);

    $database = [
        'host' => env('DB_HOST'),
        'port' => env('DB_PORT'),
        'database' => env('DB_DATABASE'),
        'username' => env('DB_USERNAME'),
        'password' => env('DB_PASSWORD'),
        'charset' => env('DB_CHARSET', 'utf8mb4'),
        'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
        'persistent' => filter_var(env('DB_PERSISTENT', false), FILTER_VALIDATE_BOOL),
    ];

    $pdo = \App\Infrastructure\Database\ConnectionFactory::make($database);
    $stmt = $pdo->query('SELECT 1');
    $stmt->fetchColumn();
    $checks['database'] = ['status' => 'ok'];
} catch (Throwable $exception) {
    $status = 'degraded';
    $checks['database'] = [
        'status' => 'error',
        'message' => $exception->getMessage(),
    ];
}

http_response_code($status === 'ok' ? 200 : 503);

try {
    echo json_encode([
        'status' => $status,
        'checks' => $checks,
        'timestamp' => gmdate('c'),
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $encodeError) {
    echo '{"status":"' . $status . '","checks":{},"timestamp":"' . gmdate('c') . '"}';
}
