<?php
declare(strict_types=1);

use Throwable;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Manager', 'Operatore');

$consulenzaId = isset($_GET['id']) && ctype_digit((string) $_GET['id']) ? (int) $_GET['id'] : (isset($_POST['id']) && ctype_digit((string) $_POST['id']) ? (int) $_POST['id'] : 0);
if ($consulenzaId <= 0) {
    header('Location: index.php');
    exit;
}

$service = consulenza_fiscale_service($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'update_rate') {
            $rateId = isset($_POST['rate_id']) && ctype_digit((string) $_POST['rate_id']) ? (int) $_POST['rate_id'] : 0;
            $status = $_POST['status'] ?? '';
            if ($rateId > 0) {
                $service->toggleRateStatus($rateId, $status);
                add_flash('success', 'Stato rata aggiornato.');
            }
        } elseif ($action === 'mark_reminder') {
            $service->markReminderSent($consulenzaId);
            add_flash('success', 'Promemoria segnato come inviato.');
        } elseif ($action === 'upload_document' && !empty($_FILES['documento']['name'])) {
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $service->addDocument($consulenzaId, $_FILES['documento'], $userId, isset($_POST['signed']));
            add_flash('success', 'Documento caricato correttamente.');
        } elseif ($action === 'delete_document') {
            $documentId = isset($_POST['document_id']) && ctype_digit((string) $_POST['document_id']) ? (int) $_POST['document_id'] : 0;
            if ($documentId > 0) {
                $service->deleteDocument($documentId);
                add_flash('success', 'Documento rimosso.');
            }
        }
    } catch (Throwable $exception) {
        add_flash('danger', 'Operazione non completata: ' . $exception->getMessage());
        error_log('Consulenza Fiscale view action error: ' . $exception->getMessage());
    }

    header('Location: view.php?id=' . $consulenzaId);
    exit;
}

$record = $service->find($consulenzaId);
if ($record === null) {
    add_flash('warning', 'La consulenza richiesta non esiste o è stata rimossa.');
    header('Location: index.php');
    exit;
}

$pageTitle = 'Dettaglio consulenza ' . ($record['codice'] ?? '');
$rateStatuses = consulenza_fiscale_rate_status_options();
$statusOptions = consulenza_fiscale_status_options();
$modelOptions = consulenza_fiscale_model_options();
$frequencyOptions = consulenza_fiscale_frequency_options();

$clienteParts = array_filter([
    $record['ragione_sociale'] ?? null,
    trim(($record['cliente_cognome'] ?? '') . ' ' . ($record['cliente_nome'] ?? '')) ?: null,
]);
$clienteLabel = $clienteParts ? implode(' - ', $clienteParts) : 'Cliente non associato';

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Consulenza <?php echo sanitize_output($record['codice'] ?? ''); ?></h1>
                <p class="text-muted mb-0">Monitoraggio completo delle scadenze fiscali, delle rate e dei documenti firmati.</p>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Elenco</a>
                <a class="btn btn-warning text-dark" href="edit.php?id=<?php echo $consulenzaId; ?>"><i class="fa-solid fa-pen me-2"></i>Modifica</a>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-7">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <div>
                            <h2 class="h5 mb-0">Dati fiscali</h2>
                            <span class="badge ag-badge text-uppercase"><?php echo sanitize_output(consulenza_fiscale_status_label($record['stato'] ?? '')); ?></span>
                        </div>
                        <span class="text-muted">Aggiornato <?php echo sanitize_output(format_datetime_locale($record['updated_at'] ?? '')); ?></span>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Cliente</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output($clienteLabel); ?></dd>
                            <dt class="col-sm-4">Intestatario</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output($record['intestatario_nome'] ?? ''); ?></dd>
                            <dt class="col-sm-4">Codice fiscale</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output($record['codice_fiscale'] ?? ''); ?></dd>
                            <dt class="col-sm-4">Modello</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output($modelOptions[$record['tipo_modello']] ?? $record['tipo_modello']); ?></dd>
                            <dt class="col-sm-4">Anno / periodo</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output((string) ($record['anno_riferimento'] ?? '')); ?><?php echo $record['periodo_riferimento'] ? ' - ' . sanitize_output((string) $record['periodo_riferimento']) : ''; ?></dd>
                            <dt class="col-sm-4">Importo totale</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output(format_currency((float) ($record['importo_totale'] ?? 0))); ?></dd>
                            <dt class="col-sm-4">Rate / frequenza</dt>
                            <dd class="col-sm-8"><?php echo (int) ($record['numero_rate'] ?? 1); ?> × <?php echo sanitize_output($frequencyOptions[$record['frequenza_rate']] ?? $record['frequenza_rate']); ?></dd>
                            <dt class="col-sm-4">Prima scadenza</dt>
                            <dd class="col-sm-8"><?php echo sanitize_output(date('d/m/Y', strtotime((string) ($record['prima_scadenza'] ?? date('Y-m-d'))))); ?></dd>
                        </dl>
                        <div class="mt-3">
                            <h3 class="h6 text-uppercase text-muted">Note operative</h3>
                            <p class="mb-0"><?php echo $record['note'] ? nl2br(sanitize_output((string) $record['note'])) : '<span class="text-muted">Nessuna nota</span>'; ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Promemoria</h2>
                    </div>
                    <div class="card-body">
                        <p class="text-muted mb-1">Prossimo promemoria manuale</p>
                        <p class="fs-4 mb-3">
                            <?php if (!empty($record['promemoria_scadenza'])): ?>
                                <?php echo sanitize_output(date('d/m/Y', strtotime((string) $record['promemoria_scadenza']))); ?>
                            <?php else: ?>
                                <span class="text-muted">Non impostato</span>
                            <?php endif; ?>
                        </p>
                        <p class="text-muted mb-1">Ultimo promemoria inviato</p>
                        <p class="mb-3">
                            <?php if (!empty($record['promemoria_inviato_at'])): ?>
                                <?php echo sanitize_output(format_datetime_locale($record['promemoria_inviato_at'])); ?>
                            <?php else: ?>
                                <span class="text-muted">Mai registrato</span>
                            <?php endif; ?>
                        </p>
                        <form method="post" class="d-inline">
                            <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="id" value="<?php echo $consulenzaId; ?>">
                            <input type="hidden" name="action" value="mark_reminder">
                            <button class="btn btn-outline-warning" type="submit"><i class="fa-solid fa-bell me-2"></i>Segna promemoria inviato</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-7">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Piano rate</h2>
                        <span class="badge ag-badge"><?php echo count($record['rate']); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <?php if ($record['rate']): ?>
                            <div class="table-responsive">
                                <table class="table table-dark table-hover align-middle mb-0">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>Importo</th>
                                            <th>Scadenza</th>
                                            <th>Stato</th>
                                            <th>Pagamento</th>
                                            <th class="text-end">Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($record['rate'] as $rate): ?>
                                            <tr>
                                                <td><?php echo (int) ($rate['numero'] ?? 0); ?></td>
                                                <td><?php echo sanitize_output(format_currency((float) ($rate['importo'] ?? 0))); ?></td>
                                                <td><?php echo sanitize_output(date('d/m/Y', strtotime((string) $rate['scadenza']))); ?></td>
                                                <td><span class="badge bg-<?php echo ($rate['stato'] ?? '') === 'paid' ? 'success' : 'secondary'; ?>"><?php echo sanitize_output(consulenza_fiscale_rate_status_label($rate['stato'] ?? '')); ?></span></td>
                                                <td>
                                                    <?php if (!empty($rate['pagato_il'])): ?>
                                                        <?php echo sanitize_output(date('d/m/Y', strtotime((string) $rate['pagato_il']))); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-end">
                                                    <form method="post" class="d-inline">
                                                        <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                        <input type="hidden" name="id" value="<?php echo $consulenzaId; ?>">
                                                        <input type="hidden" name="action" value="update_rate">
                                                        <input type="hidden" name="rate_id" value="<?php echo (int) $rate['id']; ?>">
                                                        <?php if (($rate['stato'] ?? '') === 'paid'): ?>
                                                            <input type="hidden" name="status" value="pending">
                                                            <button class="btn btn-soft-secondary btn-sm" type="submit">Segna da pagare</button>
                                                        <?php else: ?>
                                                            <input type="hidden" name="status" value="paid">
                                                            <button class="btn btn-soft-success btn-sm" type="submit">Segna pagata</button>
                                                        <?php endif; ?>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <div class="p-4 text-center text-muted">
                                <p class="mb-0">Il piano rate non è disponibile. Rigenera le rate dalla schermata di modifica.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card ag-card mb-4 mb-xl-0">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Documenti firmati</h2>
                        <span class="badge ag-badge"><?php echo count($record['documenti']); ?></span>
                    </div>
                    <div class="card-body">
                        <?php if ($record['documenti']): ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($record['documenti'] as $document): ?>
                                    <li class="list-group-item bg-transparent d-flex justify-content-between align-items-start gap-3">
                                        <div>
                                            <a href="<?php echo sanitize_output(base_url($document['file_path'] ?? '')); ?>" target="_blank" rel="noopener" class="text-warning text-decoration-none">
                                                <i class="fa-solid fa-paperclip me-2"></i><?php echo sanitize_output($document['file_name'] ?? 'Documento'); ?>
                                            </a>
                                            <?php if (!empty($document['signed'])): ?><span class="badge bg-success ms-2">Firmato</span><?php endif; ?>
                                            <div class="text-muted small">Caricato da <?php echo sanitize_output($document['uploaded_by_username'] ?? ''); ?> - <?php echo sanitize_output(format_datetime_locale($document['created_at'] ?? '')); ?></div>
                                        </div>
                                        <form method="post" onsubmit="return confirm('Eliminare il documento selezionato?');">
                                            <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                            <input type="hidden" name="id" value="<?php echo $consulenzaId; ?>">
                                            <input type="hidden" name="action" value="delete_document">
                                            <input type="hidden" name="document_id" value="<?php echo (int) ($document['id'] ?? 0); ?>">
                                            <button class="btn btn-sm btn-soft-danger" type="submit"><i class="fa-solid fa-trash"></i></button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted">Nessun documento caricato.</p>
                        <?php endif; ?>
                        <hr>
                        <form method="post" enctype="multipart/form-data" class="vstack gap-3">
                            <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="id" value="<?php echo $consulenzaId; ?>">
                            <input type="hidden" name="action" value="upload_document">
                            <div>
                                <label class="form-label" for="documento">Carica documento</label>
                                <input class="form-control" id="documento" name="documento" type="file" accept=".pdf,.jpg,.jpeg,.png" required>
                                <div class="form-text">PDF, JPG o PNG - max 15 MB.</div>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" id="signed" name="signed" type="checkbox" checked>
                                <label class="form-check-label" for="signed">Documento già firmato dal cliente</label>
                            </div>
                            <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-upload me-2"></i>Carica</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
