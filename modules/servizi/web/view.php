<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Dettaglio progetto web';

$projectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($projectId <= 0) {
    add_flash('warning', 'Progetto non trovato.');
    header('Location: index.php');
    exit;
}

$project = servizi_web_fetch_project($pdo, $projectId);
if (!$project) {
    add_flash('warning', 'Il progetto richiesto non esiste.');
    header('Location: index.php');
    exit;
}

$statusLabels = [
    'preventivo' => 'Preventivo',
    'in_attesa_cliente' => 'In attesa cliente',
    'in_lavorazione' => 'In lavorazione',
    'consegnato' => 'Consegnato',
    'annullato' => 'Annullato',
];

$statusBadgeClass = [
    'preventivo' => 'bg-secondary',
    'in_attesa_cliente' => 'bg-warning text-dark',
    'in_lavorazione' => 'bg-info text-dark',
    'consegnato' => 'bg-success',
    'annullato' => 'bg-danger',
];

$hostingerPlanSelectionLabel = servizi_web_hostinger_selection_label($project['hostinger_plan'] ?? null);
$hostingerEmailSelectionLabel = servizi_web_hostinger_selection_label($project['hostinger_email_plan'] ?? null);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Progetto <?php echo sanitize_output($project['codice']); ?></h1>
                <p class="text-muted mb-0">Riepilogo completo dello stato del progetto digitale del cliente.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-list"></i></a>
                <a class="btn btn-warning text-dark" href="edit.php?id=<?php echo (int) $projectId; ?>"><i class="fa-solid fa-pen me-2"></i>Modifica</a>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Cliente</div>
                        <div class="fs-5 fw-semibold"><?php echo sanitize_output(servizi_web_format_cliente($project)); ?></div>
                        <?php if (!empty($project['email'])): ?>
                            <div class="text-muted">Email: <a class="text-reset" href="mailto:<?php echo sanitize_output($project['email']); ?>"><?php echo sanitize_output($project['email']); ?></a></div>
                        <?php endif; ?>
                        <?php if (!empty($project['telefono'])): ?>
                            <div class="text-muted">Telefono: <a class="text-reset" href="tel:<?php echo sanitize_output($project['telefono']); ?>"><?php echo sanitize_output($project['telefono']); ?></a></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Stato</div>
                        <span class="badge <?php echo $statusBadgeClass[$project['stato']] ?? 'bg-dark'; ?>">
                            <?php echo sanitize_output($statusLabels[$project['stato']] ?? $project['stato']); ?>
                        </span>
                        <?php if (!empty($project['consegna_prevista'])): ?>
                            <div class="mt-2 text-muted">Consegna prevista: <strong><?php echo sanitize_output(format_date_locale($project['consegna_prevista'])); ?></strong></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Preventivo &amp; ordine</div>
                        <div>Preventivo: <strong><?php echo $project['preventivo_numero'] ? sanitize_output($project['preventivo_numero']) : '—'; ?></strong></div>
                        <div>Importo: <strong><?php echo $project['preventivo_importo'] !== null ? format_currency((float) $project['preventivo_importo']) : '—'; ?></strong></div>
                        <hr class="my-2 border-secondary">
                        <div>Ordine: <strong><?php echo $project['ordine_numero'] ? sanitize_output($project['ordine_numero']) : '—'; ?></strong></div>
                        <div>Importo: <strong><?php echo $project['ordine_importo'] !== null ? format_currency((float) $project['ordine_importo']) : '—'; ?></strong></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h5 mb-0">Dettagli progetto</h2>
                    <p class="text-muted mb-0">Titolo, servizi inclusi e note operative.</p>
                </div>
                <span class="badge bg-secondary">Creato il <?php echo sanitize_output(format_datetime_locale($project['created_at'] ?? '')); ?></span>
            </div>
            <div class="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Titolo</dt>
                    <dd class="col-sm-9"><?php echo sanitize_output($project['titolo']); ?></dd>

                    <dt class="col-sm-3">Tipologia servizio</dt>
                    <dd class="col-sm-9"><?php echo sanitize_output($project['tipo_servizio']); ?></dd>

                    <dt class="col-sm-3">Dominio richiesto</dt>
                    <dd class="col-sm-9"><?php echo $project['dominio_richiesto'] ? sanitize_output($project['dominio_richiesto']) : '<span class="text-muted">—</span>'; ?></dd>

                    <dt class="col-sm-3">Datacenter Hostinger</dt>
                    <dd class="col-sm-9">
                        <?php if ($project['hostinger_datacenter']): ?>
                            <?php $datacenterLabel = servizi_web_hostinger_datacenter_label((string) $project['hostinger_datacenter']); ?>
                            <div><?php echo sanitize_output($datacenterLabel ?? (string) $project['hostinger_datacenter']); ?></div>
                            <div class="text-muted small">ID: <?php echo sanitize_output((string) $project['hostinger_datacenter']); ?></div>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-3">Piano hosting</dt>
                    <dd class="col-sm-9">
                        <?php if ($project['hostinger_plan']): ?>
                            <div><?php echo sanitize_output($hostingerPlanSelectionLabel ?? $project['hostinger_plan']); ?></div>
                            <?php $decodedPlan = servizi_web_hostinger_decode_selection($project['hostinger_plan']); ?>
                            <?php if (!empty($decodedPlan['item_id']) && !empty($decodedPlan['price_id'])): ?>
                                <div class="text-muted small">Item: <?php echo sanitize_output($decodedPlan['item_id']); ?> • Price: <?php echo sanitize_output($decodedPlan['price_id']); ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-3">Piano email</dt>
                    <dd class="col-sm-9">
                        <?php if ($project['hostinger_email_plan']): ?>
                            <div><?php echo sanitize_output($hostingerEmailSelectionLabel ?? $project['hostinger_email_plan']); ?></div>
                            <?php $decodedEmail = servizi_web_hostinger_decode_selection($project['hostinger_email_plan']); ?>
                            <?php if (!empty($decodedEmail['item_id']) && !empty($decodedEmail['price_id'])): ?>
                                <div class="text-muted small">Item: <?php echo sanitize_output($decodedEmail['item_id']); ?> • Price: <?php echo sanitize_output($decodedEmail['price_id']); ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-3">Stato Hostinger</dt>
                    <dd class="col-sm-9"><?php echo $project['hostinger_domain_status'] ? sanitize_output($project['hostinger_domain_status']) : '<span class="text-muted">—</span>'; ?></dd>

                    <dt class="col-sm-3">Riferimento ordine</dt>
                    <dd class="col-sm-9"><?php echo $project['hostinger_order_reference'] ? sanitize_output($project['hostinger_order_reference']) : '<span class="text-muted">—</span>'; ?></dd>

                    <dt class="col-sm-3">Servizi inclusi</dt>
                    <dd class="col-sm-9">
                        <?php
                        $badges = [];
                        if ((int) ($project['include_domini'] ?? 0) === 1) {
                            $badges[] = '<span class="badge bg-warning text-dark me-1">Domini</span>';
                        }
                        if ((int) ($project['include_email_professionali'] ?? 0) === 1) {
                            $badges[] = '<span class="badge bg-warning text-dark me-1">Email professionali</span>';
                        }
                        if ((int) ($project['include_hosting'] ?? 0) === 1) {
                            $badges[] = '<span class="badge bg-warning text-dark me-1">Hosting</span>';
                        }
                        if ((int) ($project['include_stampa'] ?? 0) === 1) {
                            $badges[] = '<span class="badge bg-warning text-dark me-1">Stampa</span>';
                        }
                        echo $badges ? implode(' ', $badges) : '<span class="text-muted">Nessun servizio aggiuntivo selezionato.</span>';
                        ?>
                    </dd>

                    <dt class="col-sm-3">Descrizione</dt>
                    <dd class="col-sm-9"><?php echo $project['descrizione'] ? nl2br(sanitize_output($project['descrizione']), false) : '<span class="text-muted">—</span>'; ?></dd>

                    <dt class="col-sm-3">Note interne</dt>
                    <dd class="col-sm-9"><?php echo $project['note_interne'] ? nl2br(sanitize_output($project['note_interne']), false) : '<span class="text-muted">—</span>'; ?></dd>
                </dl>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <div>
                    <h2 class="h5 mb-0">Documenti e allegati</h2>
                    <p class="text-muted mb-0">Mantieni aggiornati preventivi, bozze grafiche e ordini firmati.</p>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($project['allegato_path'])): ?>
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
                        <div>
                            <div class="fw-semibold">File caricato</div>
                            <div class="text-muted small">Hash SHA-256: <span class="font-monospace"><?php echo sanitize_output($project['allegato_hash'] ?? ''); ?></span></div>
                            <div class="text-muted small">Caricato il <?php echo sanitize_output(format_datetime_locale($project['allegato_caricato_at'] ?? '')); ?></div>
                        </div>
                        <div class="d-flex gap-2">
                            <a class="btn btn-outline-light" href="<?php echo sanitize_output(base_url($project['allegato_path'])); ?>" target="_blank"><i class="fa-solid fa-download me-2"></i>Scarica</a>
                            <a class="btn btn-outline-warning" href="edit.php?id=<?php echo (int) $projectId; ?>#allegato"><i class="fa-solid fa-pen"></i></a>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">Nessun allegato presente. Carica preventivi o bozze dalla pagina di modifica.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
