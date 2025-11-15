<?php
declare(strict_types=1);

use App\Services\CAFPatronato\PracticesService;
use RuntimeException;
use Throwable;

session_start();
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../modules/servizi/caf-patronato/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/**
 * Simple session based rate limiting: max 10 lookups per rolling minute.
 */
$now = time();
$history = isset($_SESSION['caf_tracking_requests']) && is_array($_SESSION['caf_tracking_requests'])
    ? array_values(array_filter($_SESSION['caf_tracking_requests'], static fn($timestamp): bool => is_int($timestamp) && $timestamp > ($now - 60)))
    : [];

if (count($history) >= 10) {
    http_response_code(429);
    echo json_encode(['error' => 'Sono stati effettuati troppi tentativi. Riprovare tra qualche istante.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$code = isset($_GET['code']) ? trim((string) $_GET['code']) : '';
if ($code === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Il codice di tracking è obbligatorio.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$history[] = $now;
$_SESSION['caf_tracking_requests'] = $history;

$service = new PracticesService($pdo, project_root_path());

try {
    $payload = $service->getPublicTrackingViewData($code);
} catch (RuntimeException $exception) {
    http_response_code(404);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Throwable $exception) {
    error_log('CAF/Patronato public tracking error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Servizio temporaneamente non disponibile.'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(200);
echo json_encode(['data' => $payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
