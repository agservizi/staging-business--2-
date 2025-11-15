<?php
declare(strict_types=1);

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

$shipment = brt_get_shipment($shipmentId);
if ($shipment === null) {
    add_flash('warning', 'Spedizione BRT non trovata.');
    header('Location: index.php');
    exit;
}

$pageTitle = 'Dettaglio spedizione #' . $shipmentId;

$requestPayloadRaw = $shipment['request_payload'] ?? null;
if (!is_string($requestPayloadRaw) || trim($requestPayloadRaw) === '') {
    $requestPayloadRaw = null;
}

$responsePayloadRaw = $shipment['response_payload'] ?? null;
if (!is_string($responsePayloadRaw) || trim($responsePayloadRaw) === '') {
    $responsePayloadRaw = null;
}

$trackingPayloadRaw = $shipment['last_tracking_payload'] ?? null;
if (!is_string($trackingPayloadRaw) || trim($trackingPayloadRaw) === '') {
    $trackingPayloadRaw = null;
}

$decodedRequestPayload = $requestPayloadRaw !== null ? brt_decode_request_payload($requestPayloadRaw) : ['request' => [], 'meta' => []];
$requestPayloadData = is_array($decodedRequestPayload['request']) ? $decodedRequestPayload['request'] : [];
$requestMetadata = is_array($decodedRequestPayload['meta']) ? $decodedRequestPayload['meta'] : [];
$requestCreateData = isset($requestPayloadData['createData']) && is_array($requestPayloadData['createData']) ? $requestPayloadData['createData'] : [];
$requestAccountData = isset($requestPayloadData['account']) && is_array($requestPayloadData['account']) ? $requestPayloadData['account'] : [];
$requestLabelParameters = isset($requestPayloadData['labelParameters']) && is_array($requestPayloadData['labelParameters']) ? $requestPayloadData['labelParameters'] : [];
$requestAlerts = isset($requestCreateData['alerts']) && is_array($requestCreateData['alerts']) ? $requestCreateData['alerts'] : [];

$responsePayloadData = [];
if ($responsePayloadRaw !== null) {
    $decodedResponsePayload = json_decode($responsePayloadRaw, true);
    if (is_array($decodedResponsePayload)) {
        $responsePayloadData = $decodedResponsePayload;
    }
}
$responseExecutionMessage = isset($responsePayloadData['executionMessage']) && is_array($responsePayloadData['executionMessage']) ? $responsePayloadData['executionMessage'] : [];
$responseLabelData = [];
if (isset($responsePayloadData['labels']['label']) && is_array($responsePayloadData['labels']['label'])) {
    $firstLabel = $responsePayloadData['labels']['label'][0] ?? null;
    if (is_array($firstLabel)) {
        $responseLabelData = $firstLabel;
    }
}

$trackingPayloadData = [];
if ($trackingPayloadRaw !== null) {
    $decodedTrackingPayload = json_decode($trackingPayloadRaw, true);
    if (is_array($decodedTrackingPayload)) {
        $trackingPayloadData = $decodedTrackingPayload;
    }
}

$formatSummaryValue = static function ($value, array $config = []): string {
    if (array_key_exists('default', $config) && ($value === null || $value === '')) {
        $value = $config['default'];
    }

    $format = $config['format'] ?? null;

    if ($format === 'bool') {
        return $value ? 'Sì' : 'No';
    }

    if ($value === null || $value === '' || (is_array($value) && $value === [])) {
        return 'N/D';
    }

    if ($format === 'int') {
        return number_format((int) $value, 0, ',', '.');
    }

    if ($format === 'float') {
        $precision = (int) ($config['precision'] ?? 2);
        return number_format((float) $value, $precision, ',', '.');
    }

    if ($format === 'json') {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    return (string) $value;
};

$buildSummaryRows = static function (array $source, array $fields) use ($formatSummaryValue): array {
    $rows = [];
    foreach ($fields as $key => $meta) {
        if (is_int($key) && is_string($meta)) {
            $key = $meta;
            $meta = ['label' => $meta];
        }
        if (!is_array($meta)) {
            $meta = ['label' => (string) $meta];
        }
        $label = (string) ($meta['label'] ?? $key);
        $value = $source[$key] ?? ($meta['default'] ?? null);
        if (isset($meta['value']) && is_callable($meta['value'])) {
            $value = $meta['value']($source);
        }
        $formatted = $formatSummaryValue($value, $meta);
        $hideWhenEmpty = $meta['hide_when_empty'] ?? true;
        if ($hideWhenEmpty && $formatted === 'N/D') {
            continue;
        }
        $rows[] = [
            'label' => $label,
            'value' => $formatted,
        ];
    }
    return $rows;
};

$renderSummaryTable = static function (array $rows): void {
    if ($rows === []) {
        echo '<p class="text-muted mb-0">Nessun dato disponibile.</p>';
        return;
    }

    echo '<div class="table-responsive">';
    echo '<table class="table table-sm table-striped align-middle mb-0">';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<th scope="row" class="w-50 text-muted">' . sanitize_output($row['label']) . '</th>';
        echo '<td>' . sanitize_output($row['value']) . '</td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '</div>';
};

$requestShipmentRows = $buildSummaryRows($requestCreateData, [
    'senderCustomerCode' => ['label' => 'Codice cliente mittente'],
    'departureDepot' => ['label' => 'Filiale partenza'],
    'network' => ['label' => 'Network'],
    'serviceType' => ['label' => 'Tipo servizio'],
    'deliveryFreightTypeCode' => ['label' => 'Tipo porto'],
    'pricingConditionCode' => ['label' => 'Condizione tariffaria'],
    'pudoId' => ['label' => 'PUDO'],
    'numberOfParcels' => ['label' => 'Numero colli', 'format' => 'int'],
    'weightKG' => ['label' => 'Peso dichiarato (Kg)', 'format' => 'float', 'precision' => 2],
    'volumeM3' => ['label' => 'Volume dichiarato (m³)', 'format' => 'float', 'precision' => 3],
    'isAlertRequired' => ['label' => 'Alert richiesto', 'format' => 'bool'],
    'notes' => ['label' => 'Note'],
]);

$requestConsigneeRows = $buildSummaryRows($requestCreateData, [
    'consigneeCompanyName' => ['label' => 'Destinatario'],
    'consigneeAddress' => ['label' => 'Indirizzo'],
    'consigneeZIPCode' => ['label' => 'CAP'],
    'consigneeCity' => ['label' => 'Città'],
    'consigneeProvinceAbbreviation' => ['label' => 'Provincia'],
    'consigneeCountryAbbreviationISOAlpha2' => ['label' => 'Nazione'],
    'consigneeTelephone' => ['label' => 'Telefono'],
    'consigneeMobilePhoneNumber' => ['label' => 'Cellulare'],
    'consigneeEMail' => ['label' => 'Email'],
    'consigneeContactName' => ['label' => 'Referente'],
]);

$requestMetaRows = $buildSummaryRows($requestMetadata, [
    'source' => ['label' => 'Origine richiesta', 'hide_when_empty' => false],
    'customer_id' => ['label' => 'Cliente (ID)', 'hide_when_empty' => false],
    'dimension_length_cm' => ['label' => 'Lunghezza (cm)', 'format' => 'float', 'precision' => 0],
    'dimension_depth_cm' => ['label' => 'Profondità (cm)', 'format' => 'float', 'precision' => 0],
    'dimension_height_cm' => ['label' => 'Altezza (cm)', 'format' => 'float', 'precision' => 0],
    'pudo_description' => ['label' => 'Descrizione PUDO'],
]);

$requestLabelRows = $buildSummaryRows($requestLabelParameters, [
    'outputType' => ['label' => 'Formato'],
    'isBorderRequired' => ['label' => 'Bordo etichetta', 'format' => 'bool'],
    'isLogoRequired' => ['label' => 'Logo', 'format' => 'bool'],
    'isBarcodeControlRowRequired' => ['label' => 'Riga codice a barre', 'format' => 'bool'],
    'offsetX' => ['label' => 'Offset X'],
    'offsetY' => ['label' => 'Offset Y'],
]);

$requestAccountRows = $buildSummaryRows($requestAccountData, [
    'userID' => ['label' => 'Account BRT (userID)'],
]);

$requestAlertsRows = [];
if ($requestAlerts !== []) {
    $requestAlertsRows[] = [
        'label' => 'Alert configurati',
        'value' => $formatSummaryValue($requestAlerts, ['format' => 'json', 'hide_when_empty' => false]),
    ];
}

$responseMainRows = $buildSummaryRows($responsePayloadData, [
    'trackingByParcelID' => ['label' => 'trackingByParcelID'],
    'parcelID' => ['label' => 'ParcelID'],
    'parcelNumberFrom' => ['label' => 'Numero colli da'],
    'parcelNumberTo' => ['label' => 'Numero colli a'],
    'numberOfParcels' => ['label' => 'Colli confermati', 'format' => 'int'],
    'weightKG' => ['label' => 'Peso confermato (Kg)', 'format' => 'float', 'precision' => 2],
    'volumeM3' => ['label' => 'Volume confermato (m³)', 'format' => 'float', 'precision' => 3],
    'arrivalDepot' => ['label' => 'Filiale arrivo'],
    'arrivalTerminal' => ['label' => 'Terminal arrivo'],
    'routing' => ['label' => 'Routing', 'format' => 'json'],
]);

$responseConsigneeRows = $buildSummaryRows($responsePayloadData, [
    'consigneeCompanyName' => ['label' => 'Destinatario'],
    'consigneeAddress' => ['label' => 'Indirizzo'],
    'consigneeZIPCode' => ['label' => 'CAP'],
    'consigneeCity' => ['label' => 'Città'],
    'consigneeProvinceAbbreviation' => ['label' => 'Provincia'],
    'consigneeCountryAbbreviationBRT' => ['label' => 'Nazione'],
]);

$responseExecutionRows = $buildSummaryRows($responseExecutionMessage, [
    'code' => ['label' => 'Codice', 'format' => 'int', 'hide_when_empty' => false],
    'codeDesc' => ['label' => 'Descrizione', 'hide_when_empty' => false],
    'message' => ['label' => 'Messaggio'],
    'severity' => ['label' => 'Severità'],
]);

$responseLabelRows = $buildSummaryRows($responseLabelData, [
    'trackingByParcelID' => ['label' => 'trackingByParcelID'],
    'parcelID' => ['label' => 'ParcelID etichetta'],
    'labelType' => ['label' => 'Tipo etichetta'],
    'fileName' => ['label' => 'Nome file'],
    'contentType' => ['label' => 'Content-Type'],
]);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Spedizione BRT #<?php echo (int) $shipment['id']; ?></h1>
                <p class="text-muted mb-0">Riepilogo completo della spedizione e payload inviati ai webservice.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Torna alla lista</a>
                <?php if (!empty($shipment['label_path'])): ?>
                    <a class="btn btn-outline-primary" href="<?php echo asset($shipment['label_path']); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf me-2"></i>Scarica etichetta</a>
                <?php endif; ?>
                <?php if ((string) ($shipment['status'] ?? '') !== 'cancelled' && empty($shipment['deleted_at'])): ?>
                    <a class="btn btn-outline-warning" href="orm.php?from_shipment=<?php echo (int) $shipment['id']; ?>">
                        <i class="fa-solid fa-truck-ramp-box me-2"></i>Prenota ritiro ORM
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-4">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <h2 class="h5">Stato spedizione</h2>
                        <p class="mb-1"><span class="text-muted">Codice mittente:</span> <strong><?php echo sanitize_output($shipment['sender_customer_code']); ?></strong></p>
                        <p class="mb-1"><span class="text-muted">Rif. numerico:</span> <strong><?php echo sanitize_output((string) $shipment['numeric_sender_reference']); ?></strong></p>
                        <?php if (!empty($shipment['alphanumeric_sender_reference'])): ?>
                            <p class="mb-1"><span class="text-muted">Rif. alfanumerico:</span> <strong><?php echo sanitize_output($shipment['alphanumeric_sender_reference']); ?></strong></p>
                        <?php endif; ?>
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
                        <p class="mb-1"><span class="text-muted">Stato:</span> <span class="badge <?php echo $statusBadge; ?>"><?php echo sanitize_output($statusLabel); ?></span></p>
                        <?php if ($executionMessage !== ''): ?>
                            <p class="text-muted small mb-1"><?php echo sanitize_output($executionMessage); ?></p>
                        <?php endif; ?>
                        <hr>
                        <p class="mb-1"><span class="text-muted">Destinatario:</span> <strong><?php echo sanitize_output($shipment['consignee_name']); ?></strong></p>
                        <p class="mb-1"><span class="text-muted">Indirizzo:</span> <?php echo sanitize_output($shipment['consignee_address'] . ' - ' . $shipment['consignee_zip'] . ' ' . $shipment['consignee_city']); ?></p>
                        <?php if (!empty($shipment['consignee_phone'])): ?>
                            <p class="mb-1"><span class="text-muted">Telefono:</span> <?php echo sanitize_output($shipment['consignee_phone']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($shipment['consignee_email'])): ?>
                            <p class="mb-1"><span class="text-muted">Email:</span> <?php echo sanitize_output($shipment['consignee_email']); ?></p>
                        <?php endif; ?>
                        <hr>
                        <p class="mb-1"><span class="text-muted">Colli:</span> <?php echo (int) $shipment['number_of_parcels']; ?></p>
                        <p class="mb-1"><span class="text-muted">Peso (Kg):</span> <?php echo sanitize_output(number_format((float) $shipment['weight_kg'], 2, ',', '.')); ?></p>
                        <?php if ((float) $shipment['volume_m3'] > 0): ?>
                            <p class="mb-1"><span class="text-muted">Volume (m³):</span> <?php echo sanitize_output(number_format((float) $shipment['volume_m3'], 3, ',', '.')); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($shipment['tracking_by_parcel_id'])): ?>
                            <p class="mb-1"><span class="text-muted">trackingByParcelID:</span> <strong><?php echo sanitize_output($shipment['tracking_by_parcel_id']); ?></strong></p>
                        <?php endif; ?>
                        <?php if (!empty($shipment['parcel_id'])): ?>
                            <p class="mb-1"><span class="text-muted">ParcelID:</span> <strong><?php echo sanitize_output($shipment['parcel_id']); ?></strong></p>
                        <?php endif; ?>
                        <?php if (!empty($shipment['manifest_reference'])): ?>
                            <p class="mb-1">
                                <span class="text-muted">Borderò:</span>
                                <?php if (!empty($shipment['manifest_pdf_path'])): ?>
                                    <a href="<?php echo asset($shipment['manifest_pdf_path']); ?>" target="_blank" rel="noopener"><?php echo sanitize_output($shipment['manifest_reference']); ?></a>
                                <?php else: ?>
                                    <?php echo sanitize_output($shipment['manifest_reference']); ?>
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($shipment['manifest_generated_at'])): ?>
                                <p class="text-muted small mb-1">Inclusa nel borderò il <?php echo sanitize_output(format_datetime_locale($shipment['manifest_generated_at'] ?? '')); ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if (!empty($shipment['manifest_official_number'])): ?>
                            <p class="mb-1">
                                <span class="text-muted">Borderò WS:</span>
                                <?php if (!empty($shipment['manifest_official_url'])): ?>
                                    <a href="<?php echo sanitize_output($shipment['manifest_official_url']); ?>" target="_blank" rel="noopener"><?php echo sanitize_output($shipment['manifest_official_number']); ?></a>
                                <?php elseif (!empty($shipment['manifest_official_pdf_path'])): ?>
                                    <a href="<?php echo asset($shipment['manifest_official_pdf_path']); ?>" target="_blank" rel="noopener"><?php echo sanitize_output($shipment['manifest_official_number']); ?></a>
                                <?php else: ?>
                                    <?php echo sanitize_output($shipment['manifest_official_number']); ?>
                                <?php endif; ?>
                            </p>
                        <?php endif; ?>
                        <p class="text-muted small mb-1">Creato il <?php echo sanitize_output(format_datetime_locale($shipment['created_at'] ?? '')); ?></p>
                        <?php if (!empty($shipment['confirmed_at'])): ?>
                            <p class="text-muted small mb-1">Confermato il <?php echo sanitize_output(format_datetime_locale($shipment['confirmed_at'])); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($shipment['deleted_at'])): ?>
                            <p class="text-muted small mb-0">Cancellato il <?php echo sanitize_output(format_datetime_locale($shipment['deleted_at'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card ag-card mb-4">
                    <div class="card-header">
                        <h2 class="card-title h5 mb-0">Payload invito (create)</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($requestShipmentRows || $requestConsigneeRows || $requestMetaRows || $requestLabelRows || $requestAccountRows || $requestAlertsRows): ?>
                            <div class="row g-4">
                                <?php if ($requestShipmentRows): ?>
                                    <div class="col-lg-6 col-12">
                                        <h3 class="h6 text-uppercase text-muted mb-2">Parametri spedizione</h3>
                                        <?php $renderSummaryTable($requestShipmentRows); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($requestConsigneeRows): ?>
                                    <div class="col-lg-6 col-12">
                                        <h3 class="h6 text-uppercase text-muted mb-2">Destinatario</h3>
                                        <?php $renderSummaryTable($requestConsigneeRows); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($requestMetaRows): ?>
                                    <div class="col-lg-6 col-12">
                                        <h3 class="h6 text-uppercase text-muted mb-2">Dimensioni</h3>
                                        <?php $renderSummaryTable($requestMetaRows); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($requestLabelRows): ?>
                                    <div class="col-lg-6 col-12">
                                        <h3 class="h6 text-uppercase text-muted mb-2">Etichetta</h3>
                                        <?php $renderSummaryTable($requestLabelRows); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($requestAccountRows): ?>
                                    <div class="col-lg-6 col-12">
                                        <h3 class="h6 text-uppercase text-muted mb-2">Credenziali invio</h3>
                                        <?php $renderSummaryTable($requestAccountRows); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($requestAlertsRows): ?>
                                    <div class="col-lg-6 col-12">
                                        <h3 class="h6 text-uppercase text-muted mb-2">Alert</h3>
                                        <?php $renderSummaryTable($requestAlertsRows); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">Nessun dato strutturato disponibile.</p>
                        <?php endif; ?>
                        <?php if ($requestPayloadRaw !== null): ?>
                            <details class="mt-4">
                                <summary class="small text-muted fw-semibold" style="cursor: pointer;">Mostra payload JSON completo</summary>
                                <pre class="bg-dark text-light p-3 rounded small overflow-auto mt-3" style="max-height: 320px;"><?php echo htmlspecialchars($requestPayloadRaw, ENT_QUOTES, 'UTF-8'); ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card ag-card mb-4">
                    <div class="card-header">
                        <h2 class="card-title h5 mb-0">Payload risposta</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($responseMainRows || $responseConsigneeRows || $responseExecutionRows || $responseLabelRows): ?>
                            <div class="row g-4">
                                <?php if ($responseMainRows): ?>
                                    <div class="col-lg-6 col-12">
                                        <h3 class="h6 text-uppercase text-muted mb-2">Conferma spedizione</h3>
                                        <?php $renderSummaryTable($responseMainRows); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($responseConsigneeRows): ?>
                                    <div class="col-lg-6 col-12">
                                        <h3 class="h6 text-uppercase text-muted mb-2">Destinatario confermato</h3>
                                        <?php $renderSummaryTable($responseConsigneeRows); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($responseExecutionRows): ?>
                                    <div class="col-lg-6 col-12">
                                        <h3 class="h6 text-uppercase text-muted mb-2">Messaggi servizio</h3>
                                        <?php $renderSummaryTable($responseExecutionRows); ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($responseLabelRows): ?>
                                    <div class="col-lg-6 col-12">
                                        <h3 class="h6 text-uppercase text-muted mb-2">Etichetta generata</h3>
                                        <?php $renderSummaryTable($responseLabelRows); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">Nessun dato strutturato disponibile.</p>
                        <?php endif; ?>
                        <?php if ($responsePayloadRaw !== null): ?>
                            <details class="mt-4">
                                <summary class="small text-muted fw-semibold" style="cursor: pointer;">Mostra payload JSON completo</summary>
                                <pre class="bg-dark text-light p-3 rounded small overflow-auto mt-3" style="max-height: 320px;"><?php echo htmlspecialchars($responsePayloadRaw, ENT_QUOTES, 'UTF-8'); ?></pre>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card ag-card">
                    <div class="card-header">
                        <h2 class="card-title h5 mb-0">Ultimo tracking</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($trackingPayloadRaw !== null): ?>
                            <p class="text-muted small">Aggiornato il <?php echo sanitize_output(format_datetime_locale($shipment['last_tracking_at'] ?? '')); ?></p>
                            <pre class="bg-dark text-light p-3 rounded small overflow-auto" style="max-height: 320px;"><?php echo htmlspecialchars($trackingPayloadRaw, ENT_QUOTES, 'UTF-8'); ?></pre>
                        <?php else: ?>
                            <p class="text-muted mb-0">Nessun tracking registrato per questa spedizione.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
</div>
