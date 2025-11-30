<?php
declare(strict_types=1);

use App\Services\ServiziWeb\TelegrammiService;
use Throwable;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$telegrammaId = isset($_GET['id']) ? trim((string) $_GET['id']) : '';
if ($telegrammaId === '') {
    add_flash('warning', 'Specifica un telegramma da visualizzare.');
    header('Location: index.php');
    exit;
}

$record = null;
$logs = [];

try {
    $service = new TelegrammiService($pdo);
    $record = $service->findByTelegrammaId($telegrammaId);
    if ($record === null) {
        add_flash('warning', 'Telegramma non trovato.');
        header('Location: index.php');
        exit;
    }
    $logs = $service->logs((int) $record['id']);
} catch (Throwable $exception) {
    add_flash('danger', 'Impossibile recuperare il telegramma: ' . $exception->getMessage());
    header('Location: index.php');
    exit;
}

$pageTitle = 'Telegramma ' . $telegrammaId;

$statusLabels = [
    'NEW' => 'Nuovo',
    'VALIDATED' => 'Validato',
    'ACCEPTED' => 'Accettato',
    'QUEUED' => 'In coda',
    'SENT' => 'Inviato',
    'DELIVERED' => 'Consegnato',
    'ERROR' => 'Errore',
    'CANCELLED' => 'Annullato',
];
$statusBadge = [
    'NEW' => 'bg-primary',
    'VALIDATED' => 'bg-info text-dark',
    'ACCEPTED' => 'bg-info text-dark',
    'QUEUED' => 'bg-warning text-dark',
    'SENT' => 'bg-success',
    'DELIVERED' => 'bg-success',
    'ERROR' => 'bg-danger',
    'CANCELLED' => 'bg-secondary',
];

$statusKey = strtoupper((string) ($record['stato'] ?? 'NEW'));
$statusLabel = $statusLabels[$statusKey] ?? ucfirst(strtolower($statusKey));
$statusClass = $statusBadge[$statusKey] ?? 'bg-secondary';
$confirmed = !empty($record['confirmed']);

$mittente = $record['mittente'] ?? null;
$destinatari = $record['destinatari'] ?? null;
$pricing = $record['pricing'] ?? null;
$callback = $record['callback'] ?? null;
$raw = $record['raw'] ?? null;

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h1 class="h3 mb-0">Telegramma <?php echo sanitize_output($telegrammaId); ?></h1>
                <p class="text-muted mb-0">Verifica i metadati, il testo inviato e lo stato di consegna sincronizzato.</p>
            </div>
            <div class="toolbar-actions d-flex flex-wrap gap-2">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-arrow-left-long me-2"></i>Elenco telegrammi
                </a>
                <form action="sync.php" method="post" class="d-inline" autocomplete="off">
                    <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="telegramma_id" value="<?php echo sanitize_output($telegrammaId); ?>">
                    <button type="submit" class="btn btn-outline-primary">
                        <i class="fa-solid fa-rotate me-2"></i>Sincronizza
                    </button>
                </form>
                <form action="confirm.php" method="post" class="d-inline">
                    <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="telegramma_id" value="<?php echo sanitize_output($telegrammaId); ?>">
                    <input type="hidden" name="confirmed" value="<?php echo $confirmed ? '0' : '1'; ?>">
                    <button type="submit" class="btn btn-<?php echo $confirmed ? 'outline-secondary' : 'primary'; ?>">
                        <i class="fa-solid <?php echo $confirmed ? 'fa-rotate-left' : 'fa-circle-check'; ?> me-2"></i><?php echo $confirmed ? 'Annulla conferma' : 'Conferma invio'; ?>
                    </button>
                </form>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-lg-8 d-flex flex-column gap-4">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <h2 class="h5 mb-0">Dettaglio invio</h2>
                            <p class="text-muted small mb-0">Informazioni generate dal servizio Ufficio Postale.</p>
                        </div>
                        <span class="badge <?php echo sanitize_output($statusClass); ?> text-uppercase">Stato: <?php echo sanitize_output($statusLabel); ?></span>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4 text-muted">Prodotto</dt>
                            <dd class="col-sm-8 fw-semibold"><?php echo sanitize_output((string) ($record['prodotto'] ?? 'telegramma')); ?></dd>

                            <dt class="col-sm-4 text-muted">Cliente</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output($record['cliente_display'] ?? 'Non associato'); ?></dd>

                            <dt class="col-sm-4 text-muted">Confermato</dt>
                            <dd class="col-sm-8">
                                <?php if ($confirmed): ?>
                                    <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Sì</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">No</span>
                                <?php endif; ?>
                                <?php if (!empty($record['confirmed_timestamp'])): ?>
                                    <span class="small text-muted ms-2">(<?php echo sanitize_output(format_datetime_locale($record['confirmed_timestamp'])); ?>)</span>
                                <?php endif; ?>
                            </dd>

                            <dt class="col-sm-4 text-muted">Creato il</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output(format_datetime_locale($record['creation_timestamp'] ?? $record['created_at'] ?? null)); ?></dd>

                            <dt class="col-sm-4 text-muted">Ultimo aggiornamento</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output(format_datetime_locale($record['update_timestamp'] ?? $record['updated_at'] ?? null)); ?></dd>

                            <?php if (!empty($record['last_error'])): ?>
                            <dt class="col-sm-4 text-muted">Ultimo errore</dt>
                            <dd class="col-sm-8 text-danger">
                                <?php echo sanitize_output((string) $record['last_error']); ?>
                                <?php if (!empty($record['last_error_timestamp'])): ?>
                                    <span class="small text-muted ms-2"><?php echo sanitize_output(format_datetime_locale($record['last_error_timestamp'])); ?></span>
                                <?php endif; ?>
                            </dd>
                            <?php endif; ?>

                            <?php if (!empty($record['riferimento'])): ?>
                            <dt class="col-sm-4 text-muted">Riferimento interno</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output((string) $record['riferimento']); ?></dd>
                            <?php endif; ?>

                            <?php if (!empty($record['note'])): ?>
                            <dt class="col-sm-4 text-muted">Note interne</dt>
                            <dd class="col-sm-8"><?php echo nl2br(sanitize_output((string) $record['note']), false); ?></dd>
                            <?php endif; ?>
                        </dl>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Mittente</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($mittente): ?>
                        <pre class="bg-light rounded-3 p-3 small mb-0"><code><?php echo htmlspecialchars(json_encode($mittente, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></code></pre>
                        <?php else: ?>
                        <p class="text-muted small mb-0">Mittente non disponibile.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Destinatari</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($destinatari): ?>
                        <pre class="bg-light rounded-3 p-3 small mb-0"><code><?php echo htmlspecialchars(json_encode($destinatari, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></code></pre>
                        <?php else: ?>
                        <p class="text-muted small mb-0">Nessun destinatario registrato.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Testo inviato</h2>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($record['documento_testo'])): ?>
                        <pre class="bg-dark text-light rounded-3 p-3 small mb-0"><code><?php echo htmlspecialchars((string) $record['documento_testo'], ENT_QUOTES, 'UTF-8'); ?></code></pre>
                        <?php else: ?>
                        <p class="text-muted small mb-0">Il testo non è stato archiviato.</p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($pricing || $callback || $raw): ?>
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Dettagli tecnici</h2>
                    </div>
                    <div class="card-body d-flex flex-column gap-3">
                        <?php if ($pricing): ?>
                        <div>
                            <h3 class="h6">Prezzi</h3>
                            <pre class="bg-light rounded-3 p-3 small mb-0"><code><?php echo htmlspecialchars(json_encode($pricing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></code></pre>
                        </div>
                        <?php endif; ?>
                        <?php if ($callback): ?>
                        <div>
                            <h3 class="h6">Callback</h3>
                            <pre class="bg-light rounded-3 p-3 small mb-0"><code><?php echo htmlspecialchars(json_encode($callback, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></code></pre>
                        </div>
                        <?php endif; ?>
                        <?php if ($raw): ?>
                        <div>
                            <h3 class="h6">Payload originale</h3>
                            <pre class="bg-light rounded-3 p-3 small mb-0"><code><?php echo htmlspecialchars(json_encode($raw, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></code></pre>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($logs): ?>
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Cronologia eventi</h2>
                    </div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <?php foreach ($logs as $log): ?>
                            <li class="list-group-item bg-transparent px-0">
                                <div class="d-flex justify-content-between small">
                                    <span class="fw-semibold text-uppercase text-muted"><?php echo sanitize_output((string) $log['evento']); ?></span>
                                    <span><?php echo sanitize_output(format_datetime_locale($log['created_at'] ?? null)); ?></span>
                                </div>
                                <div><?php echo sanitize_output($log['messaggio'] ?? ''); ?></div>
                                <?php if (!empty($log['meta'])): ?>
                                <pre class="bg-light rounded-3 p-2 small mt-2 mb-0"><code><?php echo htmlspecialchars(json_encode($log['meta'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?></code></pre>
                                <?php endif; ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-4 d-flex flex-column gap-4">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Associazione cliente</h2>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small">Collega il telegramma alla scheda di un cliente per visualizzarlo all'interno dei report e nella scheda cliente.</p>
                        <form action="assign_client.php" method="post" class="row g-3" autocomplete="off">
                            <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                            <input type="hidden" name="telegramma_pk" value="<?php echo (int) $record['id']; ?>">
                            <input type="hidden" name="redirect_id" value="<?php echo sanitize_output($telegrammaId); ?>">
                            <div class="col-12">
                                <label for="cliente-id" class="form-label">ID cliente</label>
                                <input type="number" class="form-control" name="cliente_id" id="cliente-id" value="<?php echo sanitize_output((string) ($record['cliente_id'] ?? '')); ?>" min="1">
                                <div class="form-text">Lascia vuoto per rimuovere l'associazione.</div>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fa-solid fa-link me-2"></i>Aggiorna associazione
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Timestamps</h2>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0 small">
                            <dt class="col-6 text-muted">Creato</dt>
                            <dd class="col-6"><?php echo sanitize_output(format_datetime_locale($record['creation_timestamp'] ?? null)); ?></dd>
                            <dt class="col-6 text-muted">Aggiornato</dt>
                            <dd class="col-6"><?php echo sanitize_output(format_datetime_locale($record['update_timestamp'] ?? null)); ?></dd>
                            <dt class="col-6 text-muted">Invio avviato</dt>
                            <dd class="col-6"><?php echo sanitize_output(format_datetime_locale($record['sending_timestamp'] ?? null)); ?></dd>
                            <dt class="col-6 text-muted">Inviato</dt>
                            <dd class="col-6"><?php echo sanitize_output(format_datetime_locale($record['sent_timestamp'] ?? null)); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
