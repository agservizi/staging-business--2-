<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/pickup_service.php';

if (!CustomerAuth::isAuthenticated()) {
    header('Location: login.php');
    exit;
}

$customer = CustomerAuth::getAuthenticatedCustomer();
$pickupService = new PickupService();

$statusFilters = [
    'all' => 'Tutti',
    'reported' => 'Segnalati',
    'confirmed' => 'Confermati',
    'arrived' => 'Arrivati',
    'in_arrivo' => 'In arrivo',
    'consegnato' => 'Consegnati',
    'in_giacenza' => 'In giacenza',
    'in_giacenza_scaduto' => 'Giacenza scaduta',
    'ritirato' => 'Ritirati',
    'cancelled' => 'Annullati'
];

$activeFilter = $_GET['status'] ?? 'all';
if (!array_key_exists($activeFilter, $statusFilters)) {
    $activeFilter = 'all';
}

$searchQuery = trim($_GET['q'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = max(5, min((int) portal_config('default_page_size'), 50));
$offset = ($page - 1) * $pageSize;

$options = [
    'limit' => $pageSize + 1,
    'offset' => $offset
];

if ($activeFilter !== 'all') {
    $options['status'] = $activeFilter;
}

if ($searchQuery !== '') {
    $options['search'] = $searchQuery;
}

$packages = $pickupService->getCustomerPackages($customer['id'], $options);
$hasMore = count($packages) > $pageSize;

if ($hasMore) {
    array_pop($packages);
}

$statusCounts = $pickupService->getPackageStatusCounts($customer['id']);

$viewId = (int) ($_GET['view'] ?? 0);
$selectedPackage = null;
$detailError = null;

if ($viewId > 0) {
    $selectedPackage = $pickupService->getCustomerPackage($customer['id'], $viewId);
    if (!$selectedPackage) {
        $detailError = 'Il pacco richiesto non è stato trovato oppure non appartiene al tuo account.';
    }
}

$baseParams = [
    'status' => $activeFilter !== 'all' ? $activeFilter : null,
    'q' => $searchQuery !== '' ? $searchQuery : null,
];

$buildPackagesUrl = static function (array $overrides = []) use ($baseParams): string {
    $params = array_merge($baseParams, $overrides);
    $params = array_filter($params, static fn ($value) => $value !== null && $value !== '' && $value !== false);
    $queryString = http_build_query($params);
    return 'packages.php' . ($queryString ? '?' . $queryString : '');
};

$pageTitle = 'I miei pacchi';
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>
<?php require_once __DIR__ . '/includes/sidebar.php'; ?>

<div class="portal-main">
    <?php require_once __DIR__ . '/includes/topbar.php'; ?>

    <main class="portal-content">
        <div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3 mb-4">
            <div>
                <h1 class="h3 mb-1 d-flex align-items-center gap-2"><i class="fa-solid fa-box-open text-primary"></i>I miei pacchi</h1>
                <p class="text-muted-soft mb-0">Monitora lo stato dei tuoi pacchi, accedi ai dettagli e scarica le informazioni utili per il ritiro.</p>
            </div>
            <div class="d-flex flex-wrap gap-2 justify-content-end">
                <a class="btn topbar-btn" href="report.php">
                    <i class="fa-solid fa-plus"></i>
                    <span class="topbar-btn-label">Segnala pacco</span>
                </a>
                <a class="btn topbar-btn" href="notifications.php">
                    <i class="fa-solid fa-bell"></i>
                    <span class="topbar-btn-label">Notifiche</span>
                </a>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form class="row gy-2 gx-3 align-items-center" method="GET" action="packages.php">
                    <input type="hidden" name="status" value="<?= htmlspecialchars($activeFilter) ?>">
                    <div class="col-sm-6 col-lg-4">
                        <label class="form-label visually-hidden" for="packages-search">Cerca</label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
                            <input class="form-control" id="packages-search" name="q" type="search" placeholder="Cerca per tracking, destinatario o corriere" value="<?= htmlspecialchars($searchQuery) ?>">
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-auto">
                        <label class="visually-hidden" for="packages-page">Pagina</label>
                        <div class="input-group">
                            <span class="input-group-text">Pagina</span>
                            <input class="form-control" type="number" min="1" id="packages-page" name="page" value="<?= $page ?>">
                        </div>
                    </div>
                    <div class="col-12 col-lg-auto d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-rotate me-1"></i>Aggiorna</button>
                        <?php if ($searchQuery !== '' || $activeFilter !== 'all' || $page > 1): ?>
                            <a class="btn btn-outline-secondary" href="packages.php">Azzera filtri</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2 mb-4">
            <?php foreach ($statusFilters as $statusKey => $label): ?>
                <?php
                $count = $statusCounts[$statusKey] ?? 0;
                $isActive = $statusKey === $activeFilter;
                $url = $buildPackagesUrl([
                    'status' => $statusKey === 'all' ? null : $statusKey,
                    'page' => 1,
                    'view' => null
                ]);
                ?>
                <a class="badge rounded-pill <?= $isActive ? 'bg-primary text-white' : 'bg-light text-secondary' ?> px-3 py-2" href="<?= htmlspecialchars($url) ?>">
                    <span class="fw-semibold"><?= htmlspecialchars($label) ?></span>
                    <span class="ms-1">(<?= $count ?>)</span>
                </a>
            <?php endforeach; ?>
            <span class="badge rounded-pill bg-info-subtle text-info-emphasis px-3 py-2 ms-auto">Totale: <?= $statusCounts['all'] ?? 0 ?></span>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xxl-8">
                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0 pb-0">
                        <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">
                            <div>
                                <h2 class="h5 mb-1">Elenco pacchi</h2>
                                <p class="text-muted-soft mb-0"><?= count($packages) ?> risultati mostrati<?= $hasMore ? ' · scorri per vedere altri' : '' ?></p>
                            </div>
                            <div class="small text-muted-soft">
                                Aggiornato al <?= date('d/m/Y H:i') ?>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <?php if (empty($packages)): ?>
                            <div class="dashboard-empty-state dashboard-empty-state-compact">
                                <span class="dashboard-empty-icon"><i class="fa-solid fa-box"></i></span>
                                <h3 class="dashboard-empty-title">Nessun pacco trovato</h3>
                                <p class="dashboard-empty-text">Prova a modificare i filtri di ricerca oppure registra subito un nuovo pacco.</p>
                                <a class="btn btn-primary" href="report.php"><i class="fa-solid fa-plus me-2"></i>Segnala pacco</a>
                            </div>
                        <?php else: ?>
                            <div class="list-group list-group-flush">
                                <?php foreach ($packages as $package): ?>
                                    <?php
                                    $packageStatus = $package['pickup_status'] ?: $package['status'];
                                    $statusBadge = $pickupService->getStatusBadge($packageStatus);
                                    $packageUrl = $buildPackagesUrl([
                                        'view' => (int) $package['id'],
                                        'page' => $page,
                                        'status' => $activeFilter === 'all' ? null : $activeFilter
                                    ]);
                                    $isSelected = $selectedPackage && (int) $selectedPackage['id'] === (int) $package['id'];
                                    ?>
                                    <a class="list-group-item list-group-item-action p-3<?= $isSelected ? ' active bg-primary-subtle border-primary' : '' ?>" href="<?= htmlspecialchars($packageUrl) ?>">
                                        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3">
                                            <div class="d-flex flex-column gap-1">
                                                <div class="d-flex align-items-center gap-2">
                                                    <span class="fw-semibold text-uppercase small text-muted">Tracking</span>
                                                    <span class="text-truncate fw-semibold"><?= htmlspecialchars($package['tracking_code']) ?></span>
                                                </div>
                                                <div class="text-muted small d-flex flex-wrap gap-2">
                                                    <?php if (!empty($package['courier_name'])): ?>
                                                        <span><i class="fa-solid fa-truck fa-sm me-1"></i><?= htmlspecialchars($package['courier_name']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($package['recipient_name'])): ?>
                                                        <span><i class="fa-solid fa-user fa-sm me-1"></i><?= htmlspecialchars($package['recipient_name']) ?></span>
                                                    <?php endif; ?>
                                                    <span><i class="fa-regular fa-clock fa-sm me-1"></i><?= date('d/m/Y H:i', strtotime($package['created_at'])) ?></span>
                                                    <?php if (!empty($package['location_name'])): ?>
                                                        <span><i class="fa-solid fa-location-dot fa-sm me-1"></i><?= htmlspecialchars($package['location_name']) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="text-end ms-lg-auto">
                                                <?= $statusBadge ?>
                                                <?php if (!empty($package['expected_delivery_date'])): ?>
                                                    <div class="small text-muted mt-1">Consegna prevista <?= date('d/m/Y', strtotime($package['expected_delivery_date'])) ?></div>
                                                <?php endif; ?>
                                                <?php if (!empty($package['delivered_at'])): ?>
                                                    <div class="small text-muted">Consegnato <?= date('d/m/Y H:i', strtotime($package['delivered_at'])) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            </div>

                            <div class="d-flex justify-content-between align-items-center mt-4">
                                <div class="small text-muted">Pagina <?= $page ?><?= $hasMore ? ' · ci sono altri risultati' : '' ?></div>
                                <div class="btn-group">
                                    <?php if ($page > 1): ?>
                                        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($buildPackagesUrl(['page' => $page - 1])) ?>"><i class="fa-solid fa-arrow-left"></i></a>
                                    <?php endif; ?>
                                    <?php if ($hasMore): ?>
                                        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($buildPackagesUrl(['page' => $page + 1])) ?>"><i class="fa-solid fa-arrow-right"></i></a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xxl-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-header border-0">
                        <h2 class="h5 mb-0">Dettagli pacco</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($detailError): ?>
                            <div class="alert alert-warning" role="alert">
                                <i class="fa-solid fa-triangle-exclamation me-2"></i><?= htmlspecialchars($detailError) ?>
                            </div>
                        <?php elseif (!$selectedPackage): ?>
                            <div class="text-center text-muted-soft py-4">
                                <i class="fa-solid fa-box-open fa-2x mb-3"></i>
                                <p class="mb-0">Seleziona un pacco dall'elenco per vedere i dettagli.</p>
                            </div>
                        <?php else: ?>
                            <dl class="row mb-0">
                                <dt class="col-4 text-muted">Tracking</dt>
                                <dd class="col-8 fw-semibold"><?= htmlspecialchars($selectedPackage['tracking_code']) ?></dd>

                                <dt class="col-4 text-muted">Stato</dt>
                                <dd class="col-8"><?= $pickupService->getStatusBadge($selectedPackage['pickup_status'] ?: $selectedPackage['status']) ?></dd>

                                <dt class="col-4 text-muted">Corriere</dt>
                                <dd class="col-8"><?= htmlspecialchars($selectedPackage['courier_name'] ?: 'Non indicato') ?></dd>

                                <dt class="col-4 text-muted">Destinatario</dt>
                                <dd class="col-8"><?= htmlspecialchars($selectedPackage['recipient_name'] ?: 'Non indicato') ?></dd>

                                <?php if (!empty($selectedPackage['expected_delivery_date'])): ?>
                                    <dt class="col-4 text-muted">Previsto</dt>
                                    <dd class="col-8"><?= date('d/m/Y', strtotime($selectedPackage['expected_delivery_date'])) ?></dd>
                                <?php endif; ?>

                                <?php if (!empty($selectedPackage['delivery_location'])): ?>
                                    <dt class="col-4 text-muted">Luogo</dt>
                                    <dd class="col-8"><?= htmlspecialchars($selectedPackage['delivery_location']) ?></dd>
                                <?php endif; ?>

                                <?php if (!empty($selectedPackage['location_name'])): ?>
                                    <dt class="col-4 text-muted">Punto pickup</dt>
                                    <dd class="col-8">
                                        <div><?= htmlspecialchars($selectedPackage['location_name']) ?></div>
                                        <?php if (!empty($selectedPackage['location_address'])): ?>
                                            <div class="small text-muted"><?= htmlspecialchars($selectedPackage['location_address']) ?></div>
                                        <?php endif; ?>
                                    </dd>
                                <?php endif; ?>

                                <?php if (!empty($selectedPackage['notes'])): ?>
                                    <dt class="col-4 text-muted">Note</dt>
                                    <dd class="col-8"><?= nl2br(htmlspecialchars($selectedPackage['notes'])) ?></dd>
                                <?php endif; ?>

                                <dt class="col-4 text-muted">Segnalato</dt>
                                <dd class="col-8"><?= date('d/m/Y H:i', strtotime($selectedPackage['created_at'])) ?></dd>

                                <?php if (!empty($selectedPackage['pickup_created_at'])): ?>
                                    <dt class="col-4 text-muted">Preso in carico</dt>
                                    <dd class="col-8"><?= date('d/m/Y H:i', strtotime($selectedPackage['pickup_created_at'])) ?></dd>
                                <?php endif; ?>

                                <?php if (!empty($selectedPackage['delivered_at'])): ?>
                                    <dt class="col-4 text-muted">Consegnato</dt>
                                    <dd class="col-8"><?= date('d/m/Y H:i', strtotime($selectedPackage['delivered_at'])) ?></dd>
                                <?php endif; ?>

                                <?php if (!empty($selectedPackage['pickup_id'])): ?>
                                    <dt class="col-4 text-muted">ID pickup</dt>
                                    <dd class="col-8">#<?= (int) $selectedPackage['pickup_id'] ?></dd>
                                <?php endif; ?>
                            </dl>

                            <div class="d-flex flex-column gap-2 mt-4">
                                <button class="btn btn-outline-primary" type="button" onclick="navigator.clipboard?.writeText(<?= json_encode($selectedPackage['tracking_code']) ?>)"><i class="fa-solid fa-copy me-2"></i>Copia tracking</button>
                                <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($buildPackagesUrl(['view' => null])) ?>">Chiudi dettagli</a>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
