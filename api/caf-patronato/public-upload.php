<?php
declare(strict_types=1);

use App\Services\Automata\AutomataService;
use App\Services\CAFPatronato\PracticesService;
use RuntimeException;
use Throwable;

session_start();
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../modules/servizi/caf-patronato/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non consentito.']);
    exit;
}

$trackingCode = trim((string) ($_POST['tracking_code'] ?? ''));
$token = trim((string) ($_POST['upload_token'] ?? ''));
$csrf = (string) ($_POST['_token'] ?? '');

if ($trackingCode === '' || $token === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Codice tracking e token obbligatori.']);
    exit;
}

if (!hash_equals((string) ($_SESSION['caf_upload_token'] ?? ''), $token)
    || !hash_equals((string) ($_SESSION['caf_upload_code'] ?? ''), strtoupper($trackingCode))) {
    http_response_code(403);
    echo json_encode(['error' => 'Token upload non valido o scaduto.']);
    exit;
}

if (!isset($_FILES['documento']) || !is_array($_FILES['documento'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Nessun file ricevuto.']);
    exit;
}

try {
    $service = new PracticesService($pdo, project_root_path());
    $practice = $service->getPracticeByTrackingCode($trackingCode);
    $practiceId = (int) ($practice['id'] ?? 0);
    if ($practiceId <= 0) {
        throw new RuntimeException('Pratica non trovata.');
    }

    $upload = $_FILES['documento'];
    $service->addDocument($practiceId, $upload, 0, null);

    $automata = new AutomataService();
    $analysis = $automata->analyzeUploadedDocument([
        'tracking_code' => $trackingCode,
        'file_name' => (string) ($upload['name'] ?? ''),
    ]);

    unset($_SESSION['caf_upload_token'], $_SESSION['caf_upload_code']);

    echo json_encode([
        'success' => true,
        'message' => 'Documento caricato correttamente.',
        'analysis' => $analysis,
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (Throwable $exception) {
    error_log('CAF public upload error: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Caricamento non riuscito.']);
}
