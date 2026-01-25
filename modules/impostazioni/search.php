<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/global_search.php';

require_role('Admin', 'Manager', 'Operatore', 'Cliente');

$pageTitle = 'Risultati ricerca';

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$selectedType = trim((string) ($_GET['type'] ?? ''));
$selectedStatus = trim((string) ($_GET['status'] ?? ''));
$dateFrom = trim((string) ($_GET['from'] ?? ''));
$dateTo = trim((string) ($_GET['to'] ?? ''));

$userId = (int) ($_SESSION['user_id'] ?? 0);
$role = (string) ($_SESSION['role'] ?? '');
$userEmail = (string) ($_SESSION['email'] ?? '');

$payload = global_search($pdo, $searchQuery, [
    'limit' => 60,
    'role' => $role,
    'userId' => $userId,
    'userEmail' => $userEmail,
]);

$items = $payload['items'] ?? [];
$allowedTypes = $payload['allowedTypes'] ?? [];
$typeMeta = global_search_type_meta();

$fromTs = $dateFrom !== '' ? strtotime($dateFrom . ' 00:00:00') : null;
$toTs = $dateTo !== '' ? strtotime($dateTo . ' 23:59:59') : null;

$items = array_values(array_filter($items, static function (array $item) use ($selectedType, $selectedStatus, $fromTs, $toTs): bool {
    if ($selectedType !== '' && ($item['type'] ?? '') !== $selectedType) {
        return false;
    }
    if ($selectedStatus !== '' && isset($item['status'])) {
        if (strcasecmp((string) $item['status'], (string) $selectedStatus) !== 0) {
            return false;
        }
    }
    if ($fromTs || $toTs) {
        $itemTs = isset($item['date']) ? strtotime((string) $item['date']) : false;
        if (!$itemTs) {
            return false;
        }
        if ($fromTs && $itemTs < $fromTs) {
            return false;
        }
        if ($toTs && $itemTs > $toTs) {
            return false;
        }
    }
    return true;
}));

$statusOptions = [];
foreach ($items as $item) {
    if (!empty($item['status'])) {
        $statusOptions[] = (string) $item['status'];
    }
}
$statusOptions = array_values(array_unique($statusOptions));

$grouped = [];
foreach ($items as $item) {
    $grouped[$item['type']][] = $item;
}

$highlight = static function (string $text) use ($searchQuery): string {
    $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($searchQuery === '') {
        return $safe;
    }
    $pattern = '/' . preg_quote($searchQuery, '/') . '/i';
    return preg_replace($pattern, '<mark class="live-search-highlight">$0</mark>', $safe) ?? $safe;
};

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Risultati ricerca</h1>
                <p class="text-muted mb-0">Consulta tutti i risultati del gestionale.</p>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-12 col-lg-4">
                        <label class="form-label" for="searchQuery">Ricerca</label>
                        <input class="form-control" id="searchQuery" name="q" type="search" placeholder="Cerca clienti, pratiche, contratti, documenti…" value="<?php echo sanitize_output($searchQuery); ?>">
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="searchType">Tipo</label>
                        <select class="form-select" id="searchType" name="type">
                            <option value="">Tutti</option>
                            <?php foreach ($allowedTypes as $type): ?>
                                <option value="<?php echo sanitize_output($type); ?>"<?php echo $selectedType === $type ? ' selected' : ''; ?>><?php echo sanitize_output($typeMeta[$type]['label'] ?? ucfirst($type)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="searchStatus">Stato</label>
                        <select class="form-select" id="searchStatus" name="status">
                            <option value="">Tutti</option>
                            <?php foreach ($statusOptions as $status): ?>
                                <option value="<?php echo sanitize_output($status); ?>"<?php echo $selectedStatus === $status ? ' selected' : ''; ?>><?php echo sanitize_output($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="searchFrom">Dal</label>
                        <input class="form-control" id="searchFrom" name="from" type="date" value="<?php echo sanitize_output($dateFrom); ?>">
                    </div>
                    <div class="col-6 col-lg-2">
                        <label class="form-label" for="searchTo">Al</label>
                        <input class="form-control" id="searchTo" name="to" type="date" value="<?php echo sanitize_output($dateTo); ?>">
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary" type="submit">Cerca</button>
                        <a class="btn btn-outline-secondary" href="<?php echo base_url('modules/impostazioni/search.php'); ?>">Reset</a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($searchQuery === ''): ?>
            <div class="alert alert-info">Inserisci una parola chiave per avviare la ricerca globale.</div>
        <?php elseif (!$items): ?>
            <div class="alert alert-warning">Nessun risultato trovato per la ricerca.</div>
        <?php else: ?>
            <?php foreach ($grouped as $type => $groupItems): ?>
                <div class="mb-4">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h2 class="h5 mb-0"><?php echo sanitize_output($typeMeta[$type]['label'] ?? ucfirst($type)); ?></h2>
                        <span class="badge bg-light text-muted"><?php echo count($groupItems); ?></span>
                    </div>
                    <div class="row g-3">
                        <?php foreach ($groupItems as $item): ?>
                            <div class="col-12 col-lg-6">
                                <div class="card ag-card h-100">
                                    <div class="card-body d-flex gap-3">
                                        <div class="live-search-item-icon">
                                            <i class="fa-solid <?php echo sanitize_output($item['icon'] ?? ($typeMeta[$type]['icon'] ?? 'fa-circle-info')); ?>"></i>
                                        </div>
                                        <div class="flex-grow-1">
                                            <div class="fw-semibold mb-1"><?php echo $highlight($item['title'] ?? ''); ?></div>
                                            <?php if (!empty($item['subtitle'])): ?>
                                                <div class="text-muted small mb-2"><?php echo $highlight($item['subtitle']); ?></div>
                                            <?php endif; ?>
                                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                                <span class="badge rounded-pill bg-light text-muted"><?php echo sanitize_output($item['badge'] ?? ($typeMeta[$type]['label'] ?? ucfirst($type))); ?></span>
                                                <?php if (!empty($item['status'])): ?>
                                                    <span class="badge rounded-pill bg-secondary-subtle text-secondary-emphasis"><?php echo sanitize_output((string) $item['status']); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($item['date'])): ?>
                                                    <span class="text-muted small"><?php echo sanitize_output(format_datetime_locale($item['date'])); ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="ms-auto">
                                            <a class="btn btn-sm btn-outline-primary" href="<?php echo sanitize_output($item['url'] ?? '#'); ?>">Apri</a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
