<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_role('Admin');

$pageTitle = 'Impostazioni Opportunity';
$csrfToken = csrf_token();
$errors = [];
$categoryLabels = [
    'telefonia' => 'Telefonia',
    'luce' => 'Luce',
    'gas' => 'Gas',
];
$categoryKeys = array_keys($categoryLabels);
$lastCategoryKey = end($categoryKeys) ?: 'gas';
reset($categoryKeys);
$statusColors = [
    'warning' => 'Giallo (warning)',
    'info' => 'Azzurro (info)',
    'primary' => 'Blu (primary)',
    'success' => 'Verde (success)',
    'danger' => 'Rosso (danger)',
    'secondary' => 'Grigio (secondary)',
    'slate' => 'Slate',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'status_create':
                $opportunityService->createStatusDefinition([
                    'label' => $_POST['label'] ?? '',
                    'color' => $_POST['color'] ?? '',
                    'ordering' => $_POST['ordering'] ?? 0,
                    'code' => $_POST['code'] ?? null,
                ]);
                add_flash('success', 'Nuovo stato aggiunto.');
                header('Location: settings.php');
                exit;
            case 'status_update':
                $statusId = isset($_POST['status_id']) ? (int) $_POST['status_id'] : 0;
                if ($statusId <= 0) {
                    throw new RuntimeException('Stato non valido.');
                }
                $opportunityService->updateStatusDefinition($statusId, [
                    'label' => $_POST['label'] ?? '',
                    'color' => $_POST['color'] ?? '',
                    'ordering' => $_POST['ordering'] ?? 0,
                ]);
                add_flash('success', 'Stato aggiornato correttamente.');
                header('Location: settings.php');
                exit;
            case 'status_delete':
                $statusId = isset($_POST['status_id']) ? (int) $_POST['status_id'] : 0;
                if ($statusId <= 0) {
                    throw new RuntimeException('Stato non valido.');
                }
                $opportunityService->deleteStatus($statusId);
                add_flash('success', 'Stato eliminato.');
                header('Location: settings.php');
                exit;
            case 'provider_create':
                $opportunityService->createProvider([
                    'category' => $_POST['category'] ?? '',
                    'name' => $_POST['name'] ?? '',
                    'default_commission' => $_POST['default_commission'] ?? null,
                    'ordering' => $_POST['ordering'] ?? 0,
                    'active' => $_POST['active'] ?? 1,
                ]);
                add_flash('success', 'Gestore creato.');
                header('Location: settings.php');
                exit;
            case 'provider_update':
                $providerId = isset($_POST['provider_id']) ? (int) $_POST['provider_id'] : 0;
                if ($providerId <= 0) {
                    throw new RuntimeException('Gestore non valido.');
                }
                $opportunityService->updateProvider($providerId, [
                    'name' => $_POST['name'] ?? '',
                    'default_commission' => $_POST['default_commission'] ?? null,
                    'ordering' => $_POST['ordering'] ?? 0,
                    'active' => $_POST['active'] ?? 1,
                ]);
                add_flash('success', 'Gestore aggiornato.');
                header('Location: settings.php');
                exit;
            case 'offer_create':
                $opportunityService->createOffer([
                    'provider_id' => $_POST['provider_id'] ?? 0,
                    'name' => $_POST['name'] ?? '',
                    'commission' => $_POST['commission'] ?? null,
                    'ordering' => $_POST['ordering'] ?? 0,
                    'active' => $_POST['active'] ?? 1,
                ]);
                add_flash('success', 'Offerta creata.');
                header('Location: settings.php');
                exit;
            case 'offer_update':
                $offerId = isset($_POST['offer_id']) ? (int) $_POST['offer_id'] : 0;
                if ($offerId <= 0) {
                    throw new RuntimeException('Offerta non valida.');
                }
                $opportunityService->updateOffer($offerId, [
                    'name' => $_POST['name'] ?? '',
                    'commission' => $_POST['commission'] ?? null,
                    'ordering' => $_POST['ordering'] ?? 0,
                    'active' => $_POST['active'] ?? 1,
                ]);
                add_flash('success', 'Offerta aggiornata.');
                header('Location: settings.php');
                exit;
            default:
                $errors[] = 'Azione non riconosciuta.';
        }
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }
}

$statuses = $opportunityService->listStatusesDetailed();
$providersByCategory = $opportunityService->listProvidersWithOffers();
$providerOptions = [];
$offersFlat = [];

foreach ($categoryLabels as $category => $label) {
    $providers = $providersByCategory[$category] ?? [];
    foreach ($providers as $provider) {
        $providerOptions[] = [
            'id' => $provider['id'],
            'label' => $label . ' · ' . $provider['name'],
        ];
        foreach ($provider['offers'] as $offer) {
            $offersFlat[] = [
                'id' => $offer['id'],
                'name' => $offer['name'],
                'commission' => $offer['commission'],
                'ordering' => $offer['ordering'],
                'active' => $offer['active'],
                'provider_label' => $label . ' · ' . $provider['name'],
            ];
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Opportunity</p>
                <h1 class="h4 mb-0">Impostazioni modulo</h1>
                <p class="text-muted mb-0">Gestisci stati del workflow, gestori e offerte disponibili per i collaboratori.</p>
            </div>
            <a class="btn btn-outline-secondary" href="<?php echo asset('modules/opportunities/index.php'); ?>">
                <i class="fa-solid fa-diagram-project me-2"></i>Pipeline
            </a>
        </div>

        <?php foreach ($errors as $message): ?>
            <div class="alert alert-warning" role="alert"><?php echo sanitize_output($message); ?></div>
        <?php endforeach; ?>

        <ul class="nav nav-pills flex-wrap gap-2 mb-4" id="op-settings-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-workflow-tab" data-bs-toggle="pill" data-bs-target="#tab-workflow" type="button" role="tab" aria-controls="tab-workflow" aria-selected="true">
                    <i class="fa-solid fa-diagram-project me-2"></i>Workflow e stati
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-providers-tab" data-bs-toggle="pill" data-bs-target="#tab-providers" type="button" role="tab" aria-controls="tab-providers" aria-selected="false">
                    <i class="fa-solid fa-building me-2"></i>Gestori e offerte
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-overview-tab" data-bs-toggle="pill" data-bs-target="#tab-overview" type="button" role="tab" aria-controls="tab-overview" aria-selected="false">
                    <i class="fa-solid fa-table-list me-2"></i>Panoramica offerte
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="tab-workflow" role="tabpanel" aria-labelledby="tab-workflow-tab">
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                            <div>
                                <h2 class="h5 mb-1">Workflow e stati</h2>
                                <p class="text-muted mb-0">Personalizza gli stati disponibili per il team amministrativo.</p>
                            </div>
                        </div>
                        <?php foreach ($statuses as $status): ?>
                            <div class="op-settings-status mb-3">
                                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                    <div>
                                        <p class="text-uppercase small text-muted mb-1">Codice</p>
                                        <strong><?php echo sanitize_output($status['code']); ?></strong>
                                        <div class="text-muted small">Colore attuale: <span class="badge bg-light text-dark"><?php echo sanitize_output($status['color']); ?></span></div>
                                    </div>
                                    <div class="text-end">
                                        <p class="text-uppercase small text-muted mb-1">Tipologia</p>
                                        <?php if ((int) $status['is_core'] === 1): ?>
                                            <span class="badge bg-secondary">Sistema</span>
                                        <?php else: ?>
                                            <span class="badge bg-success-subtle text-success">Personalizzato</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if ((int) $status['is_core'] === 1): ?>
                                    <p class="mb-0 mt-3">Etichetta: <strong><?php echo sanitize_output($status['label']); ?></strong> &middot; Ordine: <?php echo (int) $status['ordering']; ?></p>
                                <?php else: ?>
                                    <form class="row g-2 align-items-end mt-3" method="post">
                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                        <input type="hidden" name="action" value="status_update">
                                        <input type="hidden" name="status_id" value="<?php echo (int) $status['id']; ?>">
                                        <div class="col-md-4">
                                            <label class="form-label text-uppercase small text-muted">Etichetta</label>
                                            <input class="form-control" type="text" name="label" value="<?php echo sanitize_output($status['label']); ?>" required>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label text-uppercase small text-muted">Colore</label>
                                            <select class="form-select" name="color" required>
                                                <?php foreach ($statusColors as $colorKey => $colorLabel): ?>
                                                    <option value="<?php echo $colorKey; ?>" <?php echo $status['color'] === $colorKey ? 'selected' : ''; ?>><?php echo sanitize_output($colorLabel); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label text-uppercase small text-muted">Ordine</label>
                                            <input class="form-control" type="number" name="ordering" value="<?php echo (int) $status['ordering']; ?>">
                                        </div>
                                        <div class="col-md-3 d-flex gap-2">
                                            <button class="btn btn-primary flex-grow-1" type="submit">
                                                <i class="fa-solid fa-save me-2"></i>Salva
                                            </button>
                                        </div>
                                    </form>
                                    <form class="mt-2 text-end" method="post" onsubmit="return confirm('Eliminare questo stato?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                        <input type="hidden" name="action" value="status_delete">
                                        <input type="hidden" name="status_id" value="<?php echo (int) $status['id']; ?>">
                                        <button class="btn btn-outline-danger btn-sm" type="submit">
                                            <i class="fa-solid fa-trash me-1"></i>Elimina stato
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <hr>
                        <form class="row g-3 align-items-end" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="action" value="status_create">
                            <div class="col-md-4">
                                <label class="form-label text-uppercase small text-muted">Etichetta</label>
                                <input class="form-control" type="text" name="label" placeholder="Es. In lavorazione" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-uppercase small text-muted">Colore</label>
                                <select class="form-select" name="color" required>
                                    <?php foreach ($statusColors as $colorKey => $colorLabel): ?>
                                        <option value="<?php echo $colorKey; ?>"><?php echo sanitize_output($colorLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label text-uppercase small text-muted">Ordine</label>
                                <input class="form-control" type="number" name="ordering" value="60">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-uppercase small text-muted">Codice (opzionale)</label>
                                <input class="form-control" type="text" name="code" placeholder="es. revisione">
                            </div>
                            <div class="col-12 d-flex justify-content-end">
                                <button class="btn btn-success" type="submit">
                                    <i class="fa-solid fa-plus me-2"></i>Nuovo stato
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="tab-providers" role="tabpanel" aria-labelledby="tab-providers-tab">
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                            <div>
                                <h2 class="h5 mb-1">Gestori e offerte</h2>
                                <p class="text-muted mb-0">Definisci i fornitori disponibili e le provvigioni di riferimento.</p>
                            </div>
                        </div>
                        <form class="row g-3 align-items-end mb-4" method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="action" value="provider_create">
                            <div class="col-md-3">
                                <label class="form-label text-uppercase small text-muted">Categoria</label>
                                <select class="form-select" name="category" required>
                                    <option value="">Seleziona</option>
                                    <?php foreach ($categoryLabels as $category => $label): ?>
                                        <option value="<?php echo $category; ?>"><?php echo sanitize_output($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-uppercase small text-muted">Nome gestore</label>
                                <input class="form-control" type="text" name="name" placeholder="Es. Enel" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label text-uppercase small text-muted">Provvigione €</label>
                                <input class="form-control" type="number" name="default_commission" step="0.01" min="0" placeholder="0.00">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label text-uppercase small text-muted">Ordine</label>
                                <input class="form-control" type="number" name="ordering" value="0">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label text-uppercase small text-muted">Stato</label>
                                <select class="form-select" name="active">
                                    <option value="1" selected>Attivo</option>
                                    <option value="0">Nascosto</option>
                                </select>
                            </div>
                            <div class="col-12 d-flex justify-content-end">
                                <button class="btn btn-success" type="submit">
                                    <i class="fa-solid fa-plus me-2"></i>Nuovo gestore
                                </button>
                            </div>
                        </form>
                        <?php foreach ($categoryLabels as $category => $label): ?>
                            <div class="mb-4">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <h3 class="h6 text-uppercase text-muted mb-0"><?php echo sanitize_output($label); ?></h3>
                                    <span class="badge bg-light text-dark"><?php echo count($providersByCategory[$category] ?? []); ?> gestori</span>
                                </div>
                                <?php if (empty($providersByCategory[$category])): ?>
                                    <div class="alert alert-light border mb-0">Nessun gestore per questa categoria.</div>
                                <?php else: ?>
                                    <?php foreach ($providersByCategory[$category] as $provider): ?>
                                        <form class="op-settings-provider row g-2 align-items-end mb-3" method="post">
                                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                            <input type="hidden" name="action" value="provider_update">
                                            <input type="hidden" name="provider_id" value="<?php echo (int) $provider['id']; ?>">
                                            <div class="col-md-4">
                                                <label class="form-label text-uppercase small text-muted">Nome</label>
                                                <input class="form-control" type="text" name="name" value="<?php echo sanitize_output($provider['name']); ?>" required>
                                                <p class="text-muted small mb-0 mt-1">Slug: <?php echo sanitize_output($provider['slug']); ?></p>
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label text-uppercase small text-muted">Provvigione €</label>
                                                <input class="form-control" type="number" name="default_commission" step="0.01" min="0" value="<?php echo sanitize_output($provider['default_commission'] ?? ''); ?>">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label text-uppercase small text-muted">Ordine</label>
                                                <input class="form-control" type="number" name="ordering" value="<?php echo (int) $provider['ordering']; ?>">
                                            </div>
                                            <div class="col-md-2">
                                                <label class="form-label text-uppercase small text-muted">Stato</label>
                                                <select class="form-select" name="active">
                                                    <option value="1" <?php echo $provider['active'] ? 'selected' : ''; ?>>Attivo</option>
                                                    <option value="0" <?php echo !$provider['active'] ? 'selected' : ''; ?>>Nascosto</option>
                                                </select>
                                            </div>
                                            <div class="col-md-2 d-flex align-items-end">
                                                <button class="btn btn-outline-primary w-100" type="submit">
                                                    <i class="fa-solid fa-save me-1"></i>Salva
                                                </button>
                                            </div>
                                            <?php if (!empty($provider['offers'])): ?>
                                                <div class="col-12">
                                                    <p class="text-uppercase small text-muted mb-1">Offerte</p>
                                                    <div class="op-settings-offers">
                                                        <?php foreach ($provider['offers'] as $offer): ?>
                                                            <form class="op-settings-offer" method="post">
                                                                <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                                <input type="hidden" name="action" value="offer_update">
                                                                <input type="hidden" name="offer_id" value="<?php echo (int) $offer['id']; ?>">
                                                                <div class="row g-2 align-items-end">
                                                                    <div class="col-md-4">
                                                                        <label class="form-label text-uppercase small text-muted">Nome</label>
                                                                        <input class="form-control" type="text" name="name" value="<?php echo sanitize_output($offer['name']); ?>" required>
                                                                    </div>
                                                                    <div class="col-md-2">
                                                                        <label class="form-label text-uppercase small text-muted">Provvigione €</label>
                                                                        <input class="form-control" type="number" name="commission" step="0.01" min="0" value="<?php echo sanitize_output($offer['commission'] ?? ''); ?>">
                                                                    </div>
                                                                    <div class="col-md-2">
                                                                        <label class="form-label text-uppercase small text-muted">Ordine</label>
                                                                        <input class="form-control" type="number" name="ordering" value="<?php echo (int) $offer['ordering']; ?>">
                                                                    </div>
                                                                    <div class="col-md-2">
                                                                        <label class="form-label text-uppercase small text-muted">Stato</label>
                                                                        <select class="form-select" name="active">
                                                                            <option value="1" <?php echo $offer['active'] ? 'selected' : ''; ?>>Attiva</option>
                                                                            <option value="0" <?php echo !$offer['active'] ? 'selected' : ''; ?>>Nascosta</option>
                                                                        </select>
                                                                    </div>
                                                                    <div class="col-md-2">
                                                                        <button class="btn btn-outline-primary w-100" type="submit">
                                                                            <i class="fa-solid fa-save me-1"></i>Salva
                                                                        </button>
                                                                    </div>
                                                                </div>
                                                            </form>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                            <div class="col-12">
                                                <form class="row g-2 align-items-end mt-2" method="post">
                                                    <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                    <input type="hidden" name="action" value="offer_create">
                                                    <input type="hidden" name="provider_id" value="<?php echo (int) $provider['id']; ?>">
                                                    <div class="col-md-4">
                                                        <label class="form-label text-uppercase small text-muted">Nuova offerta</label>
                                                        <input class="form-control" type="text" name="name" placeholder="Es. Promo Estate" required>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label text-uppercase small text-muted">Provvigione €</label>
                                                        <input class="form-control" type="number" name="commission" step="0.01" min="0" placeholder="0.00">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label text-uppercase small text-muted">Ordine</label>
                                                        <input class="form-control" type="number" name="ordering" value="0">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <label class="form-label text-uppercase small text-muted">Stato</label>
                                                        <select class="form-select" name="active">
                                                            <option value="1" selected>Attiva</option>
                                                            <option value="0">Nascosta</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <button class="btn btn-outline-success w-100" type="submit">
                                                            <i class="fa-solid fa-plus me-1"></i>Aggiungi
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </form>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($category !== $lastCategoryKey): ?>
                                <hr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="tab-overview" role="tabpanel" aria-labelledby="tab-overview-tab">
                <div class="card shadow-sm mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
                            <div>
                                <h2 class="h5 mb-1">Panoramica offerte</h2>
                                <p class="text-muted mb-0">Controlla rapidamente tutte le offerte e il relativo stato di pubblicazione.</p>
                            </div>
                        </div>
                        <?php if (!$offersFlat): ?>
                            <div class="alert alert-light border mb-0">Non ci sono offerte configurate.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Offerta</th>
                                            <th>Gestore</th>
                                            <th>Provvigione</th>
                                            <th>Ordine</th>
                                            <th>Stato</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($offersFlat as $offer): ?>
                                            <tr>
                                                <td><?php echo sanitize_output($offer['name']); ?></td>
                                                <td><?php echo sanitize_output($offer['provider_label']); ?></td>
                                                <td><?php echo $offer['commission'] !== null ? sanitize_output(number_format((float) $offer['commission'], 2, ',', '.')) . ' €' : '—'; ?></td>
                                                <td><?php echo (int) $offer['ordering']; ?></td>
                                                <td>
                                                    <span class="badge <?php echo $offer['active'] ? 'bg-success' : 'bg-secondary'; ?>">
                                                        <?php echo $offer['active'] ? 'Attiva' : 'Nascosta'; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
