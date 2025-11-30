<?php

use App\Services\ServiziWeb\VisureService;
use Throwable;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$visuraId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
if ($visuraId === '') {
    add_flash('warning', 'Specifica una visura da visualizzare.');
    header('Location: index.php');
    exit;
}

$visura = null;
$projectRoot = dirname(__DIR__, 3);

try {
    $service = new VisureService($pdo, $projectRoot);
    $visura = $service->getVisura($visuraId, true);
} catch (Throwable $exception) {
    add_flash('danger', 'Impossibile recuperare i dettagli della visura: ' . $exception->getMessage());
    header('Location: index.php');
    exit;
}

$pageTitle = 'Visura ' . $visuraId;

$parametri = [];
if (!empty($visura['parametri_json'])) {
    $decoded = json_decode((string) $visura['parametri_json'], true);
    $parametri = is_array($decoded) ? $decoded : [];
}

$risultato = [];
if (!empty($visura['risultato_json'])) {
    $decoded = json_decode((string) $visura['risultato_json'], true);
    $risultato = is_array($decoded) ? $decoded : [];
}

$documentAvailable = !empty($visura['documento_path']);
$documentUrl = $documentAvailable ? base_url($visura['documento_path']) : null;
$defaultMessage = "Gentile cliente,\nla visura catastale richiesta è disponibile. In allegato trovi il documento aggiornato.";
$statusLabels = [
    'in_erogazione' => 'In elaborazione',
    'evasa' => 'Evasa',
    'errore' => 'Errore',
];
$statusBadgeClass = [
    'in_erogazione' => 'bg-warning text-dark',
    'evasa' => 'bg-success',
    'errore' => 'bg-danger',
];
$statusKey = $visura['stato'] ?? 'in_erogazione';
$statusLabel = $statusLabels[$statusKey] ?? ucfirst(str_replace('_', ' ', (string) $statusKey));
$statusBadge = $statusBadgeClass[$statusKey] ?? 'bg-secondary';

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Visura <?php echo sanitize_output($visuraId); ?></h1>
                <p class="text-muted mb-0">Gestisci metadati, allegati e notifiche della pratica.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2 flex-wrap">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-arrow-left-long me-2"></i>Elenco visure
                </a>
                <form action="download_document.php" method="post" class="d-inline" autocomplete="off">
                    <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="request_id" value="<?php echo sanitize_output($visuraId); ?>">
                    <input type="hidden" name="archive" value="1">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-file-arrow-down me-2"></i>Scarica PDF aggiornato
                    </button>
                </form>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <h2 class="h5 mb-0">Dettaglio pratica</h2>
                            <p class="text-muted small mb-0">Informazioni recuperate dal portale OpenAPI Catasto.</p>
                        </div>
                        <span class="badge <?php echo sanitize_output($statusBadge); ?>">Stato: <?php echo sanitize_output($statusLabel); ?></span>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4 text-muted">Tipo visura</dt>
                            <dd class="col-sm-8 fw-semibold"><?php echo sanitize_output($visura['tipo_visura'] ?? ''); ?></dd>

                            <dt class="col-sm-4 text-muted">Richiedente</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output($visura['richiedente'] ?? 'n/d'); ?></dd>

                            <dt class="col-sm-4 text-muted">Esito</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output($visura['esito'] ?? 'n/d'); ?></dd>

                            <dt class="col-sm-4 text-muted">Completata il</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output(format_datetime_locale($visura['completata_il'] ?? null)); ?></dd>

                            <dt class="col-sm-4 text-muted">Aggiornata il</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output(format_datetime_locale($visura['updated_at'] ?? null)); ?></dd>

                            <dt class="col-sm-4 text-muted">Documento</dt>
                            <dd class="col-sm-8">
                                <?php if ($documentAvailable): ?>
                                <a href="<?php echo sanitize_output($documentUrl); ?>" target="_blank" rel="noopener" class="text-decoration-none">
                                    <i class="fa-solid fa-file-pdf me-1 text-danger"></i><?php echo sanitize_output((string) $visura['documento_nome']); ?>
                                </a>
                                <div class="small text-muted mt-1">
                                    Ultimo aggiornamento: <?php echo sanitize_output(format_datetime_locale($visura['documento_aggiornato_il'] ?? null)); ?>
                                </div>
                                <?php else: ?>
                                <span class="text-muted">Nessun documento archiviato</span>
                                <?php endif; ?>
                            </dd>
                        </dl>
                    </div>
                </div>

                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Parametri richiesta</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($parametri): ?>
                        <pre class="bg-light rounded-3 p-3 small mb-0"><code><?php echo htmlspecialchars(json_encode($parametri, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></code></pre>
                        <?php else: ?>
                        <p class="text-muted mb-0 small">Nessun parametro disponibile per questa pratica.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Risultato</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($risultato): ?>
                        <pre class="bg-dark text-light rounded-3 p-3 small mb-0"><code><?php echo htmlspecialchars(json_encode($risultato, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></code></pre>
                        <?php else: ?>
                        <p class="text-muted mb-0 small">Il risultato non è stato ancora ricevuto dal servizio oppure è vuoto.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($visura['logs'])): ?>
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Cronologia eventi</h2>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <?php foreach ($visura['logs'] as $log): ?>
                            <li class="list-group-item bg-transparent px-0">
                                <div class="d-flex justify-content-between small">
                                    <span class="fw-semibold text-uppercase text-muted"><?php echo sanitize_output($log['evento']); ?></span>
                                    <span><?php echo sanitize_output(format_datetime_locale($log['created_at'] ?? null)); ?></span>
                                </div>
                                <div><?php echo sanitize_output($log['messaggio'] ?? ''); ?></div>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-4">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Associazione cliente</h2>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">Collega la visura alla scheda di un cliente per visualizzarla all'interno del suo profilo e dei report.</p>
                        <form action="assign_client.php" method="post" class="row g-3" autocomplete="off">
                            <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="visura_id" value="<?php echo sanitize_output($visuraId); ?>">
                            <div class="col-12">
                                <label for="cliente-id" class="form-label">ID cliente</label>
                                <input type="number" class="form-control" name="cliente_id" id="cliente-id" value="<?php echo sanitize_output((string) ($visura['cliente_id'] ?? '')); ?>" min="1">
                                <div class="form-text">Lascia vuoto per rimuovere l'associazione.</div>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-outline-primary w-100">
                                    <i class="fa-solid fa-link me-2"></i>Aggiorna associazione
                                </button>
                            </div>
                        </form>
                        <?php if (!empty($visura['cliente_display'])): ?>
                        <div class="alert alert-info mt-3 mb-0 small">
                            <strong>Cliente corrente:</strong> <?php echo sanitize_output($visura['cliente_display']); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Invia notifica</h2>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">Invia una notifica via email al cliente o ad un collega e registra l'invio nel registro della visura.</p>
                        <form action="notify.php" method="post" class="row g-3" autocomplete="off">
                            <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="visura_id" value="<?php echo sanitize_output($visuraId); ?>">
                            <div class="col-12">
                                <label for="recipient" class="form-label">Destinatario</label>
                                <input type="email" class="form-control" name="recipient" id="recipient" value="<?php echo sanitize_output((string) ($visura['email'] ?? '')); ?>" required>
                            </div>
                            <div class="col-12">
                                <label for="message" class="form-label">Messaggio</label>
                                <textarea class="form-control" name="message" id="message" rows="4" placeholder="Gentile cliente, la visura catastale richiesta è disponibile in allegato." required><?php echo htmlspecialchars($defaultMessage, ENT_QUOTES, 'UTF-8'); ?></textarea>
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="attach-document" name="attach_document" <?php echo $documentAvailable ? 'checked' : 'disabled'; ?>>
                                    <label class="form-check-label small" for="attach-document">Allega automaticamente l'ultimo PDF archiviato</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="fa-solid fa-paper-plane me-2"></i>Invia notifica
                                </button>
                            </div>
                        </form>
                        <?php if (!empty($visura['notificata_il'])): ?>
                        <div class="alert alert-success mt-3 mb-0 small">
                            Ultima notifica registrata: <?php echo sanitize_output(format_datetime_locale($visura['notificata_il'])); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Metadati</h2>
                    </div>
                    <div class="card-body">
                        <dl class="row small mb-0">
                            <dt class="col-5 text-muted">Entità</dt>
                            <dd class="col-7"><?php echo sanitize_output($visura['entita'] ?? ''); ?></dd>

                            <dt class="col-5 text-muted">Owner</dt>
                            <dd class="col-7"><?php echo sanitize_output($visura['owner'] ?? ''); ?></dd>

                            <dt class="col-5 text-muted">Richiesta inviata</dt>
                            <dd class="col-7"><?php echo sanitize_output(format_datetime_locale($visura['richiesta_timestamp'] ?? null)); ?></dd>

                            <dt class="col-5 text-muted">Sincronizzata</dt>
                            <dd class="col-7"><?php echo sanitize_output(format_datetime_locale($visura['sincronizzata_il'] ?? null)); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
