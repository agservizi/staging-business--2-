<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_role('Admin', 'Manager');

$monthParam = isset($_GET['month']) ? trim((string) $_GET['month']) : '';
$monthOptions = $opportunityService->getCommissionMonthOptions(null, 12);
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

$rows = $opportunityService->getMonthlyCommissionsByCollaborator($monthParam);
$grandTotal = 0.0;
$grandOpportunities = 0;
foreach ($rows as $row) {
    $grandTotal += (float) ($row['total_commission'] ?? 0);
    $grandOpportunities += (int) ($row['opportunities'] ?? 0);
}
$totalCollaborators = count($rows);
$averagePerOpportunity = $grandOpportunities > 0 ? $grandTotal / $grandOpportunities : 0.0;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Opportunity</p>
                <h1 class="h4 mb-0">Provvigioni per collaboratore</h1>
                <p class="text-muted mb-0">Analizza i valori stimati caricati dai collaboratori su base mensile.</p>
            </div>
            <a class="btn btn-outline-primary" href="<?php echo opportunities_module_url('index'); ?>">
                <i class="fa-solid fa-arrow-left me-2"></i>Torna alla pipeline
            </a>
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
                        <p class="text-uppercase small text-muted mb-1">Totale mese</p>
                        <h2 class="display-6 mb-0"><?php echo sanitize_output(number_format($grandTotal, 2, ',', '.')); ?> €</h2>
                        <p class="text-muted small mb-0">Somma delle provvigioni stimate nel periodo selezionato.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Collaboratori attivi</p>
                        <h2 class="display-6 mb-0"><?php echo (int) $totalCollaborators; ?></h2>
                        <p class="text-muted small mb-0">Collaboratori con almeno una opportunity nel mese.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Opportunity totali</p>
                        <h2 class="display-6 mb-0"><?php echo (int) $grandOpportunities; ?></h2>
                        <p class="text-muted small mb-0">Pratiche conteggiate sul periodo selezionato.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Media per pratica</p>
                        <h2 class="display-6 mb-0"><?php echo sanitize_output(number_format($averagePerOpportunity, 2, ',', '.')); ?> €</h2>
                        <p class="text-muted small mb-0">Valore medio stimato sul mese.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div>
                        <p class="text-uppercase small text-muted mb-1">Dettaglio</p>
                        <h2 class="h5 mb-0"><?php echo sanitize_output($selectedMonthLabel); ?></h2>
                    </div>
                    <span class="badge bg-light text-muted border">Provvigioni stimate</span>
                </div>
                <?php if ($rows): ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Collaboratore</th>
                                    <th>Email</th>
                                    <th class="text-center">Opportunity</th>
                                    <th class="text-end">Totale mese</th>
                                    <th class="text-end">Media</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php
                                        $total = (float) ($row['total_commission'] ?? 0);
                                        $count = (int) ($row['opportunities'] ?? 0);
                                        $average = $count > 0 ? $total / $count : 0.0;
                                        $fullName = trim(sprintf('%s %s', (string) ($row['collaborator_name'] ?? ''), (string) ($row['collaborator_surname'] ?? '')));
                                        if ($fullName === '') {
                                            $fullName = 'Collaboratore #' . (int) ($row['collaborator_id'] ?? 0);
                                        }
                                    ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo sanitize_output($fullName); ?></td>
                                        <td class="text-muted small"><?php echo sanitize_output($row['collaborator_email'] ?? '—'); ?></td>
                                        <td class="text-center fw-semibold"><?php echo $count; ?></td>
                                        <td class="text-end fw-semibold"><?php echo sanitize_output(number_format($total, 2, ',', '.')); ?> €</td>
                                        <td class="text-end text-muted"><?php echo sanitize_output(number_format($average, 2, ',', '.')); ?> €</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">Nessuna opportunity registrata nel mese selezionato.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
