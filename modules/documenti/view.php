<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Dettaglio documento';
$csrfToken = csrf_token();

$documentId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($documentId <= 0) {
    header('Location: index.php');
    exit;
}

$documentStmt = $pdo->prepare('SELECT d.*, c.ragione_sociale, c.nome AS cliente_nome, c.cognome AS cliente_cognome, c.email AS cliente_email, c.cf_piva AS cliente_cf_piva, u.username AS proprietario
    FROM documents d
    LEFT JOIN clienti c ON c.id = d.cliente_id
    LEFT JOIN users u ON u.id = d.owner_id
    WHERE d.id = :id LIMIT 1');
$documentStmt->execute([':id' => $documentId]);
$document = $documentStmt->fetch();

if (!$document) {
    add_flash('warning', 'Documento non trovato.');
    header('Location: index.php');
    exit;
}

if (!function_exists('db_column_exists')) {
    function db_column_exists(PDO $pdo, string $tableName, string $columnName): bool
    {
        static $cache = [];
        $key = $tableName . ':' . $columnName;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1');
        $stmt->execute([':table' => $tableName, ':column' => $columnName]);
        $cache[$key] = (bool) $stmt->fetchColumn();
        return $cache[$key];
    }
}

$moduleOptions = ['Entrate/Uscite', 'Appuntamenti', 'Programma Fedeltà', 'Gestione Curriculum', 'Pickup', 'Ticket', 'Altro'];
$statuses = ['Bozza', 'Pubblicato', 'Archiviato'];

$hasClientCompanyName = db_column_exists($pdo, 'clienti', 'ragione_sociale');
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
        $nameParts = array_filter([
            trim((string)($clientRow['nome'] ?? '')),
            trim((string)($clientRow['cognome'] ?? '')),
        ]);
        if ($nameParts) {
            $display = implode(' ', $nameParts);
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

$tagStmt = $pdo->prepare('SELECT dt.nome FROM document_tag_map dtm INNER JOIN document_tags dt ON dt.id = dtm.tag_id WHERE dtm.document_id = :id ORDER BY dt.nome');
$tagStmt->execute([':id' => $documentId]);
$currentTags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'meta') {
        $titolo = trim($_POST['titolo'] ?? '');
        $descrizione = trim($_POST['descrizione'] ?? '');
        $clienteId = $_POST['cliente_id'] !== '' ? (int) $_POST['cliente_id'] : null;
        $modulo = $_POST['modulo'] ?? $document['modulo'];
        $stato = $_POST['stato'] ?? $document['stato'];
        $tagsInput = trim($_POST['tags'] ?? '');
        $tags = array_filter(array_map('trim', explode(',', $tagsInput)), fn($tag) => $tag !== '');

        if ($titolo === '') {
            add_flash('danger', 'Il titolo è obbligatorio.');
        } else {
            $pdo->beginTransaction();
            try {
                $update = $pdo->prepare('UPDATE documents SET titolo = :titolo, descrizione = :descrizione, cliente_id = :cliente_id, modulo = :modulo, stato = :stato, updated_at = NOW() WHERE id = :id');
                $update->execute([
                    ':titolo' => $titolo,
                    ':descrizione' => $descrizione,
                    ':cliente_id' => $clienteId,
                    ':modulo' => $modulo,
                    ':stato' => $stato,
                    ':id' => $documentId,
                ]);

                $pdo->prepare('DELETE FROM document_tag_map WHERE document_id = :id')->execute([':id' => $documentId]);
                if ($tags) {
                    $tagInsert = $pdo->prepare('INSERT INTO document_tags (nome) VALUES (:nome)
                        ON DUPLICATE KEY UPDATE nome = VALUES(nome)');
                    $tagLink = $pdo->prepare('INSERT INTO document_tag_map (document_id, tag_id) VALUES (:document_id, :tag_id)');
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
                    ':azione' => 'Aggiornamento metadati',
                    ':dettagli' => $titolo,
                ]);

                $pdo->commit();
                add_flash('success', 'Dati aggiornati.');
                header('Location: view.php?id=' . $documentId);
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('Aggiornamento metadati documento fallito: ' . $e->getMessage());
                add_flash('danger', 'Errore durante il salvataggio.');
            }
        }
    }

    if ($action === 'version') {
        if (!isset($_FILES['nuova_versione']) || $_FILES['nuova_versione']['error'] !== UPLOAD_ERR_OK) {
            add_flash('danger', 'Seleziona un file da caricare.');
        } else {
            $file = $_FILES['nuova_versione'];
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'jpg', 'jpeg', 'png'];
            if (!in_array($extension, $allowed, true)) {
                add_flash('danger', 'Formato non supportato per la versione.');
            } else {
                $pdo->beginTransaction();
                try {
                    $nextVersionStmt = $pdo->prepare('SELECT COALESCE(MAX(versione), 0) + 1 FROM document_versions WHERE document_id = :id');
                    $nextVersionStmt->execute([':id' => $documentId]);
                    $versionNumber = (int) $nextVersionStmt->fetchColumn();

                    $uploadDir = __DIR__ . '/../../assets/uploads/documenti/' . $documentId;
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0775, true);
                    }

                    $safeName = sanitize_filename($file['name']);
                    $versionName = sprintf('v%d_%s', $versionNumber, $safeName);
                    $destination = $uploadDir . '/' . $versionName;
                    if (!move_uploaded_file($file['tmp_name'], $destination)) {
                        throw new RuntimeException('Salvataggio file non riuscito.');
                    }

                    $insertVersion = $pdo->prepare('INSERT INTO document_versions (document_id, versione, file_name, file_path, mime_type, file_size, uploaded_by, created_at)
                        VALUES (:document_id, :versione, :file_name, :file_path, :mime_type, :file_size, :uploaded_by, NOW())');
                    $insertVersion->execute([
                        ':document_id' => $documentId,
                        ':versione' => $versionNumber,
                        ':file_name' => $safeName,
                        ':file_path' => 'assets/uploads/documenti/' . $documentId . '/' . $versionName,
                        ':mime_type' => mime_content_type($destination) ?: 'application/octet-stream',
                        ':file_size' => filesize($destination),
                        ':uploaded_by' => $_SESSION['user_id'],
                    ]);

                    $pdo->prepare('UPDATE documents SET updated_at = NOW() WHERE id = :id')->execute([':id' => $documentId]);

                    $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
                        VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
                    $logStmt->execute([
                        ':user_id' => $_SESSION['user_id'],
                        ':modulo' => 'Documenti',
                        ':azione' => 'Nuova versione',
                        ':dettagli' => $document['titolo'] . ' v' . $versionNumber,
                    ]);

                    $pdo->commit();
                    add_flash('success', 'Nuova versione caricata.');
                    header('Location: view.php?id=' . $documentId);
                    exit;
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('Upload versione documento fallito: ' . $e->getMessage());
                    add_flash('danger', 'Impossibile salvare la nuova versione.');
                }
            }
        }
    }
}

$versionStmt = $pdo->prepare('SELECT dv.*, u.username AS autore
    FROM document_versions dv
    LEFT JOIN users u ON u.id = dv.uploaded_by
    WHERE dv.document_id = :id
    ORDER BY dv.versione DESC');
$versionStmt->execute([':id' => $documentId]);
$versions = $versionStmt->fetchAll();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <h1 class="h3 mb-0"><?php echo sanitize_output($document['titolo']); ?></h1>
            <div class="toolbar-actions">
                <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Archivio</a>
                <?php if ($versions): ?>
                    <a class="btn btn-warning text-dark" href="download.php?version=<?php echo (int) $versions[0]['id']; ?>"><i class="fa-solid fa-cloud-arrow-down me-2"></i>Scarica ultima</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-12 col-xl-6">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Metadati</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <input type="hidden" name="action" value="meta">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="col-12">
                                <label class="form-label" for="titolo">Titolo</label>
                                <input class="form-control" id="titolo" name="titolo" value="<?php echo sanitize_output($document['titolo']); ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="descrizione">Descrizione</label>
                                <textarea class="form-control" id="descrizione" name="descrizione" rows="3"><?php echo sanitize_output($document['descrizione'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="cliente_id">Cliente</label>
                                <select class="form-select" id="cliente_id" name="cliente_id">
                                    <option value="">Nessuno</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo (int)$client['id']; ?>" <?php echo ((string)$document['cliente_id'] === (string)$client['id']) ? 'selected' : ''; ?>><?php echo sanitize_output($client['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label" for="modulo">Modulo</label>
                                <select class="form-select" id="modulo" name="modulo">
                                    <?php foreach ($moduleOptions as $module): ?>
                                        <option value="<?php echo $module; ?>" <?php echo $document['modulo'] === $module ? 'selected' : ''; ?>><?php echo $module; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-3">
                                <label class="form-label" for="stato">Stato</label>
                                <select class="form-select" id="stato" name="stato">
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?php echo $status; ?>" <?php echo $document['stato'] === $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="tags">Tag</label>
                                <input class="form-control" id="tags" name="tags" value="<?php echo sanitize_output(implode(', ', $currentTags)); ?>">
                            </div>
                            <div class="col-12">
                                <div class="d-flex justify-content-between text-muted small">
                                    <span>Caricato da: <?php echo sanitize_output($document['proprietario'] ?? 'Sconosciuto'); ?></span>
                                    <span>Aggiornato: <?php echo sanitize_output(format_datetime($document['updated_at'])); ?></span>
                                </div>
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva metadati</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-6">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Versioni</h5>
                        <span class="badge bg-secondary">Totali: <?php echo count($versions); ?></span>
                    </div>
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data" class="mb-4">
                            <input type="hidden" name="action" value="version">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <label class="form-label" for="nuova_versione">Carica una nuova versione</label>
                            <input class="form-control mb-2" id="nuova_versione" name="nuova_versione" type="file" required>
                            <button class="btn btn-outline-warning" type="submit"><i class="fa-solid fa-plus me-2"></i>Aggiungi versione</button>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Versione</th>
                                        <th>File</th>
                                        <th>Caricato da</th>
                                        <th>Data</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($versions as $version): ?>
                                        <tr>
                                            <td><span class="badge ag-badge">v<?php echo (int) $version['versione']; ?></span></td>
                                            <td><?php echo sanitize_output($version['file_name']); ?><br><small class="text-muted"><?php echo number_format((float) $version['file_size'] / 1024, 1); ?> KB</small></td>
                                            <td><?php echo sanitize_output($version['autore'] ?? 'Sconosciuto'); ?></td>
                                            <td><?php echo sanitize_output(format_datetime($version['created_at'])); ?></td>
                                            <td class="text-end">
                                                <a class="btn btn-sm btn-outline-light" href="download.php?version=<?php echo (int) $version['id']; ?>"><i class="fa-solid fa-download"></i></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$versions): ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">Nessuna versione disponibile.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
