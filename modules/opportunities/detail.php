<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../../includes/mailer.php';

require_role('Admin', 'Manager');

$opportunityId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($opportunityId <= 0) {
    add_flash('warning', 'Opportunity non trovata.');
    header('Location: index.php');
    exit;
}

$managerId = (int) ($_SESSION['user_id'] ?? 0);
if ($managerId <= 0) {
    add_flash('warning', 'Sessione non valida.');
    header('Location: index.php');
    exit;
}

$opportunity = $opportunityService->findById($opportunityId);
if ($opportunity === null) {
    add_flash('warning', 'Opportunity non trovata.');
    header('Location: index.php');
    exit;
}

$statusOptions = $opportunityService->getStatusOptions();
$files = $opportunityService->listFiles($opportunityId);
$errors = [];
$csrfToken = csrf_token();
$morositaScore = $opportunity['morosita_score'] ?? null;
$morositaUpdated = $opportunity['morosita_aggiornato_il'] ?? null;
$morositaNote = $opportunity['morosita_note'] ?? null;
$morositaMap = [
    'ok' => ['label' => 'Regolare', 'class' => 'badge bg-success'],
    'attenzione' => ['label' => 'Attenzione', 'class' => 'badge bg-warning text-dark'],
    'bloccato' => ['label' => 'Bloccato', 'class' => 'badge bg-danger'],
];
$morosita = $morositaMap[$morositaScore] ?? ['label' => 'Non verificato', 'class' => 'badge bg-secondary'];
$selectedStatusCode = (string) ($opportunity['status_code'] ?? '');
$metadata = [];
if (!empty($opportunity['metadata'])) {
    $decodedMeta = json_decode((string) $opportunity['metadata'], true);
    if (is_array($decodedMeta)) {
        $metadata = $decodedMeta;
    }
}
$telefoniaContractType = strtolower((string) ($metadata['telefonia_contract_type'] ?? 'migrazione'));
$isTelefoniaMigration = ($opportunity['category'] ?? '') === 'telefonia' && $telefoniaContractType === 'migrazione';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['form_action'] ?? '');
    try {
        if ($action === 'update_status') {
            $statusCode = (string) ($_POST['status_code'] ?? '');
            $adminNotes = isset($_POST['admin_notes']) ? trim((string) $_POST['admin_notes']) : null;
            $opportunityService->updateStatus($opportunityId, $statusCode, $managerId, $adminNotes, $_FILES['status_documents'] ?? []);
            $updatedOpportunity = $opportunityService->findById($opportunityId);
            if ($updatedOpportunity !== null) {
                $collaboratorName = trim(sprintf('%s %s', (string) ($updatedOpportunity['collaborator_name'] ?? ''), (string) ($updatedOpportunity['collaborator_surname'] ?? '')));
                send_opportunity_status_update_email([
                    'collaborator_email' => $updatedOpportunity['collaborator_email'] ?? null,
                    'collaborator_name' => $collaboratorName,
                    'status_label' => $updatedOpportunity['status_label'] ?? null,
                    'status_code' => $statusCode,
                    'code' => $updatedOpportunity['code'] ?? null,
                    'category' => $updatedOpportunity['category'] ?? null,
                    'customer_first_name' => $updatedOpportunity['customer_first_name'] ?? null,
                    'customer_last_name' => $updatedOpportunity['customer_last_name'] ?? null,
                    'admin_notes' => $adminNotes,
                    'updated_at' => $updatedOpportunity['last_status_change'] ?? null,
                ]);
            }
            add_flash('success', 'Stato aggiornato correttamente.');
            header('Location: detail.php?id=' . $opportunityId);
            exit;
        }
        if ($action === 'update_codes') {
            $payload = [
                'contract_code' => $_POST['contract_code'] ?? null,
                'client_code' => $_POST['client_code'] ?? null,
            ];
            $opportunityService->updateCodes($opportunityId, $payload, $managerId);
            add_flash('success', 'Codici aggiornati.');
            header('Location: detail.php?id=' . $opportunityId);
            exit;
        }
        if ($action === 'reopen_for_correction') {
            $reopenNote = isset($_POST['reopen_note']) ? trim((string) $_POST['reopen_note']) : null;
            $newStatus = 'in_verifica';
            $opportunityService->updateStatus($opportunityId, $newStatus, $managerId, $reopenNote, []);
            $updatedOpportunity = $opportunityService->findById($opportunityId);
            if ($updatedOpportunity !== null) {
                $collaboratorName = trim(sprintf('%s %s', (string) ($updatedOpportunity['collaborator_name'] ?? ''), (string) ($updatedOpportunity['collaborator_surname'] ?? '')));
                send_opportunity_status_update_email([
                    'collaborator_email' => $updatedOpportunity['collaborator_email'] ?? null,
                    'collaborator_name' => $collaboratorName,
                    'status_label' => $updatedOpportunity['status_label'] ?? null,
                    'status_code' => $newStatus,
                    'code' => $updatedOpportunity['code'] ?? null,
                    'category' => $updatedOpportunity['category'] ?? null,
                    'customer_first_name' => $updatedOpportunity['customer_first_name'] ?? null,
                    'customer_last_name' => $updatedOpportunity['customer_last_name'] ?? null,
                    'admin_notes' => $reopenNote,
                    'updated_at' => $updatedOpportunity['last_status_change'] ?? null,
                ]);
            }
            add_flash('success', 'Opportunity riaperta per rettifica collaboratore.');
            header('Location: detail.php?id=' . $opportunityId);
            exit;
        }
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
        if ($action === 'update_status') {
            $selectedStatusCode = (string) ($_POST['status_code'] ?? $selectedStatusCode);
            $opportunity['admin_notes'] = $_POST['admin_notes'] ?? $opportunity['admin_notes'];
        }
        if ($action === 'update_codes') {
            $opportunity['contract_code'] = $_POST['contract_code'] ?? $opportunity['contract_code'];
            $opportunity['client_code'] = $_POST['client_code'] ?? $opportunity['client_code'];
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Opportunity #<?php echo (int) $opportunity['id']; ?></p>
                <h1 class="h4 mb-0"><?php echo sanitize_output($opportunity['code'] ?? ''); ?></h1>
                <p class="text-muted mb-0">Categoria <?php echo sanitize_output(strtoupper((string) ($opportunity['category'] ?? ''))); ?> &middot; Stato <?php echo sanitize_output($opportunity['status_label'] ?? $opportunity['status_code'] ?? ''); ?></p>
            </div>
            <a class="btn btn-outline-secondary" href="<?php echo asset('modules/opportunities/index.php'); ?>">
                <i class="fa-solid fa-arrow-left me-2"></i>Torna alla pipeline
            </a>
        </div>

        <?php foreach ($errors as $message): ?>
            <div class="alert alert-warning" role="alert"><?php echo sanitize_output($message); ?></div>
        <?php endforeach; ?>

        <div class="row g-4">
            <div class="col-lg-8 d-flex flex-column gap-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
                            <div>
                                <p class="text-uppercase small text-muted mb-1">Cliente</p>
                                <h2 class="h5 mb-0"><?php echo sanitize_output(($opportunity['customer_first_name'] ?? '') . ' ' . ($opportunity['customer_last_name'] ?? '')); ?></h2>
                                <p class="text-muted mb-0"><?php echo sanitize_output($opportunity['customer_tax_code'] ?? ''); ?></p>
                            </div>
                            <div class="text-end">
                                <p class="text-uppercase small text-muted mb-1">Stato</p>
                                <?php
                                $badgeClass = 'badge bg-secondary';
                                $statusColor = $opportunity['status_color'] ?? '';
                                $colorToBootstrap = [
                                    'warning' => 'badge bg-warning text-dark',
                                    'info' => 'badge bg-info text-dark',
                                    'primary' => 'badge bg-primary',
                                    'danger' => 'badge bg-danger',
                                    'success' => 'badge bg-success',
                                ];
                                if ($statusColor && isset($colorToBootstrap[$statusColor])) {
                                    $badgeClass = $colorToBootstrap[$statusColor];
                                }
                                ?>
                                <span class="<?php echo $badgeClass; ?>"><?php echo sanitize_output($opportunity['status_label'] ?? $opportunity['status_code'] ?? ''); ?></span>
                                <p class="text-muted small mb-0 mt-2">Ultimo aggiornamento <?php echo sanitize_output(format_datetime_locale($opportunity['last_status_change'] ?? $opportunity['created_at'] ?? null)); ?></p>
                                <div class="mt-3">
                                    <p class="text-uppercase small text-muted mb-1">Morosità cliente</p>
                                    <div class="d-flex flex-wrap justify-content-end align-items-center gap-2">
                                        <span class="js-morosita-badge <?php echo $morosita['class']; ?>" data-tax="<?php echo sanitize_output($opportunity['customer_tax_code'] ?? ''); ?>">
                                            <?php echo sanitize_output($morosita['label']); ?>
                                        </span>
                                        <?php if (!empty($opportunity['customer_tax_code'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary js-morosita-check" data-tax="<?php echo sanitize_output($opportunity['customer_tax_code']); ?>">
                                                <i class="fa-solid fa-shield-halved me-1"></i>Verifica ora
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-muted small js-morosita-updated" data-tax="<?php echo sanitize_output($opportunity['customer_tax_code'] ?? ''); ?>">
                                        <?php echo $morositaUpdated ? 'Aggiornata: ' . sanitize_output(format_datetime_locale($morositaUpdated)) : 'Mai verificata'; ?>
                                    </div>
                                    <?php if ($morositaNote): ?>
                                        <div class="text-muted small">Nota: <?php echo sanitize_output($morositaNote); ?></div>
                                    <?php endif; ?>
                                    <script>
                                    (function() {
                                        const buttons = document.querySelectorAll('.js-morosita-check');
                                        if (!buttons.length) return;

                                        const csrfToken = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
                                        const badgeClasses = {
                                            ok: 'js-morosita-badge badge bg-success',
                                            attenzione: 'js-morosita-badge badge bg-warning text-dark',
                                            bloccato: 'js-morosita-badge badge bg-danger',
                                            default: 'js-morosita-badge badge bg-secondary'
                                        };

                                        buttons.forEach((button) => {
                                            button.addEventListener('click', async () => {
                                                const tax = button.getAttribute('data-tax');
                                                if (!tax) return;

                                                button.disabled = true;
                                                const originalText = button.innerHTML;
                                                button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Verifica...';

                                                try {
                                                    const response = await fetch('<?php echo base_url('api/customers/morosita-check.php'); ?>', {
                                                        method: 'POST',
                                                        headers: {
                                                            'Content-Type': 'application/json',
                                                            'X-CSRF-TOKEN': csrfToken
                                                        },
                                                        body: JSON.stringify({ tax_code: tax })
                                                    });

                                                    const data = await response.json();
                                                    if (!response.ok) {
                                                        const message = data?.error || 'Errore durante la verifica.';
                                                        alert(message);
                                                        return;
                                                    }

                                                    const score = data?.score || 'ok';
                                                    const badge = document.querySelector('.js-morosita-badge[data-tax="' + tax + '"]');
                                                    const updated = document.querySelector('.js-morosita-updated[data-tax="' + tax + '"]');

                                                    if (badge) {
                                                        badge.className = badgeClasses[score] || badgeClasses.default;
                                                        const labelMap = { ok: 'Regolare', attenzione: 'Attenzione', bloccato: 'Bloccato' };
                                                        badge.textContent = labelMap[score] || 'Non verificato';
                                                    }

                                                    if (updated) {
                                                        updated.textContent = 'Aggiornata: ' + new Date().toLocaleString();
                                                    }
                                                } catch (error) {
                                                    console.error(error);
                                                    alert('Impossibile completare la verifica.');
                                                } finally {
                                                    button.disabled = false;
                                                    button.innerHTML = originalText;
                                                }
                                            });
                                        });
                                    })();
                                    </script>
                                </div>
                            </div>
                        </div>
                        <hr>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <p class="text-uppercase small text-muted mb-1">Telefono</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['customer_phone'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-4">
                                <p class="text-uppercase small text-muted mb-1">Email</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['customer_email'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-4">
                                <p class="text-uppercase small text-muted mb-1">Collaboratore</p>
                                <p class="mb-0"><?php echo sanitize_output(trim(($opportunity['collaborator_name'] ?? '') . ' ' . ($opportunity['collaborator_surname'] ?? '')) ?: '—'); ?></p>
                            </div>
                        </div>
                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Indirizzo</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['customer_address'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-3">
                                <p class="text-uppercase small text-muted mb-1">Città</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['customer_city'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-3">
                                <p class="text-uppercase small text-muted mb-1">CAP</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['customer_postal_code'] ?? '—'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Gestore e offerta</h2>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Gestore</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['provider_label'] ?? ''); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Offerta</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['offer_label'] ?? '—'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Categoria</p>
                                <p class="mb-0 text-uppercase"><?php echo sanitize_output($opportunity['category'] ?? ''); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Provvigione stimata</p>
                                <p class="mb-0"><?php echo $opportunity['commission_amount'] !== null ? sanitize_output(number_format((float) $opportunity['commission_amount'], 2, ',', '.')) . ' €' : '—'; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Dettagli contrattuali</h2>
                        <div class="row g-3">
                            <?php if (($opportunity['category'] ?? '') === 'telefonia'): ?>
                                <div class="col-md-6">
                                    <p class="text-uppercase small text-muted mb-1">Tipologia contratto</p>
                                    <p class="mb-0"><?php echo $telefoniaContractType === 'migrazione' ? 'Migrazione' : 'Nuova attivazione'; ?></p>
                                </div>
                                <?php if ($isTelefoniaMigration): ?>
                                    <div class="col-md-6">
                                        <p class="text-uppercase small text-muted mb-1">Operatore attuale</p>
                                        <p class="mb-0"><?php echo sanitize_output($opportunity['telefonia_current_operator'] ?? '—'); ?></p>
                                    </div>
                                    <div class="col-md-6">
                                        <p class="text-uppercase small text-muted mb-1">Numero linea</p>
                                        <p class="mb-0"><?php echo sanitize_output($opportunity['telefonia_line_number'] ?? '—'); ?></p>
                                    </div>
                                    <?php if (!empty($metadata['migration_code'])): ?>
                                        <div class="col-md-6">
                                            <p class="text-uppercase small text-muted mb-1">Codice migrazione</p>
                                            <p class="mb-0"><?php echo sanitize_output($metadata['migration_code']); ?></p>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (($opportunity['category'] ?? '') === 'luce'): ?>
                                <div class="col-md-6">
                                    <p class="text-uppercase small text-muted mb-1">Codice POD</p>
                                    <p class="mb-0"><?php echo sanitize_output($opportunity['luce_pod'] ?? '—'); ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if (($opportunity['category'] ?? '') === 'gas'): ?>
                                <div class="col-md-6">
                                    <p class="text-uppercase small text-muted mb-1">Codice PDR</p>
                                    <p class="mb-0"><?php echo sanitize_output($opportunity['gas_pdr'] ?? '—'); ?></p>
                                </div>
                            <?php endif; ?>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">Metodo pagamento</p>
                                <p class="mb-0 text-capitalize"><?php echo sanitize_output($opportunity['payment_method'] ?? ''); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p class="text-uppercase small text-muted mb-1">IBAN</p>
                                <p class="mb-0"><?php echo sanitize_output($opportunity['payment_iban'] ?? '—'); ?></p>
                            </div>
                            <div class="col-12">
                                <p class="text-uppercase small text-muted mb-1">Note collaboratore</p>
                                <p class="mb-0"><?php echo nl2br(sanitize_output($opportunity['additional_notes'] ?? 'Nessuna nota.'), false); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h6 text-uppercase text-muted mb-0">Allegati</h2>
                            <span class="badge bg-secondary"><?php echo count($files); ?></span>
                        </div>
                        <?php if (!$files): ?>
                            <p class="text-muted mb-0">Nessun file caricato.</p>
                        <?php else: ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($files as $file): ?>
                                    <?php $fileUrl = !empty($file['file_path']) ? asset($file['file_path']) : '#'; ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center flex-wrap gap-2">
                                        <div>
                                            <p class="mb-0 fw-semibold"><?php echo sanitize_output($file['original_name'] ?? $file['stored_name']); ?></p>
                                            <p class="text-muted small mb-0">
                                                Caricato da ID <?php echo (int) ($file['uploaded_by'] ?? 0); ?> &middot; <?php echo sanitize_output(format_datetime_locale($file['created_at'] ?? null)); ?>
                                            </p>
                                        </div>
                                        <a class="btn btn-outline-secondary btn-sm" href="<?php echo $fileUrl; ?>" target="_blank" rel="noreferrer">
                                            <i class="fa-solid fa-download me-2"></i>Scarica
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-4 d-flex flex-column gap-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start mb-3">
                            <div>
                                <h2 class="h6 text-uppercase text-muted mb-1">Aggiorna stato</h2>
                                <p class="mb-0 small text-muted">Allega eventuale documentazione e lascia una nota interna.</p>
                            </div>
                            <span class="badge bg-light text-dark"><?php echo sanitize_output($opportunity['status_label'] ?? $opportunity['status_code'] ?? ''); ?></span>
                        </div>
                        <form method="post" enctype="multipart/form-data" class="d-flex flex-column gap-3">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="form_action" value="update_status">
                            <div>
                                <label class="form-label text-uppercase small text-muted">Nuovo stato</label>
                                <select class="form-select" name="status_code" required>
                                    <option value="">Seleziona</option>
                                    <?php foreach ($statusOptions as $status): ?>
                                        <option value="<?php echo sanitize_output($status['code']); ?>" <?php echo $selectedStatusCode === $status['code'] ? 'selected' : ''; ?>>
                                            <?php echo sanitize_output($status['label']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="form-label text-uppercase small text-muted">Nota interna</label>
                                <textarea class="form-control" name="admin_notes" rows="3" placeholder="Es. documentazione incompleta o dati corretti."><?php echo sanitize_output($opportunity['admin_notes'] ?? ''); ?></textarea>
                            </div>
                            <div>
                                <label class="form-label text-uppercase small text-muted">Allegati</label>
                                <div class="dropzone-area" id="status-dropzone" data-dropzone>
                                    <p class="mb-1"><i class="fa-solid fa-cloud-arrow-up me-2"></i>Trascina qui i file o clicca per selezionare</p>
                                    <p class="text-muted small mb-0">PDF, immagini e ZIP fino a 10MB.</p>
                                    <input class="d-none" type="file" name="status_documents[]" id="status-documents" multiple>
                                </div>
                                <div class="dropzone-files" id="status-files"></div>
                            </div>
                            <button class="btn btn-primary w-100" type="submit">
                                <i class="fa-solid fa-rotate me-2"></i>Salva aggiornamento
                            </button>
                        </form>
                        <hr>
                        <form method="post" class="d-flex flex-column gap-2">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="form_action" value="reopen_for_correction">
                            <label class="form-label text-uppercase small text-muted mb-1" for="reopen-note">Nota per il collaboratore (opzionale)</label>
                            <textarea class="form-control" id="reopen-note" name="reopen_note" rows="2" placeholder="Spiega cosa va rettificato."></textarea>
                            <button class="btn btn-outline-warning w-100" type="submit">
                                <i class="fa-solid fa-rotate-left me-2"></i>Riapri per rettifica
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Codici contratto</h2>
                        <form method="post" class="d-flex flex-column gap-3">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="form_action" value="update_codes">
                            <div>
                                <label class="form-label text-uppercase small text-muted">Codice contratto</label>
                                <input class="form-control" type="text" name="contract_code" value="<?php echo sanitize_output($opportunity['contract_code'] ?? ''); ?>">
                            </div>
                            <div>
                                <label class="form-label text-uppercase small text-muted">Codice cliente</label>
                                <input class="form-control" type="text" name="client_code" value="<?php echo sanitize_output($opportunity['client_code'] ?? ''); ?>">
                            </div>
                            <button class="btn btn-outline-secondary" type="submit">
                                <i class="fa-solid fa-floppy-disk me-2"></i>Salva codici
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Responsabile</h2>
                        <p class="mb-1">Ultimo manager: <?php echo sanitize_output(trim(($opportunity['manager_name'] ?? '') . ' ' . ($opportunity['manager_surname'] ?? '')) ?: 'Non assegnato'); ?></p>
                        <p class="text-muted small mb-0">Creato il <?php echo sanitize_output(format_datetime_locale($opportunity['created_at'] ?? null)); ?></p>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const dropzone = document.getElementById('status-dropzone');
        const input = document.getElementById('status-documents');
        const list = document.getElementById('status-files');
        if (!dropzone || !input || !list) {
            return;
        }
        const renderList = () => {
            list.innerHTML = '';
            Array.from(input.files || []).forEach((file, index) => {
                const entry = document.createElement('div');
                entry.className = 'dropzone-file-entry';
                entry.innerHTML = `
                    <div class="file-meta">
                        <strong>${file.name}</strong>
                        <small>${(file.size / 1024).toFixed(1)} KB</small>
                    </div>
                    <div class="file-actions">
                        <button type="button" data-index="${index}" aria-label="Rimuovi">
                            <i class="fa-solid fa-times"></i>
                        </button>
                    </div>
                `;
                entry.querySelector('button')?.addEventListener('click', (event) => {
                    const button = event.currentTarget;
                    const removeIndex = Number(button?.getAttribute('data-index'));
                    const dt = new DataTransfer();
                    Array.from(input.files || []).forEach((fileItem, idx) => {
                        if (idx !== removeIndex) {
                            dt.items.add(fileItem);
                        }
                    });
                    input.files = dt.files;
                    renderList();
                });
                list.appendChild(entry);
            });
        };
        dropzone.addEventListener('click', () => input.click());
        dropzone.addEventListener('dragover', (event) => {
            event.preventDefault();
            dropzone.classList.add('dragover');
        });
        dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
        dropzone.addEventListener('drop', (event) => {
            event.preventDefault();
            dropzone.classList.remove('dragover');
            const dt = new DataTransfer();
            Array.from(input.files || []).forEach((file) => dt.items.add(file));
            Array.from(event.dataTransfer?.files || []).forEach((file) => dt.items.add(file));
            input.files = dt.files;
            renderList();
        });
        input.addEventListener('change', renderList);
        renderList();
    });
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
