<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Archivio documenti';

if (!function_exists('db_table_exists')) {
    /**
     * Simple helper to detect optional tables without breaking the listing.
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
        $cache[$tableName] = (bool) $stmt->fetchColumn();
        return $cache[$tableName];
    }
}

if (!function_exists('db_column_exists')) {
    /**
     * Check if a column exists to keep queries portable across deployments.
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
        $cache[$key] = (bool) $stmt->fetchColumn();
        return $cache[$key];
    }
}

$hasClientCompanyName = db_column_exists($pdo, 'clienti', 'ragione_sociale');
$hasVersioning = db_table_exists($pdo, 'document_versions');
$hasTagging = db_table_exists($pdo, 'document_tags') && db_table_exists($pdo, 'document_tag_map');

$clientBaseNameExpression = "TRIM(CONCAT_WS(' ', c.nome, c.cognome))";
if ($hasClientCompanyName) {
    $clientDisplayExpression = "COALESCE(NULLIF(TRIM(c.ragione_sociale), ''), NULLIF($clientBaseNameExpression, ''), NULLIF(c.email, ''), c.cf_piva)";
} else {
    $clientDisplayExpression = "COALESCE(NULLIF($clientBaseNameExpression, ''), NULLIF(c.email, ''), c.cf_piva)";
}


$filters = [
    'cliente' => $_GET['cliente'] ?? '',
    'stato' => $_GET['stato'] ?? '',
    'modulo' => $_GET['modulo'] ?? '',
    'tag' => $hasTagging ? ($_GET['tag'] ?? '') : '',
    'search' => trim($_GET['q'] ?? ''),
];

$params = [];
$conditions = [];

if ($filters['cliente'] !== '') {
    $conditions[] = 'd.cliente_id = :cliente_id';
    $params[':cliente_id'] = (int) $filters['cliente'];
}

if ($filters['stato'] !== '') {
    $conditions[] = 'd.stato = :stato';
    $params[':stato'] = $filters['stato'];
}

if ($filters['modulo'] !== '') {
    $conditions[] = 'd.modulo = :modulo';
    $params[':modulo'] = $filters['modulo'];
}

if ($hasTagging && $filters['tag'] !== '') {
    $conditions[] = "EXISTS (
        SELECT 1 FROM document_tag_map dtm
        INNER JOIN document_tags dt ON dt.id = dtm.tag_id
        WHERE dtm.document_id = d.id AND dt.nome = :tag
    )";
    $params[':tag'] = $filters['tag'];
}

if ($filters['search'] !== '') {
    $conditions[] = "(d.titolo LIKE :search OR d.descrizione LIKE :search OR $clientDisplayExpression LIKE :search)";
    $params[':search'] = '%' . $filters['search'] . '%';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

try {
    $selectColumns = [
        'd.id',
        'd.titolo',
        'd.stato',
        'd.modulo',
        'd.updated_at',
        'd.created_at',
    ];

    $joins = [];
    $groupColumns = [
        'd.id',
        'd.titolo',
        'd.stato',
        'd.modulo',
        'd.updated_at',
        'd.created_at',
    ];

    if ($hasClientCompanyName) {
        $groupColumns[] = 'c.ragione_sociale';
    }
    $selectColumns[] = $clientDisplayExpression . ' AS cliente';
    $groupColumns[] = 'c.nome';
    $groupColumns[] = 'c.cognome';
    $groupColumns[] = 'c.email';
    $groupColumns[] = 'c.cf_piva';

    if ($hasTagging) {
        $selectColumns[] = "GROUP_CONCAT(DISTINCT dt.nome ORDER BY dt.nome SEPARATOR ', ') AS tags";
        $joins[] = 'LEFT JOIN document_tag_map dtm ON dtm.document_id = d.id';
        $joins[] = 'LEFT JOIN document_tags dt ON dt.id = dtm.tag_id';
    } else {
        $selectColumns[] = 'NULL AS tags';
    }

    if ($hasVersioning) {
        $selectColumns[] = 'v.versione';
        $selectColumns[] = 'v.file_name';
        $selectColumns[] = 'v.created_at AS versione_data';
        $joins[] = 'LEFT JOIN (
        SELECT dv.document_id, dv.versione, dv.file_name, dv.created_at
        FROM document_versions dv
        INNER JOIN (
            SELECT document_id, MAX(versione) AS latest_version
            FROM document_versions
            GROUP BY document_id
        ) latest ON latest.document_id = dv.document_id AND latest.latest_version = dv.versione
    ) v ON v.document_id = d.id';
        $groupColumns[] = 'v.versione';
        $groupColumns[] = 'v.file_name';
        $groupColumns[] = 'v.created_at';
    } else {
        $selectColumns[] = 'NULL AS versione';
        $selectColumns[] = 'NULL AS file_name';
        $selectColumns[] = 'NULL AS versione_data';
    }

    $sql = 'SELECT ' . implode(",\n        ", $selectColumns) . "\n    FROM documents d\n    LEFT JOIN clienti c ON c.id = d.cliente_id\n    " . implode("\n    ", $joins) . "\n    $where";

    if ($hasTagging) {
        $groupColumns = array_values(array_unique($groupColumns));
        $sql .= "\n    GROUP BY " . implode(', ', $groupColumns);
    }

    $sql .= "\n    ORDER BY d.updated_at DESC";

    $documentsStmt = $pdo->prepare($sql);
    $documentsStmt->execute($params);
    $documents = $documentsStmt->fetchAll();
    $documentsError = null;
} catch (PDOException $exception) {
    error_log('Document archive query failed: ' . $exception->getMessage());
    $documents = [];
    $documentsError = 'Impossibile caricare i documenti. Verifica che le ultime migrazioni siano state applicate al database.';
}

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

$tagOptions = [];
if ($hasTagging) {
    $tagStmt = $pdo->query('SELECT nome FROM document_tags ORDER BY nome');
    $tagOptions = $tagStmt->fetchAll(PDO::FETCH_COLUMN);
}
$moduleOptions = ['Entrate/Uscite', 'Appuntamenti', 'Programma Fedeltà', 'Gestione Curriculum', 'Pickup', 'Ticket', 'Altro'];
$statuses = ['Bozza', 'Pubblicato', 'Archiviato'];

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Archivio documenti</h1>
                <p class="text-muted mb-0">Gestisci contratti, pratiche e allegati con versioning e tag personalizzati.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-upload me-2"></i>Carica documento</a>
            </div>
        </div>

        <form class="card ag-card mb-4" method="get">
            <div class="card-body row g-3 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label" for="cliente">Cliente</label>
                    <select class="form-select" id="cliente" name="cliente">
                        <option value="">Tutti</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo (int)$client['id']; ?>" <?php echo ((string)$filters['cliente'] === (string)$client['id']) ? 'selected' : ''; ?>><?php echo sanitize_output($client['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label" for="stato">Stato</label>
                    <select class="form-select" id="stato" name="stato">
                        <option value="">Tutti</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?php echo $status; ?>" <?php echo $filters['stato'] === $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label" for="modulo">Modulo</label>
                    <select class="form-select" id="modulo" name="modulo">
                        <option value="">Tutti</option>
                        <?php foreach ($moduleOptions as $module): ?>
                            <option value="<?php echo $module; ?>" <?php echo $filters['modulo'] === $module ? 'selected' : ''; ?>><?php echo $module; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label" for="tag">Tag</label>
                    <select class="form-select" id="tag" name="tag" <?php echo $hasTagging ? '' : 'disabled'; ?>>
                        <option value="">Tutti</option>
                        <?php foreach ($tagOptions as $tag): ?>
                            <option value="<?php echo sanitize_output($tag); ?>" <?php echo $filters['tag'] === $tag ? 'selected' : ''; ?>><?php echo sanitize_output($tag); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!$hasTagging): ?>
                        <div class="form-text text-warning">Abilita la tabella dei tag eseguendo l'ultima migrazione per utilizzare questo filtro.</div>
                    <?php endif; ?>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="q">Ricerca</label>
                    <div class="input-group">
                        <input class="form-control" id="q" name="q" placeholder="Titolo, descrizione, cliente" value="<?php echo sanitize_output($filters['search']); ?>">
                        <button class="btn btn-outline-warning" type="submit"><i class="fa-solid fa-magnifying-glass"></i></button>
                    </div>
                </div>
            </div>
        </form>

        <div class="card ag-card">
            <div class="card-body">
                <?php if ($documentsError !== null): ?>
                    <div class="alert alert-warning mb-0" role="alert"><?php echo sanitize_output($documentsError); ?></div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle" data-datatable="true">
                            <thead>
                                <tr>
                                    <th>Titolo</th>
                                    <th>Cliente</th>
                                    <th>Modulo</th>
                                    <th>Stato</th>
                                    <th>Versione</th>
                                    <th>Tag</th>
                                    <th>Aggiornato</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($documents as $doc): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo sanitize_output($doc['titolo']); ?></div>
                                            <small class="text-muted">Caricato il <?php echo sanitize_output(format_datetime($doc['created_at'])); ?></small>
                                        </td>
                                        <td><?php echo sanitize_output($doc['cliente'] ?? '—'); ?></td>
                                        <td><?php echo sanitize_output($doc['modulo']); ?></td>
                                        <td><span class="badge bg-secondary text-uppercase"><?php echo sanitize_output($doc['stato']); ?></span></td>
                                        <td>
                                            <?php if ($doc['versione']): ?>
                                                v<?php echo (int)$doc['versione']; ?><br>
                                                <small class="text-muted"><?php echo sanitize_output($doc['file_name']); ?></small>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($doc['tags']): ?>
                                                <?php foreach (explode(', ', $doc['tags']) as $tag): ?>
                                                    <span class="badge ag-badge me-1 mb-1"><?php echo sanitize_output($tag); ?></span>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo sanitize_output(format_datetime($doc['updated_at'])); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-warning" href="view.php?id=<?php echo (int)$doc['id']; ?>">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (!$documents): ?>
                            <div class="text-center text-muted py-4">Nessun documento trovato con i filtri applicati.</div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
