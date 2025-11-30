<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Email marketing';

if (!function_exists('email_marketing_tables_ready')) {
    function email_marketing_tables_ready(PDO $pdo): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        try {
            $pdo->query('SELECT 1 FROM email_campaigns LIMIT 1');
            $pdo->query('SELECT 1 FROM email_subscribers LIMIT 1');
            $cache = true;
        } catch (PDOException $exception) {
            error_log('Email marketing tables missing: ' . $exception->getMessage());
            $cache = false;
        }

        return $cache;
    }
}

$emailTablesReady = email_marketing_tables_ready($pdo);
$stats = [
    'activeSubscribers' => 0,
    'totalLists' => 0,
    'sentLast30' => 0,
    'draftOrScheduled' => 0,
];
$campaigns = [];
$lists = [];
$recentSubscribers = [];
$scheduledCampaigns = [];
$analyticsSeries = [
    'labels' => [],
    'opens' => [],
    'clicks' => [],
    'bounces' => [],
    'unsubscribes' => [],
    'complaints' => [],
];
$analyticsTotals = [
    'opens' => 0,
    'clicks' => 0,
    'bounces' => 0,
    'unsubscribes' => 0,
    'complaints' => 0,
];
$analyticsJson = null;

if ($emailTablesReady) {
    try {
        $stats['activeSubscribers'] = (int) $pdo->query("SELECT COUNT(*) FROM email_subscribers WHERE status = 'active'")->fetchColumn();
        $stats['totalLists'] = (int) $pdo->query('SELECT COUNT(*) FROM email_lists')->fetchColumn();
        $stats['sentLast30'] = (int) $pdo->query("SELECT COUNT(*) FROM email_campaigns WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
        $stats['draftOrScheduled'] = (int) $pdo->query("SELECT COUNT(*) FROM email_campaigns WHERE status IN ('draft','scheduled')")->fetchColumn();

        $campaignStmt = $pdo->query("SELECT id, name, status, subject, audience_type, scheduled_at, sent_at, updated_at, metrics_summary FROM email_campaigns ORDER BY updated_at DESC LIMIT 15");
        $campaigns = $campaignStmt->fetchAll() ?: [];

        $listStmt = $pdo->query("SELECT l.id, l.name, l.description, COUNT(ls.subscriber_id) AS subscribers
            FROM email_lists l
            LEFT JOIN email_list_subscribers ls ON ls.list_id = l.id AND ls.status = 'active'
            GROUP BY l.id, l.name, l.description
            ORDER BY l.name");
        $lists = $listStmt->fetchAll() ?: [];

        $recentSubscriberStmt = $pdo->query("SELECT id, email, first_name, last_name, status, created_at FROM email_subscribers ORDER BY created_at DESC LIMIT 10");
        $recentSubscribers = $recentSubscriberStmt->fetchAll() ?: [];

        $scheduledStmt = $pdo->query("SELECT id, name, scheduled_at FROM email_campaigns WHERE status IN ('draft','scheduled') ORDER BY COALESCE(scheduled_at, updated_at) ASC LIMIT 5");
        $scheduledCampaigns = $scheduledStmt->fetchAll() ?: [];

        $eventsStmt = $pdo->prepare(
            "SELECT DATE(occurred_at) AS event_date,
                SUM(CASE WHEN event_type = 'open' THEN 1 ELSE 0 END) AS open_count,
                SUM(CASE WHEN event_type = 'click' THEN 1 ELSE 0 END) AS click_count,
                SUM(CASE WHEN event_type = 'bounce' THEN 1 ELSE 0 END) AS bounce_count,
                SUM(CASE WHEN event_type = 'unsubscribe' THEN 1 ELSE 0 END) AS unsubscribe_count,
                SUM(CASE WHEN event_type = 'complaint' THEN 1 ELSE 0 END) AS complaint_count
            FROM email_campaign_events
            WHERE occurred_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY event_date
            ORDER BY event_date"
        );
        $eventsStmt->execute();

        $dailyStats = [];
        while ($row = $eventsStmt->fetch(PDO::FETCH_ASSOC)) {
            $key = (string) ($row['event_date'] ?? '');
            if ($key === '') {
                continue;
            }
            $dailyStats[$key] = [
                'open' => (int) ($row['open_count'] ?? 0),
                'click' => (int) ($row['click_count'] ?? 0),
                'bounce' => (int) ($row['bounce_count'] ?? 0),
                'unsubscribe' => (int) ($row['unsubscribe_count'] ?? 0),
                'complaint' => (int) ($row['complaint_count'] ?? 0),
            ];
        }

        try {
            $startDay = new DateTimeImmutable('today');
        } catch (Exception $exception) {
            $startDay = new DateTimeImmutable();
        }
        $startDay = $startDay->modify('-29 days');

        for ($offset = 0; $offset < 30; $offset++) {
            $currentDay = $startDay->modify('+' . $offset . ' days');
            $dayKey = $currentDay->format('Y-m-d');
            $label = $currentDay->format('d/m');
            $statsRow = $dailyStats[$dayKey] ?? ['open' => 0, 'click' => 0, 'bounce' => 0, 'unsubscribe' => 0, 'complaint' => 0];

            $analyticsSeries['labels'][] = $label;
            $analyticsSeries['opens'][] = $statsRow['open'];
            $analyticsSeries['clicks'][] = $statsRow['click'];
            $analyticsSeries['bounces'][] = $statsRow['bounce'];
            $analyticsSeries['unsubscribes'][] = $statsRow['unsubscribe'];
            $analyticsSeries['complaints'][] = $statsRow['complaint'];

            $analyticsTotals['opens'] += $statsRow['open'];
            $analyticsTotals['clicks'] += $statsRow['click'];
            $analyticsTotals['bounces'] += $statsRow['bounce'];
            $analyticsTotals['unsubscribes'] += $statsRow['unsubscribe'];
            $analyticsTotals['complaints'] += $statsRow['complaint'];
        }

        try {
            $analyticsJson = json_encode($analyticsSeries, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $analyticsJson = null;
        }
    } catch (PDOException $exception) {
        error_log('Email marketing overview error: ' . $exception->getMessage());
        add_flash('danger', 'Impossibile caricare i dati di email marketing. Esegui le ultime migrazioni e riprova.');
        $emailTablesReady = false;
    }
}

function email_marketing_status_badge(string $status): string
{
    $map = [
        'draft' => 'bg-secondary',
        'scheduled' => 'bg-info',
        'sending' => 'bg-warning text-dark',
        'sent' => 'bg-success',
        'cancelled' => 'bg-dark',
        'failed' => 'bg-danger',
    ];
    $class = $map[$status] ?? 'bg-secondary';
    return '<span class="badge ' . $class . ' text-uppercase">' . sanitize_output($status) . '</span>';
}

$hasAnalyticsEvents = array_sum($analyticsTotals);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <h1 class="h3 mb-1">Email marketing</h1>
                <p class="text-muted mb-0">Gestisci campagne, liste e iscritti. Invia newsletter sfruttando Resend.</p>
            </div>
            <?php if ($emailTablesReady): ?>
                <div class="toolbar-actions d-flex gap-2">
                    <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-plus me-2"></i>Nuova campagna</a>
                    <a class="btn btn-outline-light" href="templates.php"><i class="fa-solid fa-pen-ruler me-2"></i>Modelli</a>
                    <a class="btn btn-outline-light" href="subscribers.php"><i class="fa-solid fa-users me-2"></i>Iscritti</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$emailTablesReady): ?>
            <div class="alert alert-warning">
                <h5 class="alert-heading">Setup richiesto</h5>
                <p class="mb-2">Per utilizzare l'area email marketing esegui la migrazione <code>20251108_100000_create_email_marketing_tables.sql</code> o aggiorna l'ambiente con l'ultima versione del database.</p>
                <p class="mb-0">Dopo aver eseguito la migrazione ricarica la pagina.</p>
            </div>
        <?php else: ?>
            <div class="row g-4 mb-4">
                <div class="col-12 col-xl-3">
                    <div class="card ag-card h-100">
                        <div class="card-body">
                            <span class="badge ag-badge mb-2"><i class="fa-solid fa-users"></i> Iscritti attivi</span>
                            <h3 class="mb-0"><?php echo number_format($stats['activeSubscribers']); ?></h3>
                            <small class="text-muted">Destinatari pronti a ricevere campagne</small>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-3">
                    <div class="card ag-card h-100">
                        <div class="card-body">
                            <span class="badge ag-badge mb-2"><i class="fa-solid fa-list-ul"></i> Liste</span>
                            <h3 class="mb-0"><?php echo number_format($stats['totalLists']); ?></h3>
                            <small class="text-muted">Segmenti disponibili</small>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-3">
                    <div class="card ag-card h-100">
                        <div class="card-body">
                            <span class="badge ag-badge mb-2"><i class="fa-solid fa-paper-plane"></i> Inviate 30gg</span>
                            <h3 class="mb-0"><?php echo number_format($stats['sentLast30']); ?></h3>
                            <small class="text-muted">Campagne consegnate nell'ultimo mese</small>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-3">
                    <div class="card ag-card h-100">
                        <div class="card-body">
                            <span class="badge ag-badge mb-2"><i class="fa-solid fa-clock"></i> Bozze / pianificate</span>
                            <h3 class="mb-0"><?php echo number_format($stats['draftOrScheduled']); ?></h3>
                            <small class="text-muted">Campagne pronte all'invio</small>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-12 mb-4">
                    <div class="row g-4 align-items-stretch">
                        <div class="col-12 col-xl-8">
                            <div class="card ag-card h-100">
                                <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between">
                                    <h5 class="card-title mb-0">Andamento ultimi 30 giorni</h5>
                                    <span class="text-muted small">Eventi registrati nel mese</span>
                                </div>
                                <div class="card-body">
                                    <?php if ($analyticsSeries['labels']): ?>
                                        <canvas id="emailAnalyticsChart" height="220"></canvas>
                                    <?php else: ?>
                                        <p class="text-muted mb-0">Non ci sono interazioni registrate negli ultimi 30 giorni.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 col-xl-4">
                            <div class="card ag-card h-100">
                                <div class="card-header bg-transparent border-0">
                                    <h5 class="card-title mb-0">Interazioni totali</h5>
                                </div>
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="text-muted">Aperture</span>
                                        <span class="fw-semibold"><?php echo number_format($analyticsTotals['opens']); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="text-muted">Click</span>
                                        <span class="fw-semibold"><?php echo number_format($analyticsTotals['clicks']); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="text-muted">Disiscrizioni</span>
                                        <span class="fw-semibold"><?php echo number_format($analyticsTotals['unsubscribes']); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center mb-3">
                                        <span class="text-muted">Bounce</span>
                                        <span class="fw-semibold"><?php echo number_format($analyticsTotals['bounces']); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span class="text-muted">Segnalazioni</span>
                                        <span class="fw-semibold"><?php echo number_format($analyticsTotals['complaints']); ?></span>
                                    </div>
                                    <?php if ($hasAnalyticsEvents === 0): ?>
                                        <p class="text-muted mb-0 mt-3" style="font-size: 0.9rem;">I dati appariranno dopo il primo invio tracciato.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xxl-8">
                    <div class="card ag-card h-100">
                        <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between">
                            <h5 class="card-title mb-0">Campagne recenti</h5>
                            <a class="btn btn-sm btn-outline-warning" href="create.php"><i class="fa-solid fa-plus me-2"></i>Nuova campagna</a>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-dark table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>Campagna</th>
                                            <th>Oggetto</th>
                                            <th>Stato</th>
                                            <th>Pubblico</th>
                                            <th>Aggiornata</th>
                                            <th></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($campaigns): ?>
                                            <?php foreach ($campaigns as $campaign): ?>
                                                <?php
                                                $metrics = [];
                                                if (!empty($campaign['metrics_summary'])) {
                                                    try {
                                                        $metrics = json_decode((string) $campaign['metrics_summary'], true, 512, JSON_THROW_ON_ERROR);
                                                    } catch (JsonException $ignore) {
                                                        $metrics = [];
                                                    }
                                                }
                                                ?>
                                                <tr>
                                                    <td>
                                                        <div class="fw-semibold"><?php echo sanitize_output($campaign['name'] ?: ('Campagna #' . (int) $campaign['id'])); ?></div>
                                                        <?php if ($metrics): ?>
                                                            <small class="text-muted">Tot: <?php echo (int) ($metrics['total'] ?? 0); ?> • Inviate: <?php echo (int) ($metrics['sent'] ?? 0); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo sanitize_output($campaign['subject'] ?? '—'); ?></td>
                                                    <td><?php echo email_marketing_status_badge($campaign['status'] ?? 'draft'); ?></td>
                                                    <td><?php echo sanitize_output($campaign['audience_type'] ?? '—'); ?></td>
                                                    <td><?php echo sanitize_output(format_datetime($campaign['updated_at'] ?? '')); ?></td>
                                                    <td class="text-end">
                                                        <a class="btn btn-sm btn-outline-warning" href="view.php?id=<?php echo (int) $campaign['id']; ?>">
                                                            <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <tr>
                                                <td colspan="6" class="text-center text-muted py-4">Nessuna campagna disponibile. Crea la prima per iniziare.</td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xxl-4">
                    <div class="card ag-card mb-4">
                        <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between">
                            <h5 class="card-title mb-0">Invii pianificati</h5>
                            <a class="btn btn-sm btn-link text-decoration-none" href="view.php?id=<?php echo $scheduledCampaigns ? (int) $scheduledCampaigns[0]['id'] : 0; ?>"<?php echo $scheduledCampaigns ? '' : ' hidden'; ?>>Gestisci</a>
                        </div>
                        <div class="card-body">
                            <?php if ($scheduledCampaigns): ?>
                                <ul class="list-unstyled mb-0">
                                    <?php foreach ($scheduledCampaigns as $scheduled): ?>
                                        <li class="mb-3">
                                            <div class="fw-semibold"><?php echo sanitize_output($scheduled['name'] ?? ('Campagna #' . (int) $scheduled['id'])); ?></div>
                                            <small class="text-muted">Invio previsto: <?php echo $scheduled['scheduled_at'] ? sanitize_output(format_datetime($scheduled['scheduled_at'])) : 'non pianificato'; ?></small>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted mb-0">Nessuna campagna programmata.</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="card ag-card">
                        <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between">
                            <h5 class="card-title mb-0">Liste</h5>
                            <a class="btn btn-sm btn-outline-light" href="subscribers.php"><i class="fa-solid fa-pen"></i></a>
                        </div>
                        <div class="card-body">
                            <?php if ($lists): ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($lists as $list): ?>
                                        <li class="list-group-item d-flex justify-content-between align-items-center">
                                            <span>
                                                <span class="fw-semibold"><?php echo sanitize_output($list['name']); ?></span>
                                                <?php if (!empty($list['description'])): ?>
                                                    <br><small class="text-muted"><?php echo sanitize_output($list['description']); ?></small>
                                                <?php endif; ?>
                                            </span>
                                            <span class="badge bg-secondary"><?php echo (int) $list['subscribers']; ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <p class="text-muted mb-0">Crea una lista per segmentare gli iscritti.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card ag-card mt-4">
                <div class="card-header bg-transparent border-0 d-flex align-items-center justify-content-between">
                    <h5 class="card-title mb-0">Ultimi iscritti</h5>
                    <a class="btn btn-sm btn-outline-light" href="subscribers.php">Gestisci iscritti</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Nome</th>
                                    <th>Stato</th>
                                    <th>Registrato</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recentSubscribers): ?>
                                    <?php foreach ($recentSubscribers as $subscriber): ?>
                                        <tr>
                                            <td><?php echo sanitize_output($subscriber['email']); ?></td>
                                            <td><?php echo sanitize_output(trim(($subscriber['first_name'] ?? '') . ' ' . ($subscriber['last_name'] ?? '')) ?: '—'); ?></td>
                                            <td><?php echo sanitize_output($subscriber['status']); ?></td>
                                            <td><?php echo sanitize_output(format_datetime($subscriber['created_at'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">Non ci sono iscritti registrati.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<?php if ($analyticsJson): ?>
<script>
    (function() {
        const canvas = document.getElementById('emailAnalyticsChart');
        if (!canvas) {
            return;
        }

        const analyticsSeries = <?php echo $analyticsJson; ?>;
        if (!analyticsSeries || !Array.isArray(analyticsSeries.labels)) {
            return;
        }

        const palette = {
            opens: '#3bb273',
            clicks: '#f5a623',
            unsubscribes: '#d64541',
            bounces: '#8e5ea2'
        };
        const fills = {
            opens: 'rgba(59, 178, 115, 0.18)',
            clicks: 'rgba(245, 166, 35, 0.18)',
            unsubscribes: 'rgba(214, 69, 65, 0.18)',
            bounces: 'rgba(142, 94, 162, 0.18)'
        };

        new Chart(canvas, {
            type: 'line',
            data: {
                labels: analyticsSeries.labels,
                datasets: [
                    {
                        label: 'Aperture',
                        data: analyticsSeries.opens,
                        borderColor: palette.opens,
                        backgroundColor: fills.opens,
                        borderWidth: 2,
                        tension: 0.35,
                        fill: false,
                    },
                    {
                        label: 'Click',
                        data: analyticsSeries.clicks,
                        borderColor: palette.clicks,
                        backgroundColor: fills.clicks,
                        borderWidth: 2,
                        tension: 0.35,
                        fill: false,
                    },
                    {
                        label: 'Disiscrizioni',
                        data: analyticsSeries.unsubscribes,
                        borderColor: palette.unsubscribes,
                        backgroundColor: fills.unsubscribes,
                        borderWidth: 2,
                        tension: 0.35,
                        fill: false,
                        borderDash: [6, 4],
                    },
                    {
                        label: 'Bounce',
                        data: analyticsSeries.bounces,
                        borderColor: palette.bounces,
                        backgroundColor: fills.bounces,
                        borderWidth: 2,
                        tension: 0.35,
                        fill: false,
                        borderDash: [4, 4],
                    }
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                    },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                    },
                },
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                        },
                        grid: {
                            color: 'rgba(255,255,255,0.08)',
                        },
                    },
                    x: {
                        grid: {
                            display: false,
                        },
                    },
                },
            },
        });
    })();
</script>
<?php endif; ?>
