<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/helpers.php';

use JsonException;
use RuntimeException;
use SoapClient;
use SoapFault;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || !current_user_can('Collaboratore')) {
    http_response_code(403);
    echo json_encode(['error' => 'Non sei autorizzato a verificare la P.IVA.']);
    exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Metodo non supportato.']);
    exit;
}

// CSRF enforcement (expects form field _token or header)
$originalMethod = $_SERVER['REQUEST_METHOD'];
$_SERVER['REQUEST_METHOD'] = 'POST';
require_valid_csrf();
$_SERVER['REQUEST_METHOD'] = $originalMethod;

try {
    $vat = '';
    if (isset($_POST['vat'])) {
        $vat = (string) $_POST['vat'];
    } else {
        $rawBody = file_get_contents('php://input') ?: '';
        if ($rawBody !== '') {
            $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            $vat = (string) ($payload['vat'] ?? '');
        }
    }

    $vat = strtoupper(preg_replace('/[^A-Z0-9]/', '', $vat) ?? '');
    if ($vat === '') {
        throw new RuntimeException('Inserisci una Partita IVA.');
    }

    if (strlen($vat) >= 3 && preg_match('/^[0-9]{11}$/', $vat)) {
        $vat = 'IT' . $vat;
    }

    if (strlen($vat) < 3) {
        throw new RuntimeException('Partita IVA non valida.');
    }

    $countryCode = substr($vat, 0, 2);
    $vatNumber = substr($vat, 2);
    if (!preg_match('/^[A-Z]{2}$/', $countryCode) || $vatNumber === '') {
        throw new RuntimeException('Partita IVA non valida.');
    }

    try {
        $client = new SoapClient(
            'https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl',
            [
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_MEMORY,
                'trace' => false,
            ]
        );

        $response = $client->checkVat([
            'countryCode' => $countryCode,
            'vatNumber' => $vatNumber,
        ]);
    } catch (SoapFault $exception) {
        throw new RuntimeException('Servizio VIES non disponibile. Riprova più tardi.', 0, $exception);
    }

    $valid = isset($response->valid) ? (bool) $response->valid : false;

    echo json_encode([
        'status' => 'ok',
        'valid' => $valid,
        'vat' => $vat,
        'name' => $valid ? trim((string) ($response->name ?? '')) : '',
        'address' => $valid ? trim((string) ($response->address ?? '')) : '',
    ], JSON_THROW_ON_ERROR);
} catch (RuntimeException $exception) {
    http_response_code(422);
    echo json_encode(['error' => $exception->getMessage()]);
} catch (JsonException) {
    http_response_code(400);
    echo json_encode(['error' => 'Formato JSON non valido.']);
} catch (Throwable $exception) {
    error_log('VAT check API failure: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Errore inatteso durante la verifica VIES.']);
}
