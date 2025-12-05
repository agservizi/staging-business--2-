<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$opportunities = $opportunityService->listCollaboratorOpportunities($collaboratorId);
$collaboratorDashboard = $opportunityService->getCollaboratorSummary($collaboratorId);
$collaboratorTotals = $collaboratorDashboard['totals'] ?? ['total' => 0, 'active' => 0, 'won' => 0, 'lost' => 0];
$collaboratorStatusBreakdown = $collaboratorDashboard['status_breakdown'] ?? [];
$collaboratorMonthlyTrend = $collaboratorDashboard['monthly_trend'] ?? ['labels' => [], 'values' => []];
$collaboratorTrendValues = $collaboratorMonthlyTrend['values'] ?? [];
$collaboratorTrendMax = $collaboratorTrendValues ? max($collaboratorTrendValues) : 0;
$collaboratorLastActivity = $collaboratorDashboard['last_activity'] ?? null;

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Opportunity</p>
                <h1 class="h4 mb-0">Le tue richieste</h1>
            </div>
            <a class="btn btn-primary" href="<?php echo asset('modules/opportunities/collaborator/create.php'); ?>">
                <i class="fa-solid fa-plus me-2"></i>Nuova OP
            </a>
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
                                <p class="text-uppercase small text-muted mb-1">Trend</p>
                                <h2 class="h5 mb-0">Ultimi 6 mesi</h2>
                            </div>
                        </div>
                        <?php if (!empty($collaboratorMonthlyTrend['labels'])): ?>
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
            <?php if (!$opportunities): ?>
                <div class="col-12">
                    <div class="alert alert-info mb-0" role="alert">
                        Nessuna opportunity registrata. Crea la prima utilizzando il pulsante in alto.
                    </div>
                </div>
            <?php endif; ?>
            <?php foreach ($opportunities as $opportunity): ?>
                <div class="col-12">
                    <div class="card opportunity-card shadow-sm">
                        <div class="card-body d-flex flex-column flex-lg-row justify-content-between gap-3">
                            <div>
                                <p class="text-uppercase small text-muted mb-1 d-flex align-items-center gap-2 flex-wrap">
                                    <span><?php echo sanitize_output(strtoupper($opportunity['category'] ?? '')); ?></span>
                                    <span class="opportunity-code"><?php echo sanitize_output($opportunity['code'] ?? ''); ?></span>
                                </p>
                                <h3 class="h5 mb-1"><?php echo sanitize_output($opportunity['customer_first_name'] . ' ' . $opportunity['customer_last_name']); ?></h3>
                                <p class="text-muted mb-0">Gestore: <?php echo sanitize_output($opportunity['provider_label'] ?? ''); ?></p>
                            </div>
                            <div class="text-end">
                                <?php
                                $badgeClass = 'bg-secondary';
                                $statusColor = $opportunity['status_color'] ?? '';
                                $colorToBootstrap = [
                                    'warning' => 'bg-warning text-dark',
                                    'info' => 'bg-info text-dark',
                                    'primary' => 'bg-primary',
                                    'danger' => 'bg-danger',
                                    'success' => 'bg-success',
                                ];
                                if ($statusColor && isset($colorToBootstrap[$statusColor])) {
                                    $badgeClass = $colorToBootstrap[$statusColor];
                                }
                                ?>
                                <span class="badge <?php echo $badgeClass; ?> opportunity-status-badge">
                                    <?php echo sanitize_output($opportunity['status_label'] ?? $opportunity['status_code'] ?? ''); ?>
                                </span>
                                <p class="text-muted small mb-0 mt-2">
                                    Inviata il <?php echo sanitize_output(format_datetime_locale($opportunity['created_at'] ?? null)); ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
