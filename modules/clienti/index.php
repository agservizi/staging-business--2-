<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore');
$pageTitle = 'Clienti';

$searchTerm = trim($_GET['q'] ?? '');
$createdFromRaw = trim($_GET['created_from'] ?? '');
$createdToRaw = trim($_GET['created_to'] ?? '');

$createdFrom = DateTimeImmutable::createFromFormat('Y-m-d', $createdFromRaw) ?: null;
$createdTo = DateTimeImmutable::createFromFormat('Y-m-d', $createdToRaw) ?: null;

if ($createdFrom && $createdTo && $createdFrom > $createdTo) {
    add_flash('warning', 'Intervallo date non valido: la data iniziale non può superare quella finale.');
    $createdTo = null;
}

$selectColumns = 'id, ragione_sociale, nome, cognome, cf_piva, email, telefono, indirizzo, note, created_at';
$baseQuery = 'FROM clienti';
$params = [];
$conditions = [];
$allowedSorts = [
    'id' => 'id',
    'cliente' => "CASE WHEN ragione_sociale <> '' THEN ragione_sociale ELSE CONCAT(cognome, ' ', nome) END",
    'cf_piva' => 'cf_piva',
    'email' => 'email',
    'telefono' => 'telefono',
    'created_at' => 'created_at'
];
$sortKey = strtolower(trim($_GET['sort'] ?? 'cliente'));
if (!array_key_exists($sortKey, $allowedSorts)) {
    $sortKey = 'cliente';
}
$sortDirection = strtolower(trim($_GET['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

if ($searchTerm !== '') {
    $conditions[] = '(
        ragione_sociale LIKE :term_ragione OR
        nome LIKE :term_nome OR
        cognome LIKE :term_cognome OR
        email LIKE :term_email OR
        cf_piva LIKE :term_cf
    )';
    $likeTerm = "%{$searchTerm}%";
    $params[':term_ragione'] = $likeTerm;
    $params[':term_nome'] = $likeTerm;
    $params[':term_cognome'] = $likeTerm;
    $params[':term_email'] = $likeTerm;
    $params[':term_cf'] = $likeTerm;
}

if ($createdFrom) {
    $conditions[] = 'DATE(created_at) >= :created_from';
    $params[':created_from'] = $createdFrom->format('Y-m-d');
}

if ($createdTo) {
    $conditions[] = 'DATE(created_at) <= :created_to';
    $params[':created_to'] = $createdTo->format('Y-m-d');
}

$whereClause = $conditions ? ' WHERE ' . implode(' AND ', $conditions) : '';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$countStmt = $pdo->prepare("SELECT COUNT(*) $baseQuery$whereClause");
$countStmt->execute($params);
$totalClients = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalClients / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$orderExpression = $allowedSorts[$sortKey] ?? $allowedSorts['cliente'];
$orderClause = $orderExpression . ' ' . strtoupper($sortDirection);
if ($sortKey === 'cliente') {
    $orderClause .= ', cognome ' . strtoupper($sortDirection) . ', nome ' . strtoupper($sortDirection) . ', id ASC';
} elseif ($sortKey !== 'id') {
    $orderClause .= ', id ' . ($sortDirection === 'desc' ? 'DESC' : 'ASC');
}

$dataSql = "SELECT $selectColumns $baseQuery$whereClause ORDER BY $orderClause LIMIT :limit OFFSET :offset";
$stmt = $pdo->prepare($dataSql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
$stmt->execute();
$clients = $stmt->fetchAll();

$displayFrom = $totalClients > 0 ? $offset + 1 : 0;
$displayTo = $totalClients > 0 ? $offset + count($clients) : 0;
$paginationQuery = [
    'q' => $searchTerm,
    'created_from' => $createdFromRaw,
    'created_to' => $createdToRaw,
    'sort' => $sortKey,
    'dir' => $sortDirection,
];

$buildSortLink = static function (string $column) use ($paginationQuery, $sortKey, $sortDirection): string {
    $nextDir = ($sortKey === $column && $sortDirection === 'asc') ? 'desc' : 'asc';
    $query = array_merge($paginationQuery, ['sort' => $column, 'dir' => $nextDir, 'page' => 1]);
    return '?' . http_build_query($query);
};

$sortIndicator = static function (string $column) use ($sortKey, $sortDirection): string {
    if ($sortKey !== $column) {
        return '';
    }
    return $sortDirection === 'asc' ? ' &#9650;' : ' &#9660;';
};

$csrfToken = csrf_token();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Gestione Clienti</h1>
                <p class="text-muted mb-0">Amministra l'anagrafica clienti e consulta lo storico dei servizi associati.</p>
            </div>
            <div class="toolbar-actions">
                <form class="toolbar-search" method="get" role="search">
                    <div class="input-group">
                        <input class="form-control" type="search" name="q" placeholder="Cerca per nome, email o CF" value="<?php echo sanitize_output($searchTerm); ?>">
                        <input class="form-control" type="date" name="created_from" value="<?php echo sanitize_output($createdFrom ? $createdFrom->format('Y-m-d') : ''); ?>" aria-label="Registrati dal">
                        <input class="form-control" type="date" name="created_to" value="<?php echo sanitize_output($createdTo ? $createdTo->format('Y-m-d') : ''); ?>" aria-label="Registrati fino al">
                        <button class="btn btn-warning" type="submit" title="Applica filtri"><i class="fa-solid fa-search"></i></button>
                        <a class="btn btn-outline-warning" href="index.php" title="Reimposta"><i class="fa-solid fa-rotate-left"></i></a>
                    </div>
                </form>
                <a class="btn btn-outline-warning" href="import.php"><i class="fa-solid fa-file-import me-2"></i>Importa CSV</a>
                <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-user-plus me-2"></i>Nuovo cliente</a>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-body">
                <?php if ($clients): ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover">
                            <thead>
                                <tr>
                                    <th scope="col">
                                        <a class="text-decoration-none text-reset <?php echo $sortKey === 'id' ? 'fw-semibold' : ''; ?>" href="<?php echo sanitize_output($buildSortLink('id')); ?>">
                                            #<?php echo $sortIndicator('id'); ?>
                                        </a>
                                    </th>
                                    <th scope="col">
                                        <a class="text-decoration-none text-reset <?php echo $sortKey === 'cliente' ? 'fw-semibold' : ''; ?>" href="<?php echo sanitize_output($buildSortLink('cliente')); ?>">
                                            Cliente<?php echo $sortIndicator('cliente'); ?>
                                        </a>
                                    </th>
                                    <th scope="col">
                                        <a class="text-decoration-none text-reset <?php echo $sortKey === 'cf_piva' ? 'fw-semibold' : ''; ?>" href="<?php echo sanitize_output($buildSortLink('cf_piva')); ?>">
                                            CF / P.IVA<?php echo $sortIndicator('cf_piva'); ?>
                                        </a>
                                    </th>
                                    <th scope="col">
                                        <a class="text-decoration-none text-reset <?php echo $sortKey === 'email' ? 'fw-semibold' : ''; ?>" href="<?php echo sanitize_output($buildSortLink('email')); ?>">
                                            Email<?php echo $sortIndicator('email'); ?>
                                        </a>
                                    </th>
                                    <th scope="col">
                                        <a class="text-decoration-none text-reset <?php echo $sortKey === 'telefono' ? 'fw-semibold' : ''; ?>" href="<?php echo sanitize_output($buildSortLink('telefono')); ?>">
                                            Telefono<?php echo $sortIndicator('telefono'); ?>
                                        </a>
                                    </th>
                                    <th scope="col">
                                        <a class="text-decoration-none text-reset <?php echo $sortKey === 'created_at' ? 'fw-semibold' : ''; ?>" href="<?php echo sanitize_output($buildSortLink('created_at')); ?>">
                                            Registrato<?php echo $sortIndicator('created_at'); ?>
                                        </a>
                                    </th>
                                    <th class="text-end" scope="col">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($clients as $client): ?>
                                    <tr>
                                        <td><?php echo (int) $client['id']; ?></td>
                                        <td>
                                            <?php
                                                $company = trim((string) ($client['ragione_sociale'] ?? ''));
                                                $fullName = trim(trim((string) ($client['cognome'] ?? '')) . ' ' . trim((string) ($client['nome'] ?? '')));
                                            ?>
                                            <div class="fw-semibold"><?php echo sanitize_output($company !== '' ? $company : ($fullName !== '' ? $fullName : 'Cliente #' . (string) $client['id'])); ?></div>
                                            <?php if ($company !== '' && $fullName !== ''): ?>
                                                <small class="text-muted">Referente: <?php echo sanitize_output($fullName); ?></small><br>
                                            <?php endif; ?>
                                            <?php if (!empty($client['indirizzo'])): ?>
                                                <small class="text-muted"><?php echo sanitize_output($client['indirizzo']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo sanitize_output($client['cf_piva']); ?></td>
                                        <td>
                                            <?php $email = trim((string) ($client['email'] ?? '')); ?>
                                            <?php if ($email !== ''): ?>
                                                <a class="link-warning" href="mailto:<?php echo sanitize_output($email); ?>"><?php echo sanitize_output($email); ?></a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php $phone = trim((string) ($client['telefono'] ?? '')); ?>
                                            <?php if ($phone !== ''): ?>
                                                <a class="link-warning" href="tel:<?php echo sanitize_output($phone); ?>"><?php echo sanitize_output($phone); ?></a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo sanitize_output(date('d/m/Y', strtotime($client['created_at']))); ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap">
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $client['id']; ?>" title="Dettaglio">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="edit.php?id=<?php echo (int) $client['id']; ?>" title="Modifica">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                <button class="btn btn-icon btn-soft-danger btn-sm" type="button" data-bs-toggle="modal" data-bs-target="#deleteModal" data-id="<?php echo (int) $client['id']; ?>" title="Elimina">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-5">
                        <i class="fa-solid fa-users-slash fa-2x mb-3"></i>
                        <p class="mb-1">Nessun cliente corrisponde ai filtri applicati.</p>
                        <a class="btn btn-outline-warning" href="index.php">Reimposta filtri</a>
                    </div>
                <?php endif; ?>
            </div>
            <?php if ($totalClients > 0): ?>
                <div class="card-footer bg-transparent border-0">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                        <span class="text-muted small">
                            Mostrati <?php echo sanitize_output((string) number_format($displayFrom)); ?>-<?php echo sanitize_output((string) number_format($displayTo)); ?> di <?php echo sanitize_output((string) number_format($totalClients)); ?> clienti.
                        </span>
                        <?php if ($totalPages > 1): ?>
                            <nav aria-label="Paginazione clienti">
                                <ul class="pagination pagination-sm mb-0">
                                    <?php $prevQuery = array_merge($paginationQuery, ['page' => max(1, $page - 1)]); ?>
                                    <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query($prevQuery); ?>" aria-label="Pagina precedente">&laquo;</a>
                                    </li>
                                    <?php
                                    $window = 3;
                                    $start = max(1, $page - $window);
                                    $end = min($totalPages, $page + $window);
                                    for ($i = $start; $i <= $end; $i++):
                                        $pageQuery = array_merge($paginationQuery, ['page' => $i]);
                                        ?>
                                        <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query($pageQuery); ?>"><?php echo $i; ?></a>
                                        </li>
                                    <?php endfor; ?>
                                    <?php $nextQuery = array_merge($paginationQuery, ['page' => min($totalPages, $page + 1)]); ?>
                                    <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query($nextQuery); ?>" aria-label="Pagina successiva">&raquo;</a>
                                    </li>
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header border-0">
                <h5 class="modal-title" id="deleteModalLabel">Conferma eliminazione</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                Sei sicuro di voler eliminare questo cliente? L'operazione è irreversibile.
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-outline-warning" data-bs-dismiss="modal">Annulla</button>
                <form id="deleteForm" method="post" action="delete.php">
                    <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="id" id="deleteId" value="">
                    <button type="submit" class="btn btn-warning">Elimina</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<script>
    const deleteModal = document.getElementById('deleteModal');
    deleteModal?.addEventListener('show.bs.modal', (event) => {
        const button = event.relatedTarget;
        const id = button.getAttribute('data-id');
        const deleteId = document.getElementById('deleteId');
        deleteId.value = id;
    });
</script>
