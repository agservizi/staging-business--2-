<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Modifica pratica ANPR';

$praticaId = (int) ($_GET['id'] ?? 0);
if ($praticaId <= 0) {
    add_flash('warning', 'Pratica non valida.');
    header('Location: index.php');
    exit;
}

$pratica = anpr_fetch_pratica($pdo, $praticaId);
if (!$pratica) {
    add_flash('warning', 'Pratica non trovata.');
    header('Location: index.php');
    exit;
}

$types = anpr_practice_types();
$statuses = ANPR_ALLOWED_STATUSES;
$clienti = anpr_fetch_clienti($pdo);
$csrfToken = csrf_token();

$errors = [];
$data = [
    'cliente_id' => (string) ($pratica['cliente_id'] ?? ''),
    'tipo_pratica' => (string) ($pratica['tipo_pratica'] ?? ''),
    'stato' => (string) ($pratica['stato'] ?? 'In lavorazione'),
    'note_interne' => (string) ($pratica['note_interne'] ?? ''),
    'generate_delega' => !empty($pratica['delega_generata_auto']) ? '1' : '0',
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
        $errors[] = 'Tipologia non valida.';
    }

    if (!in_array($data['stato'], $statuses, true)) {
        $errors[] = 'Stato pratica non valido.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('UPDATE anpr_pratiche
                SET cliente_id = :cliente_id,
                    tipo_pratica = :tipo_pratica,
                    stato = :stato,
                    note_interne = :note_interne,
                    updated_at = NOW()
                WHERE id = :id');
            $stmt->execute([
                ':cliente_id' => $clienteId,
                ':tipo_pratica' => $data['tipo_pratica'],
                ':stato' => $data['stato'],
                ':note_interne' => $data['note_interne'] !== '' ? $data['note_interne'] : null,
                ':id' => $praticaId,
            ]);

            $manualDelegaUploaded = false;
            if (!empty($_FILES['delega']['name'])) {
                $storedDelega = anpr_store_delega($_FILES['delega'], $praticaId);
                anpr_delete_delega($pratica['delega_path'] ?? null);
                anpr_set_delega_metadata($pdo, $praticaId, $storedDelega, false);
                anpr_signature_clear($pdo, $praticaId);
                $manualDelegaUploaded = true;
            }

            if (!$manualDelegaUploaded && anpr_should_generate_delega($data['tipo_pratica'], $generateDelegaRequested)) {
                anpr_auto_generate_delega($pdo, $praticaId);
                $pratica = anpr_fetch_pratica($pdo, $praticaId);
            }

            if (!empty($_FILES['documento']['name'])) {
                $storedDocumento = anpr_store_documento($_FILES['documento'], $praticaId);
                anpr_delete_documento($pratica['documento_path'] ?? null);
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

            if (!empty($_FILES['certificato']['name'])) {
                $storedCert = anpr_store_certificate($_FILES['certificato'], $praticaId);
                anpr_delete_certificate($pratica['certificato_path'] ?? null);
                $updateStmt = $pdo->prepare('UPDATE anpr_pratiche
                    SET certificato_path = :path,
                        certificato_hash = :hash,
                        certificato_caricato_at = NOW()
                    WHERE id = :id');
                $updateStmt->execute([
                    ':path' => $storedCert['path'],
                    ':hash' => $storedCert['hash'],
                    ':id' => $praticaId,
                ]);
            }

            $pdo->commit();

            anpr_log_action($pdo, 'Pratica aggiornata', 'Aggiornata pratica ANPR ' . $pratica['pratica_code']);
            add_flash('success', 'Pratica aggiornata correttamente.');
            header('Location: view_request.php?id=' . $praticaId);
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('ANPR update failed: ' . $exception->getMessage());
            if ($exception instanceof RuntimeException) {
                $errors[] = $exception->getMessage();
            } else {
                $errors[] = 'Impossibile salvare le modifiche. Riprova.';
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
                <h1 class="h3 mb-0">Modifica pratica ANPR</h1>
                <p class="text-muted mb-0">Aggiorna dati e stato della pratica.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-warning" href="https://www.anagrafenazionale.interno.it/servizi-al-cittadino/" target="_blank" rel="noopener">
                    <i class="fa-solid fa-up-right-from-square me-2"></i>Portale ANPR
                </a>
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Indietro</a>
                <a class="btn btn-outline-warning" href="view_request.php?id=<?php echo $praticaId; ?>"><i class="fa-solid fa-eye me-2"></i>Dettagli</a>
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
                <form method="post" class="row g-4">
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <div class="col-md-6">
                        <label class="form-label" for="cliente_id">Cliente</label>
                        <select class="form-select" id="cliente_id" name="cliente_id" required>
                            <?php foreach ($clienti as $cliente): ?>
                                <?php $cid = (int) $cliente['id']; ?>
                                <option value="<?php echo $cid; ?>" <?php echo (string) $cid === $data['cliente_id'] ? 'selected' : ''; ?>><?php echo sanitize_output(trim($cliente['ragione_sociale'] ?: (($cliente['cognome'] ?? '') . ' ' . ($cliente['nome'] ?? '')))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="tipo_pratica">Tipologia</label>
                        <select class="form-select" id="tipo_pratica" name="tipo_pratica" required>
                            <?php foreach ($types as $type): ?>
                                <option value="<?php echo sanitize_output($type); ?>" <?php echo $data['tipo_pratica'] === $type ? 'selected' : ''; ?>><?php echo sanitize_output($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="stato">Stato</label>
                        <select class="form-select" id="stato" name="stato" required>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo sanitize_output($status); ?>" <?php echo $data['stato'] === $status ? 'selected' : ''; ?>><?php echo sanitize_output($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="note_interne">Note interne</label>
                        <textarea class="form-control" id="note_interne" name="note_interne" rows="5" placeholder="Annotazioni operative"><?php echo sanitize_output($data['note_interne']); ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="delega">Aggiorna delega (PDF)</label>
                        <input class="form-control" type="file" id="delega" name="delega" accept="application/pdf">
                        <small class="text-muted d-block">Lascia vuoto per mantenere l'allegato esistente.</small>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="generate_delega" name="generate_delega" value="1" <?php echo $data['generate_delega'] === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="generate_delega">Genera automaticamente la delega se non caricata</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="documento">Aggiorna documento identità (PDF/JPG/PNG)</label>
                        <input class="form-control" type="file" id="documento" name="documento" accept="application/pdf,image/jpeg,image/png">
                        <small class="text-muted">Lascia vuoto per mantenere l'allegato esistente.</small>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="certificato">Aggiorna certificato (PDF)</label>
                        <input class="form-control" type="file" id="certificato" name="certificato" accept="application/pdf">
                        <small class="text-muted">Carica solo se devi sostituire il PDF già salvato.</small>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <a class="btn btn-outline-warning" href="index.php">Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva modifiche</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
