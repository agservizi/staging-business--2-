<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Nuova pratica ANPR';

$types = anpr_practice_types();
$statuses = ANPR_ALLOWED_STATUSES;
$clienti = anpr_fetch_clienti($pdo);
$csrfToken = csrf_token();

$errors = [];
$data = [
    'cliente_id' => '',
    'tipo_pratica' => $types[0] ?? 'Certificato di residenza',
    'stato' => 'In lavorazione',
    'note_interne' => '',
    'generate_delega' => '1',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $data['cliente_id'] = trim((string) ($_POST['cliente_id'] ?? ''));
    $data['tipo_pratica'] = trim((string) ($_POST['tipo_pratica'] ?? ''));
    $data['stato'] = trim((string) ($_POST['stato'] ?? ''));
    $data['note_interne'] = trim((string) ($_POST['note_interne'] ?? ''));
    $generateDelegaRequested = (string) ($_POST['generate_delega'] ?? '') === '1';
    $data['generate_delega'] = $generateDelegaRequested ? '1' : '0';

    $clienteId = (int) $data['cliente_id'];
    if ($clienteId <= 0) {
        $errors[] = 'Seleziona un cliente.';
    }

    if (!in_array($data['tipo_pratica'], $types, true)) {
        $errors[] = 'Seleziona una tipologia valida.';
    }

    if (!in_array($data['stato'], $statuses, true)) {
        $data['stato'] = 'In lavorazione';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $praticaCode = '';
            $attempts = 0;
            $maxAttempts = 5;
            $inserted = false;
            $operatoreId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

            while (!$inserted && $attempts < $maxAttempts) {
                $attempts++;
                $praticaCode = anpr_generate_pratica_code($pdo);
                try {
                    $stmt = $pdo->prepare('INSERT INTO anpr_pratiche (
                        pratica_code,
                        cliente_id,
                        tipo_pratica,
                        stato,
                        note_interne,
                        operatore_id,
                        created_at,
                        updated_at
                    ) VALUES (
                        :pratica_code,
                        :cliente_id,
                        :tipo_pratica,
                        :stato,
                        :note_interne,
                        :operatore_id,
                        NOW(),
                        NOW()
                    )');
                    $stmt->execute([
                        ':pratica_code' => $praticaCode,
                        ':cliente_id' => $clienteId,
                        ':tipo_pratica' => $data['tipo_pratica'],
                        ':stato' => $data['stato'],
                        ':note_interne' => $data['note_interne'] !== '' ? $data['note_interne'] : null,
                        ':operatore_id' => $operatoreId,
                    ]);
                    $inserted = true;
                } catch (PDOException $exception) {
                    if ((int) $exception->getCode() !== 23000) {
                        throw $exception;
                    }
                }
            }

            if (!$inserted) {
                throw new RuntimeException('Impossibile generare un codice pratica univoco.');
            }

            $praticaId = (int) $pdo->lastInsertId();

            if (!empty($_FILES['certificato']['name'])) {
                $stored = anpr_store_certificate($_FILES['certificato'], $praticaId);
                $updateStmt = $pdo->prepare('UPDATE anpr_pratiche
                    SET certificato_path = :path,
                        certificato_hash = :hash,
                        certificato_caricato_at = NOW()
                    WHERE id = :id');
                $updateStmt->execute([
                    ':path' => $stored['path'],
                    ':hash' => $stored['hash'],
                    ':id' => $praticaId,
                ]);
            }

            $manualDelegaUploaded = false;
            if (!empty($_FILES['delega']['name'])) {
                $storedDelega = anpr_store_delega($_FILES['delega'], $praticaId);
                anpr_set_delega_metadata($pdo, $praticaId, $storedDelega, false);
                $manualDelegaUploaded = true;
            }

            if (!$manualDelegaUploaded && anpr_should_generate_delega($data['tipo_pratica'], $generateDelegaRequested)) {
                $praticaInfo = anpr_fetch_pratica($pdo, $praticaId);
                anpr_auto_generate_delega($pdo, $praticaId, $praticaInfo);
            }

            if (!empty($_FILES['documento']['name'])) {
                $storedDocumento = anpr_store_documento($_FILES['documento'], $praticaId);
                $updateStmt = $pdo->prepare('UPDATE anpr_pratiche
                    SET documento_path = :path,
                        documento_hash = :hash,
                        documento_caricato_at = NOW()
                    WHERE id = :id');
                $updateStmt->execute([
                    ':path' => $storedDocumento['path'],
                    ':hash' => $storedDocumento['hash'],
                    ':id' => $praticaId,
                ]);
            }

            $pdo->commit();

            anpr_log_action($pdo, 'Pratica creata', 'Creata pratica ANPR ' . $praticaCode);
            add_flash('success', 'Pratica creata correttamente. Codice: ' . $praticaCode . '.');
            header('Location: index.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('ANPR create failed: ' . $exception->getMessage());
            if ($exception instanceof RuntimeException) {
                $errors[] = $exception->getMessage();
            } else {
                $errors[] = 'Impossibile salvare la pratica. Riprova.';
            }
        }
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Nuova pratica ANPR</h1>
                <p class="text-muted mb-0">Registra una nuova richiesta anagrafica.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-warning" href="https://www.anagrafenazionale.interno.it/servizi-al-cittadino/" target="_blank" rel="noopener">
                    <i class="fa-solid fa-up-right-from-square me-2"></i>Portale ANPR
                </a>
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Indietro</a>
            </div>
        </div>
        <?php if ($errors): ?>
            <div class="alert alert-warning">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo sanitize_output($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card ag-card">
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-4">
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <div class="col-md-6">
                        <label class="form-label" for="cliente_id">Cliente <span class="text-warning">*</span></label>
                        <select class="form-select" id="cliente_id" name="cliente_id" required>
                            <option value="">Seleziona cliente</option>
                            <?php foreach ($clienti as $cliente): ?>
                                <?php $cid = (int) $cliente['id']; ?>
                                <option value="<?php echo $cid; ?>" <?php echo (string) $cid === $data['cliente_id'] ? 'selected' : ''; ?>><?php echo sanitize_output(trim($cliente['ragione_sociale'] ?: (($cliente['cognome'] ?? '') . ' ' . ($cliente['nome'] ?? '')))); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                            <small class="text-muted mb-0">Non trovi il cliente? Aggiungilo al volo.</small>
                            <button class="btn btn-sm btn-outline-warning" type="button" data-bs-toggle="modal" data-bs-target="#quickAddClienteModal">
                                <i class="fa-solid fa-user-plus me-1"></i>Nuovo cliente
                            </button>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="tipo_pratica">Tipologia <span class="text-warning">*</span></label>
                        <select class="form-select" id="tipo_pratica" name="tipo_pratica" required>
                            <?php foreach ($types as $type): ?>
                                <option value="<?php echo sanitize_output($type); ?>" <?php echo $data['tipo_pratica'] === $type ? 'selected' : ''; ?>><?php echo sanitize_output($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="stato">Stato pratica</label>
                        <select class="form-select" id="stato" name="stato">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo sanitize_output($status); ?>" <?php echo $data['stato'] === $status ? 'selected' : ''; ?>><?php echo sanitize_output($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="note_interne">Note interne</label>
                        <textarea class="form-control" id="note_interne" name="note_interne" rows="4" placeholder="Annotazioni operative"><?php echo sanitize_output($data['note_interne']); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="delega">Delega firmata (PDF)</label>
                        <input class="form-control" type="file" id="delega" name="delega" accept="application/pdf">
                        <small class="text-muted d-block">Facoltativo. Dimensione massima 10 MB.</small>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="generate_delega" name="generate_delega" value="1" <?php echo $data['generate_delega'] === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="generate_delega">Genera automaticamente la delega se non caricata</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="documento">Documento identità delegante (PDF/JPG/PNG)</label>
                        <input class="form-control" type="file" id="documento" name="documento" accept="application/pdf,image/jpeg,image/png">
                        <small class="text-muted">Facoltativo. Dimensione massima 10 MB.</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="certificato">Certificato (PDF)</label>
                        <input class="form-control" type="file" id="certificato" name="certificato" accept="application/pdf">
                        <small class="text-muted">Facoltativo. Dimensione massima 15 MB. Caricare solo dopo l’estrazione dal portale ANPR.</small>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <a class="btn btn-outline-warning" href="index.php">Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva pratica</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<div class="modal fade" id="quickAddClienteModal" tabindex="-1" aria-labelledby="quickAddClienteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0">
                <h2 class="modal-title h5 mb-0" id="quickAddClienteModalLabel">Nuovo cliente</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <form id="quickAddClienteForm" method="post" class="row g-3" novalidate autocomplete="off">
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <div id="quickAddClienteErrors" class="alert alert-warning d-none" role="alert"></div>
                    <div class="col-12">
                        <label class="form-label" for="quick_ragione_sociale">Ragione sociale</label>
                        <input class="form-control" id="quick_ragione_sociale" name="ragione_sociale" maxlength="160" placeholder="Es. Azienda ABC S.r.l.">
                        <small class="text-muted">Lascia vuoto per clienti privati.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="quick_nome">Nome <span class="text-warning">*</span></label>
                        <input class="form-control" id="quick_nome" name="nome" maxlength="80" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="quick_cognome">Cognome <span class="text-warning">*</span></label>
                        <input class="form-control" id="quick_cognome" name="cognome" maxlength="80" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="quick_email">Email</label>
                        <input class="form-control" id="quick_email" type="email" name="email" maxlength="160" placeholder="cliente@example.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="quick_telefono">Telefono</label>
                        <input class="form-control" id="quick_telefono" name="telefono" maxlength="40" placeholder="Es. +39 347 1234567">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="quick_cf_piva">CF / P.IVA</label>
                        <input class="form-control" id="quick_cf_piva" name="cf_piva" maxlength="16" placeholder="RSSMRA80A01H501Z">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="quick_indirizzo">Indirizzo</label>
                        <input class="form-control" id="quick_indirizzo" name="indirizzo" maxlength="255" placeholder="Via Roma 10, Milano">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="quick_note">Note</label>
                        <textarea class="form-control" id="quick_note" name="note" rows="3" maxlength="2000" placeholder="Note operative o preferenze del cliente"></textarea>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2 mt-2">
                        <button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Annulla</button>
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva cliente</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var quickAddModal = document.getElementById('quickAddClienteModal');
        var quickAddForm = document.getElementById('quickAddClienteForm');
        var clienteSelect = document.getElementById('cliente_id');
        if (!quickAddModal || !quickAddForm || !clienteSelect) {
            return;
        }

        var quickAddErrors = document.getElementById('quickAddClienteErrors');
        var submitButton = quickAddForm.querySelector('button[type="submit"]');
        var submitLabel = submitButton.innerHTML;

        var resetQuickAddForm = function () {
            quickAddForm.reset();
            if (quickAddErrors) {
                quickAddErrors.textContent = '';
                quickAddErrors.classList.add('d-none');
            }
            submitButton.disabled = false;
            submitButton.innerHTML = submitLabel;
        };

        quickAddModal.addEventListener('hidden.bs.modal', function () {
            resetQuickAddForm();
        });

        quickAddForm.addEventListener('submit', function (event) {
            event.preventDefault();

            if (quickAddErrors) {
                quickAddErrors.textContent = '';
                quickAddErrors.classList.add('d-none');
            }

            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Salvataggio...';

            var formData = new FormData(quickAddForm);

            fetch('<?php echo sanitize_output(base_url('modules/clienti/quick_create.php')); ?>', {
                method: 'POST',
                credentials: 'same-origin',
                body: formData
            })
                .then(function (response) {
                    return response.json().then(function (json) {
                        return { status: response.status, body: json };
                    });
                })
                .then(function (payload) {
                    var data = payload.body;
                    if (!data || data.success !== true) {
                        throw data;
                    }

                    var option = document.createElement('option');
                    option.value = String(data.id);
                    option.textContent = data.label;
                    clienteSelect.appendChild(option);
                    clienteSelect.value = String(data.id);
                    clienteSelect.dispatchEvent(new Event('change'));

                    var modalInstance = bootstrap.Modal.getInstance(quickAddModal);
                    if (!modalInstance) {
                        modalInstance = new bootstrap.Modal(quickAddModal);
                    }
                    modalInstance.hide();
                })
                .catch(function (error) {
                    var messages = (error && Array.isArray(error.errors)) ? error.errors : ['Impossibile creare il cliente.'];
                    if (quickAddErrors) {
                        quickAddErrors.textContent = messages.join(' ');
                        quickAddErrors.classList.remove('d-none');
                    }
                })
                .finally(function () {
                    submitButton.disabled = false;
                    submitButton.innerHTML = submitLabel;
                });
        });
    });
</script>
