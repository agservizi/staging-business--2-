<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

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

$opportunities = $opportunityService->listCollaboratorOpportunities($collaboratorId, $listFilters);
$hasResults = !empty($opportunities);

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
                <a class="btn btn-outline-secondary" href="<?php echo asset('modules/opportunities/collaborator/index.php'); ?>">
                    <i class="fa-solid fa-layer-group me-2"></i>Vista dashboard
                </a>
                <a class="btn btn-primary" href="<?php echo asset('modules/opportunities/collaborator/create.php'); ?>">
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
                    <div class="col-md-2 d-flex gap-2">
                        <button class="btn btn-primary w-100" type="submit">
                            <i class="fa-solid fa-filter me-2"></i>Filtra
                        </button>
                        <a class="btn btn-light w-100" href="<?php echo asset('modules/opportunities/collaborator/list.php'); ?>">Reset</a>
                    </div>
                </div>
            </div>
        </form>

        <div class="card shadow-sm">
            <div class="card-body p-0">
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
                                    <div class="text-muted small">#<?php echo (int) ($opportunity['id'] ?? 0); ?></div>
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
                                    <?php if (!empty($opportunity['id'])): ?>
                                        <?php
                                            $opportunityId = (int) $opportunity['id'];
                                            $statusCode = strtolower((string) ($opportunity['status_code'] ?? ''));
                                            $cloneUrl = asset('modules/opportunities/collaborator/create.php?clone_id=' . $opportunityId);
                                            $viewUrl = asset('modules/opportunities/collaborator/view.php?id=' . $opportunityId);
                                            $noteUrl = asset('modules/opportunities/collaborator/notes.php?id=' . $opportunityId);
                                            $reminderUrl = asset('modules/opportunities/collaborator/reminder.php?id=' . $opportunityId);
                                            $isCancelled = in_array($statusCode, ['annullato', 'annullata', 'cancelled', 'canceled'], true);
                                        ?>
                                        <div class="btn-group" role="group" aria-label="Azioni opportunity">
                                            <a class="btn btn-sm btn-outline-primary" href="<?php echo sanitize_output($cloneUrl); ?>" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Duplica opportunity">
                                                <i class="fa-solid fa-clone"></i>
                                            </a>
                                            <?php if (!$isCancelled): ?>
                                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo sanitize_output($viewUrl); ?>" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Dettagli opportunity">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <a class="btn btn-sm btn-outline-success" href="<?php echo sanitize_output($noteUrl); ?>" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Aggiungi nota">
                                                    <i class="fa-solid fa-note-sticky"></i>
                                                </a>
                                                <a class="btn btn-sm btn-outline-warning" href="<?php echo sanitize_output($reminderUrl); ?>" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Invia sollecito">
                                                    <i class="fa-solid fa-bell"></i>
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
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
