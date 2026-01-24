<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');
$pageTitle = 'Dettaglio pratica ACI';

$praticaId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($praticaId <= 0) {
    add_flash('warning', 'Pratica non valida.');
    header('Location: index.php');
    exit;
}

$pratica = aci_get_pratica($pdo, $praticaId);
if (!$pratica) {
    add_flash('warning', 'Pratica non trovata.');
    header('Location: index.php');
    exit;
}

$attachments = aci_get_attachments($pdo, $praticaId);

$clienteLabelParts = array_filter([
    $pratica['ragione_sociale'] ?? null,
    trim(($pratica['cognome'] ?? '') . ' ' . ($pratica['nome'] ?? '')) ?: null,
]);
$clienteLabel = $clienteLabelParts ? implode(' - ', $clienteLabelParts) : null;

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Pratica ACI #<?php echo (int) $pratica['id']; ?></h1>
                <p class="text-muted mb-0">Dettaglio pratica automobilistica.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna</a>
                <?php if (current_user_can('Admin', 'Operatore', 'Manager')): ?>
                    <a class="btn btn-warning text-dark" href="edit.php?id=<?php echo (int) $pratica['id']; ?>"><i class="fa-solid fa-pen me-2"></i>Modifica</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="text-muted small">Tipo pratica</div>
                        <div class="fw-semibold"><?php echo sanitize_output($pratica['tipo_pratica']); ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Stato</div>
                        <span class="badge bg-secondary"><?php echo sanitize_output($pratica['stato']); ?></span>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Cliente</div>
                        <div class="fw-semibold"><?php echo sanitize_output($clienteLabel ?? '—'); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Targa</div>
                        <div class="fw-semibold"><?php echo sanitize_output($pratica['targa'] ?: '—'); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Telaio</div>
                        <div class="fw-semibold"><?php echo sanitize_output($pratica['telaio'] ?: '—'); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Intestatario</div>
                        <div class="fw-semibold"><?php echo sanitize_output($pratica['intestatario'] ?: '—'); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Protocollo</div>
                        <div class="fw-semibold"><?php echo sanitize_output($pratica['protocollo'] ?: '—'); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Data apertura</div>
                        <div class="fw-semibold"><?php echo sanitize_output(format_date_locale($pratica['data_apertura'] ?? null)); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Data scadenza</div>
                        <div class="fw-semibold"><?php echo sanitize_output(format_date_locale($pratica['data_scadenza'] ?? null)); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Data chiusura</div>
                        <div class="fw-semibold"><?php echo sanitize_output(format_date_locale($pratica['data_chiusura'] ?? null)); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Costo</div>
                        <div class="fw-semibold"><?php echo sanitize_output(format_currency((float) ($pratica['costo'] ?? 0))); ?></div>
                    </div>
                </div>

                <?php if (!empty($pratica['note'])): ?>
                    <div class="mt-4">
                        <div class="text-muted small">Note</div>
                        <div class="border rounded-3 p-3 bg-body-tertiary">
                            <?php echo nl2br(sanitize_output((string) $pratica['note'])); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Allegati</h2>
                <span class="badge ag-badge"><?php echo count($attachments); ?></span>
            </div>
            <div class="card-body">
                <?php if (!$attachments): ?>
                    <p class="text-muted mb-0">Nessun allegato disponibile.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($attachments as $attachment): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold"><?php echo sanitize_output($attachment['file_name'] ?? 'Allegato'); ?></div>
                                    <small class="text-muted"><?php echo sanitize_output($attachment['mime_type'] ?? ''); ?> · <?php echo number_format((int) ($attachment['file_size'] ?? 0) / 1024, 1); ?> KB</small>
                                </div>
                                <a class="btn btn-sm btn-outline-primary" href="download.php?id=<?php echo (int) $attachment['id']; ?>"><i class="fa-solid fa-download me-1"></i>Scarica</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
