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
        header('Location: ' . opportunities_module_url('index'));
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

$summary = [
    'total' => count($opportunities),
    'annullate' => 0,
    'telefonia' => 0,
    'energia' => 0,
    'managed' => 0,
];

foreach ($opportunities as $opportunity) {
    $category = strtolower(trim((string) ($opportunity['category'] ?? '')));
    if ($category === 'telefonia') {
        $summary['telefonia']++;
    }
    if (in_array($category, ['luce', 'gas'], true)) {
        $summary['energia']++;
    }
    if (($opportunity['status_code'] ?? '') === 'annullato') {
        $summary['annullate']++;
    }
    if (!empty($opportunity['provider_label']) || !empty($opportunity['offer_label'])) {
        $summary['managed']++;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <?php render_module_hub_styles(); ?>
    <style>
        .op-admin-shell {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .op-admin-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(37, 99, 235, 0.16);
            border-radius: 28px;
            padding: 2rem;
            background:
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.18), transparent 34%),
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.14), transparent 30%),
                linear-gradient(135deg, #ffffff 0%, #f8fbff 54%, #eef5ff 100%);
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.10);
        }

        .op-admin-hero::after {
            content: "";
            position: absolute;
            inset: auto -90px -120px auto;
            width: 260px;
            height: 260px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.08);
            filter: blur(12px);
        }

        .op-admin-hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
            gap: 1.5rem;
            align-items: start;
        }

        .op-admin-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.10);
            color: #1d4ed8;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .op-admin-hero h1 {
            margin: 1rem 0 0.75rem;
            font-size: clamp(2rem, 3vw, 2.7rem);
            line-height: 1.05;
            font-weight: 800;
            color: #172033;
            max-width: 11ch;
        }

        .op-admin-hero p {
            margin: 0;
            max-width: 62ch;
            color: #52607a;
            font-size: 1rem;
        }

        .op-admin-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            margin-top: 1.5rem;
        }

        .op-admin-kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .op-admin-kpi-card {
            border-radius: 22px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.92);
            padding: 1.15rem 1.2rem;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
        }

        .op-admin-kpi-card span {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #607089;
        }

        .op-admin-kpi-card strong {
            display: block;
            font-size: 2rem;
            line-height: 1;
            color: #172033;
        }

        .op-admin-kpi-card small {
            display: block;
            margin-top: 0.45rem;
            color: #64748b;
            font-size: 0.85rem;
        }

        .op-admin-panel {
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 22px 45px rgba(15, 23, 42, 0.07);
        }

        .op-admin-panel-header {
            padding: 1.35rem 1.5rem 0;
        }

        .op-admin-panel-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 800;
            color: #172033;
        }

        .op-admin-panel-subtitle {
            margin: 0.35rem 0 0;
            color: #64748b;
            font-size: 0.92rem;
        }

        .op-admin-filter-form {
            padding: 1.35rem 1.5rem 1.5rem;
        }

        .op-admin-filter-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .op-admin-field label {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.78rem;
            font-weight: 700;
            color: #52607a;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .op-admin-field .form-control,
        .op-admin-field .form-select {
            min-height: 48px;
            border-radius: 15px;
            border-color: #d7dfeb;
            box-shadow: none;
        }

        .op-admin-filter-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .op-admin-table-wrap {
            padding: 1.25rem 1.5rem 1.5rem;
        }

        .op-admin-table-shell {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.7), rgba(255, 255, 255, 0.98));
        }

        .op-admin-table-shell .table {
            margin-bottom: 0;
        }

        .op-admin-table-shell thead th {
            border-bottom: 1px solid rgba(226, 232, 240, 0.95);
            background: rgba(248, 250, 252, 0.95);
            color: #52607a;
            font-size: 0.77rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .op-admin-code {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.38rem 0.7rem;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.10);
            color: #1d4ed8;
            font-weight: 700;
            font-size: 0.8rem;
        }

        .op-admin-category {
            display: inline-flex;
            align-items: center;
            padding: 0.42rem 0.78rem;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.06);
            color: #172033;
            font-size: 0.78rem;
            font-weight: 700;
            text-transform: uppercase;
        }

        .op-admin-empty {
            padding: 2.5rem 1.5rem;
            text-align: center;
            color: #64748b;
        }

        @media (max-width: 1199.98px) {
            .op-admin-hero-grid,
            .op-admin-filter-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767.98px) {
            .op-admin-hero,
            .op-admin-filter-form,
            .op-admin-table-wrap {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .op-admin-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
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
                    url: '<?php echo sanitize_output(opportunities_module_url('index')); ?>',
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

            checkLatest();
            const POLL_MS = 5000;
            setInterval(() => {
                if (document.visibilityState === 'visible') {
                    checkLatest();
                }
            }, POLL_MS);
        })();
    </script>
    <main class="content-wrapper">
        <div class="module-hub-shell op-admin-shell">
            <section class="op-admin-hero">
                <div class="op-admin-hero-grid">
                    <div>
                        <span class="op-admin-eyebrow"><i class="fa-solid fa-chart-line"></i> Admin pipeline</span>
                        <h1>Una vista piu' chiara su pipeline, collaboratori e pratiche da presidiare.</h1>
                        <p>Controlla l'andamento delle opportunity commerciali, individua subito i dossier critici e gestisci da un'unica vista avanzamenti, collaboratori e provider coinvolti.</p>
                        <div class="op-admin-hero-actions">
                            <a class="btn btn-outline-primary" href="<?php echo sanitize_output(opportunities_promotions_url('index')); ?>">
                                <i class="fa-solid fa-folder-open me-2"></i>File manager promo
                            </a>
                            <a class="btn btn-outline-secondary" href="<?php echo sanitize_output(asset('modules/impostazioni/morosita')); ?>">
                                <i class="fa-solid fa-shield-halved me-2"></i>Verifica morosita'
                            </a>
                            <a class="btn btn-primary" href="<?php echo sanitize_output(opportunities_module_url('commissions')); ?>">
                                <i class="fa-solid fa-hand-holding-dollar me-2"></i>Provvigioni collaboratori
                            </a>
                        </div>
                    </div>
                    <div class="op-admin-kpi-grid">
                        <article class="op-admin-kpi-card">
                            <span>Opportunity visibili</span>
                            <strong><?php echo number_format($summary['total'], 0, ',', '.'); ?></strong>
                            <small>Pipeline nel perimetro filtrato corrente</small>
                        </article>
                        <article class="op-admin-kpi-card">
                            <span>Telefonia</span>
                            <strong><?php echo number_format($summary['telefonia'], 0, ',', '.'); ?></strong>
                            <small>Pratiche commerciali lato TLC</small>
                        </article>
                        <article class="op-admin-kpi-card">
                            <span>Energia</span>
                            <strong><?php echo number_format($summary['energia'], 0, ',', '.'); ?></strong>
                            <small>Luce e gas attualmente in lavorazione</small>
                        </article>
                        <article class="op-admin-kpi-card">
                            <span>Annullate</span>
                            <strong><?php echo number_format($summary['annullate'], 0, ',', '.'); ?></strong>
                            <small>Opportunity da monitorare o riaprire</small>
                        </article>
                    </div>
                </div>
            </section>

            <section class="op-admin-panel">
                <div class="op-admin-panel-header">
                    <h2 class="op-admin-panel-title">Filtri operativi</h2>
                    <p class="op-admin-panel-subtitle">Riduci la vista per stato, categoria o ricerca libera per concentrare il lavoro sulle opportunity davvero prioritarie.</p>
                </div>
                <form class="op-admin-filter-form" method="get">
                    <div class="op-admin-filter-grid">
                        <div class="op-admin-field">
                            <label>Stato</label>
                            <select class="form-select" name="status">
                                <option value="">Tutti</option>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo sanitize_output($status['code']); ?>" <?php echo $statusFilter === $status['code'] ? 'selected' : ''; ?>>
                                        <?php echo sanitize_output($status['label']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="op-admin-field">
                            <label>Categoria</label>
                            <select class="form-select" name="category">
                                <option value="">Tutte</option>
                                <option value="telefonia" <?php echo $categoryFilter === 'telefonia' ? 'selected' : ''; ?>>Telefonia</option>
                                <option value="luce" <?php echo $categoryFilter === 'luce' ? 'selected' : ''; ?>>Luce</option>
                                <option value="gas" <?php echo $categoryFilter === 'gas' ? 'selected' : ''; ?>>Gas</option>
                                <option value="paytv" <?php echo $categoryFilter === 'paytv' ? 'selected' : ''; ?>>PayTV</option>
                            </select>
                        </div>
                        <div class="op-admin-field" style="grid-column: span 2;">
                            <label>Ricerca</label>
                            <input class="form-control" type="search" name="search" placeholder="Codice, cliente o collaboratore" value="<?php echo sanitize_output($searchFilter); ?>">
                        </div>
                    </div>
                    <div class="op-admin-filter-actions">
                        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter me-2"></i>Filtra</button>
                        <a class="btn btn-outline-secondary" href="<?php echo opportunities_module_url('index'); ?>">Reset</a>
                    </div>
                </form>
            </section>

            <section class="op-admin-panel">
                <div class="op-admin-panel-header">
                    <h2 class="op-admin-panel-title">Pipeline commerciale</h2>
                    <p class="op-admin-panel-subtitle">Vista ordinata di cliente, categoria, stato e collaboratore per intervenire rapidamente su gestione, rettifiche e cancellazioni.</p>
                </div>
                <div class="op-admin-table-wrap">
                <div class="op-admin-table-shell table-responsive">
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
                                <td colspan="8" class="op-admin-empty">Nessuna opportunity trovata con i filtri correnti.</td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($opportunities as $opportunity): ?>
                            <tr>
                                <td>
                                    <span class="op-admin-code"><?php echo sanitize_output($opportunity['code']); ?></span>
                                    <div class="text-muted small">#<?php echo (int) $opportunity['id']; ?></div>
                                </td>
                                <td>
                                    <div><?php echo sanitize_output(($opportunity['customer_first_name'] ?? '') . ' ' . ($opportunity['customer_last_name'] ?? '')); ?></div>
                                    <div class="text-muted small"><?php echo sanitize_output($opportunity['customer_tax_code'] ?? ''); ?></div>
                                </td>
                                <td><span class="op-admin-category"><?php echo sanitize_output($opportunity['category'] ?? ''); ?></span></td>
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
                                        <form class="d-inline" method="post" action="<?php echo opportunities_module_url('detail', ['id' => (int) $opportunity['id']]); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                            <input type="hidden" name="form_action" value="reopen_for_correction">
                                            <input type="hidden" name="reopen_note" value="Richiesta rettifica dall'elenco amministrazione">
                                            <button class="btn btn-sm btn-outline-secondary js-reopen-opportunity" type="button" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Riapri per rettifica" data-opportunity-code="<?php echo sanitize_output($opportunity['code'] ?? ''); ?>">
                                                <i class="fa-solid fa-rotate-left"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form class="d-inline" method="post" action="<?php echo opportunities_module_url('index'); ?>">
                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                        <input type="hidden" name="form_action" value="delete_opportunity">
                                        <input type="hidden" name="opportunity_id" value="<?php echo (int) $opportunity['id']; ?>">
                                        <button class="btn btn-sm btn-outline-danger js-delete-opportunity" type="button" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Elimina" data-opportunity-code="<?php echo sanitize_output($opportunity['code'] ?? ''); ?>">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo opportunities_module_url('detail', ['id' => (int) $opportunity['id']]); ?>" data-bs-toggle="tooltip" data-bs-placement="top" data-bs-title="Gestisci opportunity">
                                        <i class="fa-solid fa-eye me-1"></i>Gestisci
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                </div>
            </section>
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
