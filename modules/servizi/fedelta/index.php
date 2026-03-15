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

$exportUrl = fedelta_module_url('index', array_merge($filterQueryParams, ['export' => 'csv']));
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
        <style>
            .fedelta-shell {
                display: grid;
                gap: 1.5rem;
            }

            .fedelta-hero {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(58, 123, 213, 0.14);
                background:
                    radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 34%),
                    radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), transparent 26%),
                    #fff;
            }

            .fedelta-pill {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.45rem 0.85rem;
                border-radius: 999px;
                background: rgba(58, 123, 213, 0.10);
                color: #2154d7;
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .fedelta-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .fedelta-kpi {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.15rem;
                padding: 1rem 1.1rem;
                background: rgba(255, 255, 255, 0.88);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
            }

            .fedelta-kpi-label {
                display: block;
                margin-bottom: 0.4rem;
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .fedelta-kpi-value {
                display: block;
                color: #0f172a;
                font-size: 1.85rem;
                font-weight: 800;
                line-height: 1;
            }

            .fedelta-kpi-note {
                display: block;
                margin-top: 0.45rem;
                color: #64748b;
                font-size: 0.86rem;
            }

            .fedelta-panel {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.3rem;
                background: #fff;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
            }

            .fedelta-summary-grid {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                gap: 1rem;
            }

            .fedelta-summary-card {
                border: 1px solid rgba(15, 23, 42, 0.07);
                border-radius: 1.1rem;
                padding: 1rem 1.1rem;
                background: linear-gradient(180deg, rgba(248,250,252,0.96), rgba(255,255,255,0.98));
            }

            .fedelta-table-card-body {
                padding: 1.25rem 1.25rem 1.4rem !important;
            }

            .fedelta-table-card-body .table-responsive {
                border: 1px solid rgba(15, 23, 42, 0.06);
                border-radius: 1rem;
                overflow: hidden;
            }

            .fedelta-table-card-body .dt-container .dt-layout-row:not(.dt-layout-table) {
                margin: 0;
                padding-inline: 0.15rem;
            }

            .fedelta-table-card-body .dt-container .dt-layout-row:first-child {
                padding-bottom: 1rem;
            }

            .fedelta-table-card-body .dt-container .dt-layout-row:last-child {
                padding-top: 1rem;
            }

            .fedelta-table {
                --bs-table-bg: transparent;
                --bs-table-hover-bg: rgba(37, 99, 235, 0.04);
                margin-bottom: 0;
            }

            .fedelta-table thead th {
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                white-space: nowrap;
            }

            .fedelta-table td {
                padding-top: 1rem;
                padding-bottom: 1rem;
                border-color: rgba(15, 23, 42, 0.06);
                vertical-align: middle;
            }

            .fedelta-id {
                display: inline-flex;
                padding: 0.42rem 0.68rem;
                border-radius: 0.8rem;
                background: #f8fafc;
                color: #0f172a;
                font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
                font-size: 0.8rem;
                font-weight: 700;
            }

            @media (max-width: 1199.98px) {
                .fedelta-kpis,
                .fedelta-summary-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767.98px) {
                .fedelta-kpis,
                .fedelta-summary-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <div class="fedelta-shell">
        <section class="card fedelta-hero">
            <div class="card-body p-4 p-xl-5">
                <div class="row g-4 align-items-start">
                    <div class="col-12 col-xl-7">
                        <span class="fedelta-pill"><i class="fa-solid fa-gift"></i>Loyalty operativa</span>
                        <h1 class="mt-3 mb-2 fw-bold" style="max-width: 12ch;">Programma fedeltà più chiaro per punti, premi e movimenti.</h1>
                        <p class="text-muted mb-0" style="max-width: 70ch;">
                            Controlla accrediti e riscatti, filtra rapidamente per cliente o periodo e mantieni una vista ordinata sul saldo punti attivo.
                        </p>
                    </div>
                    <div class="col-12 col-xl-5">
                        <div class="fedelta-kpis">
                            <div class="fedelta-kpi">
                                <span class="fedelta-kpi-label">Punti attivi</span>
                                <span class="fedelta-kpi-value"><?php echo loyalty_format_points((int) $filteredStats['totale']); ?></span>
                                <span class="fedelta-kpi-note">Saldo del filtro attivo</span>
                            </div>
                            <div class="fedelta-kpi">
                                <span class="fedelta-kpi-label">Accumulati</span>
                                <span class="fedelta-kpi-value">+<?php echo loyalty_format_points((int) $filteredStats['accumulati']); ?></span>
                                <span class="fedelta-kpi-note">Punti caricati nel periodo</span>
                            </div>
                            <div class="fedelta-kpi">
                                <span class="fedelta-kpi-label">Riscattati</span>
                                <span class="fedelta-kpi-value">-<?php echo loyalty_format_points((int) $filteredStats['riscattati']); ?></span>
                                <span class="fedelta-kpi-note">Premi e storni registrati</span>
                            </div>
                            <div class="fedelta-kpi">
                                <span class="fedelta-kpi-label">Movimenti</span>
                                <span class="fedelta-kpi-value"><?php echo (int) count($movements); ?></span>
                                <span class="fedelta-kpi-note"><?php echo $filtersApplied ? 'Risultati filtrati' : 'Storico disponibile'; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card fedelta-panel">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <h2 class="h5 mb-1">Filtri movimenti</h2>
                        <p class="text-muted small mb-0">Raffina il registro punti per cliente, tipologia, direzione, periodo o contenuto testuale.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-outline-warning" href="<?php echo dashboard_url(); ?>"><i class="fa-solid fa-gauge-high me-2"></i>Dashboard</a>
                        <a class="btn btn-warning text-dark" href="<?php echo fedelta_module_url('create'); ?>"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo movimento</a>
                    </div>
                </div>
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
                        <a class="btn btn-outline-warning flex-fill" href="<?php echo fedelta_module_url('index'); ?>"><i class="fa-solid fa-rotate-left me-2"></i>Reimposta</a>
                        <a class="btn btn-outline-secondary flex-fill" href="<?php echo sanitize_output($exportUrl); ?>" title="Esporta risultati in CSV"><i class="fa-solid fa-file-arrow-down me-2"></i>Export</a>
                    </div>
                </form>
            </div>
        </section>
        <section class="card fedelta-panel">
            <div class="card-header bg-transparent border-0 px-4 pt-4 pb-0">
                <h2 class="h5 mb-1">Sintesi punti</h2>
                <p class="text-muted small mb-0">Bilanciamento del programma fedeltà tra saldo attivo, accumuli e riscatti.</p>
            </div>
            <div class="card-body">
                <div class="fedelta-summary-grid">
                    <div class="fedelta-summary-card">
                            <div class="text-muted text-uppercase small mb-1">Punti attivi</div>
                            <div class="fs-3 fw-semibold"><?php echo loyalty_format_points((int) $filteredStats['totale']); ?> pt</div>
                            <?php if ($filtersApplied): ?>
                                <div class="text-muted small mt-2">Totale complessivo: <?php echo loyalty_format_points((int) $globalStats['totale']); ?> pt</div>
                            <?php endif; ?>
                    </div>
                    <div class="fedelta-summary-card">
                            <div class="text-muted text-uppercase small mb-1">Punti accumulati</div>
                            <div class="fs-3 fw-semibold text-success">+<?php echo loyalty_format_points((int) $filteredStats['accumulati']); ?> pt</div>
                    </div>
                    <div class="fedelta-summary-card">
                            <div class="text-muted text-uppercase small mb-1">Punti riscattati</div>
                            <div class="fs-3 fw-semibold text-danger">-<?php echo loyalty_format_points((int) $filteredStats['riscattati']); ?> pt</div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card fedelta-panel">
            <div class="card-header bg-transparent border-0 px-4 pt-4 pb-0">
                <h2 class="h5 mb-1">Storico movimenti</h2>
                <p class="text-muted small mb-0">Elenco operativo di accrediti e riscatti con saldo, premio e operatore responsabile.</p>
            </div>
            <div class="card-body fedelta-table-card-body">
                <?php if ($movements): ?>
                    <div class="table-responsive">
                    <table class="table table-hover align-middle fedelta-table" data-datatable="true">
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
                                        <td><span class="fedelta-id">#<?php echo (int) $movement['id']; ?></span></td>
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
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo fedelta_module_url('view', ['id' => (int) $movement['id']]); ?>" title="Dettagli">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo fedelta_module_url('edit', ['id' => (int) $movement['id']]); ?>" title="Modifica">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                <form method="post" action="<?php echo fedelta_module_url('delete'); ?>" class="d-inline" onsubmit="return confirm('Confermi eliminazione del movimento?');">
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
                            <a class="btn btn-outline-warning" href="<?php echo fedelta_module_url('index'); ?>"><i class="fa-solid fa-rotate-left me-2"></i>Reimposta filtri</a>
                        <?php else: ?>
                            <p class="mb-1">Ancora nessun movimento registrato per il programma fedeltà.</p>
                            <a class="btn btn-outline-warning" href="<?php echo fedelta_module_url('create'); ?>"><i class="fa-solid fa-circle-plus me-2"></i>Registra il primo movimento</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
