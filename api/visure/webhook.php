<?php
declare(strict_types=1);

use App\Services\ServiziWeb\OpenApiCatastoClient;
use App\Services\ServiziWeb\VisureService;

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non consentito.']);
    exit;
}

require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

$rawBody = file_get_contents('php://input') ?: '';
$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    $payload = $_POST;
}
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload non valido.']);
    exit;
}

try {
    $projectRoot = function_exists('project_root_path') ? project_root_path() : dirname(__DIR__, 2);
    $service = new VisureService($pdo, $projectRoot);
    $client = new OpenApiCatastoClient();
    $result = $service->handleInboundWebhook($client, $payload, 0);
    echo json_encode([
        'success' => true,
        'data' => $result,
    ]);
} catch (Throwable $exception) {
    error_log('Visure webhook error: ' . $exception->getMessage());
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error' => $exception->getMessage(),
    ]);
}
