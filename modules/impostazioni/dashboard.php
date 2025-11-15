<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Manager');
require_capability('settings.view');

$pageTitle = 'Dashboard impostazioni';
$csrfToken = csrf_token();

$configCount = (int) $pdo->query('SELECT COUNT(*) FROM configurazioni')->fetchColumn();
$auditEvents = $pdo->query('SELECT modulo, azione, created_at FROM log_attivita ORDER BY created_at DESC LIMIT 5')->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Dashboard impostazioni</h1>
                <p class="text-muted mb-0">Panoramica rapida delle configurazioni e degli eventi di sistema.</p>
            </div>
            <div class="toolbar-actions btn-group">
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-gear"></i> Gestione impostazioni</a>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Configurazioni attive</div>
                        <div class="fs-2 fw-semibold"><?php echo number_format($configCount); ?></div>
                        <p class="text-muted mb-0">Numero di chiavi presenti nella tabella configurazioni.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Ultimi eventi di audit</h5>
                <a class="btn btn-sm btn-outline-warning" href="logs.php">Vedi log completo</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Modulo</th>
                                <th>Azione</th>
                                <th>Data</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($auditEvents): ?>
                                <?php foreach ($auditEvents as $event): ?>
                                    <tr>
                                        <td><?php echo sanitize_output($event['modulo']); ?></td>
                                        <td><?php echo sanitize_output($event['azione']); ?></td>
                                        <td><?php echo sanitize_output(format_datetime_locale($event['created_at'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-4">Nessun evento recente registrato.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>