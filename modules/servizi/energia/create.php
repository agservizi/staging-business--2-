<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/mailer.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Nuovo contratto energia';

$csrfToken = csrf_token();

$forniture = ['Luce', 'Gas', 'Dual'];
$operazioni = ['Voltura', 'Subentro'];

$data = [
    'nominativo' => '',
    'codice_fiscale' => '',
    'email' => '',
    'telefono' => '',
    'fornitura' => 'Luce',
    'operazione' => 'Voltura',
    'note' => '',
    'send_now' => '1',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    foreach (array_keys($data) as $field) {
        if ($field === 'send_now') {
            $data[$field] = isset($_POST[$field]) ? '1' : '0';
            continue;
        }
        $data[$field] = trim((string) ($_POST[$field] ?? ''));
    }

    if ($data['nominativo'] === '') {
        $errors[] = 'Inserisci il nominativo del cliente.';
    } elseif (mb_strlen($data['nominativo']) > 160) {
        $errors[] = 'Il nominativo non può superare 160 caratteri.';
    }

    if ($data['codice_fiscale'] !== '' && mb_strlen($data['codice_fiscale']) > 32) {
        $errors[] = 'Il codice fiscale non può superare 32 caratteri.';
    }

    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Inserisci un indirizzo email valido.';
    }

    if ($data['telefono'] !== '' && mb_strlen($data['telefono']) > 40) {
        $errors[] = 'Il numero di telefono non può superare 40 caratteri.';
    }

    if (!in_array($data['fornitura'], $forniture, true)) {
        $data['fornitura'] = 'Luce';
    }

    if (!in_array($data['operazione'], $operazioni, true)) {
        $data['operazione'] = 'Voltura';
    }

    if ($data['note'] !== '' && mb_strlen($data['note']) > 2000) {
        $errors[] = 'Le note non possono superare 2000 caratteri.';
    }

    $uploads = energia_normalize_uploads($_FILES['allegati'] ?? null);
    $allowedMimes = energia_allowed_mime_types();

    $processedUploads = [];
    foreach ($uploads as $upload) {
        if ($upload['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Errore nel caricamento di uno degli allegati.';
            continue;
        }
        if (!is_uploaded_file($upload['tmp_name'])) {
            $errors[] = 'Caricamento file non valido.';
            continue;
        }
        if ($upload['size'] > ENERGIA_MAX_UPLOAD_SIZE) {
            $errors[] = 'Ogni allegato deve essere inferiore a 15 MB.';
            continue;
        }
        $mime = energia_detect_mime($upload['tmp_name']);
        if (!isset($allowedMimes[$mime])) {
            $errors[] = 'Formato file non supportato. Usa PDF o immagini (JPG/PNG).';
            continue;
        }
        $processedUploads[] = [
            'name' => $upload['name'],
            'tmp_name' => $upload['tmp_name'],
            'size' => $upload['size'],
            'mime' => $mime,
        ];
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare('INSERT INTO energia_contratti (
                cliente_id,
                nominativo,
                codice_fiscale,
                email,
                telefono,
                fornitura,
                operazione,
                note,
                stato,
                created_by,
                created_at,
                updated_at
            ) VALUES (
                NULL,
                :nominativo,
                :codice_fiscale,
                :email,
                :telefono,
                :fornitura,
                :operazione,
                :note,
                :stato,
                :created_by,
                NOW(),
                NOW()
            )');

            $stmt->execute([
                ':nominativo' => $data['nominativo'],
                ':codice_fiscale' => $data['codice_fiscale'] ?: null,
                ':email' => $data['email'],
                ':telefono' => $data['telefono'] ?: null,
                ':fornitura' => $data['fornitura'],
                ':operazione' => $data['operazione'],
                ':note' => $data['note'] ?: null,
                ':stato' => 'Registrato',
                ':created_by' => (int) ($_SESSION['user_id'] ?? 0),
            ]);

            $contractId = (int) $pdo->lastInsertId();
            $assignedCode = energia_assign_contract_code($pdo, [
                'id' => $contractId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            if ($processedUploads) {
                $storageDir = public_path('assets/uploads/energia/' . $contractId);
                if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
                    throw new RuntimeException('Impossibile creare la cartella degli allegati.');
                }

                $attachmentStmt = $pdo->prepare('INSERT INTO energia_contratti_allegati (
                    contratto_id,
                    file_name,
                    file_path,
                    mime_type,
                    file_size,
                    created_at
                ) VALUES (
                    :contratto_id,
                    :file_name,
                    :file_path,
                    :mime_type,
                    :file_size,
                    NOW()
                )');

                foreach ($processedUploads as $upload) {
                    $original = sanitize_filename($upload['name']);
                    $unique = sprintf('%s_%s_%s', $contractId, date('YmdHis'), bin2hex(random_bytes(4)) . '_' . $original);
                    $destination = $storageDir . DIRECTORY_SEPARATOR . $unique;
                    if (!move_uploaded_file($upload['tmp_name'], $destination)) {
                        throw new RuntimeException('Impossibile salvare uno degli allegati.');
                    }

                    $relativePath = 'assets/uploads/energia/' . $contractId . '/' . $unique;

                    $attachmentStmt->execute([
                        ':contratto_id' => $contractId,
                        ':file_name' => $original,
                        ':file_path' => $relativePath,
                        ':mime_type' => $upload['mime'],
                        ':file_size' => $upload['size'],
                    ]);
                }
            }

            $pdo->commit();

            energia_log_action($pdo, 'Contratto creato', 'Creato contratto energia #' . $contractId . ' - ' . $data['nominativo']);

            $message = 'Contratto registrato correttamente.';
            if ($assignedCode) {
                $message .= ' Codice contratto: ' . $assignedCode . '.';
            }
            $type = 'success';

            if ($data['send_now'] === '1') {
                $contract = energia_fetch_contract($pdo, $contractId);
                $mailSent = $contract ? energia_send_contract_mail($pdo, $contract, false, 'manual') : false;
                if ($mailSent) {
                    $message = 'Contratto registrato ed email inviata correttamente.';
                    $updatedContract = energia_fetch_contract($pdo, $contractId);
                    $finalCode = $updatedContract['contract_code'] ?? $assignedCode;
                    if ($finalCode) {
                        $message .= ' Codice contratto: ' . $finalCode . '.';
                    }
                } else {
                    $type = 'warning';
                    $message = 'Contratto registrato, ma impossibile inviare l\'email. Invia manualmente dal riepilogo.';
                }
            }

            add_flash($type, $message);
            header('Location: index.php');
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Energia create failed: ' . $exception->getMessage());
            $errors[] = 'Si è verificato un errore durante il salvataggio. Riprova.';
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
                <h1 class="h3 mb-0">Nuovo contratto energia</h1>
                <p class="text-muted mb-0">Carica la documentazione per volture e subentri Enel luce e gas.</p>
            </div>
            <div class="toolbar-actions">
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
                        <label class="form-label" for="nominativo">Nominativo <span class="text-warning">*</span></label>
                        <input class="form-control" id="nominativo" name="nominativo" maxlength="160" value="<?php echo sanitize_output($data['nominativo']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="codice_fiscale">Codice fiscale</label>
                        <input class="form-control" id="codice_fiscale" name="codice_fiscale" maxlength="32" value="<?php echo sanitize_output($data['codice_fiscale']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="email">Email referente <span class="text-warning">*</span></label>
                        <input class="form-control" id="email" name="email" type="email" value="<?php echo sanitize_output($data['email']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="telefono">Telefono</label>
                        <input class="form-control" id="telefono" name="telefono" maxlength="40" value="<?php echo sanitize_output($data['telefono']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="fornitura">Fornitura</label>
                        <select class="form-select" id="fornitura" name="fornitura">
                            <?php foreach ($forniture as $option): ?>
                                <option value="<?php echo sanitize_output($option); ?>" <?php echo $data['fornitura'] === $option ? 'selected' : ''; ?>><?php echo sanitize_output($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="operazione">Operazione richiesta</label>
                        <select class="form-select" id="operazione" name="operazione">
                            <?php foreach ($operazioni as $option): ?>
                                <option value="<?php echo sanitize_output($option); ?>" <?php echo $data['operazione'] === $option ? 'selected' : ''; ?>><?php echo sanitize_output($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="note">Note operative</label>
                        <textarea class="form-control" id="note" name="note" rows="4" maxlength="2000" placeholder="Inserisci informazioni utili per la gestione."><?php echo sanitize_output($data['note']); ?></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="allegati">Allegati contratto</label>
                        <input class="form-control" id="allegati" name="allegati[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png">
                        <small class="text-muted">Formati consentiti: PDF, JPG, PNG. Dimensione massima 15 MB per file.</small>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="send_now" name="send_now" value="1" <?php echo $data['send_now'] === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="send_now">Invia subito email a energia@newprojectmobile.it</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <a class="btn btn-outline-warning" href="index.php">Annulla</a>
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva contratto</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
