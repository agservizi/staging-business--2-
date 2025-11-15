<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Carica documento';

if (!function_exists('db_table_exists')) {
    /**
     * Detect whether an optional table is present without failing the request.
     */
    function db_table_exists(PDO $pdo, string $tableName): bool
    {
        static $cache = [];
        if (array_key_exists($tableName, $cache)) {
            return $cache[$tableName];
        }

        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :table LIMIT 1'
        );
        $stmt->execute([':table' => $tableName]);
        $cache[$tableName] = (bool)$stmt->fetchColumn();
        return $cache[$tableName];
    }
}

if (!function_exists('db_column_exists')) {
    /**
     * Quick helper to discover optional columns that may not exist everywhere.
     */
    function db_column_exists(PDO $pdo, string $tableName, string $columnName): bool
    {
        static $cache = [];
        $key = $tableName . ':' . $columnName;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1'
        );
        $stmt->execute([
            ':table' => $tableName,
            ':column' => $columnName,
        ]);
        $cache[$key] = (bool)$stmt->fetchColumn();
        return $cache[$key];
    }
}

$hasClientCompanyName = db_column_exists($pdo, 'clienti', 'ragione_sociale');
$hasVersioning = db_table_exists($pdo, 'document_versions');
$hasTagging = db_table_exists($pdo, 'document_tags') && db_table_exists($pdo, 'document_tag_map');

$clientSelectColumns = ['id', 'nome', 'cognome', 'email', 'cf_piva'];
if ($hasClientCompanyName) {
    $clientSelectColumns[] = 'ragione_sociale';
}

$clientOrderBy = $hasClientCompanyName ? 'ragione_sociale, nome, cognome' : 'nome, cognome';
$clientStmt = $pdo->query('SELECT ' . implode(', ', $clientSelectColumns) . ' FROM clienti ORDER BY ' . $clientOrderBy);
$clientRows = $clientStmt->fetchAll();

$clients = [];
foreach ($clientRows as $clientRow) {
    $display = '';
    if ($hasClientCompanyName) {
        $display = trim((string)($clientRow['ragione_sociale'] ?? ''));
    }

    if ($display === '') {
        $first = trim((string)($clientRow['nome'] ?? ''));
        $last = trim((string)($clientRow['cognome'] ?? ''));
        $fullName = trim($first . ' ' . $last);
        if ($fullName !== '') {
            $display = $fullName;
        }
    }

    if ($display === '') {
        $display = trim((string)($clientRow['email'] ?? ''));
    }

    if ($display === '') {
        $display = trim((string)($clientRow['cf_piva'] ?? ''));
    }

    if ($display === '') {
        $display = 'Cliente #' . (string)$clientRow['id'];
    }

    $clients[] = [
        'id' => (int)$clientRow['id'],
        'label' => $display,
    ];
}

$moduleOptions = ['Entrate/Uscite', 'Appuntamenti', 'Programma Fedeltà', 'Gestione Curriculum', 'Pickup', 'Ticket', 'Altro'];
$statuses = ['Bozza', 'Pubblicato', 'Archiviato'];
$csrfToken = csrf_token();
$existingTags = [];
if ($hasTagging) {
    $tagStmt = $pdo->query('SELECT nome FROM document_tags ORDER BY nome');
    $existingTags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titolo = trim($_POST['titolo'] ?? '');
    $descrizione = trim($_POST['descrizione'] ?? '');
    $clienteId = $_POST['cliente_id'] !== '' ? (int) $_POST['cliente_id'] : null;
    $modulo = $_POST['modulo'] ?? 'Altro';
    $stato = $_POST['stato'] ?? 'Bozza';
    $tagsInput = trim($_POST['tags'] ?? '');
    $tags = array_filter(array_map('trim', explode(',', $tagsInput)), fn($tag) => $tag !== '');

    if (!$hasVersioning) {
        $errors[] = 'Struttura archivio documenti non aggiornata. Esegui la migrazione che crea la tabella document_versions prima di caricare nuovi file.';
    }

    if ($titolo === '') {
        $errors[] = 'Inserisci un titolo identificativo.';
    }

    if (!isset($_FILES['documento']) || $_FILES['documento']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Seleziona un file da caricare.';
    }

    if (!$errors) {
        $file = $_FILES['documento'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'jpg', 'jpeg', 'png'];
        if (!in_array($extension, $allowed, true)) {
            $errors[] = 'Formato non supportato. Carica PDF, Office, immagini o archivi.';
        }
    }

    if (!$hasTagging && $tags) {
        $errors[] = 'La gestione dei tag non è ancora disponibile su questo ambiente. Rimuovi i tag o applica la migrazione document_tags.';
    }

    if ($clienteId !== null && $clienteId <= 0) {
        $errors[] = 'Seleziona un cliente valido.';
    }

    if (!$errors && $clienteId !== null) {
        $clientExistsStmt = $pdo->prepare('SELECT 1 FROM clienti WHERE id = :id LIMIT 1');
        $clientExistsStmt->execute([':id' => $clienteId]);
        if (!$clientExistsStmt->fetchColumn()) {
            $errors[] = 'Il cliente selezionato non esiste più.';
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO documents (titolo, descrizione, cliente_id, modulo, stato, owner_id, created_at, updated_at)
                VALUES (:titolo, :descrizione, :cliente_id, :modulo, :stato, :owner_id, NOW(), NOW())');
            $stmt->execute([
                ':titolo' => $titolo,
                ':descrizione' => $descrizione,
                ':cliente_id' => $clienteId,
                ':modulo' => $modulo,
                ':stato' => $stato,
                ':owner_id' => $_SESSION['user_id'],
            ]);

            $documentId = (int) $pdo->lastInsertId();
            $uploadDir = __DIR__ . '/../../assets/uploads/documenti/' . $documentId;
            if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                throw new RuntimeException('Impossibile creare la cartella di destinazione.');
            }

            $safeName = sanitize_filename($file['name']);
            $versionName = sprintf('v1_%s', $safeName);
            $destination = $uploadDir . '/' . $versionName;
            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                throw new RuntimeException('Impossibile salvare il file caricato.');
            }

            $versionStmt = $pdo->prepare('INSERT INTO document_versions (document_id, versione, file_name, file_path, mime_type, file_size, uploaded_by, created_at)
                VALUES (:document_id, :versione, :file_name, :file_path, :mime_type, :file_size, :uploaded_by, NOW())');
            $versionStmt->execute([
                ':document_id' => $documentId,
                ':versione' => 1,
                ':file_name' => $safeName,
                ':file_path' => 'assets/uploads/documenti/' . $documentId . '/' . $versionName,
                ':mime_type' => mime_content_type($destination) ?: 'application/octet-stream',
                ':file_size' => filesize($destination),
                ':uploaded_by' => $_SESSION['user_id'],
            ]);

            if ($hasTagging && $tags) {
                $tagInsert = $pdo->prepare('INSERT INTO document_tags (nome) VALUES (:nome)
                    ON DUPLICATE KEY UPDATE nome = VALUES(nome)');
                $tagLink = $pdo->prepare('INSERT INTO document_tag_map (document_id, tag_id) VALUES (:document_id, :tag_id)
                    ON DUPLICATE KEY UPDATE tag_id = tag_id');
                foreach ($tags as $tag) {
                    $tagInsert->execute([':nome' => $tag]);
                    $tagId = (int) $pdo->lastInsertId();
                    if ($tagId === 0) {
                        $fetch = $pdo->prepare('SELECT id FROM document_tags WHERE nome = :nome LIMIT 1');
                        $fetch->execute([':nome' => $tag]);
                        $tagId = (int) $fetch->fetchColumn();
                    }
                    $tagLink->execute([
                        ':document_id' => $documentId,
                        ':tag_id' => $tagId,
                    ]);
                }
            }

            $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
                VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
            $logStmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':modulo' => 'Documenti',
                ':azione' => 'Upload documento',
                ':dettagli' => $titolo,
            ]);

            $pdo->commit();
            add_flash('success', 'Documento caricato con successo.');
            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Upload documento fallito: ' . $e->getMessage());
            $errors[] = 'Errore durante il salvataggio. Riprova.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <h1 class="h3 mb-0">Carica documento</h1>
            <div class="toolbar-actions">
                <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Torna all'archivio</a>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo sanitize_output($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if (!$hasVersioning): ?>
            <div class="alert alert-warning">
                Per caricare nuovi documenti è necessario applicare la migrazione che crea la tabella <code>document_versions</code> e relative dipendenze.
            </div>
        <?php endif; ?>

        <div class="card ag-card">
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" class="row g-4">
                    <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="titolo">Titolo *</label>
                        <input class="form-control" id="titolo" name="titolo" required value="<?php echo sanitize_output($_POST['titolo'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-lg-6">
                        <label class="form-label" for="cliente_id">Cliente</label>
                        <select class="form-select" id="cliente_id" name="cliente_id">
                            <option value="">Nessuno</option>
                            <?php
                            $selectedClientId = $_POST['cliente_id'] ?? '';
                            foreach ($clients as $client): ?>
                                <option value="<?php echo (int)$client['id']; ?>" <?php echo ((string)$selectedClientId === (string)$client['id']) ? 'selected' : ''; ?>><?php echo sanitize_output($client['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="descrizione">Descrizione</label>
                        <textarea class="form-control" id="descrizione" name="descrizione" rows="3"><?php echo sanitize_output($_POST['descrizione'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="modulo">Modulo di riferimento</label>
                        <select class="form-select" id="modulo" name="modulo">
                            <?php foreach ($moduleOptions as $module): ?>
                                <option value="<?php echo $module; ?>" <?php echo ($_POST['modulo'] ?? '') === $module ? 'selected' : ''; ?>><?php echo $module; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="stato">Stato</label>
                        <select class="form-select" id="stato" name="stato">
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo $status; ?>" <?php echo ($_POST['stato'] ?? '') === $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="tags">Tag</label>
                        <input class="form-control" id="tags" name="tags" placeholder="es. Contratti, Fatture" value="<?php echo sanitize_output($_POST['tags'] ?? ''); ?>" <?php echo $hasTagging ? '' : 'disabled'; ?>>
                        <small class="text-muted">
                            Separare i tag con la virgola.
                            <?php if ($existingTags): ?>
                                Tag già utilizzati: <?php echo sanitize_output(implode(', ', $existingTags)); ?>.
                            <?php elseif (!$hasTagging): ?>
                                Per abilitare i tag esegui la migrazione corrispondente.
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="documento">File *</label>
                        <input class="form-control" id="documento" name="documento" type="file" <?php echo $hasVersioning ? 'required' : 'disabled'; ?>>
                    </div>
                    <div class="col-12 text-end">
                        <button class="btn btn-warning text-dark" type="submit" <?php echo $hasVersioning ? '' : 'disabled'; ?>><i class="fa-solid fa-cloud-arrow-up me-2"></i>Carica</button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
