<?php
declare(strict_types=1);

use App\Services\Brt\BrtConfig;
use App\Services\Brt\BrtException;
use App\Services\Brt\BrtShipmentService;
use Throwable;

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

$shipmentId = (int) ($_GET['id'] ?? 0);
if ($shipmentId <= 0) {
    add_flash('warning', 'Spedizione BRT non trovata.');
    header('Location: index.php');
    exit;
}

$csrfToken = csrf_token();

try {
    ensure_brt_tables();
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit('Database BRT non configurato: ' . $exception->getMessage());
}

$shipment = brt_get_shipment($shipmentId);
if ($shipment === null) {
    add_flash('warning', 'Spedizione BRT non trovata.');
    header('Location: index.php');
    exit;
}

if (!in_array($shipment['status'] ?? '', ['created', 'warning'], true) || !empty($shipment['manifest_id']) || !empty($shipment['deleted_at'])) {
    add_flash('warning', 'La spedizione non può essere modificata.');
    header('Location: index.php');
    exit;
}

$pageTitle = 'Modifica spedizione BRT #' . $shipmentId;

$config = new BrtConfig();
$allowedDestinationCountries = $config->getAllowedDestinationCountries();
$savedRecipients = brt_get_saved_recipients();

$defaultCountry = strtoupper($config->getDefaultCountryIsoAlpha2() ?? 'IT');
if (!isset($allowedDestinationCountries[$defaultCountry])) {
    if (isset($allowedDestinationCountries['IT'])) {
        $defaultCountry = 'IT';
    } else {
        $allowedCountryKeys = array_keys($allowedDestinationCountries);
        $defaultCountry = $allowedCountryKeys[0] ?? 'IT';
    }
}

$decodedPayload = brt_decode_request_payload($shipment['request_payload'] ?? null);
$requestPayload = $decodedPayload['request'];
$metaPayload = $decodedPayload['meta'];

$customsMeta = [];
if (isset($metaPayload['customs']) && is_array($metaPayload['customs'])) {
    $customsMeta = $metaPayload['customs'];
}

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
$customsRequiredCountries = brt_customs_required_countries();
$customsForm = brt_customs_default_form_data();
$customsPayload = null;

if (isset($customsMeta['form']) && is_array($customsMeta['form'])) {
    $customsForm = brt_normalize_customs_form_input($customsMeta['form']);
}

if (isset($customsMeta['payload']) && is_array($customsMeta['payload'])) {
    $customsPayload = $customsMeta['payload'];
    if ($customsForm['goods_value'] === '' && isset($customsPayload['goods_value'])) {
        $customsForm['goods_value'] = number_format((float) $customsPayload['goods_value'], 2, ',', '.');
    }
    if ($customsForm['goods_currency'] === '' && isset($customsPayload['goods_currency'])) {
        $customsForm['goods_currency'] = (string) $customsPayload['goods_currency'];
    }
    if (!empty($customsPayload)) {
        $customsForm['enabled'] = '1';
    }
}

if (!empty($customsMeta['enabled'])) {
    $customsForm['enabled'] = '1';
}

$numericReference = isset($requestPayload['numericSenderReference']) ? (int) $requestPayload['numericSenderReference'] : (int) ($shipment['numeric_sender_reference'] ?? 0);
$alphanumericReference = (string) ($requestPayload['alphanumericSenderReference'] ?? $shipment['alphanumeric_sender_reference'] ?? '');

$lengthMeta = isset($metaPayload['dimension_length_cm']) ? (string) $metaPayload['dimension_length_cm'] : '';
$depthMeta = isset($metaPayload['dimension_depth_cm']) ? (string) $metaPayload['dimension_depth_cm'] : '';
$heightMeta = isset($metaPayload['dimension_height_cm']) ? (string) $metaPayload['dimension_height_cm'] : '';

$data = [
    'numeric_sender_reference' => (string) $numericReference,
    'alphanumeric_sender_reference' => $alphanumericReference,
    'number_of_parcels' => (string) ($requestPayload['numberOfParcels'] ?? $shipment['number_of_parcels'] ?? 1),
    'weight_kg' => number_format((float) ($requestPayload['weightKG'] ?? $shipment['weight_kg'] ?? 1), 2, ',', '.'),
    'dimension_length_cm' => $lengthMeta,
    'dimension_depth_cm' => $depthMeta,
    'dimension_height_cm' => $heightMeta,
    'consignee_company_name' => (string) ($requestPayload['consigneeCompanyName'] ?? $shipment['consignee_name'] ?? ''),
    'consignee_address' => (string) ($requestPayload['consigneeAddress'] ?? $shipment['consignee_address'] ?? ''),
    'consignee_zip' => (string) ($requestPayload['consigneeZIPCode'] ?? $shipment['consignee_zip'] ?? ''),
    'consignee_city' => (string) ($requestPayload['consigneeCity'] ?? $shipment['consignee_city'] ?? ''),
    'consignee_province' => (string) ($requestPayload['consigneeProvinceAbbreviation'] ?? $shipment['consignee_province'] ?? ''),
    'consignee_country' => strtoupper((string) ($requestPayload['consigneeCountryAbbreviationISOAlpha2'] ?? $shipment['consignee_country'] ?? $defaultCountry)),
    'consignee_contact_name' => (string) ($requestPayload['consigneeContactName'] ?? ''),
    'consignee_phone' => (string) ($requestPayload['consigneeTelephone'] ?? ''),
    'consignee_mobile' => (string) ($requestPayload['consigneeMobilePhoneNumber'] ?? $shipment['consignee_phone'] ?? ''),
    'consignee_email' => (string) ($requestPayload['consigneeEMail'] ?? $shipment['consignee_email'] ?? ''),
    'notes' => (string) ($requestPayload['notes'] ?? ''),
    'is_label_required' => ((int) ($requestPayload['isLabelRequired'] ?? 1)) ? '1' : '0',
    'pudo_id' => (string) ($requestPayload['pudoId'] ?? ''),
    'pudo_description' => (string) ($metaPayload['pudo_description'] ?? ''),
    'saved_recipient_id' => '',
    'save_recipient' => '0',
    'recipient_label' => '',
];

$errors = [];
$computedVolume = (float) ($requestPayload['volumeM3'] ?? $shipment['volume_m3'] ?? 0);
$computedVolumetricWeight = null;
$isCustomsRequired = in_array($data['consignee_country'], $customsRequiredCountries, true);
if ($isCustomsRequired && $customsForm['enabled'] !== '1') {
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

$lengthCm = $parseDecimal($data['dimension_length_cm']);
$depthCm = $parseDecimal($data['dimension_depth_cm']);
$heightCm = $parseDecimal($data['dimension_height_cm']);
$parcelsCount = preg_match('/^\d+$/', $data['number_of_parcels']) ? (int) $data['number_of_parcels'] : 0;

if ($lengthCm !== null && $depthCm !== null && $heightCm !== null && $parcelsCount > 0) {
    $perParcelVolume = ($heightCm * $lengthCm * $depthCm) / 1_000_000;
    $computedVolume = $perParcelVolume * $parcelsCount;
    $computedVolumetricWeight = (($heightCm * $lengthCm * $depthCm) / 4000) * $parcelsCount;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    foreach ($data as $field => $default) {
        if ($field === 'is_label_required') {
            $data[$field] = isset($_POST[$field]) ? '1' : '0';
            continue;
        }
        if ($field === 'save_recipient') {
            $data[$field] = isset($_POST[$field]) ? '1' : '0';
            continue;
        }
        if ($field === 'numeric_sender_reference') {
            continue;
        }
        $data[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    $data['numeric_sender_reference'] = (string) $numericReference;

    $data['consignee_country'] = strtoupper($data['consignee_country']);
    if ($data['consignee_country'] === 'IE') {
        if ($data['consignee_zip'] === '') {
            $data['consignee_zip'] = 'EIRE';
        } else {
            $data['consignee_zip'] = strtoupper($data['consignee_zip']);
        }
    }
    $isCustomsRequired = in_array($data['consignee_country'], $customsRequiredCountries, true);

    if (strlen($data['pudo_id']) > 120) {
        $errors[] = 'Il codice PUDO selezionato non è valido.';
    }

    if (!preg_match('/^\d+$/', $data['number_of_parcels']) || (int) $data['number_of_parcels'] <= 0) {
        $errors[] = 'Inserisci un numero di colli valido (>= 1).';
    }

    if (!is_numeric(str_replace(',', '.', $data['weight_kg'])) || (float) str_replace(',', '.', $data['weight_kg']) <= 0) {
        $errors[] = 'Inserisci un peso valido (kg).';
    }

    if ($data['consignee_company_name'] === '') {
        $errors[] = 'Inserisci la ragione sociale o il nominativo del destinatario.';
    }

    if ($data['consignee_address'] === '') {
        $errors[] = 'Inserisci l\'indirizzo del destinatario.';
    }

    if ($data['consignee_zip'] === '' || $data['consignee_city'] === '') {
        $errors[] = 'Inserisci CAP e città del destinatario.';
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
    $parcelsCount = preg_match('/^\d+$/', $data['number_of_parcels']) ? (int) $data['number_of_parcels'] : 0;

    if ($lengthCm === null || $lengthCm <= 0) {
        $errors[] = 'Inserisci la lunghezza in centimetri (valore positivo).';
    }

    if ($depthCm === null || $depthCm <= 0) {
        $errors[] = 'Inserisci la profondità in centimetri (valore positivo).';
    }

    if ($heightCm === null || $heightCm <= 0) {
        $errors[] = 'Inserisci l\'altezza in centimetri (valore positivo).';
    }

    $computedVolume = 0;
    $computedVolumetricWeight = null;
    if ($lengthCm !== null && $lengthCm > 0 && $depthCm !== null && $depthCm > 0 && $heightCm !== null && $heightCm > 0 && $parcelsCount > 0) {
        $perParcelVolume = ($heightCm * $lengthCm * $depthCm) / 1_000_000;
        $computedVolume = $perParcelVolume * $parcelsCount;
        $computedVolumetricWeight = (($heightCm * $lengthCm * $depthCm) / 4000) * $parcelsCount;

        if ($computedVolume <= 0) {
            $errors[] = 'Il volume calcolato risulta nullo. Verifica le dimensioni inserite.';
        }
    }

    $customsInput = $_POST['customs'] ?? [];
    if (!is_array($customsInput)) {
        $customsInput = [];
    }

    $customsForm = brt_normalize_customs_form_input($customsInput);
    $customsForm['enabled'] = $isCustomsRequired ? '1' : '0';

    $customsValidation = brt_validate_customs_form($customsForm, $isCustomsRequired);
    $customsPayload = $customsValidation['payload'];

    if ($customsValidation['errors'] !== []) {
        $errors = array_merge($errors, $customsValidation['errors']);
    }

    $newNumericReference = (int) $data['numeric_sender_reference'];
    if ($newNumericReference !== (int) ($shipment['numeric_sender_reference'] ?? 0)) {
        $existing = brt_get_shipment_by_reference($shipment['sender_customer_code'], $newNumericReference);
        if ($existing !== null && (int) $existing['id'] !== $shipmentId) {
            $errors[] = 'Il riferimento numerico selezionato è già utilizzato da un\'altra spedizione.';
        }
    }

    if (!$errors) {
        $createData = [
            'senderCustomerCode' => $shipment['sender_customer_code'],
            'numericSenderReference' => $newNumericReference,
            'alphanumericSenderReference' => $data['alphanumeric_sender_reference'],
            'departureDepot' => $shipment['departure_depot'],
            'numberOfParcels' => (int) $data['number_of_parcels'],
            'weightKG' => (float) str_replace(',', '.', $data['weight_kg']),
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

        $metadata = [
            'dimension_length_cm' => $lengthCm,
            'dimension_depth_cm' => $depthCm,
            'dimension_height_cm' => $heightCm,
            'pudo_description' => $data['pudo_description'],
            'customs' => [
                'enabled' => $customsForm['enabled'] === '1',
                'required_for_country' => $isCustomsRequired,
                'form' => $customsForm,
                'payload' => $customsPayload,
            ],
        ];

        $service = new BrtShipmentService($config);
        $originalReferences = [
            'senderCustomerCode' => $shipment['sender_customer_code'],
            'numericSenderReference' => (int) $shipment['numeric_sender_reference'],
            'alphanumericSenderReference' => (string) $shipment['alphanumeric_sender_reference'],
        ];

        $previousLabelPath = $shipment['label_path'] ?? null;

        try {
            $canAttemptInPlaceUpdate = empty($shipment['deleted_at'])
                && $newNumericReference === (int) ($shipment['numeric_sender_reference'] ?? 0);

            $updateAttempted = false;
            $updateSucceeded = false;
            $updateFailureMessage = null;
            $response = null;
            $newLabelPath = null;

            if ($canAttemptInPlaceUpdate) {
                $updateAttempted = true;
                try {
                    $response = $service->updateShipment($originalReferences, $createData, [
                        'labelParameters' => [
                            'outputType' => $config->getLabelOutputType(),
                            'offsetX' => $config->getLabelOffsetX(),
                            'offsetY' => $config->getLabelOffsetY(),
                            'isBorderRequired' => $config->isLabelBorderEnabled() ? 1 : null,
                            'isLogoRequired' => $config->isLabelLogoEnabled() ? 1 : null,
                            'isBarcodeControlRowRequired' => $config->isLabelBarcodeRowEnabled() ? 1 : null,
                        ],
                    ]);
                    $updateSucceeded = true;
                } catch (BrtException $exception) {
                    $updateFailureMessage = $exception->getMessage();
                } catch (Throwable $exception) {
                    $updateFailureMessage = $exception->getMessage();
                }
            }

            if (!$updateSucceeded) {
                if ($updateAttempted && $updateFailureMessage !== null) {
                    add_flash('info', 'Aggiornamento diretto non disponibile: ' . $updateFailureMessage . '. La spedizione verrà ricreata.');
                }

                if (empty($shipment['deleted_at'])) {
                    $service->deleteShipment($originalReferences);
                }

                $response = $service->createShipment($createData);
            }

            $updateRecordOptions = [];
            if ($updateSucceeded) {
                $updateRecordOptions = [
                    'preserve_label' => $previousLabelPath !== null,
                    'preserve_tracking' => true,
                ];
            }

            brt_update_shipment_record($shipmentId, $createData, $response, $metadata, $updateRecordOptions);

            if (isset($response['labels']['label'][0]) && is_array($response['labels']['label'][0])) {
                $newLabelPath = brt_attach_label($shipmentId, $response['labels']['label'][0]);
            }

            if ($previousLabelPath && $newLabelPath !== null && $previousLabelPath !== $newLabelPath) {
                brt_delete_label_file($previousLabelPath);
            }

            try {
                $updatedShipment = brt_get_shipment($shipmentId);
            } catch (Throwable $exception) {
                $updatedShipment = null;
                add_flash('warning', 'Impossibile ricaricare la spedizione aggiornata per i documenti doganali: ' . $exception->getMessage());
            }

            if (isset($updatedShipment) && $updatedShipment !== null) {
                try {
                    $customsSyncResult = brt_sync_customs_documents($shipmentId, $updatedShipment, $customsPayload);
                    if ($customsSyncResult['status'] === 'error' && isset($customsSyncResult['message'])) {
                        add_flash('warning', 'Documenti doganali non aggiornati: ' . $customsSyncResult['message']);
                    }
                } catch (Throwable $exception) {
                    add_flash('warning', 'Documenti doganali non aggiornati: ' . $exception->getMessage());
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

            add_flash('success', 'Spedizione aggiornata correttamente.');
            header('Location: index.php');
            exit;
        } catch (BrtException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            $errors[] = 'Errore inatteso durante l\'aggiornamento della spedizione: ' . $exception->getMessage();
        }
    }
}

$isCustomsRequired = in_array($data['consignee_country'], $customsRequiredCountries, true);
if ($isCustomsRequired && $customsForm['enabled'] !== '1') {
    $customsForm['enabled'] = '1';
}

$computedVolumeDisplay = $computedVolume !== null && $computedVolume > 0 ? number_format($computedVolume, 3, ',', '.') : '';
$computedVolumetricWeightDisplay = $computedVolumetricWeight !== null && $computedVolumetricWeight > 0 ? number_format($computedVolumetricWeight, 2, ',', '.') : '';

$extraStyles = ($extraStyles ?? []);
$extraStyles[] = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';

$extraScripts = ($extraScripts ?? []);
$extraScripts[] = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
$extraScripts[] = asset('modules/servizi/brt/js/pudo-map.js');
$extraScripts[] = asset('modules/servizi/brt/js/recipients.js');
$extraScripts[] = asset('modules/servizi/brt/js/customs.js');
$extraScripts[] = asset('modules/servizi/brt/js/cap-lookup.js');

try {
    $customsCountriesJson = htmlspecialchars(json_encode($customsRequiredCountries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
} catch (Throwable $exception) {
    $customsCountriesJson = '["CH"]';
}
$customsSectionHiddenClass = $customsForm['enabled'] === '1' ? '' : ' d-none';

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Modifica spedizione BRT #<?php echo (int) $shipment['id']; ?></h1>
                <p class="text-muted mb-0">Aggiorna i dati della spedizione prima della conferma o dell'inclusione nel borderò.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Torna alle spedizioni</a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card ag-card">
                    <div class="card-body">
                        <h2 class="h5">Riferimenti spedizione</h2>
                        <?php
                            $status = (string) ($shipment['status'] ?? '');
                            $statusBadge = [
                                'created' => 'bg-warning text-white',
                                'confirmed' => 'bg-success',
                                'warning' => 'bg-danger',
                                'cancelled' => 'bg-secondary',
                            ][$status] ?? 'bg-secondary';
                            $statusLabel = brt_translate_status($status) ?: $status;
                            $executionMessage = brt_translate_execution_message($shipment['execution_message'] ?? null);
                        ?>
                        <dl class="mb-0">
                            <dt class="text-muted small">Codice cliente mittente</dt>
                            <dd class="fw-semibold"><?php echo sanitize_output($shipment['sender_customer_code']); ?></dd>
                            <dt class="text-muted small">Filiale partenza</dt>
                            <dd class="fw-semibold"><?php echo sanitize_output($shipment['departure_depot'] ?? ''); ?></dd>
                            <dt class="text-muted small">Stato corrente</dt>
                            <dd><span class="badge <?php echo $statusBadge; ?>"><?php echo sanitize_output($statusLabel); ?></span></dd>
                            <?php if ($executionMessage !== ''): ?>
                                <dt class="text-muted small">Messaggio</dt>
                                <dd class="text-muted small"><?php echo sanitize_output($executionMessage); ?></dd>
                            <?php endif; ?>
                            <dt class="text-muted small">Creato il</dt>
                            <dd class="text-muted small"><?php echo sanitize_output(format_datetime_locale($shipment['created_at'] ?? '')); ?></dd>
                        </dl>
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
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="numeric_sender_reference">Riferimento numerico mittente</label>
                                    <input class="form-control" type="text" id="numeric_sender_reference" name="numeric_sender_reference" value="<?php echo sanitize_output($data['numeric_sender_reference']); ?>" readonly>
                                    <div class="form-text">Questo valore non può essere modificato per mantenere la progressione.</div>
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
                                    <p class="text-muted small mb-0">Aggiorna le dimensioni per inviare il volume corretto a BRT.</p>
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
                                    <p class="text-muted small mb-3">Aggiorna il PUDO BRT Fermopoint se la consegna deve avvenire in un punto dedicato. In caso contrario lascia vuoto.</p>
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
                                                <div class="col-md-2">
                                                    <label class="form-label" for="pudoSearchProvince">Provincia</label>
                                                    <input class="form-control" type="text" id="pudoSearchProvince" value="<?php echo sanitize_output($data['consignee_province']); ?>" data-pudo-search-province>
                                                </div>
                                                <div class="col-md-3">
                                                    <label class="form-label" for="pudoSearchCountry">Nazione</label>
                                                    <select class="form-select" id="pudoSearchCountry" data-pudo-search-country>
                                                        <?php foreach ($allowedDestinationCountries as $countryCode => $countryName): ?>
                                                            <option value="<?php echo sanitize_output($countryCode); ?>" <?php echo $data['consignee_country'] === $countryCode ? 'selected' : ''; ?>><?php echo sanitize_output($countryCode); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="mt-3" data-pudo-map style="height: 320px;"></div>
                                            <div class="mt-3" data-pudo-selected></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <label class="form-label" for="notes">Note (opzionali)</label>
                                    <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo sanitize_output($data['notes']); ?></textarea>
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
                                <div class="col-12 d-flex justify-content-between align-items-center">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" value="1" id="is_label_required" name="is_label_required" <?php echo $data['is_label_required'] === '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="is_label_required">Genera automaticamente l'etichetta PDF</label>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <a class="btn btn-outline-secondary" href="index.php">Annulla</a>
                                        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva modifiche</button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
</div>
