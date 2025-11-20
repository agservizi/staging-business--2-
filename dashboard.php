<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/helpers.php';

$currentRole = $_SESSION['role'] ?? '';
if ($currentRole === 'Patronato') {
    header('Location: ' . base_url('modules/servizi/caf-patronato/index.php'));
    exit;
}

$pageTitle = 'Dashboard';
$view = $_GET['view'] ?? '';

$stats = [
    'totalClients' => 0,
    'servicesInProgress' => 0,
    'dailyRevenue' => 0.0,
    'openTickets' => [],
    'financePending' => 0,
    'energyContracts' => 0,
    'appointmentsToday' => 0,
    'anprInProgress' => 0,
    'emailSubscribers' => 0,
    'campaignsScheduled' => 0,
];

$statusConfig = get_appointment_status_config($pdo);
$activeAppointmentStatuses = $statusConfig['active'] ?: $statusConfig['available'];
if (!$activeAppointmentStatuses) {
    $activeAppointmentStatuses = ['Programmato', 'Confermato', 'In corso'];
}
$activeStatusPlaceholders = implode(', ', array_fill(0, count($activeAppointmentStatuses), '?'));

$charts = [
    'revenue' => [
        'labels' => [],
        'values' => [],
    ],
    'services' => [
        'labels' => [
            'Entrate/Uscite',
            'Appuntamenti',
            'Contratti energia',
            'Pratiche ANPR',
            'Visure catastali',
            'Progetti web',
            'Programma Fedeltà',
            'Curriculum',
            'Pickup logistica',
            'Spedizioni BRT',
            'Email marketing',
            'Email inviate',
        ],
        'values' => [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0],
    ],
];

$reminders = [];
$dashboardUsername = current_user_display_name();
$latestMovements = [];
$upcomingAppointmentsList = [];
$recentShipments = [];
$topFinanceClients = [];
$dueSoonMovements = [];
$scheduledCampaigns = [];

try {
    $stats['totalClients'] = (int) $pdo->query('SELECT COUNT(*) FROM clienti')->fetchColumn();

    $servicesInProgressSql = "SELECT COUNT(*) FROM (
            SELECT id FROM entrate_uscite WHERE stato IN ('In lavorazione', 'In attesa')
            UNION ALL
        SELECT id FROM servizi_appuntamenti WHERE stato IN ($activeStatusPlaceholders)
            UNION ALL
        SELECT id FROM curriculum WHERE status <> 'Archiviato'
            UNION ALL
        SELECT id FROM spedizioni WHERE stato IN ('Registrato', 'In attesa di ritiro', 'Problema', 'In corso', 'Aperto')
        ) AS in_progress";
    $servicesInProgressStmt = $pdo->prepare($servicesInProgressSql);
    $servicesInProgressStmt->execute($activeAppointmentStatuses);
    $stats['servicesInProgress'] = (int) $servicesInProgressStmt->fetchColumn();

    $dailyRevenueStmt = $pdo->prepare("SELECT COALESCE(SUM(importo), 0) FROM (
        SELECT CASE WHEN tipo_movimento = 'Entrata' THEN importo ELSE -importo END AS importo
        FROM entrate_uscite
        WHERE stato = 'Completato' AND DATE(COALESCE(data_pagamento, updated_at)) = CURRENT_DATE
    ) AS revenues");
    $dailyRevenueStmt->execute();
    $stats['dailyRevenue'] = (float) $dailyRevenueStmt->fetchColumn();

    $stats['financePending'] = (int) $pdo->query("SELECT COUNT(*) FROM entrate_uscite WHERE stato IN ('In lavorazione', 'In attesa')")->fetchColumn();

    $stats['energyContracts'] = (int) $pdo->query('SELECT COUNT(*) FROM energia_contratti')->fetchColumn();

    $appointmentsTodaySql = 'SELECT COUNT(*) FROM servizi_appuntamenti WHERE DATE(data_inizio) = CURRENT_DATE';
    if ($activeStatusPlaceholders !== '') {
        $appointmentsTodaySql .= ' AND stato IN (' . $activeStatusPlaceholders . ')';
        $appointmentsTodayStmt = $pdo->prepare($appointmentsTodaySql);
        $appointmentsTodayStmt->execute($activeAppointmentStatuses);
        $stats['appointmentsToday'] = (int) $appointmentsTodayStmt->fetchColumn();
    } else {
        $appointmentsTodayStmt = $pdo->query($appointmentsTodaySql);
        $stats['appointmentsToday'] = (int) $appointmentsTodayStmt->fetchColumn();
    }

    $stats['anprInProgress'] = (int) $pdo->query("SELECT COUNT(*) FROM anpr_pratiche WHERE stato = 'In lavorazione'")->fetchColumn();

    $ticketStmt = $pdo->prepare("SELECT id, titolo, stato, created_at FROM ticket ORDER BY created_at DESC LIMIT 5");
    $ticketStmt->execute();
    $stats['openTickets'] = $ticketStmt->fetchAll();

    $revenueChartStmt = $pdo->prepare("SELECT DATE_FORMAT(DATE(COALESCE(data_pagamento, updated_at, created_at)), '%Y-%m') AS month_key,
           SUM(CASE WHEN tipo_movimento = 'Entrata' THEN importo ELSE -importo END) AS totale
        FROM entrate_uscite
        WHERE stato = 'Completato'
          AND DATE(COALESCE(data_pagamento, updated_at, created_at)) >= DATE_FORMAT(DATE_SUB(CURRENT_DATE, INTERVAL 5 MONTH), '%Y-%m-01')
        GROUP BY month_key
        ORDER BY month_key");
    $revenueChartStmt->execute();

    $monthlyRevenue = [];
    while ($row = $revenueChartStmt->fetch(PDO::FETCH_ASSOC)) {
        $monthlyRevenue[$row['month_key']] = (float) $row['totale'];
    }

    $charts['revenue']['labels'] = [];
    $charts['revenue']['values'] = [];
    $startMonth = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
    $monthCursor = $startMonth;
    for ($i = 0; $i < 6; $i++) {
        $monthKey = $monthCursor->format('Y-m');
        $charts['revenue']['labels'][] = format_month_label($monthCursor);
        $charts['revenue']['values'][] = $monthlyRevenue[$monthKey] ?? 0.0;
        $monthCursor = $monthCursor->modify('+1 month');
    }

    $serviceTotals = [
        'entrate_uscite' => 0,
        'servizi_appuntamenti' => 0,
        'energia_contratti' => 0,
        'anpr_pratiche' => 0,
        'servizi_visure' => 0,
        'servizi_web_progetti' => 0,
        'fedelta_movimenti' => 0,
        'curriculum' => 0,
        'spedizioni' => 0,
        'brt_shipments' => 0,
        'email_campaigns' => 0,
        'email_campaign_recipients' => 0,
    ];

    foreach ($serviceTotals as $table => &$value) {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM {$table}");
            $value = (int) $stmt->fetchColumn();
        } catch (PDOException $serviceException) {
            error_log('Dashboard service count failed for ' . $table . ': ' . $serviceException->getMessage());
            $value = 0;
        }
    }
    unset($value);

    try {
        $brtCountStmt = $pdo->query("SELECT COUNT(*) FROM brt_shipments WHERE deleted_at IS NULL");
        $serviceTotals['brt_shipments'] = (int) $brtCountStmt->fetchColumn();
    } catch (PDOException $brtException) {
        error_log('Dashboard BRT shipment count failed: ' . $brtException->getMessage());
        $serviceTotals['brt_shipments'] = 0;
    }

    try {
        $sentRecipientsStmt = $pdo->query("SELECT COUNT(*) FROM email_campaign_recipients WHERE status = 'sent'");
        $serviceTotals['email_campaign_recipients'] = (int) $sentRecipientsStmt->fetchColumn();
    } catch (PDOException $sentException) {
        error_log('Dashboard sent email count failed: ' . $sentException->getMessage());
        $serviceTotals['email_campaign_recipients'] = 0;
    }

    $charts['services']['values'] = array_values($serviceTotals);

    $serviceBreakdown = [];
    $serviceBreakdownTotal = array_sum($charts['services']['values']);
    foreach ($charts['services']['labels'] as $index => $label) {
        $value = $charts['services']['values'][$index] ?? 0;
        $percentage = $serviceBreakdownTotal > 0 ? ($value / $serviceBreakdownTotal) * 100 : 0;
        $serviceBreakdown[] = [
            'label' => $label,
            'value' => $value,
            'percentage' => $percentage,
        ];
    }

    $serviceBreakdownTop = $serviceBreakdown;
    usort($serviceBreakdownTop, static function (array $a, array $b): int {
        return $b['value'] <=> $a['value'];
    });
    $serviceBreakdownTop = array_slice($serviceBreakdownTop, 0, 5);

    try {
        $latestMovementsStmt = $pdo->query("SELECT id, descrizione, tipo_movimento, importo, stato, cliente_id, COALESCE(data_pagamento, data_scadenza, updated_at, created_at) AS movimento_data FROM entrate_uscite ORDER BY COALESCE(data_pagamento, updated_at, created_at) DESC LIMIT 6");
        if ($latestMovementsStmt) {
            $latestMovements = $latestMovementsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (PDOException $latestMovementsException) {
        error_log('Dashboard latest movements failed: ' . $latestMovementsException->getMessage());
    }

    try {
        $upcomingAppointmentsSql = "SELECT a.id, a.titolo, a.data_inizio, a.stato, COALESCE(NULLIF(c.ragione_sociale, ''), CONCAT(c.nome, ' ', c.cognome)) AS cliente_nome FROM servizi_appuntamenti a LEFT JOIN clienti c ON c.id = a.cliente_id WHERE a.data_inizio >= NOW() ORDER BY a.data_inizio ASC LIMIT 6";
        $upcomingAppointmentsStmt = $pdo->query($upcomingAppointmentsSql);
        if ($upcomingAppointmentsStmt) {
            $upcomingAppointmentsList = $upcomingAppointmentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (PDOException $upcomingAppointmentsException) {
        error_log('Dashboard upcoming appointments failed: ' . $upcomingAppointmentsException->getMessage());
    }

    try {
        $recentShipmentsStmt = $pdo->query("SELECT id, consignee_name, status, confirmed_at, created_at, numeric_sender_reference FROM brt_shipments WHERE deleted_at IS NULL ORDER BY COALESCE(confirmed_at, created_at) DESC LIMIT 6");
        if ($recentShipmentsStmt) {
            $recentShipments = $recentShipmentsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (PDOException $recentShipmentsException) {
        error_log('Dashboard recent BRT shipments failed: ' . $recentShipmentsException->getMessage());
    }

    try {
        $dueSoonMovementsSql = "SELECT id, descrizione, tipo_movimento, importo, stato, data_scadenza FROM entrate_uscite WHERE stato IN ('In lavorazione', 'In attesa') AND data_scadenza IS NOT NULL AND data_scadenza BETWEEN CURRENT_DATE AND DATE_ADD(CURRENT_DATE, INTERVAL 7 DAY) ORDER BY data_scadenza ASC LIMIT 6";
        $dueSoonMovementsStmt = $pdo->query($dueSoonMovementsSql);
        if ($dueSoonMovementsStmt) {
            $dueSoonMovements = $dueSoonMovementsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (PDOException $dueSoonMovementsException) {
        error_log('Dashboard due movements stat failed: ' . $dueSoonMovementsException->getMessage());
    }

    try {
        $topClientsSql = "SELECT ranked.* FROM (
                SELECT 
                    c.id,
                    COALESCE(NULLIF(c.ragione_sociale, ''), CONCAT(c.nome, ' ', c.cognome)) AS cliente_nome,
                    SUM(CASE WHEN eu.tipo_movimento = 'Entrata' THEN eu.importo ELSE 0 END) AS totale_entrate,
                    SUM(CASE WHEN eu.tipo_movimento = 'Uscita' THEN eu.importo ELSE 0 END) AS totale_uscite
                FROM entrate_uscite eu
                LEFT JOIN clienti c ON c.id = eu.cliente_id
                WHERE eu.cliente_id IS NOT NULL
                  AND YEAR(COALESCE(eu.data_pagamento, eu.created_at)) = YEAR(CURRENT_DATE)
                GROUP BY c.id, cliente_nome
            ) AS ranked
            ORDER BY (ranked.totale_entrate - ranked.totale_uscite) DESC
            LIMIT 5";
        $topClientsStmt = $pdo->prepare($topClientsSql);
        $topClientsStmt->execute();
        $topFinanceClients = $topClientsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $topClientsException) {
        error_log('Dashboard top clients stat failed: ' . $topClientsException->getMessage());
    }

    try {
        $scheduledCampaignsSql = "SELECT id, name, status, scheduled_at, updated_at FROM email_campaigns WHERE status = 'scheduled' ORDER BY scheduled_at ASC LIMIT 6";
        $scheduledCampaignsStmt = $pdo->query($scheduledCampaignsSql);
        if ($scheduledCampaignsStmt) {
            $scheduledCampaigns = $scheduledCampaignsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (PDOException $scheduledCampaignsException) {
        error_log('Dashboard scheduled campaigns stat failed: ' . $scheduledCampaignsException->getMessage());
    }

    try {
        $stats['emailSubscribers'] = (int) $pdo->query("SELECT COUNT(*) FROM email_subscribers WHERE status = 'active'")->fetchColumn();
    } catch (PDOException $emailStatsException) {
        error_log('Dashboard email subscriber stat failed: ' . $emailStatsException->getMessage());
    }

    try {
        $stats['campaignsScheduled'] = (int) $pdo->query("SELECT COUNT(*) FROM email_campaigns WHERE status IN ('draft','scheduled')")->fetchColumn();
    } catch (PDOException $campaignStatException) {
        error_log('Dashboard campaign stat failed: ' . $campaignStatException->getMessage());
    }

    try {
        $pendingCampaignsStmt = $pdo->query("SELECT id, name, status, scheduled_at, updated_at FROM email_campaigns WHERE status IN ('draft','scheduled') ORDER BY COALESCE(scheduled_at, updated_at) ASC LIMIT 1");
        if ($pendingCampaign = $pendingCampaignsStmt->fetch()) {
            $statusLabel = $pendingCampaign['status'] === 'scheduled' ? 'programmata' : 'in bozza';
            $scheduleInfo = $pendingCampaign['scheduled_at'] ? 'Invio previsto: ' . format_datetime($pendingCampaign['scheduled_at']) : 'Non ancora programmata';
            $reminders[] = [
                'icon' => 'fa-envelope-open-text',
                'title' => 'Campagna email da seguire',
                'detail' => sprintf('%s (%s). %s.', $pendingCampaign['name'] ?: ('Campagna #' . $pendingCampaign['id']), $statusLabel, $scheduleInfo),
                'url' => base_url('modules/email-marketing/view.php?id=' . (int) $pendingCampaign['id']),
            ];
        }
    } catch (PDOException $emailReminderException) {
        error_log('Dashboard email marketing reminder failed: ' . $emailReminderException->getMessage());
    }

    $oldestTicketStmt = $pdo->prepare("SELECT id, titolo, created_at FROM ticket WHERE stato IN ('Aperto', 'In corso') ORDER BY created_at ASC LIMIT 1");
    $oldestTicketStmt->execute();
    if ($oldestTicket = $oldestTicketStmt->fetch()) {
        $reminders[] = [
            'icon' => 'fa-life-ring',
            'title' => 'Ticket da prendere in carico',
            'detail' => sprintf('Ticket #%d aperto il %s.', $oldestTicket['id'], format_datetime($oldestTicket['created_at'] ?? '')),
            'url' => base_url('modules/ticket/view.php?id=' . $oldestTicket['id']),
        ];
    }

    $pendingMovimentiStmt = $pdo->prepare("SELECT id, descrizione, stato, tipo_movimento, data_scadenza, updated_at FROM entrate_uscite WHERE stato IN ('In lavorazione', 'In attesa') ORDER BY COALESCE(data_scadenza, updated_at) ASC LIMIT 1");
    $pendingMovimentiStmt->execute();
    if ($pendingMovimento = $pendingMovimentiStmt->fetch()) {
        $movimentoLabel = $pendingMovimento['tipo_movimento'] ?? 'Entrata';
        $icon = $movimentoLabel === 'Uscita' ? 'fa-arrow-trend-down' : 'fa-arrow-trend-up';
        $reminders[] = [
            'icon' => $icon,
            'title' => sprintf('%s da completare', $movimentoLabel),
            'detail' => sprintf('%s in stato %s. Scadenza %s.',
                $pendingMovimento['descrizione'] ?: ($movimentoLabel . ' #' . $pendingMovimento['id']),
                strtoupper($pendingMovimento['stato'] ?? ''),
                $pendingMovimento['data_scadenza'] ? format_datetime($pendingMovimento['data_scadenza'], 'd/m/Y') : 'N/D'
            ),
            'url' => base_url('modules/servizi/entrate-uscite/view.php?id=' . $pendingMovimento['id']),
        ];
    }
} catch (PDOException $e) {
    error_log('Dashboard query failed: ' . $e->getMessage());
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>
    <main class="content-wrapper" data-dashboard-root data-dashboard-endpoint="api/dashboard.php" data-refresh-interval="60000">
            <style>
                .chart-card-body {
                    min-height: 220px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .chart-canvas {
                    max-height: 180px;
                    width: 100%;
                }
                .revenue-chart-canvas {
                    max-height: 260px;
                }
                .service-chart-canvas {
                    max-height: 320px;
                }
                .services-card-body {
                    justify-content: space-between;
                    align-items: stretch;
                    gap: 1.5rem;
                }
                .services-card-body .service-chart-column {
                    flex: 0 0 48%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                }
                .services-card-body .service-details-column {
                    flex: 1;
                    display: flex;
                    flex-direction: column;
                    justify-content: flex-start;
                    gap: 0.75rem;
                }
                .service-breakdown-list {
                    display: flex;
                    flex-direction: column;
                    gap: 0.75rem;
                }
                .service-breakdown-item {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding-bottom: 0.35rem;
                    border-bottom: 1px dashed rgba(0, 0, 0, 0.08);
                }
                .service-breakdown-item:last-child {
                    border-bottom: none;
                    padding-bottom: 0;
                }
                @media (max-width: 991.98px) {
                    .services-card-body {
                        flex-direction: column;
                        align-items: stretch;
                    }
                    .services-card-body .service-chart-column {
                        flex: 1 1 auto;
                    }
                }
            </style>
        <?php if ($view === 'cliente' && $_SESSION['role'] === 'Cliente'): ?>
            <div class="row g-4 mb-4">
                <div class="col-12">
                    <div class="card ag-card h-100">
                        <div class="card-body">
                            <h5 class="card-title">Benvenuto</h5>
                            <p class="mb-0">Consulta lo stato delle tue pratiche, scarica documenti e invia richieste di supporto.
                                Usa il menu per navigare.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        <?php else: ?>

            <div class="card ag-card dashboard-hero mb-4">
                <div class="card-body d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-4">
                    <div class="hero-copy">
                        <div class="badge ag-badge mb-3"><i class="fa-solid fa-gauge-high me-2"></i>Dashboard</div>
                        <h2 class="hero-title mb-2">Bentornato, <?php echo sanitize_output($dashboardUsername); ?></h2>
                        <p class="hero-subtitle mb-0">Consulta gli indicatori chiave e usa la barra di ricerca in testata per trovare subito ciò che ti serve.</p>
                    </div>
                    <div class="hero-kpi-grid">
                        <div class="hero-kpi">
                            <div class="hero-kpi-icon hero-kpi-icon-clients"><i class="fa-solid fa-users"></i></div>
                            <div class="hero-kpi-body">
                                <span class="hero-kpi-label">Clienti attivi</span>
                                <span class="hero-kpi-value" data-dashboard-stat="totalClients" data-format="number"><?php echo number_format($stats['totalClients']); ?></span>
                            </div>
                        </div>
                        <div class="hero-kpi">
                            <div class="hero-kpi-icon hero-kpi-icon-services"><i class="fa-solid fa-diagram-project"></i></div>
                            <div class="hero-kpi-body">
                                <span class="hero-kpi-label">Servizi in corso</span>
                                <span class="hero-kpi-value" data-dashboard-stat="servicesInProgress" data-format="number"><?php echo number_format($stats['servicesInProgress']); ?></span>
                            </div>
                        </div>
                        <div class="hero-kpi">
                            <div class="hero-kpi-icon hero-kpi-icon-revenue"><i class="fa-solid fa-sack-dollar"></i></div>
                            <div class="hero-kpi-body">
                                <span class="hero-kpi-label">Saldo oggi</span>
                                <span class="hero-kpi-value" data-dashboard-stat="dailyRevenue" data-format="currency"><?php echo sanitize_output(format_currency($stats['dailyRevenue'])); ?></span>
                            </div>
                        </div>
                        <div class="hero-kpi">
                            <div class="hero-kpi-icon hero-kpi-icon-subscribers"><i class="fa-solid fa-envelope-open"></i></div>
                            <div class="hero-kpi-body">
                                <span class="hero-kpi-label">Iscritti attivi</span>
                                <span class="hero-kpi-value" data-dashboard-stat="emailSubscribers" data-format="number"><?php echo number_format($stats['emailSubscribers']); ?></span>
                            </div>
                        </div>
                        <div class="hero-kpi">
                            <div class="hero-kpi-icon hero-kpi-icon-campaigns"><i class="fa-solid fa-paper-plane"></i></div>
                            <div class="hero-kpi-body">
                                <span class="hero-kpi-label">Campagne da inviare</span>
                                <span class="hero-kpi-value" data-dashboard-stat="campaignsScheduled" data-format="number"><?php echo number_format($stats['campaignsScheduled']); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="dashboard-status alert alert-warning align-items-center gap-2 mb-4" id="dashboardStatus" role="status" hidden>
                <i class="fa-solid fa-circle-exclamation"></i>
                <span class="dashboard-status-text"></span>
                <button class="btn btn-sm btn-outline-warning ms-auto" type="button" id="dashboardRetry" hidden>Riprova</button>
            </div>

            <div class="card ag-card mb-4">
                <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <div>
                        <h5 class="card-title mb-0">Indicatori principali</h5>
                        <small class="text-muted">Visione rapida delle metriche chiave</small>
                    </div>
                    <span class="badge ag-badge text-uppercase">Agg. ogni 60s</span>
                </div>
                <div class="card-body">
                    <div class="dashboard-summary-grid">
                        <div class="summary-tile">
                            <div class="summary-icon summary-icon-revenue"><i class="fa-solid fa-coins"></i></div>
                            <div class="summary-content">
                                <p class="summary-label mb-1">Entrate/Uscite aperte</p>
                                <div class="summary-value" data-dashboard-stat="financePending" data-format="number"><?php echo number_format($stats['financePending']); ?></div>
                                <small class="text-muted">Movimenti da chiudere</small>
                            </div>
                        </div>
                        <div class="summary-tile">
                            <div class="summary-icon summary-icon-services"><i class="fa-solid fa-bolt"></i></div>
                            <div class="summary-content">
                                <p class="summary-label mb-1">Contratti energia</p>
                                <div class="summary-value" data-dashboard-stat="energyContracts" data-format="number"><?php echo number_format($stats['energyContracts']); ?></div>
                                <small class="text-muted">Totale caricati</small>
                            </div>
                        </div>
                        <div class="summary-tile">
                            <div class="summary-icon summary-icon-clients"><i class="fa-solid fa-calendar-check"></i></div>
                            <div class="summary-content">
                                <p class="summary-label mb-1">Appuntamenti oggi</p>
                                <div class="summary-value" data-dashboard-stat="appointmentsToday" data-format="number"><?php echo number_format($stats['appointmentsToday']); ?></div>
                                <small class="text-muted">Programmato / in corso</small>
                            </div>
                        </div>
                        <div class="summary-tile">
                            <div class="summary-icon summary-icon-tickets"><i class="fa-solid fa-file-signature"></i></div>
                            <div class="summary-content">
                                <p class="summary-label mb-1">Pratiche ANPR</p>
                                <div class="summary-value" data-dashboard-stat="anprInProgress" data-format="number"><?php echo number_format($stats['anprInProgress']); ?></div>
                                <small class="text-muted">In lavorazione</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-12 col-xl-6">
                    <div class="card ag-card h-100">
                        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Trend Entrate/Uscite</h5>
                            <span class="text-muted small">Ultimi 6 mesi</span>
                        </div>
                        <div class="card-body chart-card-body">
                            <canvas id="chartRevenue" class="chart-canvas revenue-chart-canvas" height="260"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-6">
                    <div class="card ag-card h-100">
                        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">Ripartizione servizi</h5>
                            <span class="text-muted small">Pratiche per tipologia</span>
                        </div>
                        <div class="card-body chart-card-body services-card-body">
                            <div class="service-chart-column">
                                <canvas id="chartServices" class="chart-canvas service-chart-canvas" height="320"></canvas>
                            </div>
                            <div class="service-details-column">
                                <p class="text-muted text-uppercase small mb-1">Totale pratiche monitorate</p>
                                <div class="h3 mb-3 fw-semibold"><?php echo number_format($serviceBreakdownTotal); ?></div>
                                <?php if (!empty($serviceBreakdownTop)): ?>
                                    <div class="service-breakdown-list">
                                        <?php foreach ($serviceBreakdownTop as $serviceItem): ?>
                                            <div class="service-breakdown-item">
                                                <div>
                                                    <div class="fw-semibold"><?php echo sanitize_output($serviceItem['label']); ?></div>
                                                    <small class="text-muted"><?php echo number_format($serviceItem['percentage'], 1, ',', '.'); ?>% del totale</small>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge bg-light text-dark"><?php echo number_format($serviceItem['value']); ?></span>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Nessun dato disponibile.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-12 col-xxl-4">
                    <div class="card ag-card h-100">
                        <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between">
                            <h5 class="card-title mb-0">Movimenti recenti</h5>
                            <a class="btn btn-sm btn-outline-warning" href="modules/servizi/entrate-uscite/index.php">Gestisci</a>
                        </div>
                        <div class="card-body">
                            <?php if ($latestMovements): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($latestMovements as $movement): ?>
                                        <?php
                                            $isIncome = ($movement['tipo_movimento'] ?? '') === 'Entrata';
                                            $movementAmount = (float) ($movement['importo'] ?? 0);
                                            $amountLabel = format_currency($movementAmount);
                                            $movementDateValue = $movement['movimento_data'] ?? null;
                                            $movementDateLabel = $movementDateValue ? format_datetime($movementDateValue, 'd/m/Y') : 'N/D';
                                            $movementStatus = strtoupper($movement['stato'] ?? '');
                                            $movementTitle = $movement['descrizione'] ?: (($movement['tipo_movimento'] ?? 'Movimento') . ' #' . $movement['id']);
                                            $iconClass = $isIncome ? 'fa-arrow-trend-up text-success' : 'fa-arrow-trend-down text-danger';
                                        ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex align-items-center justify-content-between gap-3">
                                                <div class="d-flex align-items-center gap-3">
                                                    <span class="badge ag-badge flex-shrink-0"><i class="fa-solid <?php echo $iconClass; ?>"></i></span>
                                                    <div>
                                                        <div class="fw-semibold"><?php echo sanitize_output($movementTitle); ?></div>
                                                        <small class="text-muted"><?php echo sanitize_output($movementDateLabel); ?> · <?php echo sanitize_output($movementStatus); ?></small>
                                                    </div>
                                                </div>
                                                <div class="text-end">
                                                    <span class="fw-semibold <?php echo $isIncome ? 'text-success' : 'text-danger'; ?>"><?php echo sanitize_output($amountLabel); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">Nessun movimento registrato negli ultimi giorni.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xxl-4">
                    <div class="card ag-card h-100">
                        <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between">
                            <h5 class="card-title mb-0">Prossimi appuntamenti</h5>
                            <a class="btn btn-sm btn-outline-warning" href="modules/servizi/appuntamenti/index.php">Agenda</a>
                        </div>
                        <div class="card-body">
                            <?php if ($upcomingAppointmentsList): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($upcomingAppointmentsList as $appointment): ?>
                                        <?php
                                            $startValue = $appointment['data_inizio'] ?? null;
                                            $startLabel = $startValue ? format_datetime($startValue, 'd/m/Y H:i') : 'N/D';
                                            $appointmentTitle = $appointment['titolo'] ?: ('Appuntamento #' . $appointment['id']);
                                            $clientLabel = $appointment['cliente_nome'] ?: 'Cliente non assegnato';
                                            $statusLabel = $appointment['stato'] ?? '';
                                        ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex align-items-start justify-content-between gap-3">
                                                <div>
                                                    <div class="fw-semibold"><?php echo sanitize_output($appointmentTitle); ?></div>
                                                    <small class="text-muted"><?php echo sanitize_output($startLabel); ?> · <?php echo sanitize_output($clientLabel); ?></small>
                                                </div>
                                                <span class="badge ag-badge text-uppercase flex-shrink-0"><?php echo sanitize_output($statusLabel); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">Non ci sono appuntamenti in agenda nelle prossime ore.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xxl-4">
                    <div class="card ag-card h-100">
                        <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between">
                            <h5 class="card-title mb-0">Spedizioni BRT recenti</h5>
                            <a class="btn btn-sm btn-outline-warning" href="modules/servizi/brt/index.php">Dettagli</a>
                        </div>
                        <div class="card-body">
                            <?php if ($recentShipments): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($recentShipments as $shipment): ?>
                                        <?php
                                            $statusRaw = strtolower($shipment['status'] ?? '');
                                            $statusMap = [
                                                'created' => 'Creata',
                                                'confirmed' => 'Confermata',
                                                'warning' => 'Con avvisi',
                                                'cancelled' => 'Annullata',
                                            ];
                                            $statusLabel = $statusMap[$statusRaw] ?? ucfirst($statusRaw);
                                            $statusClassMap = [
                                                'created' => 'text-warning',
                                                'confirmed' => 'text-success',
                                                'warning' => 'text-danger',
                                                'cancelled' => 'text-muted',
                                            ];
                                            $statusClass = $statusClassMap[$statusRaw] ?? 'text-body';
                                            $shipmentWhen = $shipment['confirmed_at'] ?: ($shipment['created_at'] ?? null);
                                            $shipmentWhenLabel = $shipmentWhen ? format_datetime($shipmentWhen, 'd/m/Y H:i') : 'N/D';
                                            $consigneeLabel = $shipment['consignee_name'] ?: 'Destinatario non indicato';
                                            $referenceLabel = $shipment['numeric_sender_reference'] ? '#' . $shipment['numeric_sender_reference'] : '#' . $shipment['id'];
                                        ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex align-items-start justify-content-between gap-3">
                                                <div>
                                                    <div class="fw-semibold"><?php echo sanitize_output($consigneeLabel); ?></div>
                                                    <small class="text-muted"><?php echo sanitize_output($referenceLabel); ?> · <?php echo sanitize_output($shipmentWhenLabel); ?></small>
                                                </div>
                                                <span class="badge ag-badge text-uppercase <?php echo $statusClass; ?> flex-shrink-0"><?php echo sanitize_output($statusLabel); ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">Nessuna spedizione registrata di recente.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4 mb-4">
                <div class="col-12 col-xl-6">
                    <div class="card ag-card h-100">
                        <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between">
                            <h5 class="card-title mb-0">Scadenze contabili (7 giorni)</h5>
                            <a class="btn btn-sm btn-outline-warning" href="modules/servizi/entrate-uscite/index.php">Situazione</a>
                        </div>
                        <div class="card-body">
                            <?php if ($dueSoonMovements): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($dueSoonMovements as $movement): ?>
                                        <?php
                                            $dueDateValue = $movement['data_scadenza'] ?? null;
                                            $dueDateLabel = $dueDateValue ? format_datetime($dueDateValue, 'd/m/Y') : 'N/D';
                                            $movementTitle = $movement['descrizione'] ?: (($movement['tipo_movimento'] ?? 'Movimento') . ' #' . $movement['id']);
                                            $movementAmount = (float) ($movement['importo'] ?? 0);
                                            $amountLabel = format_currency($movementAmount);
                                            $isIncome = ($movement['tipo_movimento'] ?? '') === 'Entrata';
                                        ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex align-items-start justify-content-between gap-3">
                                                <div>
                                                    <div class="fw-semibold"><?php echo sanitize_output($movementTitle); ?></div>
                                                    <small class="text-muted">Scadenza: <?php echo sanitize_output($dueDateLabel); ?> · <?php echo sanitize_output(strtoupper($movement['stato'] ?? '')); ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <span class="fw-semibold <?php echo $isIncome ? 'text-success' : 'text-danger'; ?>"><?php echo sanitize_output($amountLabel); ?></span>
                                                    <div><a class="link-warning small" href="modules/servizi/entrate-uscite/view.php?id=<?php echo (int) $movement['id']; ?>">Apri</a></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">Non ci sono scadenze contabili nella prossima settimana.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-6">
                    <div class="card ag-card h-100">
                        <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between">
                            <h5 class="card-title mb-0">Campagne email programmate</h5>
                            <a class="btn btn-sm btn-outline-warning" href="modules/email-marketing/index.php">Pianifica</a>
                        </div>
                        <div class="card-body">
                            <?php if ($scheduledCampaigns): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($scheduledCampaigns as $campaign): ?>
                                        <?php
                                            $campaignName = $campaign['name'] ?: ('Campagna #' . $campaign['id']);
                                            $scheduledValue = $campaign['scheduled_at'] ?? null;
                                            $scheduledLabel = $scheduledValue ? format_datetime($scheduledValue, 'd/m/Y H:i') : 'Non programmata';
                                            $statusLabel = strtoupper($campaign['status'] ?? '');
                                        ?>
                                        <div class="list-group-item px-0">
                                            <div class="d-flex align-items-start justify-content-between gap-3">
                                                <div>
                                                    <div class="fw-semibold"><?php echo sanitize_output($campaignName); ?></div>
                                                    <small class="text-muted">Invio previsto: <?php echo sanitize_output($scheduledLabel); ?></small>
                                                </div>
                                                <div class="text-end">
                                                    <span class="badge ag-badge text-uppercase"><?php echo sanitize_output($statusLabel); ?></span>
                                                    <div><a class="link-warning small" href="modules/email-marketing/view.php?id=<?php echo (int) $campaign['id']; ?>">Dettagli</a></div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <p class="text-muted mb-0">Nessuna campagna programmata al momento.</p>
                                <?php if ($stats['campaignsScheduled'] > 0): ?>
                                    <small class="text-muted">Controlla le bozze per completare la pianificazione.</small>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-12 col-xxl-7">
                    <div class="card ag-card h-100">
                        <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between">
                            <h5 class="card-title mb-0">Ticket in evidenza</h5>
                            <a class="btn btn-sm btn-outline-warning" href="modules/ticket/index.php">Vedi tutti</a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover align-middle mb-0" data-dashboard-table="tickets">
                                    <thead>
                                        <tr>
                                            <th>ID</th>
                                            <th>Titolo</th>
                                            <th>Stato</th>
                                            <th>Aperto il</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody id="dashboardTicketsBody">
                                        <?php if ($stats['openTickets']): ?>
                                            <?php foreach ($stats['openTickets'] as $ticket): ?>
                                                <?php $ticketDate = $ticket['created_at'] ?? null; ?>
                                                <tr>
                                                    <td>#<?php echo sanitize_output($ticket['id']); ?></td>
                                                    <td><?php echo sanitize_output($ticket['titolo']); ?></td>
                                                    <td><span class="badge ag-badge text-uppercase"><?php echo sanitize_output($ticket['stato']); ?></span></td>
                                                    <td><?php echo sanitize_output($ticketDate ? format_datetime($ticketDate, 'd/m/Y') : 'N/D'); ?></td>
                                                    <td class="text-end"><a class="btn btn-sm btn-outline-warning" href="modules/ticket/view.php?id=<?php echo (int) $ticket['id']; ?>">Apri</a></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="5" class="text-center text-muted py-4">Nessun ticket disponibile.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xxl-5">
                    <div class="d-flex flex-column gap-4 h-100">
                        <div class="card ag-card flex-fill">
                            <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between">
                                <h5 class="card-title mb-0">Attività prioritarie</h5>
                                <a class="btn btn-sm btn-link text-decoration-none" href="modules/servizi/entrate-uscite/index.php">
                                    <i class="fa-solid fa-list-check me-1"></i>Vai alla sezione
                                </a>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0 reminder-list" id="dashboardReminders">
                                    <?php if ($reminders): ?>
                                        <?php foreach ($reminders as $reminder): ?>
                                            <li class="reminder-item d-flex align-items-start">
                                                <span class="badge ag-badge me-3"><i class="fa-solid <?php echo $reminder['icon']; ?>"></i></span>
                                                <div>
                                                    <div class="fw-semibold">
                                                        <?php if (!empty($reminder['url'])): ?>
                                                            <a class="link-warning" href="<?php echo sanitize_output($reminder['url']); ?>"><?php echo sanitize_output($reminder['title']); ?></a>
                                                        <?php else: ?>
                                                            <?php echo sanitize_output($reminder['title']); ?>
                                                        <?php endif; ?>
                                                    </div>
                                                    <small class="text-muted"><?php echo sanitize_output($reminder['detail']); ?></small>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <li class="text-muted">Nessun promemoria attivo.</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                        <div class="card ag-card flex-fill">
                            <div class="card-header bg-transparent border-0">
                                <h5 class="card-title mb-0">Clienti da monitorare</h5>
                                <small class="text-muted">Bilancio anno in corso</small>
                            </div>
                            <div class="card-body p-0">
                                <?php if ($topFinanceClients): ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover align-middle mb-0">
                                            <thead>
                                                <tr>
                                                    <th>Cliente</th>
                                                    <th class="text-end">Entrate</th>
                                                    <th class="text-end">Uscite</th>
                                                    <th class="text-end">Saldo</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($topFinanceClients as $client): ?>
                                                    <?php
                                                        $entrate = (float) ($client['totale_entrate'] ?? 0);
                                                        $uscite = (float) ($client['totale_uscite'] ?? 0);
                                                        $saldo = $entrate - $uscite;
                                                        $clientName = $client['cliente_nome'] ?: ('Cliente #' . $client['id']);
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <a class="link-warning" href="modules/clienti/view.php?id=<?php echo (int) $client['id']; ?>"><?php echo sanitize_output($clientName); ?></a>
                                                        </td>
                                                        <td class="text-end text-success"><?php echo sanitize_output(format_currency($entrate)); ?></td>
                                                        <td class="text-end text-danger"><?php echo sanitize_output(format_currency($uscite)); ?></td>
                                                        <td class="text-end <?php echo $saldo >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo sanitize_output(format_currency($saldo)); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="p-4 text-muted">Nessun cliente con movimenti registrati quest'anno.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script>
    const rootStyle = getComputedStyle(document.documentElement);
    const accentColor = (rootStyle.getPropertyValue('--ag-accent') || '#0b2f6b').trim() || '#0b2f6b';
    const accentRgb = (rootStyle.getPropertyValue('--ag-accent-rgb') || '11, 47, 107').trim() || '11, 47, 107';
    const accentAlpha = (alpha) => `rgba(${accentRgb}, ${alpha})`;

    const revenueChartData = {
        labels: <?php echo json_encode($charts['revenue']['labels'], JSON_THROW_ON_ERROR); ?>,
        datasets: [{
            label: 'Saldo',
            data: <?php echo json_encode($charts['revenue']['values'], JSON_THROW_ON_ERROR); ?>,
            borderColor: accentColor,
            backgroundColor: accentAlpha(0.14),
            tension: 0.4,
            fill: true,
        }]
    };

    const serviceChartData = {
        labels: <?php echo json_encode($charts['services']['labels'], JSON_THROW_ON_ERROR); ?>,
        datasets: [{
            label: 'Totale pratiche',
            data: <?php echo json_encode($charts['services']['values'], JSON_THROW_ON_ERROR); ?>,
            backgroundColor: [],
            borderColor: accentColor
        }]
    };

    serviceChartData.datasets[0].backgroundColor = serviceChartData.labels.map((_, index) => {
        const start = 0.42;
        const step = 0.035;
        const alpha = Math.max(0.12, start - (step * index));
        return accentAlpha(alpha);
    });

    document.addEventListener('DOMContentLoaded', () => {
        const revenueCtx = document.getElementById('chartRevenue');
        const servicesCtx = document.getElementById('chartServices');
        const chartStore = window.CSCharts || (window.CSCharts = {});
        const chartLib = window.Chart;
        if (revenueCtx && chartLib) {
            const existing = typeof chartLib.getChart === 'function' ? chartLib.getChart(revenueCtx) : (revenueCtx.chart || revenueCtx._chart || null);
            if (existing && typeof existing.destroy === 'function') {
                existing.destroy();
            }
            chartStore.revenue = new chartLib(revenueCtx, {
                type: 'line',
                data: revenueChartData,
                options: {
                    plugins: { legend: { display: false } },
                    scales: {
                        y: {
                            ticks: {
                                callback: (value) => `€ ${value.toLocaleString('it-IT', { minimumFractionDigits: 2 })}`
                            }
                        }
                    }
                }
            });
        }
        if (servicesCtx && chartLib) {
            const existing = typeof chartLib.getChart === 'function' ? chartLib.getChart(servicesCtx) : (servicesCtx.chart || servicesCtx._chart || null);
            if (existing && typeof existing.destroy === 'function') {
                existing.destroy();
            }
            chartStore.services = new chartLib(servicesCtx, {
                type: 'doughnut',
                data: serviceChartData,
                options: {
                    plugins: { legend: { position: 'bottom' } }
                }
            });
        }
    });
</script>
