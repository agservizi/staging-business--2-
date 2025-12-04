<?php
declare(strict_types=1);

use App\Services\OfficeSuite\SpreadsheetPresetService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

header('Content-Type: application/json');

$service = new SpreadsheetPresetService($pdo);
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$userRole = isset($_SESSION['role']) ? (string) $_SESSION['role'] : 'Operatore';

try {
    if ($method === 'GET') {
        $sheetId = isset($_GET['sheet_id']) ? (int) $_GET['sheet_id'] : null;
        if ($sheetId !== null && $sheetId <= 0) {
            $sheetId = null;
        }
        $presets = $service->listPresets($sheetId, $userId, $userRole);
        respond_json(['status' => 'ok', 'data' => $presets]);
    }

    if ($method === 'POST') {
        require_valid_csrf();
        $payload = decode_json_request();
        if (!isset($payload['sheet_id'])) {
            $payload['sheet_id'] = isset($_GET['sheet_id']) ? (int) $_GET['sheet_id'] : null;
        }
        $preset = $service->savePreset($payload, $userId, $userRole);
        respond_json(['status' => 'ok', 'data' => $preset], 201);
    }

    if ($method === 'DELETE') {
        require_valid_csrf();
        $payload = decode_json_request();
        $presetId = isset($payload['id']) ? (int) $payload['id'] : (isset($_GET['id']) ? (int) $_GET['id'] : 0);
        if ($presetId <= 0) {
            throw new RuntimeException('ID del preset non valido.');
        }
        $service->deletePreset($presetId, $userId, $userRole);
        respond_json(['status' => 'ok']);
    }

    respond_json(['status' => 'error', 'message' => 'Metodo non supportato.'], 405);
} catch (RuntimeException $exception) {
    respond_json(['status' => 'error', 'message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('Office spreadsheet presets API error: ' . $exception->getMessage());
    respond_json(['status' => 'error', 'message' => 'Errore inatteso durante la gestione dei preset.'], 500);
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
function decode_json_request(): array
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
