<?php
declare(strict_types=1);


define('CORESUITE_BRT_BOOTSTRAP', true);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
$autoloadPath = __DIR__ . '/../../../vendor/autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

try {
    ensure_brt_tables();
} catch (RuntimeException $exception) {
    http_response_code(500);
    exit('Database BRT non configurato: ' . $exception->getMessage());
}

$pageTitle = 'Log attivita BRT';

$levelFilter = strtolower(trim((string) ($_GET['level'] ?? '')));
$levelOptions = [
    '' => 'Tutti',
    'info' => 'Info',
    'warning' => 'Avvisi',
    'error' => 'Errori',
];

if ($levelFilter !== '' && !in_array($levelFilter, BRT_LOG_LEVELS, true)) {
    add_flash('warning', 'Livello di log non valido.');
    $levelFilter = '';
}

$logs = brt_get_logs(200, $levelFilter !== '' ? $levelFilter : null);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-center">
            <div>
                <h1 class="h3 mb-0">Log attivita BRT</h1>
                <p class="text-muted mb-0">Storico delle operazioni e degli avvisi generati dal modulo BRT.</p>
            </div>
            <div class="toolbar-actions d-flex align-items-center gap-2">
                <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Torna alle spedizioni</a>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-md-4 col-lg-3">
                        <label class="form-label" for="level">Livello</label>
                        <select class="form-select" id="level" name="level">
                            <?php foreach ($levelOptions as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>"<?php echo $levelFilter === $value ? ' selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 col-lg-3 d-flex gap-2">
                        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass me-2"></i>Filtra</button>
                        <a class="btn btn-outline-secondary" href="log.php"><i class="fa-solid fa-rotate-left me-2"></i>Reimposta</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="card-title h5 mb-0">Ultimi eventi</h2>
                <span class="text-muted small">Mostrati al massimo 200 eventi</span>
            </div>
            <div class="card-body p-0">
                <?php if ($logs === []): ?>
                    <div class="p-4 text-center text-muted">Nessun evento registrato al momento.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Data</th>
                                    <th scope="col">Livello</th>
                                    <th scope="col">Messaggio</th>
                                    <th scope="col">Utente</th>
                                    <th scope="col">Contesto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                    <?php
                                        $level = (string) ($log['level'] ?? 'info');
                                        $badgeClass = 'bg-secondary';
                                        if ($level === 'warning') {
                                            $badgeClass = 'bg-warning text-dark';
                                        } elseif ($level === 'error') {
                                            $badgeClass = 'bg-danger';
                                        } elseif ($level === 'info') {
                                            $badgeClass = 'bg-info text-dark';
                                        }
                                        $contextData = $log['context'] ?? [];
                                        $contextJson = $contextData !== [] ? json_encode($contextData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
                                    ?>
                                    <tr>
                                        <td class="align-middle"><span class="text-nowrap"><?php echo sanitize_output(format_datetime_locale($log['created_at'] ?? null)); ?></span></td>
                                        <td class="align-middle"><span class="badge <?php echo sanitize_output($badgeClass); ?> text-uppercase small"><?php echo sanitize_output($level); ?></span></td>
                                        <td class="align-middle"><?php echo sanitize_output($log['message'] ?? ''); ?></td>
                                        <td class="align-middle"><?php echo sanitize_output($log['created_by'] ?? ''); ?></td>
                                        <td class="align-middle">
                                            <?php if ($contextJson === null): ?>
                                                <span class="text-muted small">N/A</span>
                                            <?php else: ?>
                                                <pre class="small mb-0 text-break"><?php echo sanitize_output($contextJson); ?></pre>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    <?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
</div>
<?php require_once __DIR__ . '/../../../includes/scripts.php'; ?>
