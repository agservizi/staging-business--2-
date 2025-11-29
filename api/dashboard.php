<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/helpers.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$response = [
    'stats' => [
        'totalClients' => 0,
        'servicesInProgress' => 0,
        'dailyRevenue' => 0.0,
        'openTickets' => 0,
        'financePending' => 0,
        'energyContracts' => 0,
        'appointmentsToday' => 0,
    'anprInProgress' => 0,
    'emailSubscribers' => 0,
    'campaignsScheduled' => 0,
    ],
    'charts' => [
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
    ],
    'tickets' => [],
    'reminders' => [],
];

$statusConfig = get_appointment_status_config($pdo);
$activeAppointmentStatuses = $statusConfig['active'] ?: $statusConfig['available'];
if (!$activeAppointmentStatuses) {
    $activeAppointmentStatuses = ['Programmato', 'Confermato', 'In corso'];
}
$activeStatusPlaceholders = implode(', ', array_fill(0, count($activeAppointmentStatuses), '?'));

try {
    $response['stats']['totalClients'] = (int) $pdo->query('SELECT COUNT(*) FROM clienti')->fetchColumn();

    $servicesInProgressParams = [];
    if ($activeStatusPlaceholders !== '') {
        $servicesInProgressSql = "SELECT COUNT(*) FROM (
            SELECT id FROM entrate_uscite WHERE stato IN ('In lavorazione', 'In attesa')
            UNION ALL
            SELECT id FROM servizi_appuntamenti WHERE stato IN ($activeStatusPlaceholders)
            UNION ALL
            SELECT id FROM curriculum WHERE status <> 'Archiviato'
            UNION ALL
            SELECT id FROM spedizioni WHERE stato IN ('Registrato', 'In attesa di ritiro', 'Problema', 'In corso', 'Aperto')
        ) AS in_progress";
        $servicesInProgressParams = $activeAppointmentStatuses;
    } else {
        $servicesInProgressSql = "SELECT COUNT(*) FROM (
            SELECT id FROM entrate_uscite WHERE stato IN ('In lavorazione', 'In attesa')
            UNION ALL
            SELECT id FROM servizi_appuntamenti
            UNION ALL
            SELECT id FROM curriculum WHERE status <> 'Archiviato'
            UNION ALL
            SELECT id FROM spedizioni WHERE stato IN ('Registrato', 'In attesa di ritiro', 'Problema', 'In corso', 'Aperto')
        ) AS in_progress";
    }
    $servicesInProgressStmt = $pdo->prepare($servicesInProgressSql);
    $servicesInProgressStmt->execute($servicesInProgressParams);
    $response['stats']['servicesInProgress'] = (int) $servicesInProgressStmt->fetchColumn();

    $appointmentsTodaySql = 'SELECT COUNT(*) FROM servizi_appuntamenti WHERE DATE(data_inizio) = CURRENT_DATE';
    if ($activeStatusPlaceholders !== '') {
        $appointmentsTodaySql .= ' AND stato IN (' . $activeStatusPlaceholders . ')';
        $appointmentsTodayStmt = $pdo->prepare($appointmentsTodaySql);
        $appointmentsTodayStmt->execute($activeAppointmentStatuses);
    } else {
        $appointmentsTodayStmt = $pdo->query($appointmentsTodaySql);
    }
    $response['stats']['appointmentsToday'] = (int) $appointmentsTodayStmt->fetchColumn();

    $dailyRevenueStmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN tipo_movimento = 'Entrata' THEN importo ELSE -importo END), 0)
        FROM entrate_uscite
        WHERE stato = 'Completato' AND DATE(COALESCE(data_pagamento, updated_at)) = CURRENT_DATE");
    $dailyRevenueStmt->execute();
    $response['stats']['dailyRevenue'] = (float) $dailyRevenueStmt->fetchColumn();

    $response['stats']['financePending'] = (int) $pdo->query("SELECT COUNT(*) FROM entrate_uscite WHERE stato IN ('In lavorazione', 'In attesa')")->fetchColumn();

    $response['stats']['energyContracts'] = (int) $pdo->query('SELECT COUNT(*) FROM energia_contratti')->fetchColumn();

    $response['stats']['anprInProgress'] = (int) $pdo->query("SELECT COUNT(*) FROM anpr_pratiche WHERE stato = 'In lavorazione'")->fetchColumn();

    $ticketStmt = $pdo->prepare("SELECT id, codice, subject, status, created_at, updated_at FROM tickets ORDER BY updated_at DESC LIMIT 5");
    $ticketStmt->execute();
    $tickets = $ticketStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $response['tickets'] = array_map(static function (array $ticket): array {
        return [
            'id' => (int) $ticket['id'],
            'code' => $ticket['codice'] ?? null,
            'subject' => $ticket['subject'] ?? null,
            'status' => $ticket['status'] ?? null,
            'createdAt' => $ticket['created_at'] ?? null,
        ];
    }, $tickets);
    $response['stats']['openTickets'] = (int) $pdo->query("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('RESOLVED','CLOSED','ARCHIVED')")->fetchColumn();

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

    $response['charts']['revenue']['labels'] = [];
    $response['charts']['revenue']['values'] = [];
    $startMonth = (new DateTimeImmutable('first day of this month'))->modify('-5 months');
    $monthCursor = $startMonth;
    for ($i = 0; $i < 6; $i++) {
        $monthKey = $monthCursor->format('Y-m');
        $response['charts']['revenue']['labels'][] = format_month_label($monthCursor);
        $response['charts']['revenue']['values'][] = $monthlyRevenue[$monthKey] ?? 0.0;
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
            error_log('Dashboard API service count failed for ' . $table . ': ' . $serviceException->getMessage());
            $value = 0;
        }
    }
    unset($value);

    try {
        $brtCountStmt = $pdo->query("SELECT COUNT(*) FROM brt_shipments WHERE deleted_at IS NULL");
        $serviceTotals['brt_shipments'] = (int) $brtCountStmt->fetchColumn();
    } catch (PDOException $brtException) {
        error_log('Dashboard API BRT shipment count failed: ' . $brtException->getMessage());
        $serviceTotals['brt_shipments'] = 0;
    }

    try {
        $sentRecipientsStmt = $pdo->query("SELECT COUNT(*) FROM email_campaign_recipients WHERE status = 'sent'");
        $serviceTotals['email_campaign_recipients'] = (int) $sentRecipientsStmt->fetchColumn();
    } catch (PDOException $sentException) {
        error_log('Dashboard API sent email count failed: ' . $sentException->getMessage());
        $serviceTotals['email_campaign_recipients'] = 0;
    }

    $response['charts']['services']['values'] = array_values($serviceTotals);

    $reminders = [];
    try {
        $response['stats']['emailSubscribers'] = (int) $pdo->query("SELECT COUNT(*) FROM email_subscribers WHERE status = 'active'")->fetchColumn();
    } catch (PDOException $emailStatsException) {
        error_log('Dashboard API email subscriber stat failed: ' . $emailStatsException->getMessage());
    }

    try {
        $response['stats']['campaignsScheduled'] = (int) $pdo->query("SELECT COUNT(*) FROM email_campaigns WHERE status IN ('draft','scheduled')")->fetchColumn();
    } catch (PDOException $campaignStatException) {
        error_log('Dashboard API campaign stat failed: ' . $campaignStatException->getMessage());
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
        error_log('Dashboard API email campaign reminder failed: ' . $emailReminderException->getMessage());
    }

    $oldestTicketStmt = $pdo->prepare("SELECT id, codice, subject, status, created_at, COALESCE(last_message_at, created_at) AS reference_date
        FROM tickets
        WHERE status IN ('OPEN','IN_PROGRESS','WAITING_CLIENT','WAITING_PARTNER')
        ORDER BY reference_date ASC
        LIMIT 1");
    $oldestTicketStmt->execute();
    if ($oldestTicket = $oldestTicketStmt->fetch()) {
        $ticketCode = $oldestTicket['codice'] ?? $oldestTicket['id'];
        $ticketSubject = trim((string) ($oldestTicket['subject'] ?? 'Ticket ' . $ticketCode));
        $reminders[] = [
            'icon' => 'fa-life-ring',
            'title' => 'Ticket da prendere in carico',
            'detail' => sprintf('Ticket #%s · %s aperto il %s.', $ticketCode, $ticketSubject, format_datetime($oldestTicket['created_at'] ?? '')),
            'url' => base_url('modules/ticket/view.php?id=' . (int) $oldestTicket['id']),
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

    $response['reminders'] = $reminders;

    echo json_encode($response, JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('Dashboard API failed: ' . $e->getMessage());
    http_response_code(500);
    try {
        echo json_encode(['error' => 'Impossibile aggiornare la dashboard in questo momento.'], JSON_THROW_ON_ERROR);
    } catch (JsonException $jsonException) {
        echo '{"error":"Dashboard offline"}';
    }
}
