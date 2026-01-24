<?php
declare(strict_types=1);

use App\Services\ServiziWeb\OpenApiAutomotiveClient;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/db_connect.php';

require_role('Admin', 'Operatore', 'Manager');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metodo non consentito.',
    ]);
    exit;
}

try {
    require_valid_csrf();
} catch (Throwable $exception) {
    http_response_code(419);
    echo json_encode([
        'success' => false,
        'message' => 'Token CSRF non valido.',
    ]);
    exit;
}

$plate = strtoupper(trim((string) ($_POST['targa'] ?? '')));
$vehicleType = strtolower(trim((string) ($_POST['vehicle_type'] ?? 'car')));
$includeInsurance = in_array((string) ($_POST['include_insurance'] ?? ''), ['1', 'true', 'on'], true);
$checkId = trim((string) ($_POST['check_id'] ?? ''));

if ($checkId === '' && $plate === '') {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => 'Inserisci una targa valida.',
    ]);
    exit;
}

if (!in_array($vehicleType, ['car', 'bike'], true)) {
    $vehicleType = 'car';
}

try {
    $client = new OpenApiAutomotiveClient();

    if ($checkId !== '') {
        $vehicleResult = $client->checkId($checkId);
    } elseif ($vehicleType === 'bike') {
        $vehicleResult = $client->lookupItBike($plate);
    } else {
        $vehicleResult = $client->lookupItCar($plate);
    }

    $insuranceResult = null;
    if ($includeInsurance && $checkId === '') {
        $insuranceResult = $client->lookupItInsurance($plate);
    }

    echo json_encode([
        'success' => true,
        'pending' => $vehicleResult['pending'],
        'check_id' => $vehicleResult['check_id'],
        'retry_after' => $vehicleResult['retry_after'],
        'vehicle' => $vehicleResult['data'],
        'vehicle_found' => $vehicleResult['found'],
        'insurance' => $insuranceResult ? $insuranceResult['data'] : null,
        'insurance_found' => $insuranceResult ? $insuranceResult['found'] : null,
        'message' => $vehicleResult['message'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $exception) {
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    http_response_code(500);
    error_log('ACI Automotive lookup failed: ' . $exception->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Errore interno durante la ricerca.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
