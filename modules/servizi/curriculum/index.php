<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Gestione Curriculum';

$filters = [
    ':cliente' => isset($_GET['cliente']) ? trim($_GET['cliente']) : '',
    ':status' => isset($_GET['status']) ? trim($_GET['status']) : ''
];

$sql = "SELECT cv.id,
               cv.titolo,
               cv.status,
               cv.created_at,
               cv.updated_at,
               cv.last_generated_at,
               cv.generated_file,
               c.nome,
               c.cognome
        FROM curriculum cv
        LEFT JOIN clienti c ON cv.cliente_id = c.id";

$where = [];
$params = [];

if ($filters[':cliente'] !== '') {
    $where[] = "(c.cognome LIKE :search_client OR c.nome LIKE :search_client)";
    $params[':search_client'] = '%' . $filters[':cliente'] . '%';
}

if ($filters[':status'] !== '') {
    $where[] = 'cv.status = :status';
    $params[':status'] = $filters[':status'];
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY cv.updated_at DESC, cv.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$statuses = ['Bozza', 'Pubblicato', 'Archiviato'];

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Gestione Curriculum</h1>
                <p class="text-muted mb-0">Progetta, compila e genera curriculum Europass per i tuoi clienti.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-warning text-dark" href="wizard.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo curriculum</a>
            </div>
        </div>
        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="card-title h5 mb-0">Filtri</h2>
            </div>
            <div class="card-body">
                <form class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="cliente">Cliente</label>
                        <input class="form-control" id="cliente" name="cliente" type="text" value="<?php echo sanitize_output($filters[':cliente']); ?>" placeholder="Cerca cliente">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="status">Stato</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">Tutti</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo $status; ?>" <?php echo $filters[':status'] === $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 align-self-end">
                        <div class="d-flex gap-2">
                            <button class="btn btn-warning text-dark" type="submit">Applica</button>
                            <a class="btn btn-outline-secondary" href="index.php">Reimposta</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-hover" data-datatable="true">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Cliente</th>
                                <th>Titolo</th>
                                <th>Stato</th>
                                <th>Ultima generazione</th>
                                <th>Documento</th>
                                <th>Ultimo aggiornamento</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $cv): ?>
                                <tr>
                                    <td>#<?php echo (int) $cv['id']; ?></td>
                                    <td><?php echo sanitize_output(trim(($cv['cognome'] ?? '') . ' ' . ($cv['nome'] ?? '')) ?: 'N/D'); ?></td>
                                    <td><?php echo sanitize_output($cv['titolo']); ?></td>
                                    <td>
                                        <span class="badge ag-badge text-uppercase text-white <?php echo $cv['status'] === 'Pubblicato' ? 'bg-success' : ($cv['status'] === 'Archiviato' ? 'bg-secondary' : 'bg-warning'); ?>">
                                            <?php echo sanitize_output($cv['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($cv['last_generated_at']): ?>
                                            <?php echo sanitize_output(format_datetime_locale($cv['last_generated_at'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Mai</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($cv['generated_file']): ?>
                                            <a class="btn btn-icon btn-soft-accent btn-sm" href="../../../<?php echo sanitize_output($cv['generated_file']); ?>" target="_blank" rel="noopener" title="Apri PDF" aria-label="Apri PDF">
                                                <i class="fa-solid fa-file-pdf"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">N/D</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo sanitize_output(format_datetime_locale($cv['updated_at'])); ?></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap">
                                            <a class="btn btn-icon btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $cv['id']; ?>" title="Dettagli" aria-label="Dettagli">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                            <a class="btn btn-icon btn-soft-accent btn-sm" href="wizard.php?id=<?php echo (int) $cv['id']; ?>" title="Modifica" aria-label="Modifica">
                                                <i class="fa-solid fa-pen"></i>
                                            </a>
                                            <form method="post" action="publish.php" class="d-inline">
                                                <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int) $cv['id']; ?>">
                                                <button class="btn btn-icon btn-soft-accent btn-sm" type="submit" title="Genera PDF" aria-label="Genera PDF">
                                                    <i class="fa-solid fa-file-pdf"></i>
                                                </button>
                                            </form>
                                            <form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('Confermi l\'eliminazione del curriculum? Questa operazione è irreversibile.');">
                                                <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int) $cv['id']; ?>">
                                                <button class="btn btn-icon btn-soft-danger btn-sm" type="submit" title="Elimina" aria-label="Elimina">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
