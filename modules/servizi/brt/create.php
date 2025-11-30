<?php
declare(strict_types=1);

use App\Services\Brt\BrtConfig;
use App\Services\Brt\BrtException;
use App\Services\Brt\BrtShipmentService;
use App\Services\SettingsService;

define('CORESUITE_BRT_BOOTSTRAP', true);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
$autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Nuova spedizione BRT';

$csrfToken = csrf_token();

try {
    ensure_brt_tables();
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit('Database BRT non configurato: ' . $exception->getMessage());
}

$config = new BrtConfig();
$allowedDestinationCountries = $config->getAllowedDestinationCountries();
$savedRecipients = brt_get_saved_recipients();
$primaryServiceCode = strtoupper($config->getDefaultBrtServiceCode() ?? '');
$returnServiceCode = strtoupper($config->getReturnServiceCode() ?? '');
$configuredReturnDepot = $config->getDefaultReturnDepot();
$configuredReturnDepotString = $configuredReturnDepot !== null ? (string) $configuredReturnDepot : '';

$projectRoot = realpath(__DIR__ . '/../../../') ?: __DIR__ . '/../../../';
$settingsService = new SettingsService($pdo, $projectRoot);
$portalBrtPricingConfig = $settingsService->getPortalBrtPricing();
$portalBrtPricingCurrency = strtoupper((string) ($portalBrtPricingConfig['currency'] ?? 'EUR'));
$portalBrtPricingTiers = $portalBrtPricingConfig['tiers'] ?? [];
try {
    $portalBrtPricingJson = htmlspecialchars(json_encode($portalBrtPricingConfig, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
} catch (Throwable $pricingException) {
    $portalBrtPricingJson = htmlspecialchars('{"currency":"' . $portalBrtPricingCurrency . '","tiers":[]}', ENT_QUOTES, 'UTF-8');
}
$portalBrtPricingInfoMessage = $portalBrtPricingTiers === []
    ? 'Configura le tariffe in Impostazioni -> Tariffe BRT per ottenere un costo stimato.'
    : 'Compila peso e dimensioni per visualizzare il costo stimato.';

$normalizePortalPricingTier = static function (array $tier, int $index): ?array {
    $priceValue = $tier['price'] ?? null;
    if (!is_numeric($priceValue)) {
        return null;
    }

    $price = (float) $priceValue;
    if ($price <= 0) {
        return null;
    }

    $label = '';
    if (isset($tier['label']) && is_string($tier['label'])) {
        $label = trim($tier['label']);
    }

    $maxWeight = null;
    if (array_key_exists('max_weight', $tier) && $tier['max_weight'] !== null && $tier['max_weight'] !== '') {
        if (is_numeric($tier['max_weight'])) {
            $maxWeight = (float) $tier['max_weight'];
        }
    }

    $maxVolume = null;
    if (array_key_exists('max_volume', $tier) && $tier['max_volume'] !== null && $tier['max_volume'] !== '') {
        if (is_numeric($tier['max_volume'])) {
            $maxVolume = (float) $tier['max_volume'];
        }
    }

    return [
        'index' => $index,
        'label' => $label,
        'price' => round($price, 2),
        'max_weight' => $maxWeight,
        'max_volume' => $maxVolume,
    ];
};

$matchPortalPricingTier = static function (array $tiers, float $weightKg, float $volumeM3) use ($normalizePortalPricingTier): ?array {
    if ($weightKg <= 0 || $volumeM3 <= 0) {
        return null;
    }

    $epsilon = 0.0001;

    foreach ($tiers as $index => $tier) {
        $normalized = $normalizePortalPricingTier(is_array($tier) ? $tier : [], (int) $index);
        if ($normalized === null) {
            continue;
        }

        $maxWeight = $normalized['max_weight'];
        $maxVolume = $normalized['max_volume'];
        $weightMatches = $maxWeight === null || $weightKg <= ($maxWeight + $epsilon);
        $volumeMatches = $maxVolume === null || $volumeM3 <= ($maxVolume + $epsilon);

        if ($weightMatches && $volumeMatches) {
            return $normalized;
        }
    }

    return null;
};

$referencePrefixRaw = (string) (env('BRT_REFERENCE_PREFIX', 'BR') ?? 'BR');
$referencePrefixNormalized = strtoupper(preg_replace('/[^A-Z]/', '', $referencePrefixRaw));
if ($referencePrefixNormalized === '') {
    $referencePrefixNormalized = 'BR';
}

$normalizeReferenceComponent = static function (string $value): string {
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT', $trimmed);
        if (is_string($converted) && $converted !== '') {
            $trimmed = $converted;
        }
    }

    $upper = strtoupper($trimmed);
    $sanitized = preg_replace('/[^A-Z0-9]/', '', $upper);
    return is_string($sanitized) ? $sanitized : '';
};

$buildSenderReference = static function (int $numericReference, ?string $senderName = null, ?string $recipientName = null) use ($normalizeReferenceComponent, $referencePrefixNormalized): string {
    $prefix = $referencePrefixNormalized . date('ymd');

    $senderCandidate = trim((string) ($senderName ?? ''));
    $recipientCandidate = trim((string) ($recipientName ?? ''));
    $sourceValue = $senderCandidate !== '' ? $senderCandidate : $recipientCandidate;
    if ($sourceValue === '') {
        $sourceValue = $referencePrefixNormalized;
    }

    $tokens = preg_split('/[\s,;]+/', $sourceValue) ?: [];
    $tokens = array_values(array_filter($tokens, static fn($token) => $token !== ''));

    $slugParts = [];
    if ($tokens !== []) {
        $firstTokenRaw = $tokens[0];
        $firstToken = $normalizeReferenceComponent($firstTokenRaw);
        if ($firstToken !== '') {
            $slugParts[] = substr($firstToken, 0, 10);
        }

        $lastTokenRaw = $tokens[count($tokens) - 1];
        if (strcasecmp($firstTokenRaw, $lastTokenRaw) !== 0) {
            $lastToken = $normalizeReferenceComponent($lastTokenRaw);
            if ($lastToken !== '') {
                $slugParts[] = substr($lastToken, 0, 10);
            }
        }
    }

    if ($slugParts === []) {
        $fallbackToken = $normalizeReferenceComponent($sourceValue);
        if ($fallbackToken === '') {
            $fallbackToken = $referencePrefixNormalized;
        }
        $slugParts[] = substr($fallbackToken, 0, 12);
    }

    $slug = implode('-', array_filter($slugParts, static fn($part) => $part !== ''));
    if ($slug === '') {
        $slug = $referencePrefixNormalized;
    }

    $reference = sprintf('%s-%s-%d', $prefix, $slug, $numericReference);
    return strlen($reference) > 80 ? substr($reference, 0, 80) : $reference;
};

$defaultCountry = strtoupper($config->getDefaultCountryIsoAlpha2() ?? 'IT');
if (!isset($allowedDestinationCountries[$defaultCountry])) {
    if (isset($allowedDestinationCountries['IT'])) {
        $defaultCountry = 'IT';
    } else {
        $allowedCountryKeys = array_keys($allowedDestinationCountries);
        $defaultCountry = $allowedCountryKeys[0] ?? 'IT';
    }
}

try {
    $senderCustomerCode = $config->getSenderCustomerCode();
    $departureDepot = $config->getDepartureDepot();
} catch (BrtException $exception) {
    add_flash('warning', $exception->getMessage());
    header('Location: index.php');
    exit;
}

$nextNumericReference = brt_next_numeric_reference($senderCustomerCode);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$referenceSessionKey = 'brt_numeric_reference_' . $senderCustomerCode;
if (!isset($_SESSION[$referenceSessionKey]) || (int) $_SESSION[$referenceSessionKey] < $nextNumericReference) {
    $_SESSION[$referenceSessionKey] = $nextNumericReference;
}

$currentNumericReference = (int) $_SESSION[$referenceSessionKey];

$data = [
    'numeric_sender_reference' => (string) $currentNumericReference,
    'alphanumeric_sender_reference' => '',
    'sender_display_name' => current_user_display_name(),
    'number_of_parcels' => '1',
    'weight_kg' => '1.00',
    'dimension_length_cm' => '',
    'dimension_depth_cm' => '',
    'dimension_height_cm' => '',
    'consignee_company_name' => '',
    'consignee_address' => '',
    'consignee_zip' => '',
    'consignee_city' => '',
    'consignee_province' => '',
    'consignee_country' => $defaultCountry,
    'consignee_contact_name' => '',
    'consignee_phone' => '',
    'consignee_mobile' => '',
    'consignee_email' => '',
    'notes' => '',
    'is_label_required' => '1',
    'insurance_amount' => '',
    'insurance_currency' => 'EUR',
    'cod_amount' => '',
    'cod_currency' => 'EUR',
    'cod_payment_type' => '',
    'is_cod_mandatory' => '0',
    'pudo_id' => $config->getDefaultPudoId() ?? '',
    'pudo_description' => '',
    'saved_recipient_id' => '',
    'save_recipient' => '0',
    'recipient_label' => '',
    'service_code' => $primaryServiceCode,
    'return_depot' => '',
    'is_return_shipment' => '0',
];

$customsCategories = brt_customs_categories();
$customsIncoterms = [
    'DAP' => 'DAP - Delivered At Place',
    'DDP' => 'DDP - Delivered Duty Paid',
    'EXW' => 'EXW - Ex Works',
    'CIP' => 'CIP - Carriage And Insurance Paid To',
    'CPT' => 'CPT - Carriage Paid To',
    'FCA' => 'FCA - Free Carrier',
    'FOB' => 'FOB - Free On Board',
];
$customsCurrencies = [
    'EUR' => 'Euro (EUR)',
    'CHF' => 'Franco Svizzero (CHF)',
];
$monetaryCurrencies = [
    'EUR' => 'Euro (EUR)',
    'CHF' => 'Franco Svizzero (CHF)',
    'USD' => 'Dollaro USA (USD)',
    'GBP' => 'Sterlina Britannica (GBP)',
];
$customsForm = brt_customs_default_form_data();
$customsPayload = null;
$customsRequiredCountries = brt_customs_required_countries();

$errors = [];
$computedVolume = null;

$computedVolumetricWeight = null;
$lengthCm = null;
$depthCm = null;
$heightCm = null;
$weightKgValue = null;
$insuranceAmountValue = null;
$codAmountValue = null;
$insuranceCurrency = strtoupper($data['insurance_currency']);
$data['insurance_currency'] = $insuranceCurrency !== '' ? $insuranceCurrency : 'EUR';
$codCurrency = strtoupper($data['cod_currency']);
$data['cod_currency'] = $codCurrency !== '' ? $codCurrency : 'EUR';
$codPaymentType = strtoupper($data['cod_payment_type']);
$data['cod_payment_type'] = $codPaymentType;
$isCodMandatoryFlag = $data['is_cod_mandatory'] === '1';
$data['is_cod_mandatory'] = $isCodMandatoryFlag ? '1' : '0';
$routingQuoteSummary = null;
$routingQuoteError = null;
$routingQuoteUnavailableReason = 'Compila peso, dimensioni, CAP e nazione per ottenere un costo stimato.';
$quoteRequested = false;
$submitIntent = 'create';
$autoGeneratedAlphanumericReference = false;

$isCustomsRequired = in_array($data['consignee_country'], $customsRequiredCountries, true);
if ($isCustomsRequired) {
    $customsForm['enabled'] = '1';
}

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

$isReturnShipment = false;
$serviceCodeToUse = $primaryServiceCode;
$returnDepotToUse = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $intentRaw = isset($_POST['intent']) ? (string) $_POST['intent'] : '';
    $submitIntent = $intentRaw === 'quote' ? 'quote' : 'create';
    $quoteRequested = $submitIntent === 'quote';

    foreach ($data as $field => $default) {
        if ($field === 'is_label_required') {
            $data[$field] = isset($_POST[$field]) ? '1' : '0';
            continue;
        }
        if ($field === 'save_recipient') {
            $data[$field] = isset($_POST[$field]) ? '1' : '0';
            continue;
        }
        if ($field === 'is_cod_mandatory') {
            $data[$field] = isset($_POST[$field]) ? '1' : '0';
            continue;
        }
        if ($field === 'numeric_sender_reference') {
            continue;
        }
        $data[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    $data['is_return_shipment'] = isset($_POST['is_return_shipment']) ? '1' : '0';

    $senderDisplayNameInput = trim((string) ($_POST['sender_display_name'] ?? $data['sender_display_name']));
    $data['sender_display_name'] = $senderDisplayNameInput !== '' ? $senderDisplayNameInput : current_user_display_name();

    $insuranceCurrency = strtoupper($data['insurance_currency']);
    $data['insurance_currency'] = $insuranceCurrency !== '' ? $insuranceCurrency : 'EUR';

    $codCurrency = strtoupper($data['cod_currency']);
    $data['cod_currency'] = $codCurrency !== '' ? $codCurrency : 'EUR';

    $codPaymentType = strtoupper($data['cod_payment_type']);
    $data['cod_payment_type'] = $codPaymentType;

    $isCodMandatoryFlag = $data['is_cod_mandatory'] === '1';
    $data['is_cod_mandatory'] = $isCodMandatoryFlag ? '1' : '0';

    $currentNumericReference = (int) ($_SESSION[$referenceSessionKey] ?? $nextNumericReference);
    $data['numeric_sender_reference'] = (string) $currentNumericReference;

    $data['consignee_country'] = strtoupper($data['consignee_country']);
    if ($data['consignee_country'] === 'IE') {
        if ($data['consignee_zip'] === '') {
            $data['consignee_zip'] = 'EIRE';
        } else {
            $data['consignee_zip'] = strtoupper($data['consignee_zip']);
        }
    }

    $senderDisplayName = $data['sender_display_name'] !== '' ? $data['sender_display_name'] : current_user_display_name();
    $recipientForReference = $data['consignee_company_name'] !== '' ? $data['consignee_company_name'] : $data['consignee_contact_name'];
    $alphaInput = trim($data['alphanumeric_sender_reference']);
    if ($alphaInput === '') {
        $data['alphanumeric_sender_reference'] = $buildSenderReference((int) $data['numeric_sender_reference'], $senderDisplayName, $recipientForReference);
        $autoGeneratedAlphanumericReference = true;
    } else {
        if (function_exists('mb_substr')) {
            $data['alphanumeric_sender_reference'] = trim((string) mb_substr($alphaInput, 0, 80, 'UTF-8'));
        } else {
            $data['alphanumeric_sender_reference'] = trim(substr($alphaInput, 0, 80));
        }
    }

    $isReturnShipment = $data['is_return_shipment'] === '1';
    $manualServiceCode = strtoupper(trim((string) ($_POST['service_code'] ?? $data['service_code'] ?? '')));
    $manualReturnDepot = trim((string) ($_POST['return_depot'] ?? $data['return_depot'] ?? ''));

    $serviceCodeToUse = $manualServiceCode !== '' ? $manualServiceCode : $primaryServiceCode;
    if ($isReturnShipment) {
        $serviceCodeToUse = $returnServiceCode !== '' ? $returnServiceCode : ($serviceCodeToUse !== '' ? $serviceCodeToUse : $primaryServiceCode);
    }

    $returnDepotToUse = '';
    if ($isReturnShipment) {
        $returnDepotToUse = $manualReturnDepot !== '' ? $manualReturnDepot : trim($configuredReturnDepotString);
    } elseif ($manualReturnDepot !== '') {
        $returnDepotToUse = $manualReturnDepot;
    }

    $data['service_code'] = $manualServiceCode !== '' ? $manualServiceCode : $serviceCodeToUse;
    $data['return_depot'] = $returnDepotToUse;

    if ($serviceCodeToUse !== '' && !preg_match('/^[A-Z0-9]{2,4}$/', $serviceCodeToUse)) {
        $errors[] = 'Il codice servizio BRT deve contenere 2-4 caratteri alfanumerici (es. B14).';
    }

    if ($returnDepotToUse !== '' && !preg_match('/^[0-9]{6,12}$/', $returnDepotToUse)) {
        $errors[] = 'Il depot di rientro deve contenere da 6 a 12 cifre numeriche.';
    }

    if ($isReturnShipment) {
        if ($serviceCodeToUse === '') {
            $errors[] = 'Seleziona un codice servizio per i resi (configura BRT_RETURN_SERVICE_CODE o compilalo manualmente).';
        }
        if ($returnDepotToUse === '') {
            $errors[] = 'Per le spedizioni di rientro indica un depot di rientro valido.';
        }
    }

    $customsInput = $_POST['customs'] ?? [];
    if (!is_array($customsInput)) {
        $customsInput = [];
    }
    $customsForm = brt_normalize_customs_form_input($customsInput);
    $isCustomsRequired = in_array($data['consignee_country'], $customsRequiredCountries, true);
    $customsForm['enabled'] = $isCustomsRequired ? '1' : '0';
    $customsValidation = brt_validate_customs_form($customsForm, $isCustomsRequired);
    $customsPayload = $customsValidation['payload'];
    if ($customsValidation['errors'] !== []) {
        $errors = array_merge($errors, $customsValidation['errors']);
    }

    if (strlen($data['pudo_id']) > 120) {
        $errors[] = 'Il codice PUDO selezionato non è valido.';
    }

    if (!preg_match('/^\d+$/', $data['number_of_parcels']) || (int) $data['number_of_parcels'] <= 0) {
        $errors[] = 'Inserisci un numero di colli valido (>= 1).';
    }

    if (!is_numeric(str_replace(',', '.', $data['weight_kg'])) || (float) str_replace(',', '.', $data['weight_kg']) <= 0) {
        $errors[] = 'Inserisci un peso valido (kg).';
    }

    if ($submitIntent === 'create' && $data['consignee_company_name'] === '') {
        $errors[] = 'Inserisci la ragione sociale o il nominativo del destinatario.';
    }

    if ($submitIntent === 'create' && $data['consignee_address'] === '') {
        $errors[] = 'Inserisci l\'indirizzo del destinatario.';
    }

    if ($data['consignee_zip'] === '') {
        $errors[] = 'Inserisci il CAP del destinatario.';
    }

    if ($submitIntent === 'create' && $data['consignee_city'] === '') {
        $errors[] = 'Inserisci la città del destinatario.';
    }

    if ($data['consignee_country'] === '' || !$config->isDestinationCountryAllowed($data['consignee_country'])) {
        $errors[] = 'Seleziona una nazione di destinazione supportata (Italia o Europa).';
    }

    if ($data['consignee_email'] !== '' && !filter_var($data['consignee_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Inserisci un indirizzo email valido per il destinatario oppure lascia il campo vuoto.';
    }

    if (!preg_match('/^\d+$/', $data['numeric_sender_reference']) || (int) $data['numeric_sender_reference'] <= 0) {
        $errors[] = 'Il riferimento numerico mittente deve essere un numero intero positivo.';
    }

    $lengthCm = $parseDecimal($data['dimension_length_cm']);
    $depthCm = $parseDecimal($data['dimension_depth_cm']);
    $heightCm = $parseDecimal($data['dimension_height_cm']);
    $weightKgValue = $parseDecimal($data['weight_kg']);
    $insuranceAmount = $parseDecimal($data['insurance_amount']);
    $codAmount = $parseDecimal($data['cod_amount']);

    if ($lengthCm === null || $lengthCm <= 0) {
        $errors[] = 'Inserisci la lunghezza in centimetri (valore positivo).';
    }

    if ($depthCm === null || $depthCm <= 0) {
        $errors[] = 'Inserisci la profondità in centimetri (valore positivo).';
    }

    if ($heightCm === null || $heightCm <= 0) {
        $errors[] = 'Inserisci l\'altezza in centimetri (valore positivo).';
    }

    if ($insuranceAmount !== null) {
        if ($insuranceAmount <= 0) {
            $errors[] = "L'importo assicurazione deve essere maggiore di zero.";
        } elseif ($insuranceAmount > 99999.99) {
            $errors[] = "L'importo assicurazione non può superare 99.999,99.";
        } elseif (!preg_match('/^[A-Z]{3}$/', $insuranceCurrency)) {
            $errors[] = "Se indichi un'assicurazione seleziona una valuta a 3 lettere (es. EUR).";
        } else {
            $insuranceAmountValue = round($insuranceAmount, 2);
        }
    } else {
        $insuranceAmountValue = null;
    }

    if ($codAmount !== null) {
        if ($codAmount <= 0) {
            $errors[] = "L'importo contrassegno deve essere maggiore di zero.";
        } elseif ($codAmount > 99999.99) {
            $errors[] = "L'importo contrassegno non può superare 99.999,99.";
        } elseif (!preg_match('/^[A-Z]{3}$/', $codCurrency)) {
            $errors[] = "Se indichi un contrassegno seleziona una valuta a 3 lettere (es. EUR).";
        } else {
            $codAmountValue = round($codAmount, 2);
        }
    } else {
        $codAmountValue = null;
    }

    if ($codPaymentType !== '' && !preg_match('/^[A-Z0-9]{1,2}$/', $codPaymentType)) {
        $errors[] = "Il tipo pagamento contrassegno deve contenere 1 o 2 caratteri alfanumerici (es. AS).";
    }

    if ($isCodMandatoryFlag && $codAmountValue === null) {
        $errors[] = 'Se imposti il contrassegno come obbligatorio indica anche un importo maggiore di zero.';
    }

    $parcelsCount = preg_match('/^\d+$/', $data['number_of_parcels']) ? (int) $data['number_of_parcels'] : 0;
    if ($lengthCm !== null && $lengthCm > 0 && $depthCm !== null && $depthCm > 0 && $heightCm !== null && $heightCm > 0 && $parcelsCount > 0) {
        $perParcelVolume = ($heightCm * $lengthCm * $depthCm) / 1_000_000; // cm³ -> m³
        $computedVolume = $perParcelVolume * $parcelsCount;
        $computedVolumetricWeight = (($heightCm * $lengthCm * $depthCm) / 4000) * $parcelsCount;

        if ($computedVolume <= 0) {
            $errors[] = 'Il volume calcolato risulta nullo. Verifica le dimensioni inserite.';
        }
    }

    $portalPricingEvaluation = null;
    $portalPricingMatch = null;
    if (!$errors && $portalBrtPricingTiers !== [] && $parcelsCount > 0 && $computedVolume !== null && $computedVolume > 0 && $weightKgValue !== null && $weightKgValue > 0) {
        $portalPricingEvaluation = [
            'weight_kg' => round($weightKgValue, 3),
            'volume_m3' => round($computedVolume, 4),
            'volumetric_weight_kg' => $computedVolumetricWeight !== null ? round($computedVolumetricWeight, 3) : null,
            'number_of_parcels' => $parcelsCount,
        ];
        $matchedTier = $matchPortalPricingTier($portalBrtPricingTiers, $weightKgValue, $computedVolume);
        if ($matchedTier !== null) {
            $portalPricingMatch = $matchedTier;
        }
    }

    if (!$errors) {
        $createData = [
            'senderCustomerCode' => $senderCustomerCode,
            'numericSenderReference' => (int) $data['numeric_sender_reference'],
            'alphanumericSenderReference' => $data['alphanumeric_sender_reference'],
            'departureDepot' => $departureDepot,
            'numberOfParcels' => (int) $data['number_of_parcels'],
            'weightKG' => $weightKgValue !== null ? $weightKgValue : (float) str_replace(',', '.', $data['weight_kg']),
            'volumeM3' => $computedVolume !== null ? round($computedVolume, 3) : 0,
            'consigneeCompanyName' => $data['consignee_company_name'],
            'consigneeAddress' => $data['consignee_address'],
            'consigneeZIPCode' => $data['consignee_zip'],
            'consigneeCity' => $data['consignee_city'],
            'consigneeProvinceAbbreviation' => $data['consignee_province'],
            'consigneeCountryAbbreviationISOAlpha2' => $data['consignee_country'],
            'consigneeContactName' => $data['consignee_contact_name'],
            'consigneeTelephone' => $data['consignee_phone'],
            'consigneeMobilePhoneNumber' => $data['consignee_mobile'],
            'consigneeEMail' => $data['consignee_email'],
            'notes' => $data['notes'],
            'isLabelRequired' => $data['is_label_required'] === '1' ? 1 : 0,
            'pudoId' => $data['pudo_id'],
        ];

        if ($serviceCodeToUse !== '') {
            $createData['brtServiceCode'] = $serviceCodeToUse;
        }

        if ($returnDepotToUse !== '') {
            $createData['returnDepot'] = $returnDepotToUse;
        }

        if ($insuranceAmountValue !== null) {
            $createData['insuranceAmount'] = $insuranceAmountValue;
            $createData['insuranceAmountCurrency'] = $insuranceCurrency !== '' ? $insuranceCurrency : 'EUR';
        }

        $createData['isCODMandatory'] = $isCodMandatoryFlag ? '1' : '0';

        if ($codAmountValue !== null) {
            $createData['cashOnDeliveryAmount'] = $codAmountValue;
            $createData['codCurrency'] = $codCurrency !== '' ? $codCurrency : 'EUR';
        }

        if ($codPaymentType !== '') {
            $createData['codPaymentType'] = $codPaymentType;
        }

        if ($submitIntent === 'create') {
            try {
                $service = new BrtShipmentService($config);
                $response = $service->createShipment($createData);
            $servicesMetadata = [];
            if ($insuranceAmountValue !== null) {
                $servicesMetadata['insurance'] = [
                    'amount' => $insuranceAmountValue,
                    'currency' => $insuranceCurrency,
                ];
            }
            if ($codAmountValue !== null || $isCodMandatoryFlag || $codPaymentType !== '') {
                $servicesMetadata['cash_on_delivery'] = [
                    'amount' => $codAmountValue,
                    'currency' => $codAmountValue !== null ? $codCurrency : null,
                    'payment_type' => $codPaymentType !== '' ? $codPaymentType : null,
                    'mandatory' => $isCodMandatoryFlag,
                ];
            }
            $metadata = [
                'dimension_length_cm' => $lengthCm,
                'dimension_depth_cm' => $depthCm,
                'dimension_height_cm' => $heightCm,
                'pudo_description' => $data['pudo_description'],
                'customs' => [
                    'enabled' => $customsPayload !== null,
                    'required_for_country' => $isCustomsRequired,
                    'form' => $customsForm,
                    'payload' => $customsPayload,
                ],
                'service' => [
                    'code' => $serviceCodeToUse !== '' ? $serviceCodeToUse : null,
                    'is_return' => $isReturnShipment,
                    'return_depot' => $returnDepotToUse !== '' ? $returnDepotToUse : null,
                ],
            ];
            if ($servicesMetadata !== []) {
                $metadata['services'] = $servicesMetadata;
            }
                if ($portalPricingEvaluation !== null) {
                    $metadata['portal_pricing'] = [
                        'currency' => $portalBrtPricingCurrency,
                        'evaluation' => $portalPricingEvaluation,
                        'matched_tier' => $portalPricingMatch,
                        'generated_at' => date('c'),
                    ];
                }
            $shipmentId = brt_store_shipment($createData, $response, $metadata);

            if (isset($response['labels']['label'][0]) && is_array($response['labels']['label'][0])) {
                brt_attach_label($shipmentId, $response['labels']['label'][0]);
            }

            $storedShipment = brt_get_shipment($shipmentId);
            if ($storedShipment !== null) {
                try {
                    $customsResult = brt_sync_customs_documents($shipmentId, $storedShipment, $customsPayload);
                    if ($customsResult['status'] === 'error' && isset($customsResult['message'])) {
                        add_flash('warning', 'Documenti doganali non generati: ' . $customsResult['message']);
                    }
                } catch (Throwable $exception) {
                    add_flash('warning', 'Documenti doganali non generati: ' . $exception->getMessage());
                }
            }

            if ($data['save_recipient'] === '1') {
                $recipientLabel = $data['recipient_label'] !== '' ? $data['recipient_label'] : $data['consignee_company_name'];
                try {
                    brt_store_saved_recipient([
                        'label' => $recipientLabel,
                        'company_name' => $data['consignee_company_name'],
                        'address' => $data['consignee_address'],
                        'zip' => $data['consignee_zip'],
                        'city' => $data['consignee_city'],
                        'province' => $data['consignee_province'],
                        'country' => $data['consignee_country'],
                        'contact_name' => $data['consignee_contact_name'],
                        'phone' => $data['consignee_phone'],
                        'mobile' => $data['consignee_mobile'],
                        'email' => $data['consignee_email'],
                        'pudo_id' => $data['pudo_id'],
                        'pudo_description' => $data['pudo_description'],
                    ]);
                } catch (Throwable $exception) {
                    add_flash('warning', 'Destinatario non salvato: ' . $exception->getMessage());
                }
            }

            if ($config->shouldAutoConfirm()) {
                try {
                    $confirmPayload = [
                        'senderCustomerCode' => $senderCustomerCode,
                        'numericSenderReference' => (int) $data['numeric_sender_reference'],
                        'alphanumericSenderReference' => $data['alphanumeric_sender_reference'],
                    ];
                    $confirmResponse = $service->confirmShipment($confirmPayload);
                    brt_mark_shipment_confirmed($shipmentId, $confirmResponse);
                    add_flash('success', 'Spedizione creata e confermata correttamente.');
                } catch (Throwable $exception) {
                    add_flash('warning', 'Spedizione creata ma conferma automatica non riuscita: ' . $exception->getMessage());
                }
            } else {
                add_flash('success', 'Spedizione creata correttamente.');
            }

            $_SESSION[$referenceSessionKey] = brt_next_numeric_reference($senderCustomerCode, (int) $data['numeric_sender_reference']);

            header('Location: index.php');
            exit;
            } catch (BrtException $exception) {
                $message = $exception->getMessage();
                if (stripos($message, 'shipment already done') !== false) {
                    $currentReference = (int) $data['numeric_sender_reference'];
                    $nextReference = brt_next_numeric_reference($senderCustomerCode, $currentReference);
                    $data['numeric_sender_reference'] = (string) $nextReference;
                    $_SESSION[$referenceSessionKey] = $nextReference;
                    $currentNumericReference = $nextReference;
                    if ($autoGeneratedAlphanumericReference) {
                        $recipientForReference = $data['consignee_company_name'] !== '' ? $data['consignee_company_name'] : $data['consignee_contact_name'];
                        $data['alphanumeric_sender_reference'] = $buildSenderReference($nextReference, $data['sender_display_name'], $recipientForReference);
                    }
                    $errors[] = sprintf('BRT segnala che il riferimento %d è già stato utilizzato. Il riferimento numerico è stato aggiornato automaticamente a %d, riprova a creare la spedizione.', $currentReference, $nextReference);
                } else {
                    $errors[] = $message;
                }
            } catch (Throwable $exception) {
                $errors[] = 'Errore inatteso nella creazione della spedizione: ' . $exception->getMessage();
            }
        }
    }
}

$isCustomsRequired = in_array($data['consignee_country'], $customsRequiredCountries, true);
if ($isCustomsRequired && $customsForm['enabled'] !== '1') {
    $customsForm['enabled'] = '1';
}

$parcelsCount = preg_match('/^\d+$/', $data['number_of_parcels']) ? (int) $data['number_of_parcels'] : 0;
$lengthCm = $parseDecimal($data['dimension_length_cm']);
$depthCm = $parseDecimal($data['dimension_depth_cm']);
$heightCm = $parseDecimal($data['dimension_height_cm']);
$weightKgValue = $parseDecimal($data['weight_kg']);

if ($lengthCm !== null && $lengthCm > 0 && $depthCm !== null && $depthCm > 0 && $heightCm !== null && $heightCm > 0 && $parcelsCount > 0) {
    $perParcelVolume = ($heightCm * $lengthCm * $depthCm) / 1_000_000;
    $computedVolume = $perParcelVolume * $parcelsCount;
    $computedVolumetricWeight = (($heightCm * $lengthCm * $depthCm) / 4000) * $parcelsCount;
} else {
    $computedVolume = null;
    $computedVolumetricWeight = null;
}

$canAttemptQuote = false;
if ($parcelsCount > 0 && $weightKgValue !== null && $weightKgValue > 0 && $computedVolume !== null && $computedVolume > 0) {
    if ($data['consignee_zip'] === '' || $data['consignee_country'] === '') {
        $routingQuoteUnavailableReason = 'Inserisci CAP e nazione per ottenere un costo stimato.';
    } elseif (!$config->isDestinationCountryAllowed($data['consignee_country'])) {
        $routingQuoteUnavailableReason = 'La nazione selezionata non è abilitata per il calcolo dei costi.';
    } else {
        $canAttemptQuote = true;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' || $quoteRequested) {
    $routingQuoteUnavailableReason = 'Verifica peso, dimensioni e numero di colli prima di calcolare il costo.';
}

if ($canAttemptQuote) {
    $quotePayload = [
        'senderCustomerCode' => $senderCustomerCode,
        'departureDepot' => $departureDepot,
        'numericSenderReference' => (int) $data['numeric_sender_reference'],
        'alphanumericSenderReference' => $data['alphanumeric_sender_reference'],
        'numberOfParcels' => $parcelsCount,
        'weightKG' => $weightKgValue,
        'volumeM3' => round($computedVolume, 3),
        'consigneeZIPCode' => $data['consignee_zip'],
        'consigneeCountryAbbreviationISOAlpha2' => $data['consignee_country'],
        'dimensionLengthCM' => $lengthCm,
        'dimensionDepthCM' => $depthCm,
        'dimensionHeightCM' => $heightCm,
    ];

    if ($computedVolumetricWeight !== null && $computedVolumetricWeight > 0) {
        $quotePayload['volumetricWeightKG'] = round($computedVolumetricWeight, 2);
    }

    if ($data['pudo_id'] !== '') {
        $quotePayload['pudoId'] = $data['pudo_id'];
    }

    if ($insuranceAmountValue !== null) {
        $quotePayload['insuranceAmount'] = $insuranceAmountValue;
        $quotePayload['insuranceAmountCurrency'] = $insuranceCurrency !== '' ? $insuranceCurrency : 'EUR';
    }

    $quotePayload['isCODMandatory'] = $isCodMandatoryFlag ? '1' : '0';

    if ($codAmountValue !== null) {
        $quotePayload['cashOnDeliveryAmount'] = $codAmountValue;
        $quotePayload['codCurrency'] = $codCurrency !== '' ? $codCurrency : 'EUR';
    }

    if ($codPaymentType !== '') {
        $quotePayload['codPaymentType'] = $codPaymentType;
    }

    try {
        $quoteService = new BrtShipmentService($config);
        $routingResponse = $quoteService->getRoutingQuote($quotePayload);
        $summary = brt_extract_routing_quote_summary($routingResponse);

        if ($summary !== null) {
            $routingQuoteSummary = $summary;
            $routingQuoteUnavailableReason = null;
        } else {
            $routingQuoteUnavailableReason = 'Nessun importo stimato restituito dal webservice BRT.';
        }
    } catch (BrtException $exception) {
        $routingQuoteError = $exception->getMessage();
    } catch (Throwable $exception) {
        $routingQuoteError = 'Impossibile calcolare il costo stimato: ' . $exception->getMessage();
    }
}

$computedVolumeDisplay = $computedVolume !== null && $computedVolume > 0 ? number_format($computedVolume, 3, ',', '.') : '';
$computedVolumetricWeightDisplay = $computedVolumetricWeight !== null && $computedVolumetricWeight > 0 ? number_format($computedVolumetricWeight, 2, ',', '.') : '';

try {
    $customsCountriesJson = htmlspecialchars(json_encode($customsRequiredCountries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
} catch (Throwable $customsException) {
    $customsCountriesJson = '["CH"]';
}
$customsSectionHiddenClass = $customsForm['enabled'] === '1' ? '' : ' d-none';

$extraStyles = ($extraStyles ?? []);
$extraStyles[] = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';

$extraScripts = ($extraScripts ?? []);
$extraScripts[] = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
$extraScripts[] = asset('modules/servizi/brt/js/pudo-map.js');
$extraScripts[] = asset('modules/servizi/brt/js/recipients.js');
$extraScripts[] = asset('modules/servizi/brt/js/cap-lookup.js');
$extraScripts[] = asset('modules/servizi/brt/js/customs.js');

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Nuova spedizione BRT</h1>
                <p class="text-muted mb-0">Compila i dati del destinatario e genera l'etichetta con riferimento mittente progressivo.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Torna alle spedizioni</a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card ag-card">
                    <div class="card-body">
                        <h2 class="h5">Configurazione mittente</h2>
                        <dl class="mb-0">
                            <dt class="text-muted small">Codice cliente mittente</dt>
                            <dd class="fw-semibold"><?php echo sanitize_output($senderCustomerCode); ?></dd>
                            <dt class="text-muted small">Filiale partenza</dt>
                            <dd class="fw-semibold"><?php echo sanitize_output($departureDepot); ?></dd>
                            <dt class="text-muted small">Prossimo riferimento numerico</dt>
                            <dd class="fw-semibold"><?php echo sanitize_output($data['numeric_sender_reference']); ?></dd>
                            <dt class="text-muted small">Conferma automatica</dt>
                            <dd class="fw-semibold"><?php echo $config->shouldAutoConfirm() ? 'Abilitata' : 'Disabilitata'; ?></dd>
                        </dl>
                    </div>
                </div>
                <div class="card ag-card mt-4" data-pricing-card data-pricing-config="<?php echo $portalBrtPricingJson; ?>" data-pricing-currency="<?php echo sanitize_output($portalBrtPricingCurrency); ?>" style="position: sticky; top: 6.5rem; z-index: 100;">
                    <div class="card-body">
                        <h2 class="h5">Costo stimato</h2>
                        <div class="d-flex flex-column gap-2 d-none" data-pricing-summary>
                            <div>
                                <div class="text-muted small text-uppercase">Totale stimato</div>
                                <div class="display-6 fw-semibold mb-0">
                                    <span data-pricing-total></span>
                                    <span class="fs-5 text-muted" data-pricing-currency><?php echo sanitize_output($portalBrtPricingCurrency); ?></span>
                                </div>
                                <div class="text-muted small d-none" data-pricing-label-container>
                                    Scaglione applicato: <span data-pricing-label></span>
                                </div>
                                <p class="text-muted small mb-0 d-none" data-pricing-criteria></p>
                            </div>
                            <p class="text-muted small mb-0">Valori basati sulle tariffe configurate nel portale clienti.</p>
                        </div>
                        <div class="alert alert-warning mb-0 d-none" role="alert" data-pricing-error></div>
                        <p class="text-muted small mb-0" data-pricing-info><?php echo sanitize_output($portalBrtPricingInfoMessage); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card ag-card">
                    <div class="card-body">
                        <?php if ($errors): ?>
                            <div class="alert alert-warning" role="alert">
                                <ul class="mb-0 ps-3">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo sanitize_output($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="post" novalidate>
                            <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <div class="row g-3">
                                <div class="col-12">
                                    <div class="card ag-card">
                                        <div class="card-body">
                                            <div class="row g-3 align-items-center">
                                                <div class="col-md-4">
                                                    <label class="form-label" for="service_code">Codice servizio BRT</label>
                                                    <input class="form-control" type="text" id="service_code" name="service_code" value="<?php echo sanitize_output($data['service_code']); ?>" maxlength="4" placeholder="Es. B14"<?php echo $data['is_return_shipment'] === '1' ? ' readonly' : ''; ?>>
                                                    <div class="form-text">Lascia vuoto per usare il servizio predefinito del contratto.</div>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label" for="return_depot">Depot di rientro</label>
                                                    <input class="form-control" type="text" id="return_depot" name="return_depot" value="<?php echo sanitize_output($data['return_depot']); ?>" maxlength="15" placeholder="Es. 1222463666"<?php echo $data['is_return_shipment'] === '1' ? '' : ' readonly'; ?>>
                                                    <div class="form-text">Indicalo solo per i resi.</div>
                                                </div>
                                                <div class="col-md-4 d-flex align-items-end">
                                                    <div class="form-check form-switch">
                                                        <input class="form-check-input" type="checkbox" id="is_return_shipment" name="is_return_shipment" value="1"<?php echo $data['is_return_shipment'] === '1' ? ' checked' : ''; ?>>
                                                        <label class="form-check-label" for="is_return_shipment">Spedizione di rientro</label>
                                                    </div>
                                                </div>
                                            </div>
                                            <p class="text-muted small mt-2 mb-0<?php echo $data['is_return_shipment'] === '1' ? ' d-none' : ''; ?>" data-standard-service-info>
                                                Il sistema userà il codice servizio configurato nel contratto se non diversamente indicato.
                                            </p>
                                            <div class="alert alert-info small mt-2 mb-0<?php echo $data['is_return_shipment'] === '1' ? '' : ' d-none'; ?>" role="status" data-return-service-info>
                                                <i class="fa-solid fa-rotate-left me-2"></i>Per i resi è richiesto un depot di rientro valido.
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="numeric_sender_reference">Riferimento numerico mittente</label>
                                    <input class="form-control" type="text" id="numeric_sender_reference" name="numeric_sender_reference" value="<?php echo sanitize_output($data['numeric_sender_reference']); ?>" readonly>
                                    <div class="form-text">Il riferimento numerico viene assegnato automaticamente in modo progressivo.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="sender_display_name">Nome mittente (etichetta)</label>
                                    <input class="form-control" type="text" id="sender_display_name" name="sender_display_name" value="<?php echo sanitize_output($data['sender_display_name']); ?>" maxlength="80" placeholder="Es. AG Servizi">
                                    <div class="form-text">Indica come vuoi che il mittente appaia sull'etichetta. Lascia vuoto per usare il tuo nome utente.</div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="alphanumeric_sender_reference">Riferimento alfanumerico (opzionale)</label>
                                    <input class="form-control" type="text" id="alphanumeric_sender_reference" name="alphanumeric_sender_reference" value="<?php echo sanitize_output($data['alphanumeric_sender_reference']); ?>" maxlength="80">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label" for="number_of_parcels">Numero colli</label>
                                    <input class="form-control" type="number" id="number_of_parcels" name="number_of_parcels" value="<?php echo sanitize_output($data['number_of_parcels']); ?>" min="1" step="1" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="weight_kg">Peso (Kg)</label>
                                    <input class="form-control" type="text" id="weight_kg" name="weight_kg" value="<?php echo sanitize_output($data['weight_kg']); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label" for="dimension_length_cm">Lunghezza (cm)</label>
                                    <input class="form-control" type="text" id="dimension_length_cm" name="dimension_length_cm" value="<?php echo sanitize_output($data['dimension_length_cm']); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label" for="dimension_depth_cm">Profondità (cm)</label>
                                    <input class="form-control" type="text" id="dimension_depth_cm" name="dimension_depth_cm" value="<?php echo sanitize_output($data['dimension_depth_cm']); ?>" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label" for="dimension_height_cm">Altezza (cm)</label>
                                    <input class="form-control" type="text" id="dimension_height_cm" name="dimension_height_cm" value="<?php echo sanitize_output($data['dimension_height_cm']); ?>" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="computed_volume_m3">Volume totale (m³)</label>
                                    <input class="form-control" type="text" id="computed_volume_m3" value="<?php echo sanitize_output($computedVolumeDisplay); ?>" readonly>
                                    <div class="form-text">Formula: (H × L × P × colli) ÷ 1.000.000.</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="computed_volumetric_weight">Peso volumetrico (Kg)</label>
                                    <input class="form-control" type="text" id="computed_volumetric_weight" value="<?php echo sanitize_output($computedVolumetricWeightDisplay); ?>" readonly>
                                    <div class="form-text">Divisore BRT 4000 (dimensioni in cm).</div>
                                </div>
                                <div class="col-md-6 d-flex align-items-end">
                                    <p class="text-muted small mb-0">Il volume calcolato viene inviato automaticamente all'API BRT; il peso volumetrico è riportato a titolo indicativo.</p>
                                </div>

                                <?php
                                    $insuranceCurrencyValue = strtoupper($data['insurance_currency']);
                                    $codCurrencyValue = strtoupper($data['cod_currency']);
                                ?>
                                <div class="col-12">
                                    <h3 class="h5 mt-3">Servizi aggiuntivi</h3>
                                </div>
                                <div class="col-lg-6">
                                    <div class="card ag-card">
                                        <div class="card-body">
                                            <h4 class="h6">Assicurazione spedizione</h4>
                                            <p class="text-muted small">Indica l'importo assicurato totale, se previsto dal contratto.</p>
                                            <div class="row g-2 align-items-end">
                                                <div class="col-7">
                                                    <label class="form-label" for="insurance_amount">Importo assicurato</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="fa-solid fa-shield"></i></span>
                                                        <input class="form-control" type="text" id="insurance_amount" name="insurance_amount" value="<?php echo sanitize_output($data['insurance_amount']); ?>" inputmode="decimal" placeholder="Es. 1500,00">
                                                    </div>
                                                </div>
                                                <div class="col-5">
                                                    <label class="form-label" for="insurance_currency">Valuta</label>
                                                    <select class="form-select" id="insurance_currency" name="insurance_currency">
                                                        <?php foreach ($monetaryCurrencies as $currencyCode => $currencyLabel): ?>
                                                            <option value="<?php echo sanitize_output($currencyCode); ?>"<?php echo $insuranceCurrencyValue === $currencyCode ? ' selected' : ''; ?>><?php echo sanitize_output($currencyLabel); ?></option>
                                                        <?php endforeach; ?>
                                                        <?php if ($insuranceCurrencyValue !== '' && !isset($monetaryCurrencies[$insuranceCurrencyValue])): ?>
                                                            <option value="<?php echo sanitize_output($insuranceCurrencyValue); ?>" selected><?php echo sanitize_output($insuranceCurrencyValue . ' (personalizzata)'); ?></option>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <p class="text-muted small mt-2 mb-0">Il valore deve essere maggiore di zero e sarà trasmesso come assicurazione BRT.</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-lg-6">
                                    <div class="card ag-card">
                                        <div class="card-body">
                                            <h4 class="h6">Contrassegno</h4>
                                            <p class="text-muted small">Compila l'importo se la spedizione prevede il pagamento alla consegna.</p>
                                            <div class="row g-2 align-items-end">
                                                <div class="col-7">
                                                    <label class="form-label" for="cod_amount">Importo contrassegno</label>
                                                    <div class="input-group">
                                                        <span class="input-group-text"><i class="fa-solid fa-money-bill-wave"></i></span>
                                                        <input class="form-control" type="text" id="cod_amount" name="cod_amount" value="<?php echo sanitize_output($data['cod_amount']); ?>" inputmode="decimal" placeholder="Es. 250,00">
                                                    </div>
                                                </div>
                                                <div class="col-5">
                                                    <label class="form-label" for="cod_currency">Valuta</label>
                                                    <select class="form-select" id="cod_currency" name="cod_currency">
                                                        <?php foreach ($monetaryCurrencies as $currencyCode => $currencyLabel): ?>
                                                            <option value="<?php echo sanitize_output($currencyCode); ?>"<?php echo $codCurrencyValue === $currencyCode ? ' selected' : ''; ?>><?php echo sanitize_output($currencyLabel); ?></option>
                                                        <?php endforeach; ?>
                                                        <?php if ($codCurrencyValue !== '' && !isset($monetaryCurrencies[$codCurrencyValue])): ?>
                                                            <option value="<?php echo sanitize_output($codCurrencyValue); ?>" selected><?php echo sanitize_output($codCurrencyValue . ' (personalizzata)'); ?></option>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                                <div class="col-6">
                                                    <label class="form-label" for="cod_payment_type">Tipo pagamento (opz.)</label>
                                                    <input class="form-control" type="text" id="cod_payment_type" name="cod_payment_type" value="<?php echo sanitize_output($data['cod_payment_type']); ?>" maxlength="2" placeholder="Es. AS">
                                                    <div class="form-text">Codice a 1-2 caratteri fornito da BRT.</div>
                                                </div>
                                                <div class="col-6 d-flex align-items-end">
                                                    <div class="form-check mt-2">
                                                        <input class="form-check-input" type="checkbox" id="is_cod_mandatory" name="is_cod_mandatory" value="1"<?php echo $data['is_cod_mandatory'] === '1' ? ' checked' : ''; ?>>
                                                        <label class="form-check-label" for="is_cod_mandatory">Contrassegno obbligatorio</label>
                                                    </div>
                                                </div>
                                            </div>
                                            <p class="text-muted small mt-2 mb-0">Se contrassegno obbligatorio è attivo, è necessario indicare un importo valido.</p>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <h3 class="h5 mt-3">Destinatario</h3>
                                </div>
                                <?php if ($savedRecipients): ?>
                                    <div class="col-12">
                                        <label class="form-label" for="saved_recipient_id">Destinatario salvato</label>
                                        <select class="form-select" id="saved_recipient_id" name="saved_recipient_id" data-recipient-select>
                                            <option value="">— Seleziona un destinatario salvato —</option>
                                            <?php foreach ($savedRecipients as $recipient): ?>
                                                <?php
                                                    $optionData = [
                                                        'company_name' => $recipient['company_name'],
                                                        'address' => $recipient['address'],
                                                        'zip' => $recipient['zip'],
                                                        'city' => $recipient['city'],
                                                        'province' => $recipient['province'],
                                                        'country' => $recipient['country'],
                                                        'contact_name' => $recipient['contact_name'],
                                                        'phone' => $recipient['phone'],
                                                        'mobile' => $recipient['mobile'],
                                                        'email' => $recipient['email'],
                                                        'pudo_id' => $recipient['pudo_id'],
                                                        'pudo_description' => $recipient['pudo_description'],
                                                    ];
                                                ?>
                                                <option value="<?php echo (int) $recipient['id']; ?>" data-recipient="<?php echo htmlspecialchars(json_encode($optionData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>"<?php echo (string) $data['saved_recipient_id'] === (string) $recipient['id'] ? ' selected' : ''; ?>>
                                                    <?php echo sanitize_output($recipient['label']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div class="form-text">La selezione compila automaticamente i campi sottostanti.</div>
                                    </div>
                                <?php endif; ?>
                                <div class="col-12">
                                    <label class="form-label" for="consignee_company_name">Ragione sociale / nominativo</label>
                                    <input class="form-control" type="text" id="consignee_company_name" name="consignee_company_name" value="<?php echo sanitize_output($data['consignee_company_name']); ?>" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="consignee_address">Indirizzo</label>
                                    <input class="form-control" type="text" id="consignee_address" name="consignee_address" value="<?php echo sanitize_output($data['consignee_address']); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="consignee_zip">CAP</label>
                                    <input class="form-control" type="text" id="consignee_zip" name="consignee_zip" value="<?php echo sanitize_output($data['consignee_zip']); ?>" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="consignee_city">Città</label>
                                    <input class="form-control" type="text" id="consignee_city" name="consignee_city" value="<?php echo sanitize_output($data['consignee_city']); ?>" list="consignee_city_options" required>
                                    <datalist id="consignee_city_options"></datalist>
                                    <div class="form-text">Inserisci il CAP per ottenere suggerimenti di città e provincia.</div>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="consignee_province">Provincia (sigla)</label>
                                    <input class="form-control" type="text" id="consignee_province" name="consignee_province" value="<?php echo sanitize_output($data['consignee_province']); ?>" maxlength="5">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="consignee_country">Nazione (ISO-2)</label>
                                    <select class="form-select" id="consignee_country" name="consignee_country" required>
                                        <?php foreach ($allowedDestinationCountries as $countryCode => $countryName): ?>
                                            <option value="<?php echo sanitize_output($countryCode); ?>" <?php echo $data['consignee_country'] === $countryCode ? 'selected' : ''; ?>><?php echo sanitize_output($countryCode . ' - ' . $countryName); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="consignee_contact_name">Referente (opzionale)</label>
                                    <input class="form-control" type="text" id="consignee_contact_name" name="consignee_contact_name" value="<?php echo sanitize_output($data['consignee_contact_name']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="consignee_phone">Telefono (opzionale)</label>
                                    <input class="form-control" type="text" id="consignee_phone" name="consignee_phone" value="<?php echo sanitize_output($data['consignee_phone']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="consignee_mobile">Cellulare (opzionale)</label>
                                    <input class="form-control" type="text" id="consignee_mobile" name="consignee_mobile" value="<?php echo sanitize_output($data['consignee_mobile']); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="consignee_email">Email (opzionale)</label>
                                    <input class="form-control" type="email" id="consignee_email" name="consignee_email" value="<?php echo sanitize_output($data['consignee_email']); ?>">
                                </div>
                                <div class="col-12">
                                    <div class="card ag-card mt-4<?php echo $customsSectionHiddenClass; ?>" data-customs-section data-customs-countries="<?php echo $customsCountriesJson; ?>">
                                        <div class="card-body">
                                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                                <h3 class="h5 mb-0">Documenti doganali Svizzera</h3>
                                                <span class="badge bg-primary">Richiesto per CH</span>
                                            </div>
                                            <p class="text-muted small mb-3">I dati compilati generano automaticamente proforma/commercial invoice e dichiarazione doganale.</p>
                                            <input type="hidden" name="customs[enabled]" value="<?php echo sanitize_output($customsForm['enabled']); ?>" data-customs-enabled>
                                            <?php $customsCurrencyValue = strtoupper($customsForm['goods_currency'] ?? ''); ?>
                                            <?php $customsIncotermValue = strtoupper($customsForm['incoterm'] ?? ''); ?>
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label" for="customs_category">Categoria merce</label>
                                                    <select class="form-select" id="customs_category" name="customs[category]">
                                                        <?php foreach ($customsCategories as $categoryKey => $categoryLabel): ?>
                                                            <option value="<?php echo sanitize_output($categoryKey); ?>"<?php echo $customsForm['category'] === $categoryKey ? ' selected' : ''; ?>><?php echo sanitize_output($categoryLabel); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-8">
                                                    <label class="form-label" for="customs_goods_description">Descrizione merce</label>
                                                    <input class="form-control" type="text" id="customs_goods_description" name="customs[goods_description]" value="<?php echo sanitize_output($customsForm['goods_description']); ?>" data-customs-required="true">
                                                </div>
                                                <div class="col-12" data-hs-search data-hs-target="#customs_hs_code" data-hs-description="#customs_goods_description" data-hs-url="hs-code-search.php" data-hs-limit="10" data-hs-description-mode="if-empty">
                                                    <label class="form-label" for="customs_hs_search_query">Ricerca codice HS</label>
                                                    <div class="input-group">
                                                        <input class="form-control" type="text" id="customs_hs_search_query" placeholder="Descrizione merce in italiano o codice HS" autocomplete="off" data-hs-query>
                                                        <button class="btn btn-outline-secondary" type="button" data-hs-button>
                                                            <span class="d-inline-flex align-items-center gap-2">
                                                                <i class="fa-solid fa-search" aria-hidden="true"></i>
                                                                <span>Cerca</span>
                                                                <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true" data-hs-spinner></span>
                                                            </span>
                                                        </button>
                                                    </div>
                                                    <div class="form-text">La ricerca privilegia descrizioni in italiano; clicca un risultato per compilare il codice HS.</div>
                                                    <div class="small text-muted mt-2 d-none" data-hs-status></div>
                                                    <div class="mt-2 d-none" data-hs-results role="listbox" aria-live="polite"></div>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label" for="customs_goods_value">Valore merce</label>
                                                    <input class="form-control" type="text" id="customs_goods_value" name="customs[goods_value]" value="<?php echo sanitize_output($customsForm['goods_value']); ?>" inputmode="decimal" data-customs-required="true">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label" for="customs_goods_currency">Valuta</label>
                                                    <select class="form-select" id="customs_goods_currency" name="customs[goods_currency]" data-customs-required="true">
                                                        <?php foreach ($customsCurrencies as $currencyCode => $currencyLabel): ?>
                                                            <option value="<?php echo sanitize_output($currencyCode); ?>"<?php echo $customsCurrencyValue === $currencyCode ? ' selected' : ''; ?>><?php echo sanitize_output($currencyLabel); ?></option>
                                                        <?php endforeach; ?>
                                                        <?php if ($customsCurrencyValue !== '' && !isset($customsCurrencies[$customsCurrencyValue])): ?>
                                                            <option value="<?php echo sanitize_output($customsCurrencyValue); ?>" selected><?php echo sanitize_output($customsCurrencyValue . ' (personalizzata)'); ?></option>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label" for="customs_goods_origin_country">Origine merce (ISO-2)</label>
                                                    <input class="form-control" type="text" id="customs_goods_origin_country" name="customs[goods_origin_country]" value="<?php echo sanitize_output(strtoupper($customsForm['goods_origin_country'])); ?>" maxlength="2" data-customs-required="true">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label" for="customs_hs_code">Codice HS</label>
                                                    <input class="form-control" type="text" id="customs_hs_code" name="customs[hs_code]" value="<?php echo sanitize_output($customsForm['hs_code']); ?>" data-customs-required="true">
                                                    <div class="form-text">Inserisci almeno 6 caratteri numerici.</div>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label" for="customs_incoterm">Incoterm</label>
                                                    <select class="form-select" id="customs_incoterm" name="customs[incoterm]" data-customs-required="true">
                                                        <?php foreach ($customsIncoterms as $incotermCode => $incotermLabel): ?>
                                                            <option value="<?php echo sanitize_output($incotermCode); ?>"<?php echo $customsIncotermValue === $incotermCode ? ' selected' : ''; ?>><?php echo sanitize_output($incotermLabel); ?></option>
                                                        <?php endforeach; ?>
                                                        <?php if ($customsIncotermValue !== '' && !isset($customsIncoterms[$customsIncotermValue])): ?>
                                                            <option value="<?php echo sanitize_output($customsIncotermValue); ?>" selected><?php echo sanitize_output($customsIncotermValue . ' (personalizzato)'); ?></option>
                                                        <?php endif; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label" for="customs_sender_vat">Partita IVA mittente</label>
                                                    <input class="form-control" type="text" id="customs_sender_vat" name="customs[sender_vat]" value="<?php echo sanitize_output($customsForm['sender_vat']); ?>">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label" for="customs_sender_eori">EORI mittente</label>
                                                    <input class="form-control" type="text" id="customs_sender_eori" name="customs[sender_eori]" value="<?php echo sanitize_output($customsForm['sender_eori']); ?>">
                                                    <div class="form-text">È obbligatorio indicare almeno uno tra Partita IVA o EORI.</div>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label" for="customs_receiver_vat">Partita IVA destinatario (opz.)</label>
                                                    <input class="form-control" type="text" id="customs_receiver_vat" name="customs[receiver_vat]" value="<?php echo sanitize_output($customsForm['receiver_vat']); ?>">
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label" for="customs_receiver_eori">EORI destinatario (opz.)</label>
                                                    <input class="form-control" type="text" id="customs_receiver_eori" name="customs[receiver_eori]" value="<?php echo sanitize_output($customsForm['receiver_eori']); ?>">
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label" for="customs_additional_notes">Note doganali (opzionali)</label>
                                                    <textarea class="form-control" id="customs_additional_notes" name="customs[additional_notes]" rows="2"><?php echo sanitize_output($customsForm['additional_notes']); ?></textarea>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <h3 class="h5 mt-4">Punto di ritiro (PUDO)</h3>
                                    <p class="text-muted small mb-3">Seleziona un punto di ritiro BRT Fermopoint se la spedizione deve essere consegnata presso un PUDO. In caso contrario lascia vuoto.</p>
                                    <input type="hidden" name="pudo_id" id="pudo_id" value="<?php echo sanitize_output($data['pudo_id']); ?>">
                                    <input type="hidden" name="pudo_description" id="pudo_description" value="<?php echo sanitize_output($data['pudo_description']); ?>">
                                    <?php $selectedPudoLabel = $data['pudo_description'] !== '' ? $data['pudo_description'] : ($data['pudo_id'] !== '' ? 'PUDO ' . $data['pudo_id'] : ''); ?>
                                    <div class="card ag-card" data-pudo-root="true"
                                         data-api-url="pudo-search.php"
                                         data-selected-id="<?php echo sanitize_output($data['pudo_id']); ?>"
                                         data-selected-label="<?php echo sanitize_output($selectedPudoLabel); ?>"
                                         data-hidden-field="#pudo_id"
                                         data-hidden-label-field="#pudo_description"
                                         data-zip-field="#pudoSearchZip"
                                         data-city-field="#pudoSearchCity"
                                         data-province-field="#pudoSearchProvince"
                                         data-country-field="#pudoSearchCountry"
                                         data-zip-source="#consignee_zip"
                                         data-city-source="#consignee_city"
                                         data-province-source="#consignee_province"
                                         data-country-source="#consignee_country"
                                         data-default-country="<?php echo sanitize_output($data['consignee_country'] ?: $defaultCountry); ?>"
                                         data-default-lat="41.8719"
                                         data-default-lng="12.5674">
                                        <div class="card-body">
                                            <div class="row g-3 align-items-end">
                                                <div class="col-md-3">
                                                    <label class="form-label" for="pudoSearchZip">CAP</label>
                                                    <input class="form-control" type="text" id="pudoSearchZip" value="<?php echo sanitize_output($data['consignee_zip']); ?>" data-pudo-search-zip>
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label" for="pudoSearchCity">Città</label>
                                                    <input class="form-control" type="text" id="pudoSearchCity" value="<?php echo sanitize_output($data['consignee_city']); ?>" data-pudo-search-city>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label" for="pudoSearchProvince">Provincia</label>
                                                    <input class="form-control" type="text" id="pudoSearchProvince" value="<?php echo sanitize_output($data['consignee_province']); ?>" data-pudo-search-province>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label" for="pudoSearchCountry">Nazione</label>
                                                    <input class="form-control" type="text" id="pudoSearchCountry" value="<?php echo sanitize_output($data['consignee_country'] ?: $defaultCountry); ?>" maxlength="2" data-pudo-search-country>
                                                </div>
                                                <div class="col-12">
                                                    <div class="form-check">
                                                        <input class="form-check-input" type="checkbox" value="1" id="save_recipient" name="save_recipient" data-save-recipient-toggle <?php echo $data['save_recipient'] === '1' ? 'checked' : ''; ?>>
                                                        <label class="form-check-label" for="save_recipient">Salva questo destinatario nella rubrica</label>
                                                    </div>
                                                    <div class="mt-2 ms-4" data-save-recipient-fields style="<?php echo $data['save_recipient'] === '1' ? '' : 'display: none;'; ?>">
                                                        <label class="form-label" for="recipient_label">Etichetta rubrica</label>
                                                        <input class="form-control" type="text" id="recipient_label" name="recipient_label" value="<?php echo sanitize_output($data['recipient_label']); ?>" maxlength="120" placeholder="Es. Cliente Roma" data-recipient-label>
                                                        <div class="form-text">Se lasci vuoto verrà utilizzata la ragione sociale.</div>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <button class="btn btn-outline-primary" type="button" data-pudo-search>
                                                        <i class="fa-solid fa-location-dot me-2"></i>Cerca PUDO nelle vicinanze
                                                    </button>
                                                    <button class="btn btn-link btn-sm text-decoration-none ps-0" type="button" data-pudo-sync-from-consignee>
                                                        <i class="fa-solid fa-arrow-turn-down me-1"></i>Usa l'indirizzo del destinatario
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="pudo-status small text-muted mt-3" data-pudo-status></div>
                                            <div class="border rounded-3 mt-3" style="height: 320px;" data-pudo-map></div>
                                            <div class="list-group mt-3" data-pudo-list></div>
                                            <div class="alert alert-info mt-3<?php echo $data['pudo_id'] === '' ? ' d-none' : ''; ?>" role="status" data-pudo-selected-alert>
                                                <div class="d-flex align-items-center justify-content-between gap-3">
                                                    <div>
                                                        <strong>PUDO selezionato:</strong>
                                                        <span data-pudo-selected-label><?php echo sanitize_output($selectedPudoLabel); ?></span>
                                                    </div>
                                                    <button class="btn btn-sm btn-outline-secondary" type="button" data-pudo-clear>
                                                        <i class="fa-solid fa-xmark me-1"></i>Rimuovi selezione
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="notes">Note per il corriere (opzionali)</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo sanitize_output($data['notes']); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="is_label_required" name="is_label_required" value="1"<?php echo $data['is_label_required'] === '1' ? ' checked' : ''; ?>>
                                        <label class="form-check-label" for="is_label_required">Genera etichetta PDF</label>
                                    </div>
                                </div>
                            </div>
                            <div class="mt-4 d-flex flex-wrap gap-2">
                                <button class="btn btn-primary" type="submit" name="intent" value="create"><i class="fa-solid fa-truck-fast me-2"></i>Crea spedizione</button>
                                <button class="btn btn-outline-primary" type="submit" name="intent" value="quote"><i class="fa-solid fa-coins me-2"></i>Calcola costo</button>
                                <a class="btn btn-outline-secondary" href="index.php">Annulla</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const lengthInput = document.getElementById('dimension_length_cm');
            const depthInput = document.getElementById('dimension_depth_cm');
            const heightInput = document.getElementById('dimension_height_cm');
            const parcelsInput = document.getElementById('number_of_parcels');
            const weightInput = document.getElementById('weight_kg');
            const volumeOutput = document.getElementById('computed_volume_m3');
            const volumetricOutput = document.getElementById('computed_volumetric_weight');
            const countrySelect = document.getElementById('consignee_country');
            const pudoCountryInput = document.getElementById('pudoSearchCountry');
            const pricingCard = document.querySelector('[data-pricing-card]');
            const form = document.querySelector('form[method="post"]');
            const returnShipmentToggle = document.getElementById('is_return_shipment');
            const standardServiceInfo = document.querySelector('[data-standard-service-info]');
            const returnServiceInfo = document.querySelector('[data-return-service-info]');

            const updateReturnServiceInfoVisibility = () => {
                if (!standardServiceInfo || !returnServiceInfo || !returnShipmentToggle) {
                    return;
                }
                const isReturn = returnShipmentToggle.checked;
                standardServiceInfo.classList.toggle('d-none', isReturn);
                returnServiceInfo.classList.toggle('d-none', !isReturn);
            };

            updateReturnServiceInfoVisibility();
            if (returnShipmentToggle) {
                returnShipmentToggle.addEventListener('change', updateReturnServiceInfoVisibility);
            }

            if (!lengthInput || !depthInput || !heightInput || !parcelsInput || !volumeOutput || !volumetricOutput) {
                return;
            }

            const parseNumber = (value) => {
                if (!value) {
                    return 0;
                }
                const normalized = value.toString().replace(/\s+/g, '').replace(',', '.');
                const parsed = parseFloat(normalized);
                return Number.isFinite(parsed) ? parsed : 0;
            };

            const formatNumber = (value, decimals) => {
                if (!Number.isFinite(value) || value <= 0) {
                    return '';
                }
                return value.toFixed(decimals).replace('.', ',');
            };

            const updateVolume = () => {
                const length = parseNumber(lengthInput.value);
                const depth = parseNumber(depthInput.value);
                const height = parseNumber(heightInput.value);
                const parcels = Math.max(0, parseInt(parcelsInput.value, 10) || 0);

                const perParcelVolume = (height * length * depth) / 1_000_000;
                const totalVolume = perParcelVolume * parcels;
                const volumetricWeight = ((height * length * depth) / 4000) * parcels;

                volumeOutput.value = formatNumber(totalVolume, 3);
                volumetricOutput.value = formatNumber(volumetricWeight, 2);

                return {
                    length,
                    depth,
                    height,
                    parcels,
                    volume: totalVolume,
                    volumetricWeight,
                };
            };
            const pricingSummaryElement = pricingCard ? pricingCard.querySelector('[data-pricing-summary]') : null;
            const pricingTotalElement = pricingCard ? pricingCard.querySelector('[data-pricing-total]') : null;
            const pricingCurrencyElement = pricingCard ? pricingCard.querySelector('[data-pricing-currency]') : null;
            const pricingLabelContainer = pricingCard ? pricingCard.querySelector('[data-pricing-label-container]') : null;
            const pricingLabelElement = pricingCard ? pricingCard.querySelector('[data-pricing-label]') : null;
            const pricingCriteriaElement = pricingCard ? pricingCard.querySelector('[data-pricing-criteria]') : null;
            const pricingInfoElement = pricingCard ? pricingCard.querySelector('[data-pricing-info]') : null;
            const pricingErrorElement = pricingCard ? pricingCard.querySelector('[data-pricing-error]') : null;

            const resolvePricingConfig = () => {
                if (!pricingCard) {
                    return { currency: 'EUR', tiers: [] };
                }
                const raw = pricingCard.dataset.pricingConfig || '';
                try {
                    const parsed = JSON.parse(raw);
                    const currency = typeof parsed.currency === 'string' && parsed.currency.trim() !== ''
                        ? parsed.currency.trim().toUpperCase()
                        : (pricingCard.dataset.pricingCurrency || 'EUR').toUpperCase();
                    const tiers = Array.isArray(parsed.tiers) ? parsed.tiers : [];
                    return { currency, tiers };
                } catch (error) {
                    return {
                        currency: (pricingCard.dataset.pricingCurrency || 'EUR').toUpperCase(),
                        tiers: [],
                    };
                }
            };

            const pricingConfig = resolvePricingConfig();
            const pricingCurrency = pricingConfig.currency;
            const pricingTiers = pricingConfig.tiers.map((tier, index) => {
                const safeTier = tier && typeof tier === 'object' ? tier : {};
                const price = Number(safeTier.price ?? 0);
                const maxWeight = safeTier.max_weight === null || safeTier.max_weight === undefined
                    ? null
                    : Number(safeTier.max_weight);
                const maxVolume = safeTier.max_volume === null || safeTier.max_volume === undefined
                    ? null
                    : Number(safeTier.max_volume);

                return {
                    index,
                    label: typeof safeTier.label === 'string' ? safeTier.label : '',
                    price: Number.isFinite(price) ? price : 0,
                    max_weight: Number.isFinite(maxWeight) ? maxWeight : null,
                    max_volume: Number.isFinite(maxVolume) ? maxVolume : null,
                };
            }).filter((tier) => tier.price > 0);

            const basePricingInfo = pricingInfoElement && pricingInfoElement.textContent
                ? pricingInfoElement.textContent.trim()
                : 'Compila peso e dimensioni per visualizzare il costo stimato.';
            const noPricingConfigMessage = 'Configura le tariffe in Impostazioni -> Tariffe BRT per ottenere un costo stimato.';
            const epsilon = 0.0001;

            const formatMoney = (value) => {
                if (!Number.isFinite(value)) {
                    return '';
                }
                return new Intl.NumberFormat('it-IT', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                }).format(value);
            };

            const formatDecimal = (value, decimals) => {
                if (!Number.isFinite(value)) {
                    return '';
                }
                return new Intl.NumberFormat('it-IT', {
                    minimumFractionDigits: 0,
                    maximumFractionDigits: decimals,
                }).format(value);
            };

            const describeLimit = (limit, label, unit, decimals) => {
                if (limit === null) {
                    return `${label} senza limite`;
                }
                if (!Number.isFinite(limit)) {
                    return '';
                }
                return `${label} ≤ ${formatDecimal(limit, decimals)} ${unit}`;
            };

            const findPricingTier = (weight, volume) => {
                for (let i = 0; i < pricingTiers.length; i += 1) {
                    const tier = pricingTiers[i];
                    const maxWeight = tier.max_weight;
                    const maxVolume = tier.max_volume;

                    const weightOk = maxWeight === null || weight <= (maxWeight + epsilon);
                    const volumeOk = maxVolume === null || volume <= (maxVolume + epsilon);

                    if (weightOk && volumeOk) {
                        return tier;
                    }
                }
                return null;
            };

            const updatePricingCard = () => {
                if (!pricingCard) {
                    updateVolume();
                    return;
                }

                const measurements = updateVolume();
                const weight = parseNumber(weightInput ? weightInput.value : '');

                if (pricingErrorElement) {
                    pricingErrorElement.classList.add('d-none');
                    pricingErrorElement.textContent = '';
                }

                if (pricingInfoElement) {
                    pricingInfoElement.classList.remove('d-none');
                    pricingInfoElement.textContent = basePricingInfo;
                }

                if (pricingSummaryElement) {
                    pricingSummaryElement.classList.add('d-none');
                }
                if (pricingLabelContainer) {
                    pricingLabelContainer.classList.add('d-none');
                }
                if (pricingLabelElement) {
                    pricingLabelElement.textContent = '';
                }
                if (pricingCriteriaElement) {
                    pricingCriteriaElement.textContent = '';
                    pricingCriteriaElement.classList.add('d-none');
                }

                if (pricingTiers.length === 0) {
                    if (pricingInfoElement) {
                        pricingInfoElement.textContent = noPricingConfigMessage;
                    }
                    return;
                }

                if (!measurements || measurements.parcels <= 0) {
                    return;
                }

                if (measurements.length <= 0 || measurements.depth <= 0 || measurements.height <= 0) {
                    if (pricingInfoElement) {
                        pricingInfoElement.textContent = 'Verifica le dimensioni inserite prima di calcolare il costo.';
                    }
                    return;
                }

                const volume = measurements.volume;
                if (weight <= 0) {
                    if (pricingInfoElement) {
                        pricingInfoElement.textContent = 'Inserisci il peso della spedizione per ottenere il costo stimato.';
                    }
                    return;
                }

                if (!Number.isFinite(volume) || volume <= 0) {
                    if (pricingInfoElement) {
                        pricingInfoElement.textContent = 'Il volume calcolato risulta nullo. Verifica le dimensioni inserite.';
                    }
                    return;
                }

                const tier = findPricingTier(weight, volume);
                if (!tier) {
                    if (pricingInfoElement) {
                        pricingInfoElement.classList.add('d-none');
                    }
                    if (pricingErrorElement) {
                        pricingErrorElement.textContent = 'Nessuno scaglione tariffario corrisponde ai valori indicati.';
                        pricingErrorElement.classList.remove('d-none');
                    }
                    return;
                }

                if (pricingInfoElement) {
                    pricingInfoElement.classList.add('d-none');
                }

                if (pricingTotalElement) {
                    pricingTotalElement.textContent = formatMoney(tier.price);
                }
                if (pricingCurrencyElement) {
                    pricingCurrencyElement.textContent = pricingCurrency;
                }

                const labelText = tier.label && tier.label.trim() !== ''
                    ? tier.label.trim()
                    : `Scaglione #${tier.index + 1}`;
                if (pricingLabelElement) {
                    pricingLabelElement.textContent = labelText;
                }
                if (pricingLabelContainer) {
                    pricingLabelContainer.classList.remove('d-none');
                }

                if (pricingCriteriaElement) {
                    const weightLabel = describeLimit(tier.max_weight, 'Peso', 'kg', 3);
                    const volumeLabel = describeLimit(tier.max_volume, 'Volume', 'm³', 4);
                    const criteriaParts = [];
                    if (weightLabel) {
                        criteriaParts.push(weightLabel);
                    }
                    if (volumeLabel) {
                        criteriaParts.push(volumeLabel);
                    }
                    pricingCriteriaElement.textContent = criteriaParts.join(' · ');
                    pricingCriteriaElement.classList.toggle('d-none', criteriaParts.length === 0);
                }

                if (pricingSummaryElement) {
                    pricingSummaryElement.classList.remove('d-none');
                }
            };

            const inputsToWatch = [lengthInput, depthInput, heightInput, parcelsInput, weightInput];
            inputsToWatch.forEach((input) => {
                if (!input) {
                    return;
                }
                input.addEventListener('input', updatePricingCard);
                input.addEventListener('change', updatePricingCard);
            });

            updatePricingCard();

            if (countrySelect && pudoCountryInput) {
                const syncPudoCountry = () => {
                    const selectedValue = (countrySelect.value || '').toUpperCase();
                    pudoCountryInput.value = selectedValue;
                    pudoCountryInput.dispatchEvent(new Event('change', { bubbles: true }));
                };

                syncPudoCountry();
                countrySelect.addEventListener('change', syncPudoCountry);
            }

            if (form) {
                form.addEventListener('submit', (event) => {
                    const submitter = event.submitter;
                    if (submitter && submitter.name === 'intent' && submitter.value === 'quote') {
                        event.preventDefault();
                        updatePricingCard();
                        if (pricingCard && typeof pricingCard.scrollIntoView === 'function') {
                            pricingCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        }
                    }
                });
            }
        });
        </script>
        <div class="modal fade" tabindex="-1" role="dialog" aria-modal="true" aria-hidden="true" data-confirm-modal hidden>
            <div class="modal-dialog modal-dialog-centered" role="document">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Conferma creazione spedizione</h5>
                        <button class="btn-close" type="button" aria-label="Chiudi" data-confirm-close></button>
                    </div>
                    <div class="modal-body">
                        <p>Confermi che tutti i dati inseriti sono corretti? Premi <strong>Conferma e crea</strong> per procedere oppure <strong>Annulla</strong> per rivedere le informazioni.</p>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-outline-secondary" type="button" data-confirm-cancel>Annulla</button>
                        <button class="btn btn-primary" type="button" data-confirm-accept>Conferma e crea</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade" data-confirm-modal-backdrop hidden></div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const form = document.querySelector('form[method="post"]');
            const modal = document.querySelector('[data-confirm-modal]');
            const modalBackdrop = document.querySelector('[data-confirm-modal-backdrop]');
            const confirmButton = modal ? modal.querySelector('[data-confirm-accept]') : null;
            const cancelButtons = modal ? modal.querySelectorAll('[data-confirm-cancel], [data-confirm-close]') : [];
            let pendingIntent = null;
            let lastSubmitter = null;
            let bypassConfirmation = false;

            if (!form || !modal || !confirmButton) {
                return;
            }

            const updatePendingIntent = (submitter) => {
                if (submitter && submitter.name === 'intent') {
                    pendingIntent = submitter.value || null;
                }
            };

            form.querySelectorAll('button[name="intent"]').forEach((button) => {
                button.addEventListener('click', () => {
                    pendingIntent = button.value || null;
                    lastSubmitter = button;
                });
            });

            const ensureIntentHiddenInput = () => {
                let hiddenInput = form.querySelector('input[name="intent"][data-confirm-hidden]');
                if (!hiddenInput) {
                    hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'intent';
                    hiddenInput.dataset.confirmHidden = 'true';
                    form.appendChild(hiddenInput);
                }
                hiddenInput.value = 'create';
            };

            const openModal = () => {
                modal.removeAttribute('hidden');
                modal.style.display = 'block';
                modal.classList.add('show');
                document.body.classList.add('modal-open');
                if (modalBackdrop) {
                    modalBackdrop.removeAttribute('hidden');
                    modalBackdrop.style.display = 'block';
                    modalBackdrop.classList.add('show');
                }
                const focusTarget = confirmButton;
                if (focusTarget) {
                    focusTarget.focus();
                }
            };

            const closeModal = () => {
                modal.classList.remove('show');
                modal.style.display = 'none';
                modal.setAttribute('hidden', 'hidden');
                document.body.classList.remove('modal-open');
                if (modalBackdrop) {
                    modalBackdrop.classList.remove('show');
                    modalBackdrop.style.display = 'none';
                    modalBackdrop.setAttribute('hidden', 'hidden');
                }
            };

            const handleCancellation = () => {
                closeModal();
            };

            cancelButtons.forEach((button) => {
                button.addEventListener('click', handleCancellation);
            });
            if (modalBackdrop) {
                modalBackdrop.addEventListener('click', handleCancellation);
            }

            confirmButton.addEventListener('click', () => {
                bypassConfirmation = true;
                closeModal();
                if (typeof form.requestSubmit === 'function' && lastSubmitter) {
                    form.requestSubmit(lastSubmitter);
                } else {
                    ensureIntentHiddenInput();
                    form.submit();
                }
            });

            form.addEventListener('submit', (event) => {
                const submitter = event.submitter || lastSubmitter;
                updatePendingIntent(submitter);
                if (bypassConfirmation) {
                    bypassConfirmation = false;
                    return;
                }
                if (pendingIntent !== 'create') {
                    return;
                }
                event.preventDefault();
                openModal();
            });
        });
        </script>
    </main>
    <?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
</div>
