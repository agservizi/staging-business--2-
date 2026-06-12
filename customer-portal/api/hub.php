<?php
declare(strict_types=1);

use App\Services\Customer\UnifiedCustomerHubService;

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once dirname(__DIR__, 2) . '/includes/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

if (!CustomerAuth::isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autenticato']);
    exit;
}

$customer = CustomerAuth::getAuthenticatedCustomer();
$customerId = (int) ($customer['id'] ?? 0);
$email = (string) ($customer['email'] ?? '');

try {
    $pdo = portal_db();
    $root = dirname(__DIR__, 2);
    $hub = new UnifiedCustomerHubService($pdo, $root);
    echo json_encode(['data' => $hub->buildHub($customerId, $email)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $exception) {
    error_log('Customer hub error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Hub non disponibile']);
}
