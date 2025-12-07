<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

$csrfToken = csrf_token();
$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$statusOptions = $opportunityService->getStatusOptions();
$statusCodes = array_column($statusOptions, 'code');
$categoryOptions = [
    'telefonia' => 'Telefonia',
    'luce' => 'Luce',
    'gas' => 'Gas',
];
$stalledThresholdDays = (int) (env('OPPORTUNITY_COLLABORATOR_STALE_DAYS') ?? 5);
if ($stalledThresholdDays <= 0) {
    $stalledThresholdDays = 5;
}
$stalledLimit = (int) (env('OPPORTUNITY_COLLABORATOR_STALE_LIMIT') ?? 5);
if ($stalledLimit <= 0) {
    $stalledLimit = 5;
}
$collaboratorStalledReminders = $opportunityService->getCollaboratorStalledReminders($collaboratorId, $stalledThresholdDays, $stalledLimit);

$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
if ($statusFilter !== '' && !in_array($statusFilter, $statusCodes, true)) {
    $statusFilter = '';
}

$categoryFilter = isset($_GET['category']) ? strtolower(trim((string) $_GET['category'])) : '';
if ($categoryFilter !== '' && !isset($categoryOptions[$categoryFilter])) {
    $categoryFilter = '';
}

$searchQuery = trim((string) ($_GET['q'] ?? ''));

$listFilters = [];
if ($statusFilter !== '') {
    $listFilters['status'] = $statusFilter;
}
if ($categoryFilter !== '') {
    $listFilters['category'] = $categoryFilter;
}
if ($searchQuery !== '') {
    $listFilters['search'] = $searchQuery;
}

$perPage = 10;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));
$totalOpportunities = $opportunityService->countCollaboratorOpportunities($collaboratorId, $listFilters);
$totalPages = $totalOpportunities > 0 ? (int) ceil($totalOpportunities / $perPage) : 1;
if ($totalPages <= 0) {
    $totalPages = 1;
}
if ($currentPage > $totalPages && $totalOpportunities > 0) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $perPage;
$opportunities = $opportunityService->listCollaboratorOpportunities($collaboratorId, $listFilters, $perPage, $offset);
$displayStart = $totalOpportunities > 0 ? $offset + 1 : 0;
$displayEnd = $totalOpportunities > 0 ? min($totalOpportunities, $offset + count($opportunities)) : 0;
$remoteDraft = $opportunityService->getCollaboratorDraft($collaboratorId);
$remoteDraftData = is_array($remoteDraft['data'] ?? null) ? $remoteDraft['data'] : [];
$hasRemoteDraft = $remoteDraftData !== [];
$remoteDraftSavedAt = $remoteDraft['saved_at'] ?? null;
$collaboratorDashboard = $opportunityService->getCollaboratorSummary($collaboratorId);
$collaboratorTotals = $collaboratorDashboard['totals'] ?? ['total' => 0, 'active' => 0, 'won' => 0, 'lost' => 0];
$collaboratorStatusBreakdown = $collaboratorDashboard['status_breakdown'] ?? [];
$collaboratorMonthlyTrend = $collaboratorDashboard['monthly_trend'] ?? ['labels' => [], 'values' => []];
$collaboratorTrendValues = $collaboratorMonthlyTrend['values'] ?? [];
$collaboratorTrendMax = $collaboratorTrendValues ? max($collaboratorTrendValues) : 0;
$collaboratorLastActivity = $collaboratorDashboard['last_activity'] ?? null;
$collaboratorTrendJson = json_encode($collaboratorMonthlyTrend, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$filterQueryParams = [];
if ($statusFilter !== '') {
    $filterQueryParams['status'] = $statusFilter;
}
if ($categoryFilter !== '') {
    $filterQueryParams['category'] = $categoryFilter;
}
if ($searchQuery !== '') {
    $filterQueryParams['q'] = $searchQuery;
}
$advancedFiltersUrl = asset('modules/opportunities/collaborator/list.php');

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Dashboard collaboratore</p>
                <h1 class="h4 mb-0">Panoramica opportunity</h1>
            </div>
            <a class="btn btn-primary" href="<?php echo asset('modules/opportunities/collaborator/create.php'); ?>">
                <i class="fa-solid fa-plus me-2"></i>Nuova OP
            </a>
        </div>


        <div class="card shadow-sm mb-4 border-warning-subtle">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <p class="text-uppercase small text-muted mb-1">Promemoria</p>
                        <h2 class="h6 mb-0">Pratiche da sollecitare</h2>
                        <small class="text-muted">Monitoriamo le opportunity ferme da oltre <?php echo (int) $stalledThresholdDays; ?> giorni senza aggiornamenti.</small>
                    </div>
                    <span class="badge bg-warning-subtle text-warning fw-semibold">
                        <?php echo count($collaboratorStalledReminders); ?> aperte
                    </span>
                </div>
                <?php if ($collaboratorStalledReminders): ?>
                    <ul class="list-unstyled mb-0 d-flex flex-column gap-3">
                        <?php foreach ($collaboratorStalledReminders as $reminder): ?>
                            <?php
                                $customerName = trim(($reminder['customer_first_name'] ?? '') . ' ' . ($reminder['customer_last_name'] ?? ''));
                                $daysWaiting = max(0, (int) ($reminder['days_waiting'] ?? 0));
                                $lastUpdate = format_datetime_locale($reminder['reference_date'] ?? null) ?? 'data non disponibile';
                            ?>
                            <li class="d-flex flex-column flex-lg-row gap-2 justify-content-between">
                                <div>
                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <strong class="text-body"><?php echo sanitize_output($reminder['code'] ?? ''); ?></strong>
                                        <?php if (!empty($reminder['status_label'])): ?>
                                            <span class="badge bg-light text-muted border">Stato: <?php echo sanitize_output($reminder['status_label']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted small">
                                        Cliente: <?php echo sanitize_output($customerName ?: 'N/D'); ?>
                                        <?php if (!empty($reminder['provider_label'])): ?> · Gestore: <?php echo sanitize_output($reminder['provider_label']); ?><?php endif; ?>
                                    </div>
                                </div>
                                <div class="text-lg-end small text-muted">
                                    <div class="fw-semibold text-warning">Fermo da <?php echo $daysWaiting; ?> <?php echo $daysWaiting === 1 ? 'giorno' : 'giorni'; ?></div>
                                    <div>Ultimo aggiornamento: <?php echo sanitize_output($lastUpdate); ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                        <?php if (count($collaboratorStalledReminders) >= $stalledLimit): ?>
                            <li class="text-muted small">Visualizzate al massimo <?php echo (int) $stalledLimit; ?> pratiche più datate.</li>
                        <?php endif; ?>
                    </ul>
                <?php else: ?>
                    <p class="text-muted mb-0">Ottimo lavoro! Nessuna opportunity supera la soglia impostata.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php
            $statCards = [
                'total' => ['label' => 'Totali inviate', 'icon' => 'fa-layer-group', 'badge' => 'bg-primary-subtle text-primary'],
                'active' => ['label' => 'In lavorazione', 'icon' => 'fa-spinner', 'badge' => 'bg-warning-subtle text-warning'],
                'won' => ['label' => 'Attivate', 'icon' => 'fa-circle-check', 'badge' => 'bg-success-subtle text-success'],
                'lost' => ['label' => 'Annullate', 'icon' => 'fa-circle-xmark', 'badge' => 'bg-danger-subtle text-danger'],
            ];
        ?>
        <div class="row g-3 mb-4">
            <?php foreach ($statCards as $key => $meta): ?>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <p class="text-uppercase small text-muted mb-1"><?php echo sanitize_output($meta['label']); ?></p>
                                    <h2 class="h3 mb-0">
                                        <?php echo sanitize_output(number_format((int) ($collaboratorTotals[$key] ?? 0), 0, ',', '.')); ?>
                                    </h2>
                                </div>
                                <span class="badge <?php echo sanitize_output($meta['badge']); ?>">
                                    <i class="fa-solid <?php echo sanitize_output($meta['icon']); ?>"></i>
                                </span>
                            </div>
                            <p class="text-muted mb-0 small">Aggiornato automaticamente</p>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-12 col-lg-7">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <p class="text-uppercase small text-muted mb-1">Pipeline</p>
                                <h2 class="h5 mb-0">Avanzamento per stato</h2>
                            </div>
                        </div>
                        <?php if ($collaboratorStatusBreakdown): ?>
                            <?php $breakdownTotal = max(1, (int) ($collaboratorTotals['total'] ?? 0)); ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($collaboratorStatusBreakdown as $status): ?>
                                    <?php
                                        $statusCount = (int) ($status['total'] ?? 0);
                                        $percent = $breakdownTotal > 0 ? (int) round(($statusCount / $breakdownTotal) * 100) : 0;
                                        $badgeClass = 'bg-secondary';
                                        $colorToBootstrap = [
                                            'warning' => 'bg-warning text-dark',
                                            'info' => 'bg-info text-dark',
                                            'primary' => 'bg-primary',
                                            'danger' => 'bg-danger',
                                            'success' => 'bg-success',
                                            'secondary' => 'bg-secondary',
                                            'slate' => 'bg-secondary',
                                        ];
                                        if (!empty($status['color']) && isset($colorToBootstrap[$status['color']])) {
                                            $badgeClass = $colorToBootstrap[$status['color']];
                                        }
                                    ?>
                                    <div>
                                        <div class="d-flex justify-content-between align-items-center mb-1">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge <?php echo $badgeClass; ?>">
                                                    <?php echo sanitize_output($status['label'] ?? $status['code']); ?>
                                                </span>
                                                <small class="text-muted">Codice: <?php echo sanitize_output($status['code'] ?? ''); ?></small>
                                            </div>
                                            <strong><?php echo $statusCount; ?></strong>
                                        </div>
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar" role="progressbar" style="width: <?php echo $percent; ?>%;" aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($opportunities): ?>
                                <hr class="text-muted">
                                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                                    <div>
                                        <p class="text-uppercase small text-muted mb-1">Ultime opportunity</p>
                                        <h3 class="h6 mb-0">Contratti caricati di recente</h3>
                                    </div>
                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo sanitize_output($advancedFiltersUrl); ?>">
                                        <i class="fa-solid fa-table-list me-1"></i>Apri elenco completo
                                    </a>
                                </div>
                                <ul class="list-unstyled mb-0 d-flex flex-column gap-3" aria-label="Elenco sintetico opportunity">
                                    <?php foreach (array_slice($opportunities, 0, 4) as $recentOp): ?>
                                        <?php
                                            $customerName = trim(
                                                (string) ($recentOp['customer_first_name'] ?? '') . ' ' . (string) ($recentOp['customer_last_name'] ?? '')
                                            );
                                            $customerName = $customerName !== '' ? $customerName : 'Cliente non indicato';
                                            $statusLabel = $recentOp['status_label'] ?? $recentOp['status_code'] ?? '';
                                            $statusClass = 'badge bg-secondary';
                                            $statusColor = $recentOp['status_color'] ?? '';
                                            $colorToBootstrap = [
                                                'warning' => 'badge bg-warning text-dark',
                                                'info' => 'badge bg-info text-dark',
                                                'primary' => 'badge bg-primary',
                                                'danger' => 'badge bg-danger',
                                                'success' => 'badge bg-success',
                                            ];
                                            if ($statusColor && isset($colorToBootstrap[$statusColor])) {
                                                $statusClass = $colorToBootstrap[$statusColor];
                                            }
                                        ?>
                                        <li class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                            <div>
                                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                                    <strong><?php echo sanitize_output($recentOp['code'] ?? ''); ?></strong>
                                                    <span class="<?php echo $statusClass; ?>">
                                                        <?php echo sanitize_output($statusLabel); ?>
                                                    </span>
                                                </div>
                                                <div class="text-muted small">
                                                    Cliente: <?php echo sanitize_output($customerName); ?>
                                                    <?php if (!empty($recentOp['provider_label'])): ?> · Gestore: <?php echo sanitize_output($recentOp['provider_label']); ?><?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="text-muted small text-lg-end">
                                                Inviata: <?php echo sanitize_output(format_datetime_locale($recentOp['created_at'] ?? null)); ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-muted mb-0">Non hai ancora opportunity registrate.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-5">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <p class="text-uppercase small text-muted mb-1">Andamento</p>
                                <h2 class="h5 mb-0">Ultimi 6 mesi</h2>
                            </div>
                            <div class="text-muted small" data-trend-delta>In caricamento…</div>
                        </div>
                        <?php if (!empty($collaboratorMonthlyTrend['labels'])): ?>
                            <div class="mb-3">
                                <canvas id="trend-chart" height="180" role="img" aria-label="Andamento opportunity ultimi 6 mesi"></canvas>
                            </div>
                            <div class="d-flex flex-column gap-2 mb-3">
                                <?php foreach ($collaboratorMonthlyTrend['labels'] as $index => $label): ?>
                                    <?php $value = (int) ($collaboratorMonthlyTrend['values'][$index] ?? 0); ?>
                                    <div>
                                        <div class="d-flex justify-content-between small text-muted mb-1">
                                            <span><?php echo sanitize_output($label); ?></span>
                                            <strong class="text-body"><?php echo $value; ?></strong>
                                        </div>
                                        <div class="progress" style="height: 6px;">
                                            <?php $width = $collaboratorTrendMax > 0 ? (int) round(($value / $collaboratorTrendMax) * 100) : 0; ?>
                                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $width; ?>%;" aria-valuenow="<?php echo $width; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">Ancora nessuna opportunity nel periodo considerato.</p>
                        <?php endif; ?>
                        <?php if ($collaboratorLastActivity): ?>
                            <hr>
                            <p class="text-uppercase small text-muted mb-1">Ultima attività</p>
                            <div class="d-flex justify-content-between flex-wrap gap-2 align-items-center">
                                <div>
                                    <strong><?php echo sanitize_output($collaboratorLastActivity['code'] ?? ''); ?></strong>
                                    <div class="text-muted small">Stato: <?php echo sanitize_output($collaboratorLastActivity['status_label'] ?? $collaboratorLastActivity['status_code'] ?? ''); ?></div>
                                </div>
                                <div class="text-muted small">
                                    <?php echo sanitize_output(format_datetime_locale($collaboratorLastActivity['reference_date'] ?? null)); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <?php if ($hasRemoteDraft): ?>
                <?php
                    $draftCategoryKey = strtolower((string) ($remoteDraftData['category'] ?? ''));
                    $draftCategoryLabel = $draftCategoryKey !== ''
                        ? ($categoryOptions[$draftCategoryKey] ?? ucfirst($draftCategoryKey))
                        : 'Categoria non selezionata';
                    $draftCustomer = trim(
                        (string) ($remoteDraftData['customer_first_name'] ?? '') . ' ' . (string) ($remoteDraftData['customer_last_name'] ?? '')
                    );
                    if ($draftCustomer === '') {
                        $draftCustomer = 'Cliente non indicato';
                    }
                    $draftSavedLabel = format_datetime_locale($remoteDraftSavedAt) ?? 'data non disponibile';
                ?>
                <div class="col-12" data-role="remote-draft-card">
                    <div class="card shadow-sm border-warning-subtle">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <div>
                                    <p class="text-uppercase small text-muted mb-0">Bozze salvate</p>
                                    <h3 class="h6 mb-0">Hai una opportunity in stato di bozza</h3>
                                </div>
                                <span class="badge bg-warning text-dark">Da completare</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-3">
                                    <thead>
                                        <tr class="text-muted">
                                            <th scope="col">Cliente</th>
                                            <th scope="col">Categoria</th>
                                            <th scope="col">Ultimo salvataggio</th>
                                            <th scope="col" class="text-end">Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>
                                                <strong><?php echo sanitize_output($draftCustomer); ?></strong><br>
                                                <small class="text-muted">Bozza cloud</small>
                                            </td>
                                            <td><?php echo sanitize_output($draftCategoryLabel); ?></td>
                                            <td><?php echo sanitize_output($draftSavedLabel); ?></td>
                                            <td class="text-end">
                                                <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                    <a class="btn btn-sm btn-warning" href="<?php echo asset('modules/opportunities/collaborator/create.php'); ?>">
                                                        <i class="fa-solid fa-pen-to-square me-1"></i>Continua
                                                    </a>
                                                    <button class="btn btn-sm btn-outline-danger" type="button" data-action="discard-remote-draft">
                                                        <span data-role="label">Elimina</span>
                                                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true" data-role="spinner"></span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-muted small mb-0">
                                Le bozze compaiono qui ma non vengono conteggiate finché non invii la opportunity.
                            </p>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (!$opportunities && !$hasRemoteDraft): ?>
                <div class="col-12">
                    <div class="alert alert-info mb-0" role="alert">
                        Nessuna opportunity registrata. Crea la prima utilizzando il pulsante in alto.
                    </div>
                </div>
            <?php elseif (!$opportunities && $hasRemoteDraft): ?>
                <div class="col-12">
                    <div class="alert alert-warning mb-0" role="alert">
                        Hai una bozza salvata: completila e inviala per farla comparire nell'elenco principale.
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($opportunities): ?>
                <div class="col-12">
                    <div class="alert alert-secondary mb-0" role="alert">
                        Hai <?php echo sanitize_output(number_format($totalOpportunities)); ?> opportunity attive. Consulta l'elenco completo e applica filtri dalla vista dedicata: <a class="fw-semibold" href="<?php echo sanitize_output($advancedFiltersUrl); ?>">apri elenco opportunity</a>.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<script>
document.addEventListener('DOMContentLoaded', function () {
    const remoteDraftEndpoint = "<?php echo sanitize_output(asset('api/opportunities/drafts.php')); ?>";
    const csrfToken = "<?php echo sanitize_output($csrfToken); ?>";

    if (window.bootstrap && typeof window.bootstrap.Tooltip === 'function') {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.forEach((triggerEl) => {
            new window.bootstrap.Tooltip(triggerEl);
        });
    }

    const trendCanvas = document.getElementById('trend-chart');
    if (trendCanvas) {
        const trendData = <?php echo $collaboratorTrendJson ?: 'null'; ?>;
        if (trendData && Array.isArray(trendData.labels) && Array.isArray(trendData.values) && trendData.labels.length) {
            const dataset = trendData.values.map((value) => Number(value) || 0);
            const ctx = trendCanvas.getContext('2d');
            const drawChart = () => {
                new window.Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: trendData.labels,
                        datasets: [{
                            label: 'Opportunity registrate',
                            data: dataset,
                            borderColor: '#f59f00',
                            backgroundColor: 'rgba(245, 159, 0, 0.15)',
                            tension: 0.35,
                            fill: true,
                            pointRadius: 4,
                            pointHoverRadius: 6,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                ticks: {
                                    precision: 0,
                                },
                                beginAtZero: true,
                            },
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: { callbacks: { label: (context) => `${context.parsed.y} opportunity` } },
                        },
                    },
                });
                const deltaBadge = document.querySelector('[data-trend-delta]');
                if (deltaBadge && dataset.length >= 2) {
                    const latest = dataset[dataset.length - 1];
                    const previous = dataset[dataset.length - 2] || 0;
                    const delta = previous === 0 ? (latest > 0 ? 100 : 0) : ((latest - previous) / previous) * 100;
                    const formatted = `${delta > 0 ? '+' : ''}${delta.toFixed(1)}% vs mese precedente`;
                    deltaBadge.textContent = formatted;
                    deltaBadge.classList.toggle('text-success', delta >= 0);
                    deltaBadge.classList.toggle('text-danger', delta < 0);
                }
            };

            if (!window.Chart) {
                const script = document.createElement('script');
                script.src = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js';
                script.onload = drawChart;
                document.head.appendChild(script);
            } else {
                drawChart();
            }
        }
    }

    const discardDraftButton = document.querySelector('[data-action="discard-remote-draft"]');
    if (discardDraftButton && remoteDraftEndpoint) {
        discardDraftButton.addEventListener('click', async () => {
            if (!window.confirm('Vuoi davvero eliminare la bozza cloud?')) {
                return;
            }
            const label = discardDraftButton.querySelector('[data-role="label"]');
            const spinner = discardDraftButton.querySelector('[data-role="spinner"]');
            discardDraftButton.disabled = true;
            if (label) {
                label.textContent = 'Eliminazione…';
            }
            if (spinner) {
                spinner.classList.remove('d-none');
            }
            try {
                const response = await fetch(remoteDraftEndpoint, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken,
                    },
                });
                if (!response.ok) {
                    throw new Error('Delete failed');
                }
                window.location.reload();
            } catch (error) {
                discardDraftButton.disabled = false;
                if (label) {
                    label.textContent = 'Riprova eliminazione';
                }
                if (spinner) {
                    spinner.classList.add('d-none');
                }
                alert('Non sono riuscito a eliminare la bozza. Riprova tra qualche istante.');
            }
        });
    }
});
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
