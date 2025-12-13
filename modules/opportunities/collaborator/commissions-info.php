<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

$categoryLabels = [
    'telefonia' => 'Telefonia',
    'luce' => 'Luce',
    'gas' => 'Gas',
];

$providersByCategory = $opportunityService->listProvidersWithOffers(null, true);
$rows = [];
foreach ($categoryLabels as $categoryKey => $categoryLabel) {
    $providers = $providersByCategory[$categoryKey] ?? [];
    foreach ($providers as $provider) {
        $offers = $provider['offers'] ?? [];
        if ($offers) {
            foreach ($offers as $offer) {
                $commissionValue = $offer['commission'] ?? ($provider['default_commission'] ?? null);
                $rows[] = [
                    'category' => $categoryLabel,
                    'provider' => (string) ($provider['name'] ?? ''),
                    'offer' => (string) ($offer['name'] ?? ''),
                    'commission' => $commissionValue,
                ];
            }
        } else {
            $rows[] = [
                'category' => $categoryLabel,
                'provider' => (string) ($provider['name'] ?? ''),
                'offer' => '—',
                'commission' => $provider['default_commission'] ?? null,
            ];
        }
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Provvigioni</p>
                <h1 class="h4 mb-0">Tabella gestori e offerte</h1>
                <p class="text-muted mb-0">Valori caricati dall'amministrazione in Impostazioni &rarr; Opportunity.</p>
            </div>
            <div class="d-flex align-items-center gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo asset('modules/opportunities/collaborator/commissions.php'); ?>">
                    <i class="fa-solid fa-arrow-left me-2"></i>Torna alle provvigioni
                </a>
                <a class="btn btn-primary" href="<?php echo asset('modules/opportunities/collaborator/create.php'); ?>">
                    <i class="fa-solid fa-plus me-2"></i>Nuova OP
                </a>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                    <div>
                        <h2 class="h5 mb-1">Catalogo provvigioni</h2>
                        <p class="text-muted small mb-0">Controlla il valore di base per gestori e offerte disponibili.</p>
                    </div>
                    <span class="badge bg-light text-muted border"><?php echo count($rows); ?> righe</span>
                </div>
                <?php if ($rows): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr class="text-muted small">
                                    <th>Categoria</th>
                                    <th>Gestore</th>
                                    <th>Offerta</th>
                                    <th class="text-end">Provvigione</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php $commissionValue = $row['commission']; ?>
                                    <tr>
                                        <td class="text-uppercase text-muted small"><?php echo sanitize_output($row['category']); ?></td>
                                        <td class="fw-semibold"><?php echo sanitize_output($row['provider']); ?></td>
                                        <td><?php echo sanitize_output($row['offer']); ?></td>
                                        <td class="text-end fw-semibold">
                                            <?php echo $commissionValue !== null ? sanitize_output(number_format((float) $commissionValue, 2, ',', '.')) . ' €' : '—'; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">Nessuna provvigione configurata al momento.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
