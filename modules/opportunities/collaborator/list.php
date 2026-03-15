<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$statusOptions = $opportunityService->getStatusOptions();
$statusCodes = array_column($statusOptions, 'code');
$categoryOptions = [
    'telefonia' => 'Telefonia',
    'luce' => 'Luce',
    'gas' => 'Gas',
];

$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
if ($statusFilter !== '' && !in_array($statusFilter, $statusCodes, true)) {
    $statusFilter = '';
}

$categoryFilter = isset($_GET['category']) ? strtolower(trim((string) $_GET['category'])) : '';
if ($categoryFilter !== '' && !isset($categoryOptions[$categoryFilter])) {
    $categoryFilter = '';
}

$searchFilter = trim((string) ($_GET['search'] ?? ''));
$sort = trim((string) ($_GET['sort'] ?? 'created_desc'));
$allowedSort = ['created_desc', 'created_asc', 'status', 'code_desc', 'code_asc'];
if (!in_array($sort, $allowedSort, true)) {
    $sort = 'created_desc';
}

$perPage = 20;
$currentPage = max(1, (int) ($_GET['page'] ?? 1));

$listFilters = [];
if ($statusFilter !== '') {
    $listFilters['status'] = $statusFilter;
}
if ($categoryFilter !== '') {
    $listFilters['category'] = $categoryFilter;
}
if ($searchFilter !== '') {
    $listFilters['search'] = $searchFilter;
}

$totalOpportunities = $opportunityService->countCollaboratorOpportunities($collaboratorId, $listFilters);
$totalPages = $totalOpportunities > 0 ? (int) ceil($totalOpportunities / $perPage) : 1;
if ($totalPages <= 0) {
    $totalPages = 1;
}
if ($currentPage > $totalPages && $totalOpportunities > 0) {
    $currentPage = $totalPages;
}
$offset = ($currentPage - 1) * $perPage;

$opportunities = $opportunityService->listCollaboratorOpportunities($collaboratorId, $listFilters, $perPage, $offset, $sort);
$hasResults = !empty($opportunities);
$remoteDraft = $opportunityService->getCollaboratorDraft($collaboratorId);
$remoteDraftData = is_array($remoteDraft['data'] ?? null) ? $remoteDraft['data'] : [];
$hasRemoteDraft = $remoteDraftData !== [];

if ($hasRemoteDraft) {
    $draftCustomerFirst = trim((string) ($remoteDraftData['customer_first_name'] ?? ''));
    $draftCustomerLast = trim((string) ($remoteDraftData['customer_last_name'] ?? ''));
    $draftCategory = strtolower((string) ($remoteDraftData['category'] ?? ''));
    $draftProviderId = (int) ($remoteDraftData['provider_id'] ?? 0);
    $draftOfferId = (int) ($remoteDraftData['offer_id'] ?? 0);

    $providerLabel = '';
    $offerLabel = '';
    if ($draftProviderId > 0) {
        $catalog = $opportunityService->getProviderCatalog(null);
        foreach ($catalog as $providers) {
            foreach ($providers as $provider) {
                if ((int) ($provider['id'] ?? 0) === $draftProviderId) {
                    $providerLabel = (string) ($provider['name'] ?? '');
                    if ($draftOfferId > 0 && !empty($provider['offers'])) {
                        foreach ($provider['offers'] as $offer) {
                            if ((int) ($offer['id'] ?? 0) === $draftOfferId) {
                                $offerLabel = (string) ($offer['name'] ?? '');
                                break 2;
                            }
                        }
                    }
                }
            }
        }
    }

    $draftRow = [
        'is_draft' => true,
        'id' => null,
        'code' => 'BOZZA',
        'customer_first_name' => $draftCustomerFirst,
        'customer_last_name' => $draftCustomerLast,
        'customer_tax_code' => (string) ($remoteDraftData['customer_tax_code'] ?? ''),
        'category' => $draftCategory !== '' ? $draftCategory : '—',
        'status_label' => 'Bozza da completare',
        'status_color' => 'warning',
        'status_code' => 'bozza',
        'provider_label' => $providerLabel,
        'offer_label' => $offerLabel,
        'created_at' => $remoteDraft['saved_at'] ?? null,
    ];

    array_unshift($opportunities, $draftRow);
    $hasResults = true;
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Opportunity</p>
                <h1 class="h4 mb-0">Vista tabellare</h1>
                <p class="text-muted mb-0">Elenco compatto delle tue opportunity, ispirato alla vista amministratore.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo opportunities_collaborator_url('index'); ?>">
                    <i class="fa-solid fa-layer-group me-2"></i>Vista dashboard
                </a>
                <a class="btn btn-primary" href="<?php echo opportunities_collaborator_url('create'); ?>">
                    <i class="fa-solid fa-plus me-2"></i>Nuova OP
                </a>
            </div>
        </div>

        <form class="card shadow-sm mb-4" method="get">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label text-uppercase small text-muted">Stato</label>
                        <select class="form-select" name="status">
                            <option value="">Tutti</option>
                            <?php foreach ($statusOptions as $status): ?>
                                <?php $code = (string) ($status['code'] ?? ''); ?>
                                <option value="<?php echo sanitize_output($code); ?>" <?php echo $statusFilter === $code ? 'selected' : ''; ?>>
                                    <?php echo sanitize_output($status['label'] ?? $code); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-uppercase small text-muted">Categoria</label>
                        <select class="form-select" name="category">
                            <option value="">Tutte</option>
                            <?php foreach ($categoryOptions as $key => $label): ?>
                                <option value="<?php echo sanitize_output($key); ?>" <?php echo $categoryFilter === $key ? 'selected' : ''; ?>>
                                    <?php echo sanitize_output($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-uppercase small text-muted">Ricerca</label>
                        <input class="form-control" type="search" name="search" placeholder="Codice, cliente o gestore" value="<?php echo sanitize_output($searchFilter); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-uppercase small text-muted">Ordina per</label>
                        <select class="form-select" name="sort">
                            <option value="created_desc" <?php echo $sort === 'created_desc' ? 'selected' : ''; ?>>Data invio (nuove)</option>
                            <option value="created_asc" <?php echo $sort === 'created_asc' ? 'selected' : ''; ?>>Data invio (vecchie)</option>
                            <option value="status" <?php echo $sort === 'status' ? 'selected' : ''; ?>>Stato</option>
                            <option value="code_desc" <?php echo $sort === 'code_desc' ? 'selected' : ''; ?>>Codice (Z-A)</option>
                            <option value="code_asc" <?php echo $sort === 'code_asc' ? 'selected' : ''; ?>>Codice (A-Z)</option>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex gap-2 align-items-end">
                        <button class="btn btn-primary w-100" type="submit">
                            <i class="fa-solid fa-filter me-2"></i>Filtra
                        </button>
                        <a class="btn btn-light w-100" href="<?php echo opportunities_collaborator_url('list'); ?>">Reset</a>
                    </div>
                </div>
            </div>
        </form>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <?php if (!$hasResults && $hasRemoteDraft): ?>
                    <div class="alert alert-warning m-3" role="alert">
                        Hai una bozza salvata: apri "Nuova OP" per riprendere la compilazione e inviarla, così comparirà nell'elenco.
                    </div>
                <?php endif; ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Codice</th>
                                <th>Cliente</th>
                                <th>Categoria</th>
                                <th>Stato</th>
                                <th>Gestore</th>
                                <th>Inviata il</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$hasResults): ?>
                            <tr>
                                <td colspan="7" class="text-center py-5 text-muted">Nessuna opportunity trovata con i filtri correnti.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($opportunities as $opportunity): ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold"><?php echo sanitize_output($opportunity['code'] ?? ''); ?></span>
                                    <?php if (!empty($opportunity['is_draft'])): ?>
                                        <div class="text-warning small">Bozza</div>
                                    <?php else: ?>
                                        <div class="text-muted small">#<?php echo (int) ($opportunity['id'] ?? 0); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div><?php echo sanitize_output(trim((string) ($opportunity['customer_first_name'] ?? '') . ' ' . (string) ($opportunity['customer_last_name'] ?? ''))); ?></div>
                                    <div class="text-muted small"><?php echo sanitize_output($opportunity['customer_tax_code'] ?? ''); ?></div>
                                </td>
                                <td class="text-uppercase small text-muted"><?php echo sanitize_output($opportunity['category'] ?? ''); ?></td>
                                <td>
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
                                    <span class="<?php echo $badgeClass; ?>">
                                        <?php echo sanitize_output($opportunity['status_label'] ?? $opportunity['status_code'] ?? ''); ?>
                                    </span>
                                </td>
                                <td>
                                    <div><?php echo sanitize_output($opportunity['provider_label'] ?? ''); ?></div>
                                    <?php if (!empty($opportunity['offer_label'])): ?>
                                        <div class="text-muted small">Offerta: <?php echo sanitize_output($opportunity['offer_label']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo sanitize_output(format_datetime_locale($opportunity['created_at'] ?? null)); ?></td>
                                <td class="text-end">
                                    <?php if (!empty($opportunity['is_draft'])): ?>
                                        <a class="btn btn-sm btn-warning" href="<?php echo sanitize_output(opportunities_collaborator_url('create', ['auto_restore_draft' => 1])); ?>">
                                            <i class="fa-solid fa-pen-to-square me-1"></i>Completa bozza
                                        </a>
                                    <?php elseif (!empty($opportunity['id'])): ?>
                                        <?php
                                            $opportunityId = (int) $opportunity['id'];
                                            $statusCode = strtolower((string) ($opportunity['status_code'] ?? ''));
                                            $cloneUrl = opportunities_collaborator_url('create', ['clone_id' => $opportunityId]);
                                            $viewUrl = opportunities_collaborator_url('view', ['id' => $opportunityId]);
                                            $noteUrl = opportunities_collaborator_url('notes', ['id' => $opportunityId]);
                                            $reminderUrl = opportunities_collaborator_url('reminder', ['id' => $opportunityId]);
                                            $ticketUrl = opportunities_collaborator_url('ticket', ['id' => $opportunityId]);
                                            $isCancelled = in_array($statusCode, ['annullato', 'annullata', 'cancelled', 'canceled'], true);
                                            $canEdit = $statusCode === 'in_verifica';
                                        ?>
                                        <div class="op-actions" role="group" aria-label="Azioni opportunity">
                                            <a class="btn btn-sm btn-outline-primary btn-icon" href="<?php echo sanitize_output($cloneUrl); ?>" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Duplica opportunity" aria-label="Duplica opportunity">
                                                <i class="fa-solid fa-clone" aria-hidden="true"></i>
                                            </a>
                                            <a class="btn btn-sm btn-outline-info btn-icon" href="<?php echo sanitize_output($ticketUrl); ?>" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Apri ticket di supporto" aria-label="Apri ticket di supporto">
                                                <i class="fa-solid fa-ticket" aria-hidden="true"></i>
                                            </a>
                                            <?php if (!$isCancelled): ?>
                                                <?php if ($canEdit): ?>
                                                    <a class="btn btn-sm btn-outline-warning btn-icon" href="<?php echo sanitize_output(opportunities_collaborator_url('create', ['edit_id' => $opportunityId])); ?>" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Modifica dati opportunity" aria-label="Modifica dati opportunity">
                                                        <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a class="btn btn-sm btn-outline-secondary btn-icon" href="<?php echo sanitize_output($viewUrl); ?>" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Dettagli opportunity" aria-label="Dettagli opportunity">
                                                    <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                                </a>
                                                <a class="btn btn-sm btn-outline-success btn-icon" href="<?php echo sanitize_output($noteUrl); ?>" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Aggiungi nota" aria-label="Aggiungi nota">
                                                    <i class="fa-solid fa-note-sticky" aria-hidden="true"></i>
                                                </a>
                                                <a class="btn btn-sm btn-outline-warning btn-icon" href="<?php echo sanitize_output($reminderUrl); ?>" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Invia sollecito" aria-label="Invia sollecito">
                                                    <i class="fa-solid fa-bell" aria-hidden="true"></i>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <?php
                $queryParams = [];
                if ($statusFilter !== '') {
                    $queryParams['status'] = $statusFilter;
                }
                if ($categoryFilter !== '') {
                    $queryParams['category'] = $categoryFilter;
                }
                if ($searchFilter !== '') {
                    $queryParams['search'] = $searchFilter;
                }
                if ($sort !== '') {
                    $queryParams['sort'] = $sort;
                }
                $buildPageUrl = static function (int $page) use ($queryParams): string {
                    $params = array_merge($queryParams, ['page' => $page]);
                    return opportunities_collaborator_url('list', $params);
                };
            ?>
            <nav class="mt-3" aria-label="Paginazione elenco opportunity">
                <ul class="pagination justify-content-center">
                    <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo $currentPage > 1 ? sanitize_output($buildPageUrl($currentPage - 1)) : '#'; ?>" aria-label="Precedente">&laquo;</a>
                    </li>
                    <?php for ($page = 1; $page <= $totalPages; $page++): ?>
                        <li class="page-item <?php echo $page === $currentPage ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo sanitize_output($buildPageUrl($page)); ?>"><?php echo $page; ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo $currentPage < $totalPages ? sanitize_output($buildPageUrl($currentPage + 1)) : '#'; ?>" aria-label="Successiva">&raquo;</a>
                    </li>
                </ul>
            </nav>
        <?php endif; ?>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
