<?php
declare(strict_types=1);

use App\Services\OfficeSuite\SpreadsheetService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

header('Content-Type: application/json');

$service = new SpreadsheetService($pdo);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

try {
    if ($method === 'GET') {
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        $search = isset($_GET['q']) ? trim((string) $_GET['q']) : null;
        $sheets = $service->listSheets($limit, $search ?: null);
        respond_json(['status' => 'ok', 'data' => $sheets]);
    }

    if ($method === 'POST') {
        require_valid_csrf();
        $payload = decode_office_request();
        $sheet = $service->saveSheet($payload, $userId);
        respond_json(['status' => 'ok', 'data' => $sheet]);
    }

    respond_json(['status' => 'error', 'message' => 'Metodo non supportato.'], 405);
} catch (RuntimeException $exception) {
    respond_json(['status' => 'error', 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Office spreadsheet API error: ' . $exception->getMessage());
    respond_json(['status' => 'error', 'message' => 'Errore inaspettato durante il salvataggio del foglio.'], 500);
}

function respond_json(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

/**
 * @return array<string,mixed>
 */
function decode_office_request(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return $_POST;
}
