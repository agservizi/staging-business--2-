<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/loyalty_helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Programma Fedeltà';

$movementTypes = loyalty_movement_types();

$clientsStmt = $pdo->query('SELECT id, nome, cognome FROM clienti ORDER BY cognome, nome');
$clients = $clientsStmt ? $clientsStmt->fetchAll() : [];

$formFilters = [
    'cliente_id' => '',
    'tipo_movimento' => '',
    'direzione' => '',
    'date_from' => '',
    'date_to' => '',
    'search' => '',
];

$filters = [
    'cliente_id' => null,
    'tipo_movimento' => null,
    'direzione' => null,
    'search' => '',
];

$dateFromObj = null;
$dateToObj = null;

if (isset($_GET['cliente_id']) && $_GET['cliente_id'] !== '') {
    if (ctype_digit((string) $_GET['cliente_id'])) {
        $formFilters['cliente_id'] = (string) $_GET['cliente_id'];
        $filters['cliente_id'] = (int) $_GET['cliente_id'];
    }
}

if (!empty($_GET['tipo_movimento']) && isset($movementTypes[$_GET['tipo_movimento']])) {
    $formFilters['tipo_movimento'] = (string) $_GET['tipo_movimento'];
    $filters['tipo_movimento'] = (string) $_GET['tipo_movimento'];
}

if (!empty($_GET['direzione']) && in_array($_GET['direzione'], ['credit', 'debit'], true)) {
    $formFilters['direzione'] = (string) $_GET['direzione'];
    $filters['direzione'] = (string) $_GET['direzione'];
}

if (!empty($_GET['date_from'])) {
    $dateFrom = DateTimeImmutable::createFromFormat('Y-m-d', $_GET['date_from']);
    if ($dateFrom) {
        $dateFromObj = $dateFrom;
        $formFilters['date_from'] = $dateFrom->format('Y-m-d');
    }
}

if (!empty($_GET['date_to'])) {
    $dateTo = DateTimeImmutable::createFromFormat('Y-m-d', $_GET['date_to']);
    if ($dateTo) {
        $dateToObj = $dateTo;
        $formFilters['date_to'] = $dateTo->format('Y-m-d');
    }
}

$filters['search'] = trim($_GET['search'] ?? '');
$formFilters['search'] = $filters['search'];

$conditions = [];
$params = [];

if ($filters['cliente_id'] !== null) {
    $conditions[] = 'fm.cliente_id = :cliente_id';
    $params[':cliente_id'] = $filters['cliente_id'];
}

if ($filters['tipo_movimento'] !== null) {
    $conditions[] = 'fm.tipo_movimento = :tipo_movimento';
    $params[':tipo_movimento'] = $filters['tipo_movimento'];
}

if ($filters['direzione'] === 'credit') {
    $conditions[] = 'fm.punti > 0';
} elseif ($filters['direzione'] === 'debit') {
    $conditions[] = 'fm.punti < 0';
}

if ($dateFromObj instanceof DateTimeImmutable) {
    $conditions[] = 'fm.data_movimento >= :date_from';
    $params[':date_from'] = $dateFromObj->setTime(0, 0)->format('Y-m-d H:i:s');
}

if ($dateToObj instanceof DateTimeImmutable) {
    $conditions[] = 'fm.data_movimento <= :date_to';
    $params[':date_to'] = $dateToObj->setTime(23, 59, 59)->format('Y-m-d H:i:s');
}

if ($filters['search'] !== '') {
    $conditions[] = '(fm.descrizione LIKE :search OR fm.ricompensa LIKE :search OR fm.operatore LIKE :search OR c.nome LIKE :search OR c.cognome LIKE :search)';
    $params[':search'] = '%' . $filters['search'] . '%';
}

$baseQuery = 'FROM fedelta_movimenti fm LEFT JOIN clienti c ON fm.cliente_id = c.id';
$whereSql = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';
$orderBySql = ' ORDER BY fm.data_movimento DESC, fm.id DESC';

$movementsSql = 'SELECT fm.id,
                        fm.cliente_id,
                        fm.tipo_movimento,
                        fm.descrizione,
                        fm.punti,
                        fm.saldo_post_movimento,
                        fm.ricompensa,
                        fm.operatore,
                        fm.data_movimento,
                        c.nome,
                        c.cognome ' . $baseQuery . $whereSql . $orderBySql;
$movementsStmt = $pdo->prepare($movementsSql);
$movementsStmt->execute($params);
$movements = $movementsStmt->fetchAll();

$globalStatsStmt = $pdo->query("SELECT
    COALESCE(SUM(punti), 0) AS totale,
    COALESCE(SUM(CASE WHEN punti > 0 THEN punti ELSE 0 END), 0) AS accumulati,
    COALESCE(ABS(SUM(CASE WHEN punti < 0 THEN punti ELSE 0 END)), 0) AS riscattati
FROM fedelta_movimenti");
$globalStats = $globalStatsStmt ? $globalStatsStmt->fetch(PDO::FETCH_ASSOC) : false;
if (!$globalStats) {
    $globalStats = ['totale' => 0, 'accumulati' => 0, 'riscattati' => 0];
}

$filteredStatsStmt = $pdo->prepare('SELECT
    COALESCE(SUM(punti), 0) AS totale,
    COALESCE(SUM(CASE WHEN punti > 0 THEN punti ELSE 0 END), 0) AS accumulati,
    COALESCE(ABS(SUM(CASE WHEN punti < 0 THEN punti ELSE 0 END)), 0) AS riscattati ' . $baseQuery . $whereSql);
$filteredStatsStmt->execute($params);
$filteredStats = $filteredStatsStmt->fetch(PDO::FETCH_ASSOC) ?: ['totale' => 0, 'accumulati' => 0, 'riscattati' => 0];

$filtersApplied = $formFilters['cliente_id'] !== ''
    || $formFilters['tipo_movimento'] !== ''
    || $formFilters['direzione'] !== ''
    || $formFilters['date_from'] !== ''
    || $formFilters['date_to'] !== ''
    || $formFilters['search'] !== '';

$filterQueryParams = [];
foreach ($formFilters as $key => $value) {
    if ($value !== '' && $value !== null) {
        $filterQueryParams[$key] = $value;
    }
}

$exportUrl = 'index.php?' . http_build_query(array_merge($filterQueryParams, ['export' => 'csv']));
$isExport = isset($_GET['export']) && $_GET['export'] === 'csv';

if ($isExport) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="fedelta_movimenti_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'wb');
    if ($output) {
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, ['ID', 'Cliente', 'Tipologia', 'Descrizione', 'Punti', 'Saldo', 'Ricompensa', 'Operatore', 'Data movimento']);
        foreach ($movements as $movement) {
            $customerName = trim((string) (($movement['cognome'] ?? '') . ' ' . ($movement['nome'] ?? '')));
            if ($customerName === '') {
                $customerName = 'N/D';
            }

            fputcsv($output, [
                (int) $movement['id'],
                $customerName,
                (string) $movement['tipo_movimento'],
                (string) $movement['descrizione'],
                (int) $movement['punti'],
                (int) ($movement['saldo_post_movimento'] ?? 0),
                $movement['ricompensa'] !== null ? (string) $movement['ricompensa'] : '',
                (string) ($movement['operatore'] ?? ''),
                (string) $movement['data_movimento'],
            ]);
        }
        fclose($output);
    }
    exit;
}

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Programma Fedeltà</h1>
                <p class="text-muted mb-0">Monitoraggio dei movimenti punti tra accumulo e riscatti.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo movimento</a>
            </div>
        </div>
        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Filtri</h2>
            </div>
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label" for="filter_cliente">Cliente</label>
                        <select class="form-select" id="filter_cliente" name="cliente_id">
                            <option value="">Tutti i clienti</option>
                            <?php foreach ($clients as $client): ?>
                                <?php $clientId = (int) $client['id']; ?>
                                <?php
                                    $clientLabel = trim((string) (($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? '')));
                                    if ($clientLabel === '') {
                                        $clientLabel = 'Cliente #' . $clientId;
                                    }
                                ?>
                                <option value="<?php echo $clientId; ?>" <?php echo $formFilters['cliente_id'] === (string) $clientId ? 'selected' : ''; ?>><?php echo sanitize_output($clientLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label" for="filter_tipo">Tipologia</label>
                        <select class="form-select" id="filter_tipo" name="tipo_movimento">
                            <option value="">Tutte le tipologie</option>
                            <?php foreach ($movementTypes as $typeKey => $config): ?>
                                <option value="<?php echo sanitize_output($typeKey); ?>" <?php echo $formFilters['tipo_movimento'] === $typeKey ? 'selected' : ''; ?>><?php echo sanitize_output($config['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6 col-lg-2">
                        <label class="form-label" for="filter_direzione">Direzione</label>
                        <select class="form-select" id="filter_direzione" name="direzione">
                            <option value="">Accrediti e riscatti</option>
                            <option value="credit" <?php echo $formFilters['direzione'] === 'credit' ? 'selected' : ''; ?>>Solo accrediti</option>
                            <option value="debit" <?php echo $formFilters['direzione'] === 'debit' ? 'selected' : ''; ?>>Solo riscatti</option>
                        </select>
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label" for="filter_date_from">Dal</label>
                        <input class="form-control" id="filter_date_from" name="date_from" type="date" value="<?php echo sanitize_output($formFilters['date_from']); ?>">
                    </div>
                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label" for="filter_date_to">Al</label>
                        <input class="form-control" id="filter_date_to" name="date_to" type="date" value="<?php echo sanitize_output($formFilters['date_to']); ?>">
                    </div>
                    <div class="col-12 col-md-6 col-lg-3">
                        <label class="form-label" for="filter_search">Ricerca</label>
                        <input class="form-control" id="filter_search" name="search" type="search" value="<?php echo sanitize_output($formFilters['search']); ?>" placeholder="Descrizione, ricompensa, operatore">
                    </div>
                    <div class="col-12 col-lg-3 d-flex flex-wrap gap-2 mt-2">
                        <button class="btn btn-warning text-dark flex-fill" type="submit"><i class="fa-solid fa-filter me-2"></i>Applica</button>
                        <a class="btn btn-outline-warning flex-fill" href="index.php"><i class="fa-solid fa-rotate-left me-2"></i>Reimposta</a>
                        <a class="btn btn-outline-secondary flex-fill" href="<?php echo sanitize_output($exportUrl); ?>" title="Esporta risultati in CSV"><i class="fa-solid fa-file-arrow-down me-2"></i>Export</a>
                    </div>
                </form>
            </div>
        </div>
        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Sintesi punti</h2>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="border border-dark-subtle rounded-3 px-3 py-3 h-100">
                            <div class="text-muted text-uppercase small mb-1">Punti attivi</div>
                            <div class="fs-3 fw-semibold"><?php echo loyalty_format_points((int) $filteredStats['totale']); ?> pt</div>
                            <?php if ($filtersApplied): ?>
                                <div class="text-muted small mt-2">Totale complessivo: <?php echo loyalty_format_points((int) $globalStats['totale']); ?> pt</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border border-dark-subtle rounded-3 px-3 py-3 h-100">
                            <div class="text-muted text-uppercase small mb-1">Punti accumulati</div>
                            <div class="fs-3 fw-semibold text-success">+<?php echo loyalty_format_points((int) $filteredStats['accumulati']); ?> pt</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border border-dark-subtle rounded-3 px-3 py-3 h-100">
                            <div class="text-muted text-uppercase small mb-1">Punti riscattati</div>
                            <div class="fs-3 fw-semibold text-danger">-<?php echo loyalty_format_points((int) $filteredStats['riscattati']); ?> pt</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Storico movimenti</h2>
            </div>
            <div class="card-body">
                <?php if ($movements): ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle" data-datatable="true">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Cliente</th>
                                    <th>Tipologia</th>
                                    <th>Descrizione</th>
                                    <th class="text-end">Punti</th>
                                    <th class="text-end">Saldo</th>
                                    <th>Ricompensa</th>
                                    <th>Operatore</th>
                                    <th>Data</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($movements as $movement): ?>
                                    <?php $isCredit = ((int) $movement['punti']) >= 0; ?>
                                    <tr>
                                        <td>#<?php echo (int) $movement['id']; ?></td>
                                        <td><?php echo sanitize_output(trim(($movement['cognome'] ?? '') . ' ' . ($movement['nome'] ?? '')) ?: 'N/D'); ?></td>
                                        <td>
                                            <span class="badge ag-badge text-uppercase <?php echo $isCredit ? 'bg-success text-dark' : 'bg-danger'; ?>">
                                                <?php echo sanitize_output($movement['tipo_movimento']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo sanitize_output($movement['descrizione']); ?></td>
                                        <td class="text-end fw-semibold <?php echo $isCredit ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo $isCredit ? '+' : ''; ?><?php echo loyalty_format_points((int) $movement['punti']); ?>
                                        </td>
                                        <td class="text-end"><?php echo loyalty_format_points((int) ($movement['saldo_post_movimento'] ?? 0)); ?></td>
                                        <td>
                                            <?php if ($movement['ricompensa']): ?>
                                                <?php echo sanitize_output($movement['ricompensa']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo sanitize_output($movement['operatore'] ?: 'Sistema'); ?></td>
                                        <td><?php echo sanitize_output(format_datetime_locale($movement['data_movimento'])); ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap">
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $movement['id']; ?>" title="Dettagli">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="edit.php?id=<?php echo (int) $movement['id']; ?>" title="Modifica">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                <form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('Confermi eliminazione del movimento?');">
                                                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                    <input type="hidden" name="id" value="<?php echo (int) $movement['id']; ?>">
                                                    <button class="btn btn-icon btn-soft-danger btn-sm" type="submit" title="Elimina">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-5">
                        <i class="fa-solid fa-gift fa-2x mb-3"></i>
                        <?php if ($filtersApplied): ?>
                            <p class="mb-1">Nessun movimento corrisponde ai filtri selezionati.</p>
                            <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-rotate-left me-2"></i>Reimposta filtri</a>
                        <?php else: ?>
                            <p class="mb-1">Ancora nessun movimento registrato per il programma fedeltà.</p>
                            <a class="btn btn-outline-warning" href="create.php"><i class="fa-solid fa-circle-plus me-2"></i>Registra il primo movimento</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
