<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

if (!defined('CORESUITE_PICKUP_BOOTSTRAP')) {
    define('CORESUITE_PICKUP_BOOTSTRAP', true);
}

require_once __DIR__ . '/functions.php';

ensure_pickup_tables();

$redirectQuery = $_GET;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $action = $_POST['action'] ?? '';
    $reportId = isset($_POST['report_id']) ? (int) $_POST['report_id'] : 0;

    try {
        if ($action === 'update_report_status') {
            $status = (string) ($_POST['status'] ?? '');
            if ($reportId <= 0) {
                throw new InvalidArgumentException('Segnalazione non valida.');
            }
            update_customer_report_status($reportId, $status);
            add_flash('success', 'Stato segnalazione aggiornato.');
        } elseif ($action === 'link_report') {
            $packageId = isset($_POST['package_id']) ? (int) $_POST['package_id'] : 0;
            $statusAfter = (string) ($_POST['status_after'] ?? 'confirmed');
            if ($reportId <= 0 || $packageId <= 0) {
                throw new InvalidArgumentException('Dati collegamento non validi.');
            }
            $package = get_package_details($packageId);
            if (!$package) {
                throw new RuntimeException('Pickup non trovato.');
            }
            $trackingRaw = (string) ($package['tracking'] ?? '');
            $trackingLabel = preg_replace('/^\s+|\s+$/u', '', $trackingRaw);
            if ($trackingLabel === null) {
                $trackingLabel = $trackingRaw;
            }
            link_customer_report_to_pickup($reportId, $packageId, $statusAfter);
            add_flash('success', 'Segnalazione collegata al pickup #' . ($trackingLabel !== '' ? $trackingLabel : (string) $packageId) . '.');
        } elseif ($action === 'unlink_report') {
            if ($reportId <= 0) {
                throw new InvalidArgumentException('Segnalazione non valida.');
            }
            unlink_customer_report($reportId);
            add_flash('success', 'Segnalazione scollegata dal pickup.');
        } elseif ($action === 'auto_link_report') {
            if ($reportId <= 0) {
                throw new InvalidArgumentException('Segnalazione non valida.');
            }
            $report = get_customer_report($reportId);
            if (!$report) {
                throw new RuntimeException('Segnalazione non trovata.');
            }
            $trackingCodeRaw = (string) ($report['tracking_code'] ?? '');
            $trackingCode = preg_replace('/^\s+|\s+$/u', '', $trackingCodeRaw);
            if ($trackingCode === null) {
                $trackingCode = $trackingCodeRaw;
            }
            if ($trackingCode === '') {
                throw new RuntimeException('La segnalazione non contiene un codice tracking.');
            }
            $package = get_package_by_tracking($trackingCode);
            if (!$package) {
                throw new RuntimeException('Nessun pickup corrisponde al tracking indicato.');
            }
            $packageTrackingRaw = (string) ($package['tracking'] ?? '');
            $packageTracking = preg_replace('/^\s+|\s+$/u', '', $packageTrackingRaw);
            if ($packageTracking === null) {
                $packageTracking = $packageTrackingRaw;
            }
            link_customer_report_to_pickup($reportId, (int) $package['id'], 'confirmed');
            add_flash('success', 'Segnalazione collegata automaticamente al pickup #' . ($packageTracking !== '' ? $packageTracking : (string) $package['id']) . '.');
        } else {
            throw new InvalidArgumentException('Azione non riconosciuta.');
        }
    } catch (Throwable $exception) {
        add_flash('warning', $exception->getMessage());
        error_log('Pickup portal report action failed: ' . $exception->getMessage());
    }

    $queryString = $redirectQuery ? '?' . http_build_query($redirectQuery) : '';
    header('Location: reports.php' . $queryString);
    exit;
}

$statuses = pickup_customer_report_statuses();
$statusFilter = isset($_GET['status']) ? (string) $_GET['status'] : '';
if ($statusFilter !== '' && !in_array($statusFilter, $statuses, true)) {
    $statusFilter = '';
}

$onlyUnlinked = ($_GET['only_unlinked'] ?? '') === '1';
$search = trim((string) ($_GET['search'] ?? ''));
$from = trim((string) ($_GET['from'] ?? ''));
$to = trim((string) ($_GET['to'] ?? ''));
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
$limit = max(10, min($limit, 200));

$filters = [];
if ($statusFilter !== '') {
    $filters['status'] = $statusFilter;
}
if ($onlyUnlinked) {
    $filters['only_unlinked'] = true;
}
if ($search !== '') {
    $filters['search'] = $search;
}
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $filters['from'] = $from;
}
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $filters['to'] = $to;
}

$stats = ['pending_unlinked' => 0, 'totale' => 0];
$reports = [];

try {
    $stats = get_customer_report_statistics();
    $reports = get_customer_reports($filters, [
        'limit' => $limit,
        'order_by' => 'created_at',
        'order_direction' => 'DESC',
    ]);
} catch (Throwable $exception) {
    add_flash('warning', 'Impossibile recuperare le segnalazioni del portale: ' . $exception->getMessage());
    error_log('Pickup portal reports load failed: ' . $exception->getMessage());
}

$pageTitle = 'Segnalazioni portal pickup';
$extraStyles = [asset('modules/servizi/logistici/css/style.css')];
$extraScripts = [asset('modules/servizi/logistici/js/script.js')];
$formToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100 pickup-module">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 flex-wrap">
            <div class="flex-grow-1 mb-3 mb-md-0">
                <h1 class="h3 mb-1">Segnalazioni portale pickup</h1>
                <p class="text-muted mb-0">Gestisci le segnalazioni inviate dai clienti attraverso il portale.</p>
            </div>
            <div class="toolbar-actions d-flex flex-wrap gap-2">
                <a class="btn btn-outline-warning w-100 w-sm-auto" href="index.php"><i class="fa-solid fa-arrow-left"></i> Pickup</a>
                <a class="btn btn-warning text-dark w-100 w-sm-auto" href="../logistici/create.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo pickup</a>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xxl-9">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0 d-flex flex-wrap justify-content-between align-items-start gap-2">
                        <div>
                            <h2 class="h5 mb-1">Filtri</h2>
                            <p class="text-muted mb-0 small">Affina le segnalazioni per stato, periodo e collegamento.</p>
                        </div>
                    </div>
                    <div class="card-body">
                        <form class="row g-3 align-items-end" method="get" action="reports.php">
                            <div class="col-12 col-md-6 col-lg-4">
                                <label class="form-label" for="search">Ricerca</label>
                                <input class="form-control" id="search" name="search" value="<?php echo sanitize_output($search); ?>" placeholder="Tracking, cliente o note">
                            </div>
                            <div class="col-6 col-md-4 col-lg-3">
                                <label class="form-label" for="status">Stato</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="">Tutti</option>
                                    <?php foreach ($statuses as $statusOption): ?>
                                        <option value="<?php echo $statusOption; ?>" <?php echo $statusOption === $statusFilter ? 'selected' : ''; ?>><?php echo pickup_customer_report_status_meta($statusOption)['label']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <label class="form-label" for="from">Dal</label>
                                <input class="form-control" id="from" name="from" type="date" value="<?php echo sanitize_output($from); ?>">
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <label class="form-label" for="to">Al</label>
                                <input class="form-control" id="to" name="to" type="date" value="<?php echo sanitize_output($to); ?>">
                            </div>
                            <div class="col-6 col-md-4 col-lg-2">
                                <label class="form-label" for="limit">Limite</label>
                                <select class="form-select" id="limit" name="limit">
                                    <?php foreach ([25, 50, 100, 200] as $limitOption): ?>
                                        <option value="<?php echo $limitOption; ?>" <?php echo $limitOption === $limit ? 'selected' : ''; ?>><?php echo $limitOption; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6 col-lg-4">
                                <label class="form-label d-block">Senza pickup</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" role="switch" id="only_unlinked" name="only_unlinked" value="1" <?php echo $onlyUnlinked ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="only_unlinked">Mostra solo segnalazioni non collegate</label>
                                </div>
                            </div>
                            <div class="col-12 d-flex flex-wrap gap-2">
                                <button class="btn btn-warning text-dark flex-grow-1 flex-sm-grow-0" type="submit">Applica filtri</button>
                                <a class="btn btn-outline-warning flex-grow-1 flex-sm-grow-0" href="reports.php">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <h2 class="h5 mb-0">Segnalazioni</h2>
                        <span class="text-muted small">Totale: <?php echo count($reports); ?></span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle" data-table="reports">
                                <thead>
                                    <tr>
                                        <th>Tracking</th>
                                        <th>Cliente</th>
                                        <th>Segnalazione</th>
                                        <th>Stato</th>
                                        <th>Collegamento</th>
                                        <th>Aggiornato</th>
                                        <th class="text-end">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$reports): ?>
                                        <tr>
                                            <td class="text-center text-muted" colspan="7">Nessuna segnalazione trovata.</td>
                                        </tr>
                                    <?php endif; ?>
                                    <?php foreach ($reports as $report): ?>
                                        <?php $meta = pickup_customer_report_status_meta($report['status']); ?>
                                        <tr>
                                            <td>
                                                <div class="fw-semibold">#<?php echo sanitize_output($report['tracking_code']); ?></div>
                                                <div class="small text-muted">Creato <?php echo sanitize_output(format_datetime_locale($report['created_at'] ?? '')); ?></div>
                                            </td>
                                            <td>
                                                <div class="fw-semibold text-body"><?php echo sanitize_output($report['customer_name'] ?? $report['recipient_name'] ?? 'Cliente'); ?></div>
                                                <?php if (!empty($report['customer_email'])): ?>
                                                    <div class="small"><a class="link-warning" href="mailto:<?php echo sanitize_output($report['customer_email']); ?>"><?php echo sanitize_output($report['customer_email']); ?></a></div>
                                                <?php endif; ?>
                                                <?php if (!empty($report['customer_phone'])): ?>
                                                    <div class="small"><a class="link-warning" href="tel:<?php echo sanitize_output(preg_replace('/[^0-9+]/', '', (string) $report['customer_phone'])); ?>"><?php echo sanitize_output($report['customer_phone']); ?></a></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($report['notes'])): ?>
                                                    <?php
                                                        $notePreview = (string) $report['notes'];
                                                        if (function_exists('mb_strimwidth')) {
                                                            $notePreview = mb_strimwidth($notePreview, 0, 90, '…', 'UTF-8');
                                                        } elseif (strlen($notePreview) > 90) {
                                                            $notePreview = substr($notePreview, 0, 87) . '…';
                                                        }
                                                    ?>
                                                    <div class="small text-secondary"><?php echo sanitize_output($notePreview); ?></div>
                                                <?php else: ?>
                                                    <span class="text-muted small">Nessuna nota</span>
                                                <?php endif; ?>
                                                <?php if (!empty($report['delivery_location'])): ?>
                                                    <div class="small text-muted mt-1">Luogo consegna: <?php echo sanitize_output($report['delivery_location']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge rounded-pill <?php echo sanitize_output($meta['badge']); ?>"><?php echo sanitize_output($meta['label']); ?></span>
                                                <form class="d-flex flex-column flex-sm-row align-items-stretch align-items-sm-center gap-2 mt-2" method="post">
                                                    <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                                                    <input type="hidden" name="action" value="update_report_status">
                                                    <input type="hidden" name="report_id" value="<?php echo (int) $report['id']; ?>">
                                                    <select class="form-select form-select-sm flex-grow-1" name="status">
                                                        <?php foreach ($statuses as $statusOption): ?>
                                                            <option value="<?php echo $statusOption; ?>" <?php echo $statusOption === $report['status'] ? 'selected' : ''; ?>><?php echo pickup_customer_report_status_meta($statusOption)['label']; ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button class="btn btn-sm btn-outline-warning w-100 w-sm-auto" type="submit">Aggiorna</button>
                                                </form>
                                            </td>
                                            <td>
                                                <?php if (!empty($report['pickup_id'])): ?>
                                                    <div class="fw-semibold text-body">Pickup #<?php echo (int) $report['pickup_id']; ?></div>
                                                    <div class="small"><a class="link-warning" href="view.php?id=<?php echo (int) $report['pickup_id']; ?>">Apri dettaglio</a></div>
                                                    <form class="mt-2" method="post">
                                                        <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                                                        <input type="hidden" name="action" value="unlink_report">
                                                        <input type="hidden" name="report_id" value="<?php echo (int) $report['id']; ?>">
                                                        <button class="btn btn-sm btn-outline-warning w-100 w-sm-auto" type="submit">Scollega</button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="badge bg-warning-subtle text-warning rounded-pill">In attesa</span>
                                                    <form class="d-flex flex-column flex-lg-row align-items-stretch align-items-lg-center gap-2 mt-2" method="post">
                                                        <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                                                        <input type="hidden" name="action" value="link_report">
                                                        <input type="hidden" name="report_id" value="<?php echo (int) $report['id']; ?>">
                                                        <input class="form-control form-control-sm flex-grow-1" name="package_id" type="number" min="1" placeholder="ID pickup">
                                                        <select class="form-select form-select-sm flex-grow-1" name="status_after">
                                                            <option value="confirmed">Confermato</option>
                                                            <option value="arrived">Arrivato</option>
                                                            <option value="cancelled">Annullato</option>
                                                        </select>
                                                        <button class="btn btn-sm btn-outline-warning w-100 w-lg-auto" type="submit">Collega</button>
                                                    </form>
                                                    <form class="mt-2" method="post">
                                                        <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                                                        <input type="hidden" name="action" value="auto_link_report">
                                                        <input type="hidden" name="report_id" value="<?php echo (int) $report['id']; ?>">
                                                        <button class="btn btn-sm btn-outline-warning w-100 w-sm-auto" type="submit">Abbina tracking</button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo sanitize_output(format_datetime_locale($report['updated_at'] ?? '')); ?></td>
                                            <td class="text-end">
                                                <div class="btn-group flex-wrap" role="group">
                                                    <a class="btn btn-sm btn-outline-warning" href="report.php?id=<?php echo (int) $report['id']; ?>"><i class="fa-solid fa-eye"></i></a>
                                                    <?php if (empty($report['pickup_id'])): ?>
                                                        <a class="btn btn-sm btn-warning text-dark mt-2 mt-lg-0" href="create.php?source_report=<?php echo (int) $report['id']; ?>"><i class="fa-solid fa-circle-plus"></i></a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xxl-3">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Riepilogo</h2>
                        <button class="btn btn-sm btn-outline-warning d-xxl-none" type="button" data-bs-toggle="collapse" data-bs-target="#reportsSummary" aria-expanded="false" aria-controls="reportsSummary">Mostra</button>
                    </div>
                    <div class="card-body collapse d-xxl-block" id="reportsSummary">
                        <ul class="list-unstyled mb-0">
                            <li class="d-flex justify-content-between"><span>Totali</span><span><?php echo (int) ($stats['totale'] ?? 0); ?></span></li>
                            <li class="d-flex justify-content-between"><span>Segnalate</span><span><?php echo (int) ($stats['reported'] ?? 0); ?></span></li>
                            <li class="d-flex justify-content-between"><span>Confermate</span><span><?php echo (int) ($stats['confirmed'] ?? 0); ?></span></li>
                            <li class="d-flex justify-content-between"><span>Arrivate</span><span><?php echo (int) ($stats['arrived'] ?? 0); ?></span></li>
                            <li class="d-flex justify-content-between"><span>Annullate</span><span><?php echo (int) ($stats['cancelled'] ?? 0); ?></span></li>
                            <li class="d-flex justify-content-between text-warning fw-semibold mt-2"><span>In attesa</span><span><?php echo (int) ($stats['pending_unlinked'] ?? 0); ?></span></li>
                        </ul>
                    </div>
                </div>
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Azioni rapide</h2>
                        <button class="btn btn-sm btn-outline-warning d-xxl-none" type="button" data-bs-toggle="collapse" data-bs-target="#reportsQuickActions" aria-expanded="false" aria-controls="reportsQuickActions">Mostra</button>
                    </div>
                    <div class="card-body collapse d-xxl-block" id="reportsQuickActions">
                        <ul class="list-unstyled mb-0 small text-secondary">
                            <li class="mb-2">• Usa <strong>Abbina tracking</strong> per collegare automaticamente la segnalazione al pickup con lo stesso codice.</li>
                            <li class="mb-2">• Il campo <strong>ID pickup</strong> consente di collegare manualmente una segnalazione già gestita.</li>
                            <li>• Crea rapidamente un nuovo pickup con il pulsante <strong>+</strong> accanto ad ogni segnalazione.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
