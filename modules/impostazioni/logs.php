<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Manager');
$pageTitle = 'Log attività';

$filters = [
    'utente' => $_GET['utente'] ?? '',
    'modulo' => $_GET['modulo'] ?? '',
    'dal' => $_GET['dal'] ?? '',
    'al' => $_GET['al'] ?? '',
];

$alerts = [];
$dateFrom = null;
$dateTo = null;
if ($filters['dal'] !== '') {
    $dateFrom = DateTimeImmutable::createFromFormat('Y-m-d', $filters['dal']);
    if (!$dateFrom) {
        $alerts[] = 'La data "Dal" non è valida.';
        $filters['dal'] = '';
    }
}

if ($filters['al'] !== '') {
    $dateTo = DateTimeImmutable::createFromFormat('Y-m-d', $filters['al']);
    if (!$dateTo) {
        $alerts[] = 'La data "Al" non è valida.';
        $filters['al'] = '';
    }
}

if ($dateFrom && $dateTo && $dateFrom > $dateTo) {
    $alerts[] = 'L\'intervallo temporale non è valido: la data iniziale supera quella finale.';
    $dateTo = null;
    $filters['al'] = '';
}

$params = [];
$conditions = [];

if ($filters['utente'] !== '') {
    $conditions[] = 'la.user_id = :utente';
    $params[':utente'] = (int) $filters['utente'];
}

if ($filters['modulo'] !== '') {
    $conditions[] = 'la.modulo = :modulo';
    $params[':modulo'] = $filters['modulo'];
}

if ($filters['dal'] !== '') {
    $conditions[] = 'la.created_at >= :dal';
    $params[':dal'] = $filters['dal'] . ' 00:00:00';
}

if ($filters['al'] !== '') {
    $conditions[] = 'la.created_at <= :al';
    $params[':al'] = $filters['al'] . ' 23:59:59';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$exportCsv = isset($_GET['export']) && $_GET['export'] === 'csv';
$totalLogs = 0;
$totalPages = 1;

if ($exportCsv) {
    $logStmt = $pdo->prepare("SELECT la.*, u.username FROM log_attivita la LEFT JOIN users u ON la.user_id = u.id $where ORDER BY la.created_at DESC");
    $logStmt->execute($params);
    $logs = $logStmt->fetchAll();
} else {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM log_attivita la $where");
    $countStmt->execute($params);
    $totalLogs = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalLogs / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $logStmt = $pdo->prepare("SELECT la.*, u.username FROM log_attivita la LEFT JOIN users u ON la.user_id = u.id $where ORDER BY la.created_at DESC LIMIT :limit OFFSET :offset");
    foreach ($params as $key => $value) {
        $logStmt->bindValue($key, $value);
    }
    $logStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $logStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $logStmt->execute();
    $logs = $logStmt->fetchAll();
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="log_attivita_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Data', 'Utente', 'Modulo', 'Azione', 'Dettagli']);
    foreach ($logs as $log) {
        fputcsv($output, [
            $log['created_at'],
            $log['username'] ?? 'Sistema',
            $log['modulo'],
            $log['azione'],
            $log['dettagli'],
        ]);
    }
    fclose($output);
    exit;
}

$users = $pdo->query('SELECT id, username FROM users ORDER BY username')->fetchAll();
$modules = $pdo->query('SELECT DISTINCT modulo FROM log_attivita ORDER BY modulo')->fetchAll(PDO::FETCH_COLUMN);
$exportQuery = array_merge($filters, ['export' => 'csv']);

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Registro attività</h1>
                <p class="text-muted mb-0">Traccia le azioni effettuate dagli utenti e scarica gli storici in CSV.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-gear me-2"></i>Impostazioni</a>
                <a class="btn btn-warning text-dark" href="?<?php echo http_build_query($exportQuery); ?>">
                    <i class="fa-solid fa-file-export me-2"></i>Esporta CSV
                </a>
            </div>
        </div>

        <?php foreach ($alerts as $alert): ?>
            <div class="alert alert-warning border-0 shadow-sm"><?php echo sanitize_output($alert); ?></div>
        <?php endforeach; ?>

        <form class="card ag-card mb-4" method="get">
            <div class="card-body row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label" for="utente">Utente</label>
                    <select class="form-select" id="utente" name="utente">
                        <option value="">Tutti</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo (int) $user['id']; ?>" <?php echo $filters['utente'] == $user['id'] ? 'selected' : ''; ?>>
                                <?php echo sanitize_output($user['username']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="modulo">Modulo</label>
                    <select class="form-select" id="modulo" name="modulo">
                        <option value="">Tutti</option>
                        <?php foreach ($modules as $module): ?>
                            <option value="<?php echo sanitize_output($module); ?>" <?php echo $filters['modulo'] === $module ? 'selected' : ''; ?>><?php echo sanitize_output($module); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="dal">Dal</label>
                    <input class="form-control" id="dal" name="dal" type="date" value="<?php echo sanitize_output($filters['dal']); ?>">
                </div>
                <div class="col-6 col-md-3">
                    <label class="form-label" for="al">Al</label>
                    <input class="form-control" id="al" name="al" type="date" value="<?php echo sanitize_output($filters['al']); ?>">
                </div>
                <div class="col-12 d-flex justify-content-end gap-2">
                    <a class="btn btn-outline-secondary" href="logs.php"><i class="fa-solid fa-arrows-rotate me-2"></i>Reimposta</a>
                    <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-filter me-2"></i>Applica filtri</button>
                </div>
            </div>
        </form>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Attività registrate</h5>
                <span class="badge bg-secondary">Totale: <?php echo sanitize_output((string) number_format($totalLogs)); ?></span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-hover" data-datatable="true">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Utente</th>
                                <th>Modulo</th>
                                <th>Azione</th>
                                <th>Dettagli</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td><?php echo sanitize_output(format_datetime($log['created_at'])); ?></td>
                                    <td><?php echo sanitize_output($log['username'] ?? 'Sistema'); ?></td>
                                    <td><?php echo sanitize_output($log['modulo']); ?></td>
                                    <td><?php echo sanitize_output($log['azione']); ?></td>
                                    <td><?php echo sanitize_output($log['dettagli']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$logs): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">Non ci sono attività per i filtri selezionati.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                    <span class="text-muted small">Mostrati <?php echo sanitize_output((string) number_format(count($logs))); ?> elementi su <?php echo sanitize_output((string) number_format($totalLogs)); ?> risultati totali.</span>
                    <?php if ($totalPages > 1): ?>
                        <nav aria-label="Paginazione registro attività">
                            <ul class="pagination pagination-sm mb-0">
                                <?php $prevQuery = array_merge($filters, ['page' => max(1, $page - 1)]); ?>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query($prevQuery); ?>" aria-label="Pagina precedente">&laquo;</a>
                                </li>
                                <?php
                                $window = 3;
                                $start = max(1, $page - $window);
                                $end = min($totalPages, $page + $window);
                                for ($i = $start; $i <= $end; $i++):
                                    $pageQuery = array_merge($filters, ['page' => $i]);
                                    ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query($pageQuery); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                                <?php $nextQuery = array_merge($filters, ['page' => min($totalPages, $page + 1)]); ?>
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query($nextQuery); ?>" aria-label="Pagina successiva">&raquo;</a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
