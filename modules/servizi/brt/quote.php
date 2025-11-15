<?php
declare(strict_types=1);

define('CORESUITE_BRT_BOOTSTRAP', true);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/helpers.php';
$autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}
require_once __DIR__ . '/functions.php';

use App\Services\Brt\BrtConfig;
use App\Services\Brt\BrtException;
use App\Services\Brt\BrtShipmentService;

require_role('Admin', 'Operatore', 'Manager');

header('Content-Type: application/json; charset=utf-8');

$respond = static function (int $status, array $payload): void {
    http_response_code($status);
    try {
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $encodingException) {
        echo json_encode([
            'success' => false,
            'message' => 'Errore nella preparazione della risposta JSON.',
        ]);
    }
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $respond(405, [
        'success' => false,
        'message' => 'Metodo non consentito.',
    ]);
}

require_valid_csrf();

$parseDecimal = static function (string $value): ?float {
    $normalized = str_replace([' ', ','], ['', '.'], trim($value));
    if ($normalized === '') {
        return null;
    }
    if (!is_numeric($normalized)) {
        return null;
    }
    return (float) $normalized;
};

$errors = [];

$parcelsInput = trim((string) ($_POST['number_of_parcels'] ?? ''));
if ($parcelsInput === '' || !preg_match('/^\d+$/', $parcelsInput)) {
    $errors[] = 'Inserisci un numero di colli valido.';
}
$parcels = (int) $parcelsInput;
if ($parcels <= 0) {
    $errors[] = 'Il numero di colli deve essere maggiore di zero.';
}

$weightValue = $parseDecimal((string) ($_POST['weight_kg'] ?? ''));
if ($weightValue === null || $weightValue <= 0) {
    $errors[] = 'Inserisci un peso valido per la spedizione.';
}

$lengthValue = $parseDecimal((string) ($_POST['dimension_length_cm'] ?? ''));
$depthValue = $parseDecimal((string) ($_POST['dimension_depth_cm'] ?? ''));
$heightValue = $parseDecimal((string) ($_POST['dimension_height_cm'] ?? ''));

if ($lengthValue === null || $lengthValue <= 0) {
    $errors[] = 'Inserisci la lunghezza in centimetri.';
}
if ($depthValue === null || $depthValue <= 0) {
    $errors[] = 'Inserisci la profondità in centimetri.';
}
if ($heightValue === null || $heightValue <= 0) {
    $errors[] = "Inserisci l'altezza in centimetri.";
}

$zipCode = trim((string) ($_POST['consignee_zip'] ?? ''));
$country = strtoupper(trim((string) ($_POST['consignee_country'] ?? '')));

if ($country === 'IE') {
    $zipCode = strtoupper($zipCode);
    if ($zipCode === '') {
        $zipCode = 'EIRE';
    }
}

if ($zipCode === '') {
    $errors[] = 'Inserisci il CAP del destinatario.';
}

if ($country === '') {
    $errors[] = 'Seleziona la nazione di destinazione.';
}

$numericReferenceRaw = trim((string) ($_POST['numeric_sender_reference'] ?? ''));
$numericReference = null;
if ($numericReferenceRaw !== '') {
    if (!preg_match('/^\d+$/', $numericReferenceRaw)) {
        $errors[] = 'Il riferimento numerico mittente deve essere un numero intero positivo.';
    } else {
        $numericReference = (int) $numericReferenceRaw;
        if ($numericReference <= 0) {
            $errors[] = 'Il riferimento numerico mittente deve essere un numero intero positivo.';
            $numericReference = null;
        }
    }
}

$alphaReference = trim((string) ($_POST['alphanumeric_sender_reference'] ?? ''));

$insuranceAmountRaw = trim((string) ($_POST['insurance_amount'] ?? ''));
$insuranceCurrency = strtoupper(trim((string) ($_POST['insurance_currency'] ?? '')));
$insuranceAmount = $insuranceAmountRaw !== '' ? $parseDecimal($insuranceAmountRaw) : null;
if ($insuranceAmount !== null) {
    if ($insuranceAmount <= 0) {
        $errors[] = "L'importo assicurazione deve essere maggiore di zero.";
    } elseif ($insuranceAmount > 99999.99) {
        $errors[] = "L'importo assicurazione non può superare 99.999,99.";
    } elseif (!preg_match('/^[A-Z]{3}$/', $insuranceCurrency)) {
        $errors[] = "Se indichi un'assicurazione seleziona una valuta a 3 lettere (es. EUR).";
    }
}

$codAmountRaw = trim((string) ($_POST['cod_amount'] ?? ''));
$codCurrency = strtoupper(trim((string) ($_POST['cod_currency'] ?? '')));
$codAmount = $codAmountRaw !== '' ? $parseDecimal($codAmountRaw) : null;
$codPaymentType = strtoupper(trim((string) ($_POST['cod_payment_type'] ?? '')));
$isCodMandatory = ((string) ($_POST['is_cod_mandatory'] ?? '0')) === '1';

if ($codAmount !== null) {
    if ($codAmount <= 0) {
        $errors[] = "L'importo contrassegno deve essere maggiore di zero.";
    } elseif ($codAmount > 99999.99) {
        $errors[] = "L'importo contrassegno non può superare 99.999,99.";
    } elseif (!preg_match('/^[A-Z]{3}$/', $codCurrency)) {
        $errors[] = "Se indichi un contrassegno seleziona una valuta a 3 lettere (es. EUR).";
    }
}

if ($codPaymentType !== '' && !preg_match('/^[A-Z0-9]{1,2}$/', $codPaymentType)) {
    $errors[] = "Il tipo pagamento contrassegno deve contenere 1 o 2 caratteri alfanumerici (es. AS).";
}

if ($isCodMandatory && $codAmount === null) {
    $errors[] = 'Se imposti il contrassegno come obbligatorio indica anche un importo maggiore di zero.';
}

if ($errors !== []) {
    $respond(422, [
        'success' => false,
        'message' => $errors[0],
        'errors' => $errors,
    ]);
}

try {
    $config = new BrtConfig();
} catch (Throwable $exception) {
    $respond(500, [
        'success' => false,
        'message' => 'Configurazione BRT non disponibile: ' . $exception->getMessage(),
    ]);
}

if (!$config->isDestinationCountryAllowed($country)) {
    $respond(422, [
        'success' => false,
        'message' => 'La nazione selezionata non è abilitata per le spedizioni BRT.',
    ]);
}

$volume = (($heightValue ?? 0) * ($lengthValue ?? 0) * ($depthValue ?? 0)) / 1_000_000 * $parcels;
if (!is_finite($volume) || $volume <= 0) {
    $respond(422, [
        'success' => false,
        'message' => 'Il volume calcolato risulta nullo. Verifica le dimensioni inserite.',
    ]);
}

$volumetricWeight = (($heightValue ?? 0) * ($lengthValue ?? 0) * ($depthValue ?? 0)) / 4000 * $parcels;

$payload = [
    'senderCustomerCode' => $config->getSenderCustomerCode(),
    'departureDepot' => $config->getDepartureDepot(),
    'numberOfParcels' => $parcels,
    'weightKG' => round($weightValue, 3),
    'volumeM3' => round($volume, 3),
    'consigneeZIPCode' => $zipCode,
    'consigneeCountryAbbreviationISOAlpha2' => $country,
    'dimensionLengthCM' => round($lengthValue, 2),
    'dimensionDepthCM' => round($depthValue, 2),
    'dimensionHeightCM' => round($heightValue, 2),
];

if ($volumetricWeight > 0) {
    $payload['volumetricWeightKG'] = round($volumetricWeight, 2);
}

if ($numericReference !== null) {
    $payload['numericSenderReference'] = $numericReference;
}

if ($alphaReference !== '') {
    $payload['alphanumericSenderReference'] = $alphaReference;
}

$pudoId = trim((string) ($_POST['pudo_id'] ?? ''));
if ($pudoId !== '') {
    $payload['pudoId'] = $pudoId;
}

$payload['isCODMandatory'] = $isCodMandatory ? '1' : '0';

if ($insuranceAmount !== null && $insuranceAmount > 0) {
    $payload['insuranceAmount'] = round($insuranceAmount, 2);
    $payload['insuranceAmountCurrency'] = $insuranceCurrency !== '' ? $insuranceCurrency : 'EUR';
}

if ($codAmount !== null && $codAmount > 0) {
    $payload['cashOnDeliveryAmount'] = round($codAmount, 2);
    $payload['codCurrency'] = $codCurrency !== '' ? $codCurrency : 'EUR';
}

if ($codPaymentType !== '') {
    $payload['codPaymentType'] = $codPaymentType;
}

try {
    $service = new BrtShipmentService($config);
    $response = $service->getRoutingQuote($payload);
    $summary = brt_extract_routing_quote_summary($response);

    $respond(200, [
        'success' => true,
        'summary' => $summary,
        'message' => $summary === null ? 'Nessun importo stimato restituito dal webservice BRT.' : null,
    ]);
} catch (BrtException $exception) {
    if (function_exists('error_log')) {
        $context = [
            'message' => $exception->getMessage(),
            'payload' => $payload,
        ];
        try {
            error_log('[BRT][quote] ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } catch (Throwable $loggingException) {
            error_log('[BRT][quote] ' . $exception->getMessage());
        }
    }
    $respond(400, [
        'success' => false,
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    $respond(500, [
        'success' => false,
        'message' => 'Impossibile calcolare il costo stimato: ' . $exception->getMessage(),
    ]);
}
