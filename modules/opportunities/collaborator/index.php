<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

$csrfToken = csrf_token();
$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$categoryOptions = [
    'telefonia' => 'Telefonia',
    'luce' => 'Luce',
    'gas' => 'Gas',
];
$remoteDraft = $opportunityService->getCollaboratorDraft($collaboratorId);
$remoteDraftData = is_array($remoteDraft['data'] ?? null) ? $remoteDraft['data'] : [];
$hasRemoteDraft = $remoteDraftData !== [];
$remoteDraftSavedAt = $remoteDraft['saved_at'] ?? null;

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Opportunity</p>
                <h1 class="h4 mb-0">Le tue richieste</h1>
            </div>
            <a class="btn btn-primary" href="<?php echo asset('modules/opportunities/collaborator/create.php'); ?>">
                <i class="fa-solid fa-plus me-2"></i>Nuova OP
            </a>
        </div>
        <div class="row g-4">
            <?php if ($hasRemoteDraft): ?>
                <?php
                    $draftCategoryKey = strtolower((string) ($remoteDraftData['category'] ?? ''));
                    $draftCategoryLabel = $draftCategoryKey !== ''
                        ? ($categoryOptions[$draftCategoryKey] ?? ucfirst($draftCategoryKey))
                        : 'Categoria non selezionata';
                    $draftCustomer = trim(
                        (string) ($remoteDraftData['customer_first_name'] ?? '') . ' ' . (string) ($remoteDraftData['customer_last_name'] ?? '')
                    );
                    if ($draftCustomer === '') {
                        $draftCustomer = 'Cliente non indicato';
                    }
                    $draftSavedLabel = format_datetime_locale($remoteDraftSavedAt) ?? 'data non disponibile';
                ?>
                <div class="col-12" data-role="remote-draft-card">
                    <div class="card shadow-sm border-warning-subtle">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                                <div>
                                    <p class="text-uppercase small text-muted mb-0">Bozze salvate</p>
                                    <h3 class="h6 mb-0">Hai una opportunity in stato di bozza</h3>
                                </div>
                                <span class="badge bg-warning text-dark">Da completare</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-3">
                                    <thead>
                                        <tr class="text-muted">
                                            <th scope="col">Cliente</th>
                                            <th scope="col">Categoria</th>
                                            <th scope="col">Ultimo salvataggio</th>
                                            <th scope="col" class="text-end">Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>
                                                <strong><?php echo sanitize_output($draftCustomer); ?></strong><br>
                                                <small class="text-muted">Bozza cloud</small>
                                            </td>
                                            <td><?php echo sanitize_output($draftCategoryLabel); ?></td>
                                            <td><?php echo sanitize_output($draftSavedLabel); ?></td>
                                            <td class="text-end">
                                                <div class="d-flex justify-content-end gap-2 flex-wrap">
                                                    <a class="btn btn-sm btn-warning" href="<?php echo asset('modules/opportunities/collaborator/create.php'); ?>">
                                                        <i class="fa-solid fa-pen-to-square me-1"></i>Continua
                                                    </a>
                                                    <button class="btn btn-sm btn-outline-danger" type="button" data-action="discard-remote-draft">
                                                        <span data-role="label">Elimina</span>
                                                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true" data-role="spinner"></span>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <p class="text-muted small mb-0">
                                Le bozze compaiono qui ma non vengono conteggiate finché non invii la opportunity.
                            </p>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="col-12">
                    <div class="alert alert-info mb-0" role="alert">
                        Nessuna bozza salvata. Usa "Nuova OP" per iniziare oppure consulta l'elenco completo dal menu laterale.
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<script>
document.addEventListener('DOMContentLoaded', function () {
    const remoteDraftEndpoint = "<?php echo sanitize_output(asset('api/opportunities/drafts.php')); ?>";
    const csrfToken = "<?php echo sanitize_output($csrfToken); ?>";
    const discardDraftButton = document.querySelector('[data-action="discard-remote-draft"]');
    if (discardDraftButton && remoteDraftEndpoint) {
        discardDraftButton.addEventListener('click', async () => {
            if (!window.confirm('Vuoi davvero eliminare la bozza cloud?')) {
                return;
            }
            const label = discardDraftButton.querySelector('[data-role="label"]');
            const spinner = discardDraftButton.querySelector('[data-role="spinner"]');
            discardDraftButton.disabled = true;
            if (label) {
                label.textContent = 'Eliminazione…';
            }
            if (spinner) {
                spinner.classList.remove('d-none');
            }
            try {
                const response = await fetch(remoteDraftEndpoint, {
                    method: 'DELETE',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-Token': csrfToken,
                    },
                });
                if (!response.ok) {
                    throw new Error('Delete failed');
                }
                window.location.reload();
            } catch (error) {
                discardDraftButton.disabled = false;
                if (label) {
                    label.textContent = 'Riprova eliminazione';
                }
                if (spinner) {
                    spinner.classList.add('d-none');
                }
                alert('Non sono riuscito a eliminare la bozza. Riprova tra qualche istante.');
            }
        });
    }
});
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
