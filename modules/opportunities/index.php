<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_role('Admin', 'Manager');

$pageTitle = 'Gestione Opportunity';

$statusFilter = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$categoryFilter = isset($_GET['category']) ? trim((string) $_GET['category']) : '';
$searchFilter = isset($_GET['search']) ? trim((string) $_GET['search']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['form_action'] ?? '');
    if ($action === 'delete_opportunity') {
        $opportunityId = (int) ($_POST['opportunity_id'] ?? 0);
        try {
            $opportunityService->deleteOpportunity($opportunityId);
            add_flash('success', 'Opportunity eliminata.');
        } catch (RuntimeException $exception) {
            add_flash('warning', $exception->getMessage());
        }
        header('Location: index.php');
        exit;
    }
}

$filters = [];
if ($statusFilter !== '') {
    $filters['status'] = $statusFilter;
}
if ($categoryFilter !== '') {
    $filters['category'] = $categoryFilter;
}
if ($searchFilter !== '') {
    $filters['search'] = $searchFilter;
}

$opportunities = $opportunityService->listAdminOpportunities($filters);
$statuses = $opportunityService->getStatusOptions();
$csrfToken = csrf_token();
$latestOpportunityId = $opportunities ? (int) ($opportunities[0]['id'] ?? 0) : 0;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Opportunity</p>
                <h1 class="h4 mb-0">Pipeline commerciale</h1>
    <script>
        (function () {
            const endpoint = '<?php echo sanitize_output(asset('api/opportunities/admin/latest.php')); ?>';
            const latestServerId = <?php echo (int) $latestOpportunityId; ?>;
            const storageKey = 'cs_op_admin_latest_seen_id';

            const safeParseInt = (value) => {
                const num = parseInt(value, 10);
                return Number.isFinite(num) ? num : 0;
            };

            const getStoredId = () => safeParseInt(localStorage.getItem(storageKey));
            const setStoredId = (id) => localStorage.setItem(storageKey, String(id));

            // Prime baseline without toast on first load
            if (latestServerId > 0 && getStoredId() === 0) {
                setStoredId(latestServerId);
            }

            const maybeShowToast = (payload) => {
                if (!window.CS || typeof window.CS.showToast !== 'function') {
                    return;
                }
                const code = (payload.code || '').trim();
                const collaborator = (payload.collaborator_name || '').trim();
                const message = code
                    ? `Nuova opportunity ${code}${collaborator ? ' da ' + collaborator : ''}`
                    : 'Nuova opportunity inserita';
                window.CS.showToast(message, 'info', {
                    delay: 7000,
                    url: '<?php echo sanitize_output(asset('modules/opportunities/index.php')); ?>',
                });
            };

            const checkLatest = async () => {
                try {
                    const response = await fetch(endpoint, {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    if (!response.ok) {
                        return;
                    }
                    const payload = await response.json();
                    if (!payload || payload.status !== 'ok') {
                        return;
                    }
                    const latestId = safeParseInt(payload.id);
                    const seenId = getStoredId();
                    if (latestId > seenId) {
                        setStoredId(latestId);
                        maybeShowToast(payload);
                    }
                } catch (error) {
                    // Silently ignore polling errors
                }
            };

            // Initial check (may show toast if page was open and new OP arrived)
            checkLatest();
            const POLL_MS = 5000;
            setInterval(() => {
                if (document.visibilityState === 'visible') {
                    checkLatest();
                }
            }, POLL_MS);
        })();
    </script>
                <p class="text-muted mb-0">Monitora le richieste inserite dai collaboratori e applica gli avanzamenti di stato.</p>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-primary" href="<?php echo sanitize_output(asset('modules/opportunities/promotions/index.php')); ?>">
                    <i class="fa-solid fa-folder-open me-2"></i>File manager promo
                </a>
                <a class="btn btn-outline-secondary" href="<?php echo sanitize_output(asset('modules/impostazioni/morosita.php')); ?>">
                    <i class="fa-solid fa-shield-halved me-2"></i>Verifica morosità
                </a>
                <a class="btn btn-primary" href="<?php echo sanitize_output(asset('modules/opportunities/commissions.php')); ?>">
                    <i class="fa-solid fa-hand-holding-dollar me-2"></i>Provvigioni collaboratori
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
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo sanitize_output($status['code']); ?>" <?php echo $statusFilter === $status['code'] ? 'selected' : ''; ?>>
                                    <?php echo sanitize_output($status['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-uppercase small text-muted">Categoria</label>
                        <select class="form-select" name="category">
                            <option value="">Tutte</option>
                            <option value="telefonia" <?php echo $categoryFilter === 'telefonia' ? 'selected' : ''; ?>>Telefonia</option>
                            <option value="luce" <?php echo $categoryFilter === 'luce' ? 'selected' : ''; ?>>Luce</option>
                            <option value="gas" <?php echo $categoryFilter === 'gas' ? 'selected' : ''; ?>>Gas</option>
                            <option value="paytv" <?php echo $categoryFilter === 'paytv' ? 'selected' : ''; ?>>PayTV</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-uppercase small text-muted">Ricerca</label>
                        <input class="form-control" type="search" name="search" placeholder="Codice, cliente o collaboratore" value="<?php echo sanitize_output($searchFilter); ?>">
                    </div>
                    <div class="col-md-2 d-flex gap-2">
                        <button class="btn btn-primary w-100" type="submit"><i class="fa-solid fa-filter me-2"></i>Filtra</button>
                        <a class="btn btn-light w-100" href="<?php echo asset('modules/opportunities/index.php'); ?>">Reset</a>
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
                                <th>Collaboratore</th>
                                <th>Gestore</th>
                                <th>Creata il</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$opportunities): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">Nessuna opportunity trovata con i filtri correnti.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($opportunities as $opportunity): ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold"><?php echo sanitize_output($opportunity['code']); ?></span>
                                    <div class="text-muted small">#<?php echo (int) $opportunity['id']; ?></div>
                                </td>
                                <td>
                                    <div><?php echo sanitize_output(($opportunity['customer_first_name'] ?? '') . ' ' . ($opportunity['customer_last_name'] ?? '')); ?></div>
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
                                    <?php echo sanitize_output(trim(($opportunity['collaborator_name'] ?? '') . ' ' . ($opportunity['collaborator_surname'] ?? '')) ?: '—'); ?>
                                </td>
                                <td>
                                    <div><?php echo sanitize_output($opportunity['provider_label'] ?? ''); ?></div>
                                    <?php if (!empty($opportunity['offer_label'])): ?>
                                        <div class="text-muted small">Offerta: <?php echo sanitize_output($opportunity['offer_label']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo sanitize_output(format_datetime_locale($opportunity['created_at'] ?? null)); ?></td>
                                <td class="text-end">
                                    <?php if (($opportunity['status_code'] ?? '') === 'annullato'): ?>
                                        <form class="d-inline" method="post" action="<?php echo asset('modules/opportunities/detail.php?id=' . (int) $opportunity['id']); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                            <input type="hidden" name="form_action" value="reopen_for_correction">
                                            <input type="hidden" name="reopen_note" value="Richiesta rettifica dall'elenco amministrazione">
                                            <button class="btn btn-sm btn-outline-secondary js-reopen-opportunity" type="button" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Riapri per rettifica" data-opportunity-code="<?php echo sanitize_output($opportunity['code'] ?? ''); ?>">
                                                <i class="fa-solid fa-rotate-left"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form class="d-inline" method="post" action="<?php echo asset('modules/opportunities/index.php'); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                        <input type="hidden" name="form_action" value="delete_opportunity">
                                        <input type="hidden" name="opportunity_id" value="<?php echo (int) $opportunity['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger js-delete-opportunity" type="button" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Elimina" data-opportunity-code="<?php echo sanitize_output($opportunity['code'] ?? ''); ?>">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo asset('modules/opportunities/detail.php?id=' . (int) $opportunity['id']); ?>" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Gestisci opportunity">
                                        <i class="fa-solid fa-eye me-1"></i>Gestisci
                                    </a>
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
<div class="modal fade" id="reopenConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Riapri per rettifica</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Confermi di riaprire l'opportunity <span class="fw-semibold" id="reopenOpportunityCode"></span> per rettifica al collaboratore?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-primary" id="confirmReopenSubmit">Conferma</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Elimina opportunity</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Confermi di eliminare definitivamente l'opportunity <span class="fw-semibold" id="deleteOpportunityCode"></span>? Questa azione non può essere annullata.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteSubmit">Elimina</button>
            </div>
        </div>
    </div>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<script>
document.addEventListener('DOMContentLoaded', function () {
    let pendingReopenForm = null;
    const modalElement = document.getElementById('reopenConfirmModal');
    const codeTarget = document.getElementById('reopenOpportunityCode');
    const confirmButton = document.getElementById('confirmReopenSubmit');
    let pendingDeleteForm = null;
    const deleteModalElement = document.getElementById('deleteConfirmModal');
    const deleteCodeTarget = document.getElementById('deleteOpportunityCode');
    const deleteConfirmButton = document.getElementById('confirmDeleteSubmit');

    if (modalElement && confirmButton) {
        const bootstrapModal = new bootstrap.Modal(modalElement);

        document.querySelectorAll('.js-reopen-opportunity').forEach(function (btn) {
            btn.addEventListener('click', function () {
                pendingReopenForm = btn.closest('form');
                if (!pendingReopenForm) {
                    return;
                }
                const code = btn.getAttribute('data-opportunity-code') || '';
                if (codeTarget) {
                    codeTarget.textContent = code ? '#' + code : '';
                }
                bootstrapModal.show();
            });
        });

        confirmButton.addEventListener('click', function () {
            if (pendingReopenForm) {
                pendingReopenForm.submit();
                pendingReopenForm = null;
            }
            bootstrapModal.hide();
        });
    }

    if (deleteModalElement && deleteConfirmButton) {
        const deleteModal = new bootstrap.Modal(deleteModalElement);
        document.querySelectorAll('.js-delete-opportunity').forEach(function (btn) {
            btn.addEventListener('click', function () {
                pendingDeleteForm = btn.closest('form');
                if (!pendingDeleteForm) {
                    return;
                }
                const code = btn.getAttribute('data-opportunity-code') || '';
                if (deleteCodeTarget) {
                    deleteCodeTarget.textContent = code ? '#' + code : '';
                }
                deleteModal.show();
            });
        });

        deleteConfirmButton.addEventListener('click', function () {
            if (pendingDeleteForm) {
                pendingDeleteForm.submit();
                pendingDeleteForm = null;
            }
            deleteModal.hide();
        });
    }
});
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
