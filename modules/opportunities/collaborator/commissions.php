<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$monthParam = isset($_GET['month']) ? trim((string) $_GET['month']) : '';
$monthOptions = $opportunityService->getCommissionMonthOptions($collaboratorId, 12);
$availableKeys = array_map(static fn (array $option): string => (string) ($option['key'] ?? ''), $monthOptions);
if ($monthParam === '' || !in_array($monthParam, $availableKeys, true)) {
    $monthParam = $monthOptions[0]['key'];
}
$selectedMonthLabel = '';
foreach ($monthOptions as $option) {
    if (($option['key'] ?? '') === $monthParam) {
        $selectedMonthLabel = (string) ($option['label'] ?? $monthParam);
        break;
    }
}
if ($selectedMonthLabel === '') {
    $selectedMonthLabel = $monthParam;
}

$timeline = $opportunityService->getCollaboratorCommissionTimeline($collaboratorId, 6);
$timelineMonths = $timeline['months'] ?? [];
$lifetimeTotal = (float) ($timeline['lifetime_total'] ?? 0);
$timelineMax = 0.0;
foreach ($timelineMonths as $entry) {
    $value = (float) ($entry['total'] ?? 0);
    if ($value > $timelineMax) {
        $timelineMax = $value;
    }
}
$monthlyOpportunities = $opportunityService->listCollaboratorMonthlyOpportunities($collaboratorId, $monthParam);
$monthTotal = 0.0;
foreach ($monthlyOpportunities as $entry) {
    $monthTotal += (float) ($entry['commission_amount'] ?? 0);
}
$monthCount = count($monthlyOpportunities);
$pageParam = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = 5;
$totalPages = max(1, (int) ceil($monthCount / $pageSize));
if ($pageParam > $totalPages) {
    $pageParam = $totalPages;
}
$offset = ($pageParam - 1) * $pageSize;
$pagedMonthlyOpportunities = array_slice($monthlyOpportunities, $offset, $pageSize);
$averageCommission = $monthCount > 0 ? $monthTotal / $monthCount : 0.0;

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Provvigioni stimate</p>
                <h1 class="h4 mb-0">Andamento mensile</h1>
                <p class="text-muted mb-0">Controlla quanto vale ogni mese in base alle opportunity inserite.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo asset('modules/opportunities/collaborator/promotions.php'); ?>">
                    <i class="fa-solid fa-folder-open me-2"></i>File manager
                </a>
                <a class="btn btn-outline-secondary d-flex align-items-center justify-content-center" style="width: 42px; height: 42px;" href="<?php echo asset('modules/opportunities/collaborator/commissions-info.php'); ?>" aria-label="Tabella provvigioni">
                    <i class="fa-solid fa-info"></i>
                </a>
                <a class="btn btn-primary" href="<?php echo asset('modules/opportunities/collaborator/create.php'); ?>">
                    <i class="fa-solid fa-plus me-2"></i>Nuova OP
                </a>
            </div>
        </div>

        <form class="card shadow-sm mb-4" method="get">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6 col-lg-4">
                        <label class="form-label text-uppercase small text-muted">Periodo mensile</label>
                        <select class="form-select" name="month">
                            <?php foreach ($monthOptions as $option): ?>
                                <?php $key = (string) ($option['key'] ?? ''); ?>
                                <option value="<?php echo sanitize_output($key); ?>" <?php echo $key === $monthParam ? 'selected' : ''; ?>>
                                    <?php echo sanitize_output($option['label'] ?? $key); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <button class="btn btn-outline-primary w-100" type="submit">
                            <i class="fa-solid fa-rotate me-2"></i>Aggiorna
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Totale <?php echo sanitize_output($selectedMonthLabel); ?></p>
                        <h2 class="display-6 mb-0">
                            <?php echo sanitize_output(number_format($monthTotal, 2, ',', '.')); ?> €
                        </h2>
                        <p class="text-muted small mb-0">Somma delle provvigioni stimate caricate nel mese.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Opportunity</p>
                        <h2 class="display-6 mb-0"><?php echo (int) $monthCount; ?></h2>
                        <p class="text-muted small mb-0">Numero di pratiche inviate nel mese selezionato.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Media per pratica</p>
                        <h2 class="display-6 mb-0">
                            <?php echo sanitize_output(number_format($averageCommission, 2, ',', '.')); ?> €
                        </h2>
                        <p class="text-muted small mb-0">Valore medio stimato per opportunity.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Totale storico</p>
                        <h2 class="display-6 mb-0">
                            <?php echo sanitize_output(number_format($lifetimeTotal, 2, ',', '.')); ?> €
                        </h2>
                        <p class="text-muted small mb-0">Somma complessiva delle tue provvigioni stimate.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12 col-lg-5">
                <div class="card shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <p class="text-uppercase small text-muted mb-1">Ultimi mesi</p>
                                <h2 class="h5 mb-0">Timeline stimata</h2>
                            </div>
                            <span class="badge bg-primary-subtle text-primary"><?php echo count($timelineMonths); ?> mesi</span>
                        </div>
                        <?php if ($timelineMonths): ?>
                            <div class="d-flex flex-column gap-3">
                                <?php foreach ($timelineMonths as $entry): ?>
                                    <?php
                                        $value = (float) ($entry['total'] ?? 0);
                                        $percent = $timelineMax > 0 ? (int) round(($value / $timelineMax) * 100) : 0;
                                    ?>
                                    <div>
                                        <div class="d-flex justify-content-between align-items-center mb-1 flex-wrap gap-2">
                                            <div class="fw-semibold"><?php echo sanitize_output($entry['label'] ?? $entry['key'] ?? ''); ?></div>
                                            <div class="text-muted small">
                                                <?php echo (int) ($entry['opportunities'] ?? 0); ?> op · <?php echo sanitize_output(number_format($value, 2, ',', '.')); ?> €
                                            </div>
                                        </div>
                                        <div class="progress" style="height: 6px;">
                                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $percent; ?>%;" aria-valuenow="<?php echo $percent; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">Non ci sono dati sufficienti per mostrare la timeline.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-7">
                <div class="card shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                            <div>
                                <p class="text-uppercase small text-muted mb-1">Dettaglio mese</p>
                                <h2 class="h5 mb-0"><?php echo sanitize_output($selectedMonthLabel); ?></h2>
                            </div>
                            <span class="badge bg-light text-muted border">Provvigioni stimate</span>
                        </div>
                        <?php if ($monthlyOpportunities): ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead>
                                        <tr class="text-muted small">
                                            <th>Codice</th>
                                            <th>Gestore</th>
                                            <th>Stato</th>
                                            <th class="text-end">Provvigione</th>
                                            <th class="text-end">Inviata il</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pagedMonthlyOpportunities as $opportunity): ?>
                                            <?php
                                                $commission = (float) ($opportunity['commission_amount'] ?? 0);
                                                $statusLabel = $opportunity['status_label'] ?? $opportunity['status_code'] ?? '';
                                                $badgeClass = 'badge bg-secondary';
                                                $statusColor = $opportunity['status_color'] ?? '';
                                                $colorToBootstrap = [
                                                    'warning' => 'badge bg-warning text-dark',
                                                    'info' => 'badge bg-info text-dark',
                                                    'primary' => 'badge bg-primary',
                                                    'danger' => 'badge bg-danger',
                                                    'success' => 'badge bg-success',
                                                ];
                                                if ($statusColor && isset($colorToBootstrap[$statusColor])) {
                                                    $badgeClass = $colorToBootstrap[$statusColor];
                                                }
                                            ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo sanitize_output($opportunity['code'] ?? ''); ?></td>
                                                <td>
                                                    <div><?php echo sanitize_output($opportunity['provider_label'] ?? '—'); ?></div>
                                                    <?php if (!empty($opportunity['offer_label'])): ?>
                                                        <div class="text-muted small">Offerta: <?php echo sanitize_output($opportunity['offer_label']); ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="<?php echo $badgeClass; ?>"><?php echo sanitize_output($statusLabel); ?></span>
                                                </td>
                                                <td class="text-end fw-semibold">
                                                    <?php echo sanitize_output(number_format($commission, 2, ',', '.')); ?> €
                                                </td>
                                                <td class="text-end text-muted small">
                                                    <?php echo sanitize_output(format_datetime_locale($opportunity['created_at'] ?? null)); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($totalPages > 1): ?>
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <div class="text-muted small">Pagina <?php echo $pageParam; ?> di <?php echo $totalPages; ?></div>
                                    <div class="btn-group" role="group" aria-label="Paginazione">
                                        <?php $prevPage = max(1, $pageParam - 1); ?>
                                        <?php $nextPage = min($totalPages, $pageParam + 1); ?>
                                        <a class="btn btn-outline-secondary btn-sm<?php echo $pageParam <= 1 ? ' disabled' : ''; ?>" href="<?php echo $pageParam <= 1 ? '#' : '?month=' . urlencode($monthParam) . '&page=' . $prevPage; ?>">
                                            <i class="fa-solid fa-angle-left me-1"></i>Precedente
                                        </a>
                                        <a class="btn btn-outline-secondary btn-sm<?php echo $pageParam >= $totalPages ? ' disabled' : ''; ?>" href="<?php echo $pageParam >= $totalPages ? '#' : '?month=' . urlencode($monthParam) . '&page=' . $nextPage; ?>">
                                            Successiva<i class="fa-solid fa-angle-right ms-1"></i>
                                        </a>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="text-muted mb-0">Non ci sono opportunity registrate per il mese selezionato.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
