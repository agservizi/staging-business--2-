<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

if (!defined('CORESUITE_PICKUP_BOOTSTRAP')) {
    define('CORESUITE_PICKUP_BOOTSTRAP', true);
}

require_once __DIR__ . '/functions.php';

ensure_pickup_tables();

$statusParam = $_GET['status'] ?? '';
$status = in_array($statusParam, pickup_statuses(), true) ? $statusParam : '';

$courierParam = isset($_GET['courier_id']) ? (int) $_GET['courier_id'] : 0;
$locationParam = isset($_GET['pickup_location_id']) ? (int) $_GET['pickup_location_id'] : 0;
$archived = ($_GET['archived'] ?? '') === '1';
$search = clean_input($_GET['search'] ?? '', 120);

$from = $_GET['from'] ?? '';
if ($from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = '';
}

$to = $_GET['to'] ?? '';
if ($to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = '';
}

$statsRange = isset($_GET['stats_range']) ? strtolower((string) $_GET['stats_range']) : 'today';
$allowedStatsRanges = ['today', 'week', 'month', 'custom'];
if (!in_array($statsRange, $allowedStatsRanges, true)) {
    $statsRange = 'today';
}
if ($statsRange === 'custom' && ($from === '' || $to === '')) {
    $statsRange = 'today';
}

$filters = ['archived' => $archived];
if ($status !== '') {
    $filters['status'] = $status;
}
if ($courierParam > 0) {
    $filters['courier_id'] = $courierParam;
}
if ($locationParam > 0) {
    $filters['pickup_location_id'] = $locationParam;
}
if ($search !== '') {
    $filters['search'] = $search;
}
if ($from !== '') {
    $filters['from'] = $from;
}
if ($to !== '') {
    $filters['to'] = $to;
}

$exportParams = [];
if ($status !== '') {
    $exportParams['status'] = $status;
}
if ($courierParam > 0) {
    $exportParams['courier_id'] = (string) $courierParam;
}
if ($locationParam > 0) {
    $exportParams['pickup_location_id'] = (string) $locationParam;
}
if ($search !== '') {
    $exportParams['search'] = $search;
}
if ($from !== '') {
    $exportParams['from'] = $from;
}
if ($to !== '') {
    $exportParams['to'] = $to;
}
if ($archived) {
    $exportParams['archived'] = '1';
}
if ($statsRange !== 'today') {
    $exportParams['stats_range'] = $statsRange;
}

if (isset($_GET['export'])) {
    $type = $_GET['export'];
    if ($type === 'csv') {
        export_packages_csv($filters);
    }
    if ($type === 'pdf') {
        export_packages_pdf($filters);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'generate_checkin_qr') {
        header('Content-Type: application/json');
        try {
            $locationId = isset($_POST['location_id']) ? (int) $_POST['location_id'] : 0;
            $qrPath = generate_qr_checkin($locationId > 0 ? $locationId : null);
            if (!$qrPath) {
                throw new RuntimeException('Impossibile generare il QR di check-in.');
            }

            echo json_encode([
                'success' => true,
                'qrUrl' => pickup_public_url($qrPath),
                'message' => 'QR di check-in generato con successo.',
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
        exit;
    }

    if ($action === 'update_status') {
        header('Content-Type: application/json');
        try {
            $packageId = (int) ($_POST['package_id'] ?? 0);
            $newStatus = (string) ($_POST['status'] ?? '');
            if ($packageId <= 0) {
                throw new InvalidArgumentException('ID pacco non valido.');
            }

            $details = update_package_status($packageId, $newStatus);
            log_notification($packageId, 'status', 'aggiornato', 'Stato aggiornato a ' . pickup_status_label($details['status']), [
                'status' => $details['status'],
            ]);

            echo json_encode([
                'success' => true,
                'statusKey' => $details['status'],
                'statusLabel' => pickup_status_label($details['status']),
                'updatedAt' => format_datetime_locale($details['updated_at'] ?? date('Y-m-d H:i:s')),
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
        exit;
    }

    if ($action === 'archive_packages') {
        header('Content-Type: application/json');
        try {
            $days = isset($_POST['days']) ? max(1, (int) $_POST['days']) : PICKUP_DEFAULT_ARCHIVE_DAYS;
            $count = archive_old_packages($days);
            echo json_encode([
                'success' => true,
                'message' => $count > 0 ? "Archiviati $count pacchi." : 'Nessun pacco da archiviare.',
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
        exit;
    }

    if ($action === 'send_notification') {
        header('Content-Type: application/json');
        try {
            $packageId = (int) ($_POST['package_id'] ?? 0);
            $channel = clean_input($_POST['channel'] ?? '', 16);
            $messageInput = trim((string) ($_POST['message'] ?? ''));
            $message = $messageInput;
            if ($packageId <= 0 || $channel === '') {
                throw new InvalidArgumentException('Richiesta notifica non valida.');
            }

            $package = get_package_details($packageId);
            if (!$package) {
                throw new RuntimeException('Pacco non trovato.');
            }

            $meta = ['channel' => $channel];
            $result = false;
            $notificationStatus = 'inviata';
            $fallbackUrl = null;
            if ($channel === 'email') {
                $recipient = trim((string) ($_POST['recipient'] ?? ''));
                if ($recipient === '' && !empty($package['customer_email'])) {
                    $recipient = (string) $package['customer_email'];
                }
                $subject = clean_input($_POST['subject'] ?? '', 160);
                if ($subject === '') {
                    $subject = pickup_email_subject_template($package);
                }
                $message = $messageInput !== '' ? $messageInput : pickup_email_message_template($package);
                if ($message === '') {
                    throw new InvalidArgumentException('Il messaggio è obbligatorio.');
                }
                if ($recipient === '') {
                    throw new InvalidArgumentException('Destinatario email mancante.');
                }

                $qrUrl = '';
                if (empty($package['qr_code_path'])) {
                    try {
                        $generatedQr = generate_package_qr($packageId);
                        if ($generatedQr) {
                            $package['qr_code_path'] = $generatedQr;
                        }
                    } catch (Throwable $qrException) {
                        error_log('Generazione QR email manuale fallita per pacco ' . $packageId . ': ' . $qrException->getMessage());
                    }
                }
                if (!empty($package['qr_code_path'])) {
                    $qrUrl = pickup_public_url($package['qr_code_path']);
                }

                $result = send_notification_email($recipient, $subject, $message, [
                    'qr_url' => $qrUrl,
                ]);
                $meta['recipient'] = $recipient;
                $meta['subject'] = $subject;
            } elseif ($channel === 'whatsapp') {
                $recipientRaw = trim((string) ($_POST['recipient'] ?? $package['customer_phone']));
                $normalizedRecipient = preg_replace('/[^0-9+]/', '', $recipientRaw);
                if ($normalizedRecipient === '') {
                    throw new InvalidArgumentException('Numero di telefono non valido.');
                }

                $meta['recipient'] = $normalizedRecipient;
                $message = $messageInput !== '' ? $messageInput : pickup_whatsapp_message_template($package);
                if ($message === '') {
                    throw new InvalidArgumentException('Il messaggio è obbligatorio.');
                }
                $meta['message_preview'] = $message;

                $apiUrl = env('WHATSAPP_API_URL', '');
                $apiToken = env('WHATSAPP_API_TOKEN', '');

                if ($apiUrl === '' || $apiToken === '') {
                    $waNumber = ltrim($normalizedRecipient, '+');
                    $fallbackUrl = 'https://wa.me/' . rawurlencode($waNumber) . '?text=' . rawurlencode($message);
                    $result = true;
                    $notificationStatus = 'manuale';
                } else {
                    $result = send_notification_whatsapp($normalizedRecipient, $message);
                }
            } else {
                throw new InvalidArgumentException('Canale di notifica non supportato.');
            }

            if (!$result) {
                throw new RuntimeException('Impossibile inviare la notifica.');
            }

            $logId = log_notification($packageId, $channel, $notificationStatus, $message, $meta);

            $entryHtml = sprintf(
                '<div class="list-group-item bg-transparent border-secondary-subtle text-body-secondary"><div class="d-flex justify-content-between"><span class="text-warning text-uppercase fw-semibold">%s</span><span class="small">%s</span></div><div class="small text-secondary">Stato: %s</div><div class="small mt-2 text-body">%s</div><div class="small mt-2 text-secondary">%s</div></div>',
                sanitize_output(strtoupper($channel)),
                sanitize_output(format_datetime_locale(date('Y-m-d H:i:s'))),
                sanitize_output(ucfirst($notificationStatus)),
                nl2br(sanitize_output($message)),
                sanitize_output('Destinatario: ' . ($meta['recipient'] ?? 'N/D'))
            );

            echo json_encode([
                'success' => true,
                'message' => $fallbackUrl ? 'API WhatsApp non configurata: apri WhatsApp per completare l\'invio.' : 'Notifica inviata con successo.',
                'entryHtml' => $entryHtml,
                'notificationId' => $logId,
                'fallbackUrl' => $fallbackUrl,
                'status' => $notificationStatus,
            ], JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $exception->getMessage(),
            ], JSON_THROW_ON_ERROR);
        }
        exit;
    }
}

$packages = get_all_packages($filters);
$couriers = get_all_couriers();
$locations = get_pickup_locations();
$statsOptions = ['location_id' => $locationParam > 0 ? $locationParam : null];
if ($statsRange === 'custom') {
    $statsOptions['from'] = $from;
    $statsOptions['to'] = $to;
}
$pickupStats = generate_pickup_stats($statsRange, $statsOptions);
$dashboardCounters = get_dashboard_counters($locationParam > 0 ? $locationParam : null);
$statsRangeLabels = [
    'today' => 'Oggi',
    'week' => 'Settimana in corso',
    'month' => 'Mese in corso',
    'custom' => 'Intervallo personalizzato',
];
$currentStatsLabel = $statsRangeLabels[$statsRange] ?? 'Oggi';
$statsRangeStartLabel = $pickupStats['range']['start'] ?? null;
if ($statsRangeStartLabel) {
    $statsRangeStartLabel = format_datetime_locale($statsRangeStartLabel);
}
$statsRangeEndLabel = $pickupStats['range']['end'] ?? null;
if ($statsRangeEndLabel) {
    $statsRangeEndLabel = format_datetime_locale($statsRangeEndLabel);
}
$statsRangePeriodLabel = '';
if ($statsRangeStartLabel && $statsRangeEndLabel) {
    $statsRangePeriodLabel = $statsRangeStartLabel . ' - ' . $statsRangeEndLabel;
} elseif ($statsRangeStartLabel) {
    $statsRangePeriodLabel = $statsRangeStartLabel;
} elseif ($statsRangeEndLabel) {
    $statsRangePeriodLabel = $statsRangeEndLabel;
}
$statusCounts = generate_statistics(array_filter([
    'from' => $from !== '' ? $from : null,
    'to' => $to !== '' ? $to : null,
    'pickup_location_id' => $locationParam > 0 ? $locationParam : null,
], static fn($value) => $value !== null && $value !== ''));
$notifications = get_recent_notifications(6);
$customerReportStats = ['pending_unlinked' => 0, 'totale' => 0];
$pendingPortalReports = [];

try {
    $customerReportStats = get_customer_report_statistics();
    $pendingPortalReports = get_customer_reports([
        'status' => 'reported',
        'only_unlinked' => true,
    ], [
        'limit' => 5,
        'order_by' => 'created_at',
    ]);

    if (!$pendingPortalReports) {
        $pendingPortalReports = get_customer_reports([], [
            'limit' => 5,
            'order_by' => 'updated_at',
        ]);
    }
} catch (Throwable $portalReportException) {
    error_log('Pickup portal report summary unavailable: ' . $portalReportException->getMessage());
}

$statuses = pickup_statuses();
$formToken = csrf_token();

$pageTitle = 'Servizio Pickup';
$extraStyles = [asset('modules/servizi/logistici/css/style.css')];
$extraScripts = [asset('modules/servizi/logistici/js/script.js')];

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100 pickup-module">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Servizio Pickup</h1>
                <p class="text-muted mb-0">Monitoraggio pacchi, notifiche clienti e archivio ritiri.</p>
            </div>
            <div class="toolbar-actions d-flex flex-wrap gap-2">
                <a class="btn btn-outline-warning" href="reports.php"><i class="fa-solid fa-inbox me-2"></i>Segnalazioni portal</a>
                <button class="btn btn-outline-warning" type="button" data-bs-toggle="modal" data-bs-target="#pickupCheckinModal"><i class="fa-solid fa-qrcode me-2"></i>Ritiro con codice</button>
                <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo pickup</a>
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-rotate"></i></a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xxl-9">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h5 mb-0">Situazione attuale</h2>
                            <?php if ($locationParam > 0): ?>
                                <span class="text-muted small">Filtro punto ritiro attivo</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid">
                            <div class="stat-card">
                                <span class="stat-card-title">Totale attivi</span>
                                <span class="stat-card-value"><?php echo (int) ($statusCounts['totale'] ?? 0); ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-card-title">In arrivo</span>
                                <span class="stat-card-value"><?php echo (int) ($dashboardCounters['incoming'] ?? 0); ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-card-title">In giacenza</span>
                                <span class="stat-card-value"><?php echo (int) ($dashboardCounters['storage'] ?? 0); ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-card-title">Scaduti</span>
                                <span class="stat-card-value"><?php echo (int) ($dashboardCounters['expired'] ?? 0); ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-card-title">Consegnati</span>
                                <span class="stat-card-value"><?php echo (int) ($statusCounts['consegnato'] ?? 0); ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-card-title">Ritirati oggi</span>
                                <span class="stat-card-value"><?php echo (int) ($dashboardCounters['picked_today'] ?? 0); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h5 mb-0">Andamento <?php echo sanitize_output($currentStatsLabel); ?></h2>
                            <?php if ($statsRangePeriodLabel !== ''): ?>
                                <span class="text-muted small">Intervallo <?php echo sanitize_output($statsRangePeriodLabel); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="stats-grid">
                            <div class="stat-card">
                                <span class="stat-card-title">Ricevuti</span>
                                <span class="stat-card-value"><?php echo (int) ($pickupStats['received'] ?? 0); ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-card-title">Ritirati (totale)</span>
                                <span class="stat-card-value"><?php echo (int) ($pickupStats['picked'] ?? 0); ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-card-title">Ritirati nel periodo</span>
                                <span class="stat-card-value"><?php echo (int) ($pickupStats['picked_in_range'] ?? 0); ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-card-title">In giacenza</span>
                                <span class="stat-card-value"><?php echo (int) ($pickupStats['in_storage'] ?? 0); ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-card-title">Giacenza scaduta</span>
                                <span class="stat-card-value"><?php echo (int) ($pickupStats['storage_expired'] ?? 0); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <h2 class="h5 mb-0">Filtri</h2>
                            <div class="action-buttons d-flex flex-wrap gap-2">
                                <?php $queryString = http_build_query(array_merge($exportParams, ['export' => 'csv'])); ?>
                                <a class="btn btn-sm btn-outline-warning" href="index.php?<?php echo sanitize_output($queryString); ?>"><i class="fa-solid fa-file-csv me-1"></i>Esporta CSV</a>
                                <?php $queryStringPdf = http_build_query(array_merge($exportParams, ['export' => 'pdf'])); ?>
                                <a class="btn btn-sm btn-outline-warning" href="index.php?<?php echo sanitize_output($queryStringPdf); ?>"><i class="fa-solid fa-file-pdf me-1"></i>Esporta PDF</a>
                                <button class="btn btn-sm btn-outline-warning" type="button" data-pickup-archive-button data-days="<?php echo PICKUP_DEFAULT_ARCHIVE_DAYS; ?>" data-action="index.php" data-csrf="<?php echo $formToken; ?>">
                                    <i class="fa-solid fa-box-archive me-1"></i>Archivia ritirati
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <form class="row g-3 align-items-end filters" method="get" action="index.php">
                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label" for="search">Ricerca</label>
                                <input class="form-control" id="search" name="search" value="<?php echo sanitize_output($search); ?>" placeholder="Tracking o cliente">
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label" for="status">Stato</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">Tutti</option>
                                    <?php foreach ($statuses as $statusOption): ?>
                                        <option value="<?php echo $statusOption; ?>" <?php echo $statusOption === $status ? 'selected' : ''; ?>><?php echo pickup_status_label($statusOption); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label" for="courier_id">Corriere</label>
                                <select class="form-select" id="courier_id" name="courier_id">
                                    <option value="">Tutti</option>
                                    <?php foreach ($couriers as $courier): ?>
                                        <option value="<?php echo (int) $courier['id']; ?>" <?php echo (int) $courier['id'] === $courierParam ? 'selected' : ''; ?>><?php echo sanitize_output($courier['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label" for="pickup_location_id">Punto ritiro</label>
                                <select class="form-select" id="pickup_location_id" name="pickup_location_id">
                                    <option value="">Tutti</option>
                                    <?php foreach ($locations as $location): ?>
                                        <option value="<?php echo (int) $location['id']; ?>" <?php echo (int) $location['id'] === $locationParam ? 'selected' : ''; ?>><?php echo sanitize_output($location['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label" for="from">Dal</label>
                                <input class="form-control" id="from" name="from" type="date" value="<?php echo sanitize_output($from); ?>">
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label" for="to">Al</label>
                                <input class="form-control" id="to" name="to" type="date" value="<?php echo sanitize_output($to); ?>">
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label" for="stats_range">Intervallo statistiche</label>
                                <select class="form-select" id="stats_range" name="stats_range">
                                    <option value="today" <?php echo $statsRange === 'today' ? 'selected' : ''; ?>>Oggi</option>
                                    <option value="week" <?php echo $statsRange === 'week' ? 'selected' : ''; ?>>Settimana</option>
                                    <option value="month" <?php echo $statsRange === 'month' ? 'selected' : ''; ?>>Mese</option>
                                    <option value="custom" <?php echo $statsRange === 'custom' ? 'selected' : ''; ?>>Personalizzato</option>
                                </select>
                            </div>
                            <div class="col-sm-6 col-lg-3 d-flex align-items-center">
                                <div class="form-check form-switch mt-4">
                                    <input class="form-check-input" type="checkbox" role="switch" id="archived" name="archived" value="1" <?php echo $archived ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="archived">Mostra archiviati</label>
                                </div>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button class="btn btn-warning text-dark" type="submit">Applica filtri</button>
                                <a class="btn btn-outline-warning" href="index.php">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Elenco pickup</h2>
                        <span class="text-muted small">Risultati: <?php echo count($packages); ?></span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th>Tracking</th>
                                        <th>Cliente</th>
                                        <th>Corriere</th>
                                        <th>Punto ritiro</th>
                                        <th>Stato</th>
                                        <th>Previsto</th>
                                        <th>Aggiornato</th>
                                        <th class="text-end">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$packages): ?>
                                        <tr>
                                            <td class="text-center text-muted" colspan="8">Nessun pacco trovato per i filtri selezionati.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($packages as $package): ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">#<?php echo sanitize_output($package['tracking']); ?></div>
                                                <div class="small text-muted">Creato <?php echo sanitize_output(format_datetime_locale($package['created_at'] ?? '')); ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-body"><?php echo sanitize_output($package['customer_name']); ?></div>
                                                <div class="small"><a class="link-warning" href="tel:<?php echo sanitize_output(preg_replace('/[^0-9+]/', '', $package['customer_phone'])); ?>"><?php echo sanitize_output($package['customer_phone']); ?></a></div>
                                            </td>
                                            <td>
                                                <?php if (!empty($package['courier_name'])): ?>
                                                    <div class="fw-semibold text-warning"><?php echo sanitize_output($package['courier_name']); ?></div>
                                                <?php else: ?>
                                                    <span class="text-muted small">N/D</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($package['location_name'])): ?>
                                                    <div class="fw-semibold text-warning"><?php echo sanitize_output($package['location_name']); ?></div>
                                                    <?php if (!empty($package['location_address'])): ?>
                                                        <div class="small text-muted"><?php echo sanitize_output($package['location_address']); ?></div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="text-muted small">N/D</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge-status" data-status="<?php echo sanitize_output($package['status']); ?>" data-status-badge><?php echo pickup_status_label($package['status']); ?></span>
                                                <form class="d-flex align-items-center gap-2 mt-2" method="post" action="index.php" data-pickup-status-form>
                                                    <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                                                    <input type="hidden" name="action" value="update_status">
                                                    <input type="hidden" name="package_id" value="<?php echo (int) $package['id']; ?>">
                                                    <select class="form-select form-select-sm" name="status">
                                                        <?php foreach ($statuses as $statusOption): ?>
                                                            <option value="<?php echo $statusOption; ?>" <?php echo $statusOption === $package['status'] ? 'selected' : ''; ?>><?php echo pickup_status_label($statusOption); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button class="btn btn-soft-accent btn-sm" type="submit">Aggiorna</button>
                                                </form>
                                            </td>
                                            <td><?php echo sanitize_output($package['expected_at'] ? format_datetime_locale($package['expected_at']) : 'N/D'); ?></td>
                                            <td data-updated-at><?php echo sanitize_output(format_datetime_locale($package['updated_at'] ?? '')); ?></td>
                                            <td class="text-end">
                                                <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap">
                                                    <a class="btn btn-icon btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $package['id']; ?>" title="Dettagli">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </a>
                                                    <a class="btn btn-icon btn-soft-accent btn-sm" href="edit.php?id=<?php echo (int) $package['id']; ?>" title="Modifica">
                                                        <i class="fa-solid fa-pen"></i>
                                                    </a>
                                                    <form method="post" action="delete.php" onsubmit="return confirm('Confermi l\'eliminazione del pickup?');">
                                                        <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                                                        <input type="hidden" name="id" value="<?php echo (int) $package['id']; ?>">
                                                        <button class="btn btn-icon btn-soft-danger btn-sm" type="submit" title="Elimina">
                                                            <i class="fa-solid fa-trash"></i>
                                                        </button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xxl-3">
                <div class="card ag-card mb-4" data-checkin-qr-container>
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">QR check-in</h2>
                    </div>
                    <div class="card-body">
                        <form method="post" action="index.php" data-pickup-checkin-qr>
                            <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                            <input type="hidden" name="action" value="generate_checkin_qr">
                            <div class="mb-3">
                                <label class="form-label" for="qr_pickup_location">Punto ritiro</label>
                                <select class="form-select" id="qr_pickup_location" name="location_id">
                                    <option value="">Tutti i punti</option>
                                    <?php foreach ($locations as $location): ?>
                                        <option value="<?php echo (int) $location['id']; ?>"><?php echo sanitize_output($location['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button class="btn btn-outline-warning w-100" type="submit"><i class="fa-solid fa-qrcode me-2"></i>Genera QR</button>
                        </form>
                        <div class="mt-3 text-center" data-checkin-qr-output>
                            <div class="text-muted small">Scarica un QR da esporre al punto ritiro.</div>
                        </div>
                    </div>
                </div>
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start">
                        <div>
                            <h2 class="h5 mb-0">Segnalazioni clienti</h2>
                            <p class="text-muted small mb-0">Portale pickup</p>
                        </div>
                        <a class="btn btn-sm btn-outline-warning" href="reports.php">Gestisci</a>
                    </div>
                    <div class="card-body">
                        <?php if (($customerReportStats['pending_unlinked'] ?? 0) > 0): ?>
                            <div class="alert alert-warning py-2 px-3" role="alert">
                                <strong><?php echo (int) $customerReportStats['pending_unlinked']; ?></strong> segnalazioni in attesa di presa in carico
                            </div>
                        <?php endif; ?>
                        <div class="list-group list-group-flush" data-portal-report-log>
                            <?php if (!$pendingPortalReports): ?>
                                <div class="text-muted small">Nessuna segnalazione recente dal portale.</div>
                            <?php endif; ?>
                            <?php foreach ($pendingPortalReports as $report): ?>
                                <?php $meta = pickup_customer_report_status_meta($report['status']); ?>
                                <div class="list-group-item bg-transparent border-secondary-subtle text-body">
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div>
                                            <div class="fw-semibold">#<?php echo sanitize_output($report['tracking_code']); ?></div>
                                            <div class="small text-muted">Segnalato <?php echo sanitize_output(format_datetime_locale($report['created_at'] ?? '')); ?></div>
                                        </div>
                                        <span class="badge rounded-pill <?php echo sanitize_output($meta['badge']); ?>"><?php echo sanitize_output($meta['label']); ?></span>
                                    </div>
                                    <div class="small text-secondary mt-2">
                                        <?php
                                            $contactName = $report['customer_name'] ?? $report['recipient_name'] ?? '';
                                            echo $contactName !== '' ? sanitize_output($contactName) : 'Cliente anonimo';
                                        ?>
                                        <?php if (!empty($report['customer_phone'])): ?>
                                            · <a class="link-warning" href="tel:<?php echo sanitize_output(preg_replace('/[^0-9+]/', '', (string) $report['customer_phone'])); ?>">Chiama</a>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($report['notes'])): ?>
                                        <?php
                                            $notePreview = (string) $report['notes'];
                                            if (function_exists('mb_strimwidth')) {
                                                $notePreview = mb_strimwidth($notePreview, 0, 80, '…', 'UTF-8');
                                            } elseif (strlen($notePreview) > 80) {
                                                $notePreview = substr($notePreview, 0, 77) . '…';
                                            }
                                        ?>
                                        <div class="small text-muted mt-2"><?php echo sanitize_output($notePreview); ?></div>
                                    <?php endif; ?>
                                    <div class="d-flex flex-wrap gap-2 mt-3">
                                        <a class="btn btn-sm btn-outline-warning" href="report.php?id=<?php echo (int) $report['id']; ?>">Dettagli</a>
                                        <a class="btn btn-sm btn-warning text-dark" href="create.php?source_report=<?php echo (int) $report['id']; ?>">Crea pickup</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Invia notifica</h2>
                    </div>
                    <div class="card-body">
                        <form class="mb-4" method="post" action="index.php" data-pickup-notification-form>
                            <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                            <input type="hidden" name="action" value="send_notification">
                            <input type="hidden" name="channel" value="email">
                            <div class="mb-3">
                                <label class="form-label" for="email_package">Pacco</label>
                                <select class="form-select" id="email_package" name="package_id" required data-pickup-package-select>
                                    <option value="">Seleziona</option>
                                    <?php foreach ($packages as $package): ?>
                                        <option
                                            value="<?php echo (int) $package['id']; ?>"
                                            data-email="<?php echo sanitize_output($package['customer_email'] ?? ''); ?>"
                                            data-subject="<?php echo sanitize_output(pickup_email_subject_template($package)); ?>"
                                            data-message="<?php echo sanitize_output(pickup_email_message_template($package)); ?>"
                                        ><?php echo sanitize_output('#' . $package['tracking'] . ' - ' . $package['customer_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="email_recipient">Email destinatario</label>
                                <input class="form-control" id="email_recipient" name="recipient" type="email" placeholder="cliente@example.com" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="email_subject">Oggetto</label>
                                <input class="form-control" id="email_subject" name="subject" placeholder="Aggiornamento pickup">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="email_message">Messaggio</label>
                                <textarea class="form-control" id="email_message" name="message" rows="3" required></textarea>
                            </div>
                            <button class="btn btn-warning text-dark w-100" type="submit">Invia email</button>
                        </form>

                        <form method="post" action="index.php" data-pickup-notification-form>
                            <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                            <input type="hidden" name="action" value="send_notification">
                            <input type="hidden" name="channel" value="whatsapp">
                            <div class="mb-3">
                                <label class="form-label" for="whatsapp_package">Pacco</label>
                                <select class="form-select" id="whatsapp_package" name="package_id" required data-pickup-package-select>
                                    <option value="">Seleziona</option>
                                    <?php foreach ($packages as $package): ?>
                                        <option
                                            value="<?php echo (int) $package['id']; ?>"
                                            data-phone="<?php echo sanitize_output($package['customer_phone']); ?>"
                                            data-status="<?php echo sanitize_output($package['status']); ?>"
                                            data-message="<?php echo sanitize_output(pickup_whatsapp_message_template($package)); ?>"
                                        ><?php echo sanitize_output('#' . $package['tracking'] . ' - ' . $package['customer_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="whatsapp_recipient">Numero WhatsApp</label>
                                <input class="form-control" id="whatsapp_recipient" name="recipient" placeholder="+391234567890" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="whatsapp_message">Messaggio</label>
                                <textarea class="form-control" id="whatsapp_message" name="message" rows="3" required></textarea>
                            </div>
                            <button class="btn btn-outline-warning w-100" type="submit"><i class="fa-brands fa-whatsapp me-1"></i>Invia WhatsApp</button>
                        </form>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Ultime notifiche</h2>
                    </div>
                    <div class="card-body">
                        <div class="list-group list-group-flush" data-notification-log>
                            <?php if (!$notifications): ?>
                                <div class="text-muted small">Nessuna notifica registrata.</div>
                            <?php endif; ?>
                            <?php foreach ($notifications as $notification): ?>
                                <div class="list-group-item bg-transparent border-secondary-subtle text-body-secondary">
                                    <div class="d-flex justify-content-between">
                                        <span class="text-warning text-uppercase fw-semibold"><?php echo sanitize_output($notification['channel']); ?></span>
                                        <span class="small"><?php echo sanitize_output(format_datetime_locale($notification['created_at'] ?? '')); ?></span>
                                    </div>
                                    <div class="small text-secondary">Stato: <?php echo sanitize_output(ucfirst($notification['status'] ?? '')); ?></div>
                                    <div class="small text-secondary">Tracking #<?php echo sanitize_output($notification['tracking']); ?></div>
                                    <div class="small mt-2 text-body"><?php echo nl2br(sanitize_output($notification['message'])); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<div class="modal fade" id="pickupCheckinModal" tabindex="-1" aria-labelledby="pickupCheckinModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h1 class="modal-title fs-5" id="pickupCheckinModalLabel">Conferma ritiro</h1>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form method="post" action="checkin.php" id="pickupCheckinForm">
                    <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                    <div class="mb-3">
                        <label class="form-label" for="checkin_tracking">Tracking</label>
                        <input class="form-control" id="checkin_tracking" name="tracking" placeholder="Inserisci tracking" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="checkin_code">Codice OTP / QR</label>
                        <input class="form-control" id="checkin_code" name="code" placeholder="Codice OTP oppure link QR" required>
                        <small class="form-text text-muted">Incolla il codice OTP oppure scansiona il QR e inserisci il codice mostrato.</small>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="submit" form="pickupCheckinForm" class="btn btn-warning text-dark">Conferma ritiro</button>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
