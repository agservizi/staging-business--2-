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

$summaryStmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_customers,
        SUM(CASE WHEN TRIM(COALESCE(ragione_sociale, '')) <> '' THEN 1 ELSE 0 END) AS company_customers,
        SUM(CASE WHEN TRIM(COALESCE(email, '')) <> '' THEN 1 ELSE 0 END) AS email_customers,
        SUM(CASE WHEN TRIM(COALESCE(telefono, '')) <> '' THEN 1 ELSE 0 END) AS phone_customers,
        SUM(CASE WHEN DATE(created_at) >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS recent_customers
    $baseQuery$whereClause
");
$summaryStmt->execute($params);
$customerSummary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [
    'total_customers' => 0,
    'company_customers' => 0,
    'email_customers' => 0,
    'phone_customers' => 0,
    'recent_customers' => 0,
];
$customerSummary['has_filters'] = ($searchTerm !== '' || $createdFrom !== null || $createdTo !== null);
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
        <style>
            .clienti-shell {
                display: grid;
                gap: 1.5rem;
            }

            .clienti-hero {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(58, 123, 213, 0.16);
                background:
                    radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 32%),
                    radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), transparent 28%),
                    #fff;
            }

            .clienti-pill {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.45rem 0.85rem;
                border-radius: 999px;
                background: rgba(58, 123, 213, 0.10);
                color: #2154d7;
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .clienti-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .clienti-kpi {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.15rem;
                padding: 1rem 1.1rem;
                background: rgba(255, 255, 255, 0.9);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
            }

            .clienti-kpi-label {
                display: block;
                margin-bottom: 0.4rem;
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .clienti-kpi-value {
                display: block;
                color: #0f172a;
                font-size: 1.85rem;
                font-weight: 800;
                line-height: 1;
            }

            .clienti-kpi-note {
                display: block;
                margin-top: 0.45rem;
                color: #64748b;
                font-size: 0.86rem;
            }

            .clienti-panel {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.3rem;
                background: #fff;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
            }

            .clienti-toolbar {
                display: flex;
                flex-wrap: wrap;
                gap: 1rem;
                align-items: end;
            }

            .clienti-toolbar form {
                flex: 1 1 36rem;
            }

            .clienti-toolbar-actions {
                display: flex;
                flex-wrap: wrap;
                gap: 0.75rem;
                flex: 0 0 auto;
            }

            .clienti-search-grid {
                display: grid;
                grid-template-columns: minmax(0, 1.7fr) repeat(2, minmax(11rem, 0.8fr)) auto;
                gap: 0.85rem;
            }

            .clienti-table {
                --bs-table-bg: transparent;
                --bs-table-hover-bg: rgba(37, 99, 235, 0.04);
                margin-bottom: 0;
            }

            .clienti-table thead th {
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                white-space: nowrap;
            }

            .clienti-table td {
                padding-top: 1rem;
                padding-bottom: 1rem;
                border-color: rgba(15, 23, 42, 0.06);
                vertical-align: middle;
            }

            .clienti-id {
                display: inline-flex;
                padding: 0.42rem 0.68rem;
                border-radius: 0.8rem;
                background: #f8fafc;
                color: #0f172a;
                font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
                font-size: 0.8rem;
                font-weight: 700;
            }

            .clienti-name {
                display: flex;
                flex-direction: column;
                gap: 0.18rem;
            }

            .clienti-meta {
                color: #64748b;
                font-size: 0.84rem;
            }

            .clienti-empty {
                padding: 2.5rem 1rem;
                text-align: center;
                color: #64748b;
            }

            @media (max-width: 1199.98px) {
                .clienti-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }

                .clienti-search-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767.98px) {
                .clienti-kpis {
                    grid-template-columns: 1fr;
                }

                .clienti-search-grid {
                    grid-template-columns: 1fr;
                }

                .clienti-toolbar-actions {
                    width: 100%;
                }

                .clienti-toolbar-actions .btn {
                    flex: 1 1 0;
                }
            }
        </style>

        <div class="clienti-shell">
            <section class="card clienti-hero">
                <div class="card-body p-4 p-xl-5">
                    <div class="row g-4 align-items-start">
                        <div class="col-12 col-xl-7">
                            <span class="clienti-pill"><i class="fa-solid fa-users"></i>Anagrafica clienti</span>
                            <h1 class="mt-3 mb-2 fw-bold" style="max-width: 13ch;">Una rubrica più chiara per relazioni, servizi e storico operativo.</h1>
                            <p class="text-muted mb-0" style="max-width: 70ch;">
                                Consulta rapidamente i clienti registrati, filtra per periodo di inserimento e raggiungi dettaglio, documenti e ticket con una lettura più ordinata.
                            </p>
                        </div>
                        <div class="col-12 col-xl-5">
                            <div class="clienti-kpis">
                                <div class="clienti-kpi">
                                    <span class="clienti-kpi-label"><?php echo $customerSummary['has_filters'] ? 'Risultati' : 'Clienti'; ?></span>
                                    <span class="clienti-kpi-value"><?php echo (int) $customerSummary['total_customers']; ?></span>
                                    <span class="clienti-kpi-note"><?php echo $customerSummary['has_filters'] ? 'Anagrafiche trovate con i filtri attivi' : 'Totale rubrica disponibile'; ?></span>
                                </div>
                                <div class="clienti-kpi">
                                    <span class="clienti-kpi-label">Aziende</span>
                                    <span class="clienti-kpi-value"><?php echo (int) $customerSummary['company_customers']; ?></span>
                                    <span class="clienti-kpi-note">Ragioni sociali censite</span>
                                </div>
                                <div class="clienti-kpi">
                                    <span class="clienti-kpi-label">Con email</span>
                                    <span class="clienti-kpi-value"><?php echo (int) $customerSummary['email_customers']; ?></span>
                                    <span class="clienti-kpi-note">Contatti digitali disponibili</span>
                                </div>
                                <div class="clienti-kpi">
                                    <span class="clienti-kpi-label">Ultimi 30 giorni</span>
                                    <span class="clienti-kpi-value"><?php echo (int) $customerSummary['recent_customers']; ?></span>
                                    <span class="clienti-kpi-note">Nuove anagrafiche inserite</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card clienti-panel">
                <div class="card-body p-4">
                    <div class="clienti-toolbar">
                        <form method="get" role="search">
                            <div class="clienti-search-grid">
                                <div>
                                    <label class="form-label small text-uppercase text-muted fw-semibold">Ricerca</label>
                                    <input class="form-control" type="search" name="q" placeholder="Nome, ragione sociale, email o CF/P.IVA" value="<?php echo sanitize_output($searchTerm); ?>">
                                </div>
                                <div>
                                    <label class="form-label small text-uppercase text-muted fw-semibold">Creati dal</label>
                                    <input class="form-control" type="date" name="created_from" value="<?php echo sanitize_output($createdFrom ? $createdFrom->format('Y-m-d') : ''); ?>" aria-label="Registrati dal">
                                </div>
                                <div>
                                    <label class="form-label small text-uppercase text-muted fw-semibold">Creati al</label>
                                    <input class="form-control" type="date" name="created_to" value="<?php echo sanitize_output($createdTo ? $createdTo->format('Y-m-d') : ''); ?>" aria-label="Registrati fino al">
                                </div>
                                <div class="d-flex align-items-end gap-2">
                                    <button class="btn btn-warning w-100" type="submit"><i class="fa-solid fa-magnifying-glass me-2"></i>Filtra</button>
                                    <a class="btn btn-outline-warning" href="<?php echo clienti_module_url('index'); ?>" title="Reimposta filtri"><i class="fa-solid fa-rotate-left"></i></a>
                                </div>
                            </div>
                        </form>
                        <div class="clienti-toolbar-actions">
                            <a class="btn btn-outline-warning" href="<?php echo clienti_module_url('import'); ?>"><i class="fa-solid fa-file-import me-2"></i>Importa CSV</a>
                            <a class="btn btn-warning text-dark" href="<?php echo clienti_module_url('create'); ?>"><i class="fa-solid fa-user-plus me-2"></i>Nuovo cliente</a>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card clienti-panel">
                <div class="card-body p-0">
                <?php if ($clients): ?>
                    <div class="table-responsive">
                        <table class="table clienti-table table-hover align-middle">
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
                                        <td><span class="clienti-id"><?php echo (int) $client['id']; ?></span></td>
                                        <td>
                                            <?php
                                                $company = trim((string) ($client['ragione_sociale'] ?? ''));
                                                $fullName = trim(trim((string) ($client['cognome'] ?? '')) . ' ' . trim((string) ($client['nome'] ?? '')));
                                            ?>
                                            <div class="clienti-name">
                                                <div class="fw-semibold"><?php echo sanitize_output($company !== '' ? $company : ($fullName !== '' ? $fullName : 'Cliente #' . (string) $client['id'])); ?></div>
                                            <?php if ($company !== '' && $fullName !== ''): ?>
                                                <span class="clienti-meta">Referente: <?php echo sanitize_output($fullName); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($client['indirizzo'])): ?>
                                                <span class="clienti-meta"><?php echo sanitize_output($client['indirizzo']); ?></span>
                                            <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?php echo sanitize_output($client['cf_piva'] !== '' ? $client['cf_piva'] : '—'); ?></td>
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
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo clienti_module_url('view', ['id' => (int) $client['id']]); ?>" title="Dettaglio">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo clienti_module_url('edit', ['id' => (int) $client['id']]); ?>" title="Modifica">
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
                    <div class="clienti-empty">
                        <i class="fa-solid fa-users-slash fa-2x mb-3"></i>
                        <p class="mb-1">Nessun cliente corrisponde ai filtri applicati.</p>
                        <a class="btn btn-outline-warning" href="<?php echo clienti_module_url('index'); ?>">Reimposta filtri</a>
                    </div>
                <?php endif; ?>
                </div>
            <?php if ($totalClients > 0): ?>
                <div class="card-footer bg-transparent border-0 px-4 py-3">
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
            </section>
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
                <form id="deleteForm" method="post" action="<?php echo clienti_module_url('delete'); ?>">
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
