<?php
declare(strict_types=1);

use App\Services\CAFPatronato\PracticesService;
use RuntimeException;
use Throwable;

session_start();
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

$code = trim((string) ($_GET['code'] ?? ''));
if ($code === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Codice obbligatorio.']);
    exit;
}

try {
    $service = new PracticesService($pdo, project_root_path());
    $service->getPracticeByTrackingCode($code);

    $token = bin2hex(random_bytes(16));
    $_SESSION['caf_upload_token'] = $token;
    $_SESSION['caf_upload_code'] = strtoupper($code);

    echo json_encode([
        'upload_token' => $token,
        'upload_url' => base_url('api/caf-patronato/public-upload.php'),
        'expires_in' => 900,
    ]);
} catch (RuntimeException $exception) {
    http_response_code(404);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(['error' => 'Servizio non disponibile.']);
}
