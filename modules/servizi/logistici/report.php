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

$reportId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($reportId <= 0) {
    add_flash('warning', 'Segnalazione non valida.');
    header('Location: reports.php');
    exit;
}

$report = get_customer_report($reportId);
if (!$report) {
    add_flash('warning', 'Segnalazione non trovata.');
    header('Location: reports.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_report_status') {
            $status = (string) ($_POST['status'] ?? '');
            update_customer_report_status($reportId, $status);
            add_flash('success', 'Stato segnalazione aggiornato.');
        } elseif ($action === 'link_report') {
            $packageId = isset($_POST['package_id']) ? (int) $_POST['package_id'] : 0;
            $statusAfter = (string) ($_POST['status_after'] ?? 'confirmed');
            if ($packageId <= 0) {
                throw new InvalidArgumentException('ID pickup non valido.');
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
            unlink_customer_report($reportId);
            add_flash('success', 'Segnalazione scollegata dal pickup.');
        } elseif ($action === 'auto_link_report') {
            $trackingRaw = (string) ($report['tracking_code'] ?? '');
            $trackingCode = preg_replace('/^\s+|\s+$/u', '', $trackingRaw);
            if ($trackingCode === null) {
                $trackingCode = $trackingRaw;
            }
            if ($trackingCode === '') {
                throw new RuntimeException('Il tracking della segnalazione non è disponibile.');
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

    header('Location: report.php?id=' . $reportId);
    exit;
}

$linkedPackage = null;
if (!empty($report['pickup_id'])) {
    try {
        $linkedPackage = get_package_details((int) $report['pickup_id']);
    } catch (Throwable $exception) {
        error_log('Impossibile recuperare il pickup collegato: ' . $exception->getMessage());
    }
}

$statuses = pickup_customer_report_statuses();
$reportMeta = pickup_customer_report_status_meta($report['status']);
$formToken = csrf_token();

$pageTitle = 'Segnalazione #' . $reportId;
$extraStyles = [asset('modules/servizi/logistici/css/style.css')];
$extraScripts = [asset('modules/servizi/logistici/js/script.js')];

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100 pickup-module">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-center">
            <a class="btn btn-outline-warning" href="reports.php"><i class="fa-solid fa-arrow-left"></i> Tutte le segnalazioni</a>
            <div class="toolbar-actions d-flex flex-wrap gap-2">
                <?php if (empty($report['pickup_id'])): ?>
                    <a class="btn btn-warning text-dark" href="create.php?source_report=<?php echo $reportId; ?>"><i class="fa-solid fa-circle-plus me-2"></i>Crea pickup</a>
                <?php else: ?>
                    <a class="btn btn-outline-warning" href="view.php?id=<?php echo (int) $report['pickup_id']; ?>"><i class="fa-solid fa-box"></i> Apri pickup</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h1 class="h5 mb-0">Dettagli segnalazione</h1>
                        <span class="badge rounded-pill <?php echo sanitize_output($reportMeta['badge']); ?>"><?php echo sanitize_output($reportMeta['label']); ?></span>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Tracking</dt>
                            <dd class="col-sm-8">#<?php echo sanitize_output($report['tracking_code']); ?></dd>
                            <dt class="col-sm-4">Segnalato il</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output(format_datetime_locale($report['created_at'] ?? '')); ?></dd>
                            <dt class="col-sm-4">Ultimo aggiornamento</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output(format_datetime_locale($report['updated_at'] ?? '')); ?></dd>
                            <?php if (!empty($report['expected_delivery_date'])): ?>
                                <dt class="col-sm-4">Consegna prevista</dt>
                                <dd class="col-sm-8"><?php echo sanitize_output(format_datetime_locale($report['expected_delivery_date'] . ' 00:00:00')); ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($report['delivery_location'])): ?>
                                <dt class="col-sm-4">Luogo consegna</dt>
                                <dd class="col-sm-8"><?php echo sanitize_output($report['delivery_location']); ?></dd>
                            <?php endif; ?>
                            <?php if (!empty($report['notes'])): ?>
                                <dt class="col-sm-4">Note cliente</dt>
                                <dd class="col-sm-8"><?php echo nl2br(sanitize_output($report['notes'])); ?></dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Azioni</h2>
                    </div>
                    <div class="card-body">
                        <form class="row g-3 align-items-end mb-4" method="post">
                            <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                            <input type="hidden" name="action" value="update_report_status">
                            <div class="col-sm-6">
                                <label class="form-label" for="status">Nuovo stato</label>
                                <select class="form-select" id="status" name="status">
                                    <?php foreach ($statuses as $statusOption): ?>
                                        <option value="<?php echo $statusOption; ?>" <?php echo $statusOption === $report['status'] ? 'selected' : ''; ?>><?php echo pickup_customer_report_status_meta($statusOption)['label']; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6">
                                <button class="btn btn-warning text-dark w-100" type="submit">Aggiorna stato</button>
                            </div>
                        </form>

                        <?php if (!empty($report['pickup_id'])): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                                <input type="hidden" name="action" value="unlink_report">
                                <button class="btn btn-outline-warning" type="submit">Scollega dal pickup</button>
                            </form>
                        <?php else: ?>
                            <form class="row g-3 align-items-end" method="post">
                                <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                                <input type="hidden" name="action" value="link_report">
                                <div class="col-sm-5">
                                    <label class="form-label" for="package_id">ID pickup</label>
                                    <input class="form-control" id="package_id" name="package_id" type="number" min="1" placeholder="ID esistente">
                                </div>
                                <div class="col-sm-4">
                                    <label class="form-label" for="status_after">Stato dopo collegamento</label>
                                    <select class="form-select" id="status_after" name="status_after">
                                        <option value="confirmed">Confermato</option>
                                        <option value="arrived">Arrivato</option>
                                        <option value="cancelled">Annullato</option>
                                    </select>
                                </div>
                                <div class="col-sm-3">
                                    <button class="btn btn-outline-warning w-100" type="submit">Collega</button>
                                </div>
                            </form>
                            <form class="mt-3" method="post">
                                <input type="hidden" name="_token" value="<?php echo $formToken; ?>">
                                <input type="hidden" name="action" value="auto_link_report">
                                <button class="btn btn-outline-warning" type="submit">Abbina automaticamente (tracking)</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Cliente portale</h2>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Nome</dt>
                            <dd class="col-sm-7"><?php echo sanitize_output($report['customer_name'] ?? $report['recipient_name'] ?? 'N/D'); ?></dd>
                            <dt class="col-sm-5">Email</dt>
                            <dd class="col-sm-7">
                                <?php if (!empty($report['customer_email'])): ?>
                                    <a class="link-warning" href="mailto:<?php echo sanitize_output($report['customer_email']); ?>"><?php echo sanitize_output($report['customer_email']); ?></a>
                                <?php else: ?>
                                    <span class="text-muted">N/D</span>
                                <?php endif; ?>
                            </dd>
                            <dt class="col-sm-5">Telefono</dt>
                            <dd class="col-sm-7">
                                <?php if (!empty($report['customer_phone'])): ?>
                                    <a class="link-warning" href="tel:<?php echo sanitize_output(preg_replace('/[^0-9+]/', '', (string) $report['customer_phone'])); ?>"><?php echo sanitize_output($report['customer_phone']); ?></a>
                                <?php else: ?>
                                    <span class="text-muted">N/D</span>
                                <?php endif; ?>
                            </dd>
                        </dl>
                    </div>
                </div>

                <?php if ($linkedPackage): ?>
                    <div class="card ag-card">
                        <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                            <h2 class="h5 mb-0">Pickup collegato</h2>
                            <span class="badge-status" data-status="<?php echo sanitize_output($linkedPackage['status']); ?>" data-status-badge><?php echo pickup_status_label($linkedPackage['status']); ?></span>
                        </div>
                        <div class="card-body">
                            <dl class="row mb-0">
                                <dt class="col-sm-5">Tracking</dt>
                                <dd class="col-sm-7">#<?php echo sanitize_output($linkedPackage['tracking'] ?? ''); ?></dd>
                                <dt class="col-sm-5">Cliente</dt>
                                <dd class="col-sm-7"><?php echo sanitize_output($linkedPackage['customer_name'] ?? 'N/D'); ?></dd>
                                <dt class="col-sm-5">Aggiornato</dt>
                                <dd class="col-sm-7"><?php echo sanitize_output(format_datetime_locale($linkedPackage['updated_at'] ?? '')); ?></dd>
                            </dl>
                            <a class="btn btn-sm btn-outline-warning mt-3" href="view.php?id=<?php echo (int) $linkedPackage['id']; ?>">Apri dettagli pickup</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="card ag-card">
                        <div class="card-header bg-transparent border-0">
                            <h2 class="h5 mb-0">Stato collegamento</h2>
                        </div>
                        <div class="card-body">
                            <p class="mb-0 text-muted small">Nessun pickup è ancora collegato a questa segnalazione. Usa le azioni a sinistra per abbinarla oppure crea un nuovo pickup precompilato.</p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
