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

if (!function_exists('array_is_list')) {
    function array_is_list(array $array): bool
    {
        $expectedKey = 0;
        foreach ($array as $key => $_) {
            if ($key !== $expectedKey) {
                return false;
            }
            $expectedKey++;
        }
        return true;
    }
}

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
$getTrackingSectionData = static function (array $payload, string $key): array {
    $section = $payload[$key] ?? [];
    return is_array($section) ? $section : [];
};
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

$trackingSummaryRows = [];
if ($trackingPayloadData !== []) {
    $trackingSummaryRows = $buildSummaryRows($trackingPayloadData, [
        'parcelID' => ['label' => 'ParcelID'],
        'trackingByParcelID' => ['label' => 'trackingByParcelID'],
        'bolla' => ['label' => 'Bolla'],
        'statusDescription' => ['label' => 'Stato'],
        'deliveryStatus' => ['label' => 'Stato consegna'],
        'deliveryDate' => ['label' => 'Data consegna prevista'],
        'deliveryTime' => ['label' => 'Ora consegna prevista'],
        'deliveryCompanyName' => ['label' => 'Destinatario registrato'],
        'deliveryContactName' => ['label' => 'Contatto consegna'],
        'deliveryNote' => ['label' => 'Note consegna'],
    ]);
}
$trackingDataShipment = $getTrackingSectionData($trackingPayloadData, 'dati_spedizione');
$trackingDataDelivery = $getTrackingSectionData($trackingPayloadData, 'dati_consegna');
$trackingDataReferences = $getTrackingSectionData($trackingPayloadData, 'riferimenti');
$trackingDataSender = $getTrackingSectionData($trackingPayloadData, 'mittente');
$trackingDataConsignee = $getTrackingSectionData($trackingPayloadData, 'destinatario');
$trackingDataGoods = $getTrackingSectionData($trackingPayloadData, 'merce');
$trackingDataCod = $getTrackingSectionData($trackingPayloadData, 'contrassegno');
$trackingDataInsurance = $getTrackingSectionData($trackingPayloadData, 'assicurazione');

$trackingShipmentDetailRows = $trackingDataShipment !== [] ? $buildSummaryRows($trackingDataShipment, [
    'spedizione_id' => ['label' => 'ID spedizione', 'hide_when_empty' => false],
    'spedizione_data' => ['label' => 'Data spedizione', 'hide_when_empty' => false],
    'tipo_porto' => ['label' => 'Tipo porto (codice)'],
    'porto' => ['label' => 'Porto'],
    'tipo_servizio' => ['label' => 'Tipo servizio (codice)'],
    'servizio' => ['label' => 'Servizio'],
    'cod_filiale_arrivo' => ['label' => 'Cod. filiale arrivo'],
    'filiale_arrivo' => ['label' => 'Filiale arrivo'],
    'filiale_arrivo_URL' => ['label' => 'URL filiale arrivo'],
    'stato_sped_parte1' => ['label' => 'Stato spedizione (titolo)'],
    'stato_sped_parte2' => ['label' => 'Stato spedizione (sottotitolo)'],
    'descrizione_stato_sped_parte1' => ['label' => 'Dettaglio stato'],
    'descrizione_stato_sped_parte2' => ['label' => 'Dettaglio stato (2)'],
]) : [];

$trackingDeliveryDetailRows = $trackingDataDelivery !== [] ? $buildSummaryRows($trackingDataDelivery, [
    'data_cons_richiesta' => ['label' => 'Data consegna richiesta'],
    'ora_cons_richiesta' => ['label' => 'Ora consegna richiesta'],
    'tipo_cons_richiesta' => ['label' => 'Tipo consegna richiesta'],
    'descrizione_cons_richiesta' => ['label' => 'Descrizione consegna richiesta'],
    'data_teorica_consegna' => ['label' => 'Data teorica consegna'],
    'ora_teorica_consegna_da' => ['label' => 'Ora teorica da'],
    'ora_teorica_consegna_a' => ['label' => 'Ora teorica a'],
    'data_consegna_merce' => ['label' => 'Data consegna effettiva'],
    'ora_consegna_merce' => ['label' => 'Ora consegna effettiva'],
    'firmatario_consegna' => ['label' => 'Firmatario'],
]) : [];

$trackingReferenceRows = $trackingDataReferences !== [] ? $buildSummaryRows($trackingDataReferences, [
    'riferimento_mittente_numerico' => ['label' => 'Rif. mittente numerico', 'hide_when_empty' => false],
    'riferimento_mittente_alfabetico' => ['label' => 'Rif. mittente alfanumerico', 'hide_when_empty' => false],
    'riferimento_partner_estero' => ['label' => 'Rif. partner estero'],
]) : [];

$trackingSenderRows = $trackingDataSender !== [] ? $buildSummaryRows($trackingDataSender, [
    'codice' => ['label' => 'Codice mittente', 'hide_when_empty' => false],
    'ragione_sociale' => ['label' => 'Ragione sociale'],
    'indirizzo' => ['label' => 'Indirizzo'],
    'cap' => ['label' => 'CAP'],
    'localita' => ['label' => 'Località'],
    'sigla_area' => ['label' => 'Provincia/Area'],
]) : [];

$trackingConsigneeRows = $trackingDataConsignee !== [] ? $buildSummaryRows($trackingDataConsignee, [
    'ragione_sociale' => ['label' => 'Ragione sociale'],
    'indirizzo' => ['label' => 'Indirizzo'],
    'cap' => ['label' => 'CAP'],
    'localita' => ['label' => 'Località'],
    'sigla_provincia' => ['label' => 'Provincia'],
    'sigla_nazione' => ['label' => 'Nazione'],
    'referente_consegna' => ['label' => 'Referente consegna'],
    'telefono_referente' => ['label' => 'Telefono referente'],
]) : [];

$trackingGoodsRows = $trackingDataGoods !== [] ? $buildSummaryRows($trackingDataGoods, [
    'colli' => ['label' => 'Colli', 'format' => 'int', 'hide_when_empty' => false],
    'peso_kg' => ['label' => 'Peso (Kg)', 'format' => 'float', 'precision' => 2, 'hide_when_empty' => false],
    'volume_m3' => ['label' => 'Volume (m³)', 'format' => 'float', 'precision' => 3],
    'natura_merce' => ['label' => 'Natura merce'],
]) : [];

$trackingCodRows = $trackingDataCod !== [] ? $buildSummaryRows($trackingDataCod, [
    'contrassegno_importo' => ['label' => 'Importo contrassegno', 'format' => 'float', 'precision' => 2, 'hide_when_empty' => false],
    'contrassegno_divisa' => ['label' => 'Divisa contrassegno', 'hide_when_empty' => false],
    'contrassegno_incasso' => ['label' => 'Modalità incasso'],
    'contrassegno_particolarita' => ['label' => 'Particolarità'],
]) : [];

$trackingInsuranceRows = $trackingDataInsurance !== [] ? $buildSummaryRows($trackingDataInsurance, [
    'assicurazione_importo' => ['label' => 'Importo assicurazione', 'format' => 'float', 'precision' => 2, 'hide_when_empty' => false],
    'assicurazione_divisa' => ['label' => 'Divisa assicurazione', 'hide_when_empty' => false],
]) : [];

$trackingStructuredSections = [];
foreach ([
    ['title' => 'Dati spedizione', 'rows' => $trackingShipmentDetailRows],
    ['title' => 'Dati consegna', 'rows' => $trackingDeliveryDetailRows],
    ['title' => 'Riferimenti', 'rows' => $trackingReferenceRows],
    ['title' => 'Mittente', 'rows' => $trackingSenderRows],
    ['title' => 'Destinatario', 'rows' => $trackingConsigneeRows],
    ['title' => 'Merce', 'rows' => $trackingGoodsRows],
    ['title' => 'Contrassegno', 'rows' => $trackingCodRows],
    ['title' => 'Assicurazione', 'rows' => $trackingInsuranceRows],
] as $section) {
    if ($section['rows'] === []) {
        continue;
    }
    $trackingStructuredSections[] = $section;
}

$extractTrackingEvents = static function (array $payload): array {
    $candidates = [];
    foreach (['trackingList', 'trackingEvents', 'events'] as $key) {
        if (isset($payload[$key])) {
            $candidates[] = $payload[$key];
        }
    }

    foreach ($candidates as $candidate) {
        if (!is_array($candidate)) {
            continue;
        }

        if (array_is_list($candidate)) {
            return array_values(array_filter($candidate, static fn ($event) => is_array($event)));
        }

        if (isset($candidate['tracking']) && is_array($candidate['tracking'])) {
            return array_values(array_filter($candidate['tracking'], static fn ($event) => is_array($event)));
        }

        if (isset($candidate['event']) && is_array($candidate['event'])) {
            return array_values(array_filter($candidate['event'], static fn ($event) => is_array($event)));
        }
    }

    return [];
};

$trackingEvents = $trackingPayloadData !== [] ? $extractTrackingEvents($trackingPayloadData) : [];

$formatTrackingEventValue = static function (array $event, array $keys): string {
    foreach ($keys as $key) {
        if (!isset($event[$key])) {
            continue;
        }
        $value = $event[$key];
        if (is_array($value)) {
            continue;
        }
        $text = trim((string) $value);
        if ($text !== '') {
            return $text;
        }
    }

    return '';
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
                            <?php if ($trackingSummaryRows): ?>
                                <h3 class="h6 text-uppercase text-muted mb-2">Sintesi</h3>
                                <?php $renderSummaryTable($trackingSummaryRows); ?>
                            <?php endif; ?>
                            <?php if ($trackingEvents): ?>
                                <h3 class="h6 text-uppercase text-muted mt-4 mb-2">Timeline eventi</h3>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th scope="col">Data/Ora</th>
                                                <th scope="col">Stato</th>
                                                <th scope="col">Descrizione</th>
                                                <th scope="col">Località / Note</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($trackingEvents as $event): ?>
                                                <?php
                                                    $eventDate = $formatTrackingEventValue($event, ['eventDate', 'trackingDate', 'date']);
                                                    $eventTime = $formatTrackingEventValue($event, ['eventTime', 'trackingTime', 'time']);
                                                    $eventStatus = $formatTrackingEventValue($event, ['trackingStatusDescription', 'eventStatusDescription', 'trackingStatus', 'statusDescription', 'status']);
                                                    $eventDescription = $formatTrackingEventValue($event, ['trackingDescription', 'eventDescription', 'description', 'message']);
                                                    $eventLocation = $formatTrackingEventValue($event, ['locationDescription', 'eventLocation', 'location', 'branch']);
                                                    $eventNote = $formatTrackingEventValue($event, ['note', 'noteDescription', 'memo']);
                                                    $eventOperator = $formatTrackingEventValue($event, ['operator', 'user', 'agent']);
                                                    $eventDateTime = trim($eventDate . ' ' . $eventTime);
                                                ?>
                                                <tr>
                                                    <td>
                                                        <?php if ($eventDateTime !== ''): ?>
                                                            <?php echo sanitize_output($eventDateTime); ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($eventStatus !== ''): ?>
                                                            <span class="badge bg-secondary text-uppercase"><?php echo sanitize_output($eventStatus); ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($eventDescription !== ''): ?>
                                                            <?php echo sanitize_output($eventDescription); ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($eventLocation !== ''): ?>
                                                            <div><?php echo sanitize_output($eventLocation); ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($eventNote !== ''): ?>
                                                            <div class="small text-muted"><?php echo sanitize_output($eventNote); ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($eventOperator !== ''): ?>
                                                            <div class="small text-muted">Operatore: <?php echo sanitize_output($eventOperator); ?></div>
                                                        <?php endif; ?>
                                                        <?php if ($eventLocation === '' && $eventNote === '' && $eventOperator === ''): ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">Nessun evento di tracking disponibile nel payload.</p>
                            <?php endif; ?>
                                <?php if ($trackingStructuredSections): ?>
                                    <h3 class="h6 text-uppercase text-muted mt-4 mb-2">Dettaglio payload</h3>
                                    <div class="row g-4">
                                        <?php foreach ($trackingStructuredSections as $section): ?>
                                            <div class="col-lg-6 col-12">
                                                <h4 class="h6 text-muted text-uppercase mb-2"><?php echo sanitize_output($section['title']); ?></h4>
                                                <?php $renderSummaryTable($section['rows']); ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
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
