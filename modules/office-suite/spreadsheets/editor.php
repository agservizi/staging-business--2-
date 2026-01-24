<?php
http_response_code(410);
exit;
__halt_compiler();

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Editor foglio';
$csrfToken = csrf_token();
$sheetService = new SpreadsheetService($pdo);
$sheetId = isset($_GET['id']) ? max(0, (int) $_GET['id']) : 0;
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

$statusOptions = [
    'draft' => 'Bozza',
    'review' => 'Revisione',
    'published' => 'Pubblicato',
    'archived' => 'Archiviato',
];

$categoryOptions = ['Standard', 'Finance', 'Operations', 'Logistica'];
$hotLicenseKey = (string) env('HOT_LICENSE_KEY', 'non-commercial-and-evaluation');
$hfLicenseKey = (string) env('HF_LICENSE_KEY', 'gpl-v3');
$userRole = isset($_SESSION['role']) ? (string) $_SESSION['role'] : 'Operatore';
$roleOptions = ['Admin', 'Manager', 'Operatore', 'Patronato', 'Cliente'];
$canSharePresets = in_array($userRole, ['Admin', 'Manager'], true);
$presetApiUrl = '';

$formError = null;
$sheet = null;
$latestRevision = null;
$gridState = '';
$gridMetadata = '';
$gridMetaPayload = ['cellMeta' => []];
$formData = [
    'id' => $sheetId,
    'title' => '',
    'category' => 'Standard',
    'status' => 'draft',
    'tags' => '',
];

if ($sheetId > 0) {
    $sheet = $sheetService->getSheet($sheetId);
    if ($sheet) {
        $latestRevision = $sheetService->getLatestRevision($sheetId);
        $gridState = (string) ($latestRevision['grid_state'] ?? '');
        $gridMetadata = (string) ($latestRevision['metadata'] ?? '');
        $formData['title'] = (string) ($sheet['titolo'] ?? '');
        $formData['category'] = (string) ($sheet['categoria'] ?? 'Standard');
        $formData['status'] = (string) ($sheet['stato'] ?? 'draft');
        $formData['tags'] = $sheet['tags'] ? implode(', ', (array) $sheet['tags']) : '';
    }
}

if ($gridState === '' || $gridState === '[]') {
    $gridMatrix = [];
    for ($row = 0; $row < 10; $row++) {
        $gridMatrix[$row] = array_fill(0, 8, '');
    }
    $gridState = json_encode($gridMatrix);
} else {
    $gridMatrix = json_decode($gridState, true);
    if (!is_array($gridMatrix) || empty($gridMatrix)) {
        $gridMatrix = [];
        for ($row = 0; $row < 10; $row++) {
            $gridMatrix[$row] = array_fill(0, 8, '');
        }
        $gridState = json_encode($gridMatrix);
    }
}

if ($gridMetadata === '') {
    $gridMetaPayload = ['cellMeta' => []];
    $gridMetadata = json_encode($gridMetaPayload);
} else {
    $gridMetaPayload = json_decode($gridMetadata, true);
    if (!is_array($gridMetaPayload)) {
        $gridMetaPayload = ['cellMeta' => []];
    }
    if (!isset($gridMetaPayload['cellMeta']) || !is_array($gridMetaPayload['cellMeta'])) {
        $gridMetaPayload['cellMeta'] = [];
    }
    $gridMetadata = json_encode($gridMetaPayload);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    if (isset($_POST['revert_revision_id']) && $sheetId > 0) {
        $revisionId = (int) $_POST['revert_revision_id'];
        try {
            $sheetService->revertToRevision($sheetId, $revisionId, $userId);
            add_flash('success', 'Versione del foglio ripristinata.');
            header('Location: editor.php?id=' . $sheetId);
            exit;
        } catch (RuntimeException $exception) {
            $formError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Office spreadsheet revert error: ' . $exception->getMessage());
            $formError = 'Impossibile ripristinare la versione selezionata.';
        }
    } else {
        $payload = [
            'id' => isset($_POST['sheet_id']) && $_POST['sheet_id'] !== '' ? (int) $_POST['sheet_id'] : null,
            'title' => $_POST['title'] ?? '',
            'category' => $_POST['category'] ?? 'Standard',
            'status' => $_POST['status'] ?? 'draft',
            'tags' => $_POST['tags'] ?? '',
            'grid' => $_POST['grid'] ?? '',
            'grid_meta' => $_POST['grid_meta'] ?? '',
            'owner_id' => $userId,
        ];

        $formData = [
            'id' => $payload['id'] ?? 0,
            'title' => $payload['title'],
            'category' => $payload['category'],
            'status' => $payload['status'],
            'tags' => is_array($payload['tags']) ? implode(', ', $payload['tags']) : (string) $payload['tags'],
        ];
        $gridState = $payload['grid'];
        $gridMetadata = $payload['grid_meta'];
        $gridMetaPayload = json_decode($gridMetadata, true);
        if (!is_array($gridMetaPayload)) {
            $gridMetaPayload = ['cellMeta' => []];
        }
        if (!isset($gridMetaPayload['cellMeta']) || !is_array($gridMetaPayload['cellMeta'])) {
            $gridMetaPayload['cellMeta'] = [];
        }
        $gridMetadata = json_encode($gridMetaPayload);

        try {
            $saved = $sheetService->saveSheet($payload, $userId);
            add_flash('success', 'Foglio salvato correttamente.');
            header('Location: editor.php?id=' . (int) $saved['id']);
            exit;
        } catch (RuntimeException $exception) {
            $formError = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Office spreadsheet save error: ' . $exception->getMessage());
            $formError = 'Errore inatteso durante il salvataggio del foglio. Riprovare.';
        }
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <form id="sheet-editor-form" class="d-flex flex-column gap-4" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
            <input type="hidden" name="sheet_id" value="<?php echo (int) $formData['id']; ?>">
            <input type="hidden" name="grid" id="grid-state-field" value="<?php echo htmlspecialchars($gridState, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="grid_meta" id="grid-meta-field" value="<?php echo htmlspecialchars($gridMetadata, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="d-flex flex-wrap justify-content-between align-items-center mb-0 gap-3">
                <div>
                    <h1 class="h4 mb-1"><?php echo $formData['id'] ? 'Modifica foglio' : 'Nuovo foglio'; ?></h1>
                    <p class="text-muted mb-0">Shell stile Excel con storage versionato.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary" href="#">
                        <i class="fa-solid fa-arrow-left me-2"></i>Ritorna ai fogli
                    </a>
                    <button class="btn btn-outline-secondary" type="button" disabled>
                        <i class="fa-solid fa-database me-2"></i>Connessioni dati
                    </button>
                    <button class="btn btn-success" type="submit">
                        <i class="fa-solid fa-floppy-disk me-2"></i>Salva foglio
                    </button>
                </div>
            </div>

            <?php if ($formError !== null): ?>
                <div class="alert alert-warning mb-0" role="alert"><?php echo sanitize_output($formError); ?></div>
            <?php endif; ?>

            <div class="spreadsheet card border-0 shadow-sm">
                <div class="card-header bg-white border-0">
                    <div class="toolbar d-flex flex-wrap gap-3 align-items-end">
                        <div class="toolbar-group">
                            <label class="text-uppercase small fw-semibold text-muted mb-1" for="sheet-title">Titolo</label>
                            <input id="sheet-title" class="form-control form-control-sm" type="text" name="title" value="<?php echo sanitize_output($formData['title']); ?>" placeholder="Es. Dashboard vendite" required>
                        </div>
                        <div class="toolbar-group">
                            <label class="text-uppercase small fw-semibold text-muted mb-1" for="sheet-category">Categoria</label>
                            <select id="sheet-category" class="form-select form-select-sm" name="category">
                                <?php foreach ($categoryOptions as $option): ?>
                                    <option value="<?php echo sanitize_output($option); ?>" <?php echo $option === $formData['category'] ? 'selected' : ''; ?>><?php echo sanitize_output($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="toolbar-group">
                            <label class="text-uppercase small fw-semibold text-muted mb-1" for="sheet-status">Stato</label>
                            <select id="sheet-status" class="form-select form-select-sm" name="status">
                                <?php foreach ($statusOptions as $value => $label): ?>
                                    <option value="<?php echo sanitize_output($value); ?>" <?php echo $value === $formData['status'] ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="toolbar-group">
                            <label class="text-uppercase small fw-semibold text-muted mb-1" for="sheet-tags">Tag</label>
                            <input id="sheet-tags" class="form-control form-control-sm" type="text" name="tags" value="<?php echo sanitize_output($formData['tags']); ?>" placeholder="kpi, budget">
                        </div>
                    </div>
                    <hr class="my-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                        <div>
                            <p class="small text-uppercase fw-semibold text-muted mb-1">Barra stile Office</p>
                            <p class="text-muted mb-0">Comandi Home / Inserisci ottimizzati per i fogli elettronici.</p>
                        </div>
                        <span class="badge bg-primary text-uppercase">Spreadsheet suite</span>
                    </div>
                    <ul class="nav nav-pills ribbon-tabs mt-3" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" type="button" data-ribbon-tab="home" aria-selected="true">Home</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" type="button" data-ribbon-tab="insert" aria-selected="false">Inserisci</button>
                        </li>
                    </ul>
                    <div class="excel-ribbon mt-3">
                        <div class="ribbon-pane active" data-ribbon-pane="home">
                            <div class="ribbon-group">
                                <p class="small text-uppercase fw-semibold text-muted mb-1">Formato</p>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Formato celle">
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="bold" title="Grassetto"><i class="fa-solid fa-bold"></i></button>
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="italic" title="Corsivo"><i class="fa-solid fa-italic"></i></button>
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="underline" title="Sottolineato"><i class="fa-solid fa-underline"></i></button>
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="highlight" title="Evidenzia cella"><i class="fa-solid fa-highlighter"></i></button>
                                </div>
                            </div>
                            <div class="ribbon-group">
                                <p class="small text-uppercase fw-semibold text-muted mb-1">Numeri</p>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Formattazione numerica">
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="currency" title="Formato valuta"><i class="fa-solid fa-euro-sign"></i></button>
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="percent" title="Formato percentuale"><i class="fa-solid fa-percent"></i></button>
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="clear-format" title="Rimuovi formattazione"><i class="fa-solid fa-eraser"></i></button>
                                </div>
                            </div>
                            <div class="ribbon-group">
                                <p class="small text-uppercase fw-semibold text-muted mb-1">Allineamento</p>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Allineamento testo">
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="align-left" title="Allinea a sinistra"><i class="fa-solid fa-align-left"></i></button>
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="align-center" title="Allinea al centro"><i class="fa-solid fa-align-center"></i></button>
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="align-right" title="Allinea a destra"><i class="fa-solid fa-align-right"></i></button>
                                </div>
                            </div>
                            <div class="ms-auto text-muted small d-flex align-items-center gap-2">
                                <i class="fa-solid fa-table-list text-primary"></i>
                                <span>Comandi principali sempre visibili.</span>
                            </div>
                        </div>
                        <div class="ribbon-pane" data-ribbon-pane="insert">
                            <div class="ribbon-group">
                                <p class="small text-uppercase fw-semibold text-muted mb-1">Struttura foglio</p>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Struttura foglio">
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="add-row" title="Aggiungi riga"><i class="fa-solid fa-plus"></i> Riga</button>
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="remove-row" title="Elimina riga"><i class="fa-solid fa-minus"></i> Riga</button>
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="add-column" title="Aggiungi colonna"><i class="fa-solid fa-plus"></i> Colonna</button>
                                    <button class="btn btn-outline-secondary" type="button" data-grid-action="remove-column" title="Elimina colonna"><i class="fa-solid fa-minus"></i> Colonna</button>
                                </div>
                            </div>
                            <div class="ribbon-group">
                                <p class="small text-uppercase fw-semibold text-muted mb-1">Segnaposto</p>
                                <div class="btn-group btn-group-sm" role="group" aria-label="Oggetti inserimento">
                                    <button class="btn btn-outline-secondary" type="button" disabled title="Grafico in arrivo"><i class="fa-solid fa-chart-line"></i></button>
                                    <button class="btn btn-outline-secondary" type="button" disabled title="Pivot in arrivo"><i class="fa-solid fa-layer-group"></i></button>
                                </div>
                            </div>
                            <div class="ms-auto text-muted small d-flex align-items-center gap-2">
                                <i class="fa-solid fa-square-plus text-success"></i>
                                <span>Prepara il foglio con righe e colonne aggiuntive.</span>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="sheet-layout d-flex flex-column">
                        <div class="formula-bar d-flex align-items-center gap-2">
                            <span class="badge bg-primary text-white">fx</span>
                            <input id="formula-display" class="form-control form-control-sm" type="text" placeholder="=SOMMA(A1:A10)" readonly>
                            <?php if ($latestRevision): ?>
                                <span class="text-muted small">Versione <?php echo (int) $latestRevision['versione']; ?> · <?php echo sanitize_output(format_datetime_locale($latestRevision['created_at'] ?? null)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="grid flex-grow-1">
                            <div id="sheet-grid" class="hot-container"></div>
                        </div>
                        <div class="grid-hint text-muted small p-3">
                            Handsontable + HyperFormula sono attivi per calcoli client-side e serializzazione dati.
                        </div>
                        <?php if (!empty($sheet) && !empty($sheet['revisions']) && $sheetId > 0): ?>
                            <div class="version-history border-top p-3 bg-light">
                                <p class="text-uppercase small fw-semibold text-muted mb-2">Versioni salvate</p>
                                <?php foreach ($sheet['revisions'] as $revision): ?>
                                    <div class="revision-entry d-flex justify-content-between align-items-start mb-2 p-2 border rounded small bg-white">
                                        <div>
                                            <strong>v<?php echo (int) ($revision['versione'] ?? 0); ?></strong>
                                            <span class="text-muted">· <?php echo sanitize_output(format_datetime_locale($revision['created_at'] ?? null)); ?></span>
                                            <?php if (!empty($revision['commento'])): ?>
                                                <div class="text-muted"><?php echo sanitize_output($revision['commento']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                        <button class="btn btn-sm btn-outline-secondary" type="submit" name="revert_revision_id" value="<?php echo (int) ($revision['id'] ?? 0); ?>" onclick="return confirm('Ripristinare questa versione del foglio?');">Ripristina</button>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <div class="preset-manager border-top p-4 bg-white" id="preset-manager" data-sheet-id="<?php echo (int) $formData['id']; ?>" data-api-url="<?php echo sanitize_output($presetApiUrl); ?>" data-can-share="<?php echo $canSharePresets ? '1' : '0'; ?>">
                            <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-3">
                                <div>
                                    <p class="text-uppercase small fw-semibold text-muted mb-1">Filtri e viste CRM</p>
                                    <h3 class="h6 mb-0">Preset CRM</h3>
                                </div>
                                <span class="badge bg-light text-dark" id="preset-active-label">Nessun preset attivo</span>
                            </div>
                            <?php if ($formData['id']): ?>
                                <div class="row g-4 align-items-start">
                                    <div class="col-lg-6">
                                        <div class="preset-form card border-0 shadow-sm">
                                            <div class="card-body">
                                                <div class="mb-3">
                                                    <label class="form-label text-uppercase small text-muted" for="preset-name">Nome preset</label>
                                                    <input id="preset-name" class="form-control form-control-sm" type="text" placeholder="Es. KPI Finance">
                                                </div>
                                                <div class="row g-2">
                                                    <div class="col-6">
                                                        <label class="form-label text-uppercase small text-muted" for="preset-visibility">Visibilità</label>
                                                        <select id="preset-visibility" class="form-select form-select-sm">
                                                            <option value="private" selected>Privato</option>
                                                            <option value="role" <?php echo $canSharePresets ? '' : 'disabled'; ?>>Ruoli CRM</option>
                                                            <option value="global" <?php echo $canSharePresets ? '' : 'disabled'; ?>>Globale</option>
                                                        </select>
                                                    </div>
                                                    <div class="col-6" id="preset-role-wrapper" hidden>
                                                        <label class="form-label text-uppercase small text-muted" for="preset-role-select">Ruoli abilitati</label>
                                                        <select id="preset-role-select" class="form-select form-select-sm" multiple size="4">
                                                            <?php foreach ($roleOptions as $roleOption): ?>
                                                                <option value="<?php echo sanitize_output($roleOption); ?>"><?php echo sanitize_output($roleOption); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="mt-3">
                                                    <label class="form-label text-uppercase small text-muted" for="preset-columns">Colonne visibili</label>
                                                    <select id="preset-columns" class="form-select form-select-sm" multiple size="6"></select>
                                                    <small class="text-muted">Lascia vuoto per mostrare tutte le colonne.</small>
                                                </div>
                                                <div class="mt-3">
                                                    <label class="form-label text-uppercase small text-muted" for="preset-tags">Tag CRM</label>
                                                    <input id="preset-tags" class="form-control form-control-sm" type="text" placeholder="logistica, vip, onboarding">
                                                </div>
                                                <div class="preset-filters mt-4">
                                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                                        <label class="text-uppercase small text-muted mb-0">Filtri dinamici</label>
                                                        <button class="btn btn-link btn-sm p-0" type="button" id="preset-add-filter">+ Aggiungi filtro</button>
                                                    </div>
                                                    <div id="preset-filter-list"></div>
                                                    <p class="text-muted small mb-0">Imposta condizioni come "Colonna + operatore + valore" per replicare i filtri CRM.</p>
                                                </div>
                                                <div class="d-flex flex-wrap gap-2 mt-4">
                                                    <button class="btn btn-primary btn-sm" type="button" id="preset-save-btn"><i class="fa-solid fa-floppy-disk me-2"></i>Salva preset</button>
                                                    <button class="btn btn-outline-secondary btn-sm" type="button" id="preset-reset-btn">Reset form</button>
                                                </div>
                                                <div class="alert alert-info small mt-3 d-none" id="preset-feedback" role="alert"></div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="preset-list card border-0 shadow-sm h-100">
                                            <div class="card-body">
                                                <p class="text-uppercase small text-muted fw-semibold mb-2">Preset disponibili</p>
                                                <div id="preset-tags-preview" class="mb-3"></div>
                                                <ul class="list-group list-group-flush preset-list-items" id="preset-list"></ul>
                                                <p class="text-muted small mb-0 d-none" id="preset-empty-state">Nessun preset disponibile per questo foglio.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <template id="preset-filter-row-template">
                                    <div class="preset-filter-row d-flex flex-wrap gap-2 align-items-center mb-2">
                                        <select class="form-select form-select-sm preset-filter-column"></select>
                                        <select class="form-select form-select-sm preset-filter-operator">
                                            <option value="contains">Contiene</option>
                                            <option value="starts_with">Inizia con</option>
                                            <option value="ends_with">Finisce con</option>
                                            <option value="eq">Uguale a</option>
                                            <option value="neq">Diverso da</option>
                                            <option value="gt">Maggiore di</option>
                                            <option value="lt">Minore di</option>
                                        </select>
                                        <input class="form-control form-control-sm preset-filter-value" type="text" placeholder="Valore">
                                        <button class="btn btn-link text-danger p-0 preset-filter-remove" type="button" title="Rimuovi filtro"><i class="fa-solid fa-times"></i></button>
                                    </div>
                                </template>
                            <?php else: ?>
                                <div class="alert alert-warning mb-0" role="alert">
                                    Salva il foglio per creare preset condivisi con l'app CRM.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('assets/vendor/handsontable/handsontable.full.min.css'); ?>">
<script>
    window.HOT_LICENSE_KEY = <?php echo json_encode($hotLicenseKey, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    window.HF_LICENSE_KEY = <?php echo json_encode($hfLicenseKey, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>
<style>
    .spreadsheet .toolbar-group {
        min-width: 150px;
    }
    .ribbon-tabs .nav-link {
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }
    .excel-ribbon .ribbon-group {
        min-width: 180px;
    }
    .excel-ribbon .btn {
        min-width: 36px;
    }
    .excel-ribbon {
        border: 1px solid rgba(15,23,42,0.08);
        border-radius: 0.5rem;
        padding: 1rem;
        background: #fdfdff;
    }
    .ribbon-pane {
        display: none;
        flex-wrap: wrap;
        gap: 1.25rem;
        align-items: center;
    }
    .ribbon-pane.active {
        display: flex;
    }
    .sheet-layout {
        min-height: 520px;
        background: #fdfdff;
    }
    .formula-bar {
        padding: 0.75rem 1rem;
        border-bottom: 1px solid rgba(15,23,42,0.08);
        background: #fff;
    }
    .grid {
        overflow: hidden;
        background: #fff;
        border-top: 1px solid rgba(15,23,42,0.05);
    }
    .hot-container {
        width: 100%;
        min-height: 520px;
    }
    .hot-container .handsontable {
        font-size: 0.95rem;
    }
    .hot-container.needs-selection {
        animation: grid-pulse 0.35s ease-in-out 0s 2;
    }
    .ht-highlight {
        background-color: #fff3cd !important;
    }
    .preset-manager {
        border-top: 1px solid rgba(15,23,42,0.08);
    }
    .preset-form .form-label {
        font-size: 0.75rem;
        letter-spacing: 0.04em;
    }
    .preset-filter-row {
        background: #f8fafc;
        border-radius: 0.5rem;
        padding: 0.5rem;
    }
    .preset-filter-row .preset-filter-value {
        min-width: 140px;
    }
    .preset-list-items .list-group-item {
        border: none;
        padding: 0.75rem 0;
        border-bottom: 1px solid rgba(15,23,42,0.08);
    }
    .preset-list-items .list-group-item:last-child {
        border-bottom: none;
    }
    .preset-tags-preview .badge {
        margin-right: 0.25rem;
        margin-bottom: 0.25rem;
    }
    .preset-role-pill {
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    @keyframes grid-pulse {
        0% { box-shadow: 0 0 0 0 rgba(13,110,253,0); }
        50% { box-shadow: 0 0 0 4px rgba(13,110,253,0.35); }
        100% { box-shadow: 0 0 0 0 rgba(13,110,253,0); }
    }
</style>
<script src="<?php echo asset('assets/vendor/hyperformula/hyperformula.full.min.js'); ?>"></script>
<script src="<?php echo asset('assets/vendor/handsontable/handsontable.full.min.js'); ?>"></script>
<script>
    (function () {
        const sheetContext = {
            id: <?php echo (int) $formData['id']; ?>,
            csrfToken: <?php echo json_encode($csrfToken, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            presetApiUrl: <?php echo json_encode($presetApiUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>,
            canShare: <?php echo $canSharePresets ? 'true' : 'false'; ?>,
            role: <?php echo json_encode($userRole, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
        };

        const form = document.getElementById('sheet-editor-form');
        const gridField = document.getElementById('grid-state-field');
        const metaField = document.getElementById('grid-meta-field');
        const toolbarButtons = document.querySelectorAll('[data-grid-action]');
        const ribbonTabs = document.querySelectorAll('[data-ribbon-tab]');
        const ribbonPanes = document.querySelectorAll('[data-ribbon-pane]');
        const gridContainer = document.getElementById('sheet-grid');
        const formulaInput = document.getElementById('formula-display');

        if (!form || !gridField || !metaField || !gridContainer || typeof Handsontable === 'undefined' || typeof HyperFormula === 'undefined') {
            console.warn('Handsontable/HyperFormula non caricati, impossibile inizializzare il foglio.');
            return;
        }

        const columnLabelFromIndex = (index) => {
            let label = '';
            let current = index;
            while (current >= 0) {
                label = String.fromCharCode((current % 26) + 65) + label;
                current = Math.floor(current / 26) - 1;
            }
            return label;
        };

        const columnIndexFromLabel = (label) => {
            if (!label) {
                return -1;
            }
            const normalized = label.trim().toUpperCase();
            if (!/^[A-Z]+$/.test(normalized)) {
                return -1;
            }
            let index = 0;
            for (let i = 0; i < normalized.length; i += 1) {
                index *= 26;
                index += normalized.charCodeAt(i) - 64;
            }
            return index - 1;
        };

        const initialMatrix = <?php echo json_encode($gridMatrix, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
        const initialMeta = <?php echo json_encode($gridMetaPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        const normalizeMatrix = (matrix) => {
            if (!Array.isArray(matrix) || matrix.length === 0) {
                return Handsontable.helper.createEmptySpreadsheetData(10, 8);
            }
            const columnCount = Math.max(
                8,
                ...matrix.map((row) => (Array.isArray(row) ? row.length : 0))
            );
            return matrix.map((row) => {
                const normalizedRow = [];
                for (let col = 0; col < columnCount; col++) {
                    normalizedRow[col] = row && row[col] !== undefined ? row[col] : '';
                }
                return normalizedRow;
            });
        };

        const ensureMetaShape = (payload) => {
            if (!payload || typeof payload !== 'object') {
                return { cellMeta: {} };
            }
            if (!payload.cellMeta || typeof payload.cellMeta !== 'object') {
                payload.cellMeta = {};
            }
            return payload;
        };

        const parseMetaPayload = (raw) => {
            if (!raw) {
                return { cellMeta: {} };
            }
            try {
                const parsed = typeof raw === 'string' ? JSON.parse(raw) : raw;
                return ensureMetaShape(parsed);
            } catch (error) {
                console.warn('Metadati foglio non validi, verranno reimpostati.', error);
                return { cellMeta: {} };
            }
        };

        const applyInitialMeta = (metaPayload) => {
            const shaped = ensureMetaShape(metaPayload);
            Object.entries(shaped.cellMeta).forEach(([key, value]) => {
                const [rowStr, colStr] = key.split(':');
                const row = Number.parseInt(rowStr, 10);
                const column = Number.parseInt(colStr, 10);
                if (!Number.isInteger(row) || !Number.isInteger(column)) {
                    return;
                }
                if (value.className) {
                    hot.setCellMeta(row, column, 'className', value.className);
                }
                if (value.type) {
                    hot.setCellMeta(row, column, 'type', value.type);
                }
                if (value.numericFormat) {
                    hot.setCellMeta(row, column, 'numericFormat', value.numericFormat);
                }
            });
            hot.render();
        };

        const collectMetadata = () => {
            const cellMeta = {};
            hot.getCellsMeta().forEach((meta) => {
                if (typeof meta.row !== 'number' || typeof meta.col !== 'number') {
                    return;
                }
                const payload = {};
                if (meta.className) {
                    payload.className = meta.className;
                }
                if (meta.type) {
                    payload.type = meta.type;
                }
                if (meta.numericFormat) {
                    payload.numericFormat = meta.numericFormat;
                }
                if (Object.keys(payload).length > 0) {
                    cellMeta[`${meta.row}:${meta.col}`] = payload;
                }
            });
            return { cellMeta };
        };

        const persistState = () => {
            gridField.value = JSON.stringify(hot.getSourceData());
            metaField.value = JSON.stringify(collectMetadata());
        };

        const flashSelectionHint = () => {
            gridContainer.classList.add('needs-selection');
            window.setTimeout(() => gridContainer.classList.remove('needs-selection'), 400);
        };

        const hyperFormulaInstance = HyperFormula.buildEmpty({
            licenseKey: window.HF_LICENSE_KEY || 'gpl-v3',
        });

        const hot = new Handsontable(gridContainer, {
            data: normalizeMatrix(initialMatrix),
            rowHeaders: true,
            colHeaders: true,
            stretchH: 'all',
            height: 'auto',
            width: '100%',
            manualColumnResize: true,
            manualRowResize: true,
            contextMenu: true,
            dropdownMenu: true,
            filters: true,
            hiddenColumns: {
                indicators: true,
            },
            multiColumnSorting: true,
            licenseKey: window.HOT_LICENSE_KEY || 'non-commercial-and-evaluation',
            formulas: {
                engine: hyperFormulaInstance,
            },
        });

        const hiddenColumnsPlugin = hot.getPlugin('hiddenColumns');
        const filtersPlugin = hot.getPlugin('filters');
        const columnOptionObservers = [];

        const buildColumnOptionList = () => {
            const totalColumns = hot.countCols();
            const options = [];
            for (let column = 0; column < totalColumns; column += 1) {
                const label = columnLabelFromIndex(column);
                const header = hot.getColHeader(column);
                const readableHeader = typeof header === 'string' && header && header !== label
                    ? `${label} · ${header}`
                    : label;
                options.push({ value: label, label: readableHeader });
            }
            return options;
        };

        const notifyColumnObservers = () => {
            const snapshot = buildColumnOptionList();
            columnOptionObservers.forEach((callback) => {
                try {
                    callback(snapshot);
                } catch (error) {
                    console.warn('Observer preset colonne non gestito', error);
                }
            });
        };

        const registerColumnObserver = (callback) => {
            if (typeof callback !== 'function') {
                return;
            }
            columnOptionObservers.push(callback);
            callback(buildColumnOptionList());
        };

        applyInitialMeta(parseMetaPayload(initialMeta));
        persistState();
        notifyColumnObservers();

        const getSelectedCells = () => {
            const selections = hot.getSelected();
            if (!selections || selections.length === 0) {
                flashSelectionHint();
                return [];
            }
            const cells = [];
            selections.forEach(([rowStart, colStart, rowEnd, colEnd]) => {
                for (let row = rowStart; row <= rowEnd; row++) {
                    for (let col = colStart; col <= colEnd; col++) {
                        cells.push([row, col]);
                    }
                }
            });
            return cells;
        };

        const getActiveSelection = () => {
            const selections = hot.getSelected();
            if (!selections || selections.length === 0) {
                return null;
            }
            return selections[selections.length - 1];
        };

        const updateCellClasses = (row, column, mutator) => {
            const meta = hot.getCellMeta(row, column);
            const classes = new Set((meta.className || '').split(' ').filter(Boolean));
            mutator(classes);
            const nextValue = Array.from(classes).join(' ');
            if (nextValue) {
                hot.setCellMeta(row, column, 'className', nextValue);
            } else {
                hot.removeCellMeta(row, column, 'className');
            }
        };

        const toggleClass = (className) => {
            const cells = getSelectedCells();
            if (!cells.length) {
                return;
            }
            cells.forEach(([row, column]) => {
                updateCellClasses(row, column, (set) => {
                    if (set.has(className)) {
                        set.delete(className);
                    } else {
                        set.add(className);
                    }
                });
            });
            hot.render();
            persistState();
        };

        const setAlignment = (targetClass) => {
            const cells = getSelectedCells();
            if (!cells.length) {
                return;
            }
            const alignClasses = ['htLeft', 'htCenter', 'htRight'];
            cells.forEach(([row, column]) => {
                updateCellClasses(row, column, (set) => {
                    alignClasses.forEach((cls) => set.delete(cls));
                    set.add(targetClass);
                });
            });
            hot.render();
            persistState();
        };

        const clearFormatting = () => {
            const cells = getSelectedCells();
            if (!cells.length) {
                return;
            }
            cells.forEach(([row, column]) => {
                hot.removeCellMeta(row, column, 'className');
                hot.removeCellMeta(row, column, 'type');
                hot.removeCellMeta(row, column, 'numericFormat');
            });
            hot.render();
            persistState();
        };

        const parseNumber = (rawValue) => {
            if (rawValue === null || rawValue === undefined) {
                return null;
            }
            const normalized = String(rawValue).replace(/[^0-9,.-]/g, '').replace(',', '.');
            const parsed = Number(normalized);
            return Number.isFinite(parsed) ? parsed : null;
        };

        const formatNumeric = (numericFormat) => {
            const cells = getSelectedCells();
            if (!cells.length) {
                return;
            }
            cells.forEach(([row, column]) => {
                const value = parseNumber(hot.getDataAtCell(row, column));
                if (value === null) {
                    return;
                }
                hot.setDataAtCell(row, column, value);
                hot.setCellMeta(row, column, 'type', 'numeric');
                hot.setCellMeta(row, column, 'numericFormat', numericFormat);
            });
            hot.render();
            persistState();
        };

        const addRow = () => {
            const selection = getActiveSelection();
            const insertIndex = selection ? selection[2] + 1 : hot.countRows();
            hot.alter('insert_row', insertIndex, 1);
            persistState();
        };

        const removeRow = () => {
            if (hot.countRows() <= 1) {
                return;
            }
            const selection = getActiveSelection();
            const index = selection ? selection[0] : hot.countRows() - 1;
            hot.alter('remove_row', index, 1);
            persistState();
        };

        const addColumn = () => {
            const selection = getActiveSelection();
            const insertIndex = selection ? selection[3] + 1 : hot.countCols();
            hot.alter('insert_col', insertIndex, 1);
            persistState();
        };

        const removeColumn = () => {
            if (hot.countCols() <= 1) {
                return;
            }
            const selection = getActiveSelection();
            const index = selection ? selection[1] : hot.countCols() - 1;
            hot.alter('remove_col', index, 1);
            persistState();
        };

        toolbarButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const action = button.dataset.gridAction;
                switch (action) {
                    case 'bold':
                        toggleClass('htBold');
                        break;
                    case 'italic':
                        toggleClass('htItalic');
                        break;
                    case 'underline':
                        toggleClass('htUnderline');
                        break;
                    case 'highlight':
                        toggleClass('ht-highlight');
                        break;
                    case 'currency':
                        formatNumeric({ pattern: '€ 0,0.00' });
                        break;
                    case 'percent':
                        formatNumeric({ pattern: '0.00%' });
                        break;
                    case 'clear-format':
                        clearFormatting();
                        break;
                    case 'align-left':
                        setAlignment('htLeft');
                        break;
                    case 'align-center':
                        setAlignment('htCenter');
                        break;
                    case 'align-right':
                        setAlignment('htRight');
                        break;
                    case 'add-row':
                        addRow();
                        break;
                    case 'remove-row':
                        removeRow();
                        break;
                    case 'add-column':
                        addColumn();
                        break;
                    case 'remove-column':
                        removeColumn();
                        break;
                    default:
                        break;
                }
            });
        });

        const setActiveRibbonTab = (targetTab) => {
            ribbonTabs.forEach((tab) => {
                const isActive = tab.dataset.ribbonTab === targetTab;
                tab.classList.toggle('active', isActive);
                tab.setAttribute('aria-selected', String(isActive));
            });
            ribbonPanes.forEach((pane) => {
                pane.classList.toggle('active', pane.dataset.ribbonPane === targetTab);
            });
        };

        ribbonTabs.forEach((tab) => tab.addEventListener('click', () => setActiveRibbonTab(tab.dataset.ribbonTab)));

        const syncFormulaBar = (row, column) => {
            if (!formulaInput) {
                return;
            }
            const cellLabel = Handsontable.helper.spreadsheetColumnLabel(column) + (row + 1);
            const displayValue = hot.getDataAtCell(row, column) ?? '';
            formulaInput.value = `${cellLabel}: ${displayValue}`;
            formulaInput.dataset.cellRef = cellLabel;
        };

        hot.addHook('afterSelectionEnd', (row, column, row2, column2) => {
            const targetRow = typeof row2 === 'number' ? row2 : row;
            const targetCol = typeof column2 === 'number' ? column2 : column;
            syncFormulaBar(targetRow, targetCol);
        });

        hot.addHook('afterChange', (changes, source) => {
            if (source === 'loadData') {
                return;
            }
            persistState();
        });

        hot.addHook('afterCreateCol', () => notifyColumnObservers());
        hot.addHook('afterRemoveCol', () => notifyColumnObservers());
        hot.addHook('afterColumnMove', () => notifyColumnObservers());

        form.addEventListener('submit', () => {
            persistState();
        });

        const presetManager = document.getElementById('preset-manager');
        if (!presetManager || Number.parseInt(presetManager.dataset.sheetId || '0', 10) <= 0) {
            return;
        }

        const presetFormCard = presetManager.querySelector('.preset-form');
        if (!presetFormCard) {
            return;
        }

        const presetNameInput = document.getElementById('preset-name');
        const presetVisibilitySelect = document.getElementById('preset-visibility');
        const presetRoleWrapper = document.getElementById('preset-role-wrapper');
        const presetRoleSelect = document.getElementById('preset-role-select');
        const presetColumnsSelect = document.getElementById('preset-columns');
        const presetTagsInput = document.getElementById('preset-tags');
        const presetAddFilterBtn = document.getElementById('preset-add-filter');
        const presetFilterList = document.getElementById('preset-filter-list');
        const presetFilterTemplate = document.getElementById('preset-filter-row-template');
        const presetSaveBtn = document.getElementById('preset-save-btn');
        const presetResetBtn = document.getElementById('preset-reset-btn');
        const presetFeedback = document.getElementById('preset-feedback');
        const presetListEl = document.getElementById('preset-list');
        const presetEmptyState = document.getElementById('preset-empty-state');
        const presetTagsPreview = document.getElementById('preset-tags-preview');
        const presetActiveLabel = document.getElementById('preset-active-label');

        const presetState = {
            list: [],
            activeId: null,
            loading: false,
        };

        const operatorMap = {
            contains: 'contains',
            starts_with: 'begins_with',
            ends_with: 'ends_with',
            eq: 'equal',
            neq: 'not_equal',
            gt: 'greater_than',
            lt: 'less_than',
        };

        const buildApiUrl = () => {
            try {
                const url = new URL(sheetContext.presetApiUrl, window.location.origin);
                if (sheetContext.id > 0) {
                    url.searchParams.set('sheet_id', String(sheetContext.id));
                }
                return url.toString();
            } catch (error) {
                return sheetContext.presetApiUrl;
            }
        };

        const togglePresetFeedback = (message, variant) => {
            if (!presetFeedback) {
                return;
            }
            if (!message) {
                presetFeedback.classList.add('d-none');
                presetFeedback.textContent = '';
                presetFeedback.classList.remove('alert-success', 'alert-danger', 'alert-info');
                return;
            }
            presetFeedback.textContent = message;
            presetFeedback.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-info');
            presetFeedback.classList.add(`alert-${variant || 'info'}`);
        };

        const csvToArray = (value, uppercase) => {
            if (!value) {
                return [];
            }
            return value
                .split(',')
                .map((item) => (uppercase ? item.trim().toUpperCase() : item.trim()))
                .filter((item) => item !== '');
        };

        const clearFilterRows = () => {
            if (!presetFilterList) {
                return;
            }
            presetFilterList.innerHTML = '';
        };

        const populateColumnSelect = (selectElement, options, selectedValues) => {
            if (!selectElement) {
                return;
            }
            const multiple = Boolean(selectElement.multiple);
            const safeSelected = multiple
                ? new Set(Array.isArray(selectedValues) ? selectedValues : [])
                : (selectedValues || '');
            const previousScrollTop = selectElement.scrollTop;
            selectElement.innerHTML = '';
            options.forEach((option) => {
                const opt = document.createElement('option');
                opt.value = option.value;
                opt.textContent = option.label;
                if (multiple) {
                    opt.selected = safeSelected.has(option.value);
                } else if (option.value === safeSelected) {
                    opt.selected = true;
                }
                selectElement.appendChild(opt);
            });
            selectElement.scrollTop = previousScrollTop;
        };

        const addFilterRow = (filter) => {
            if (!presetFilterTemplate || !presetFilterList) {
                return;
            }
            const fragment = presetFilterTemplate.content.cloneNode(true);
            const rowEl = fragment.querySelector('.preset-filter-row');
            if (!rowEl) {
                return;
            }
            const columnSelect = rowEl.querySelector('.preset-filter-column');
            const operatorSelect = rowEl.querySelector('.preset-filter-operator');
            const valueInput = rowEl.querySelector('.preset-filter-value');
            const removeBtn = rowEl.querySelector('.preset-filter-remove');
            if (operatorSelect && filter && filter.operator) {
                operatorSelect.value = filter.operator;
            }
            if (valueInput && filter && typeof filter.value === 'string') {
                valueInput.value = filter.value;
            }
            if (removeBtn) {
                removeBtn.addEventListener('click', () => {
                    rowEl.remove();
                });
            }
            presetFilterList.appendChild(fragment);
            if (columnSelect) {
                const desiredValue = filter && filter.column ? filter.column : '';
                populateColumnSelect(columnSelect, buildColumnOptionList(), desiredValue);
            }
        };

        const hydrateFilters = (filters) => {
            clearFilterRows();
            if (!Array.isArray(filters) || filters.length === 0) {
                return;
            }
            filters.forEach((filter) => addFilterRow(filter));
        };

        const getFilterPayload = () => {
            if (!presetFilterList) {
                return [];
            }
            const rows = Array.from(presetFilterList.querySelectorAll('.preset-filter-row'));
            const payload = [];
            rows.forEach((rowEl) => {
                const columnSelect = rowEl.querySelector('.preset-filter-column');
                const operatorSelect = rowEl.querySelector('.preset-filter-operator');
                const valueInput = rowEl.querySelector('.preset-filter-value');
                if (!columnSelect || !operatorSelect || !valueInput) {
                    return;
                }
                const column = columnSelect.value.trim().toUpperCase();
                const operator = operatorSelect.value.trim();
                const value = valueInput.value.trim();
                if (column && operator && value) {
                    payload.push({ column, operator, value });
                }
            });
            return payload;
        };

        const registerFilterColumnSync = () => {
            registerColumnObserver((options) => {
                const selectedColumns = presetColumnsSelect
                    ? Array.from(presetColumnsSelect.selectedOptions).map((opt) => opt.value)
                    : [];
                if (presetColumnsSelect) {
                    populateColumnSelect(presetColumnsSelect, options, selectedColumns);
                }
                if (!presetFilterList) {
                    return;
                }
                presetFilterList.querySelectorAll('.preset-filter-column').forEach((selectEl) => {
                    const preselect = selectEl.value;
                    populateColumnSelect(selectEl, options, preselect);
                });
            });
        };

        const toggleRoleWrapper = () => {
            if (!presetRoleWrapper) {
                return;
            }
            const needsRoles = presetVisibilitySelect && presetVisibilitySelect.value === 'role';
            presetRoleWrapper.hidden = !needsRoles;
        };

        const resetPresetForm = () => {
            if (presetNameInput) {
                presetNameInput.value = '';
            }
            if (presetVisibilitySelect) {
                presetVisibilitySelect.value = 'private';
            }
            if (presetRoleSelect) {
                Array.from(presetRoleSelect.options).forEach((option) => {
                    option.selected = false;
                });
            }
            if (presetColumnsSelect) {
                Array.from(presetColumnsSelect.options).forEach((option) => {
                    option.selected = false;
                });
            }
            if (presetTagsInput) {
                presetTagsInput.value = '';
            }
            clearFilterRows();
            toggleRoleWrapper();
            togglePresetFeedback('', 'info');
        };

        const buildPresetPayload = () => {
            const name = presetNameInput ? presetNameInput.value.trim() : '';
            if (!name) {
                throw new Error('Inserire un nome per il preset.');
            }
            const visibility = presetVisibilitySelect ? presetVisibilitySelect.value : 'private';
            const columns = presetColumnsSelect
                ? Array.from(presetColumnsSelect.selectedOptions).map((option) => option.value)
                : [];
            const allowedRoles = presetRoleSelect
                ? Array.from(presetRoleSelect.selectedOptions).map((option) => option.value)
                : [];
            const tags = csvToArray(presetTagsInput ? presetTagsInput.value : '', false);
            const filters = getFilterPayload();

            return {
                sheet_id: sheetContext.id,
                name,
                visibility,
                allowed_roles: visibility === 'role' ? allowedRoles : [],
                columns,
                tags,
                filters,
            };
        };

        const updateTagsPreview = (activePreset) => {
            if (!presetTagsPreview) {
                return;
            }
            presetTagsPreview.innerHTML = '';
            const tagsSource = activePreset && Array.isArray(activePreset.tags) && activePreset.tags.length
                ? activePreset.tags
                : Array.from(new Set(presetState.list.flatMap((preset) => Array.isArray(preset.tags) ? preset.tags : [])));
            if (!tagsSource.length) {
                return;
            }
            tagsSource.forEach((tag) => {
                const badge = document.createElement('span');
                badge.className = 'badge bg-light text-dark border';
                badge.textContent = tag;
                presetTagsPreview.appendChild(badge);
            });
        };

        const highlightActivePreset = () => {
            if (!presetListEl) {
                return;
            }
            presetListEl.querySelectorAll('[data-preset-id]').forEach((item) => {
                const itemId = Number.parseInt(item.dataset.presetId || '0', 10);
                item.classList.toggle('active', presetState.activeId === itemId);
            });
        };

        const updateActivePresetLabel = () => {
            if (!presetActiveLabel) {
                return;
            }
            const activePreset = presetState.list.find((preset) => preset.id === presetState.activeId);
            if (activePreset) {
                presetActiveLabel.textContent = `Preset attivo: ${activePreset.name}`;
                presetActiveLabel.classList.remove('bg-light');
                presetActiveLabel.classList.add('bg-success', 'text-white');
            } else {
                presetActiveLabel.textContent = 'Nessun preset attivo';
                presetActiveLabel.classList.add('bg-light');
                presetActiveLabel.classList.remove('bg-success', 'text-white');
            }
            updateTagsPreview(activePreset || null);
            highlightActivePreset();
        };

        const clearFiltersPlugin = () => {
            if (!filtersPlugin) {
                return;
            }
            if (typeof filtersPlugin.clearConditions === 'function') {
                filtersPlugin.clearConditions();
            } else {
                for (let column = 0; column < hot.countCols(); column += 1) {
                    filtersPlugin.removeConditions(column);
                }
            }
            filtersPlugin.filter();
        };

        const applyPresetFilters = (filters) => {
            clearFiltersPlugin();
            if (!filtersPlugin || !Array.isArray(filters) || filters.length === 0) {
                return;
            }
            filters.forEach((filter) => {
                const columnIndex = columnIndexFromLabel(filter.column);
                const conditionName = operatorMap[filter.operator] || 'contains';
                if (columnIndex >= 0) {
                    filtersPlugin.addCondition(columnIndex, conditionName, [filter.value], 'conjunction');
                }
            });
            filtersPlugin.filter();
        };

        const applyPresetColumns = (columns) => {
            if (!hiddenColumnsPlugin) {
                return;
            }
            const columnCount = hot.countCols();
            const allColumns = Array.from({ length: columnCount }, (_, index) => index);
            hiddenColumnsPlugin.showColumns(allColumns);
            if (!Array.isArray(columns) || columns.length === 0) {
                hot.render();
                return;
            }
            const visibleSet = new Set(
                columns
                    .map((columnLabel) => columnIndexFromLabel(columnLabel))
                    .filter((index) => Number.isInteger(index) && index >= 0)
            );
            const toHide = allColumns.filter((index) => !visibleSet.has(index));
            if (toHide.length > 0) {
                hiddenColumnsPlugin.hideColumns(toHide);
            }
            hot.render();
        };

        const applyPresetToGrid = (preset) => {
            applyPresetColumns(preset.columns);
            applyPresetFilters(preset.filters);
        };

        const renderPresetList = () => {
            if (!presetListEl || !presetEmptyState) {
                return;
            }
            presetListEl.innerHTML = '';
            if (!presetState.list.length) {
                presetEmptyState.classList.remove('d-none');
                updateActivePresetLabel();
                return;
            }
            presetEmptyState.classList.add('d-none');
            presetState.list.forEach((preset) => {
                const item = document.createElement('li');
                item.className = 'list-group-item d-flex justify-content-between align-items-start gap-3';
                item.dataset.presetId = String(preset.id);

                const infoWrapper = document.createElement('div');
                const title = document.createElement('div');
                title.className = 'fw-semibold';
                title.textContent = preset.name;
                const meta = document.createElement('div');
                meta.className = 'text-muted small';
                const visibilityLabel = preset.visibility === 'global'
                    ? 'Globale'
                    : (preset.visibility === 'role' ? 'Ruoli CRM' : 'Privato');
                meta.textContent = visibilityLabel;
                if (preset.visibility === 'role' && Array.isArray(preset.allowed_roles) && preset.allowed_roles.length) {
                    const rolesLine = document.createElement('div');
                    rolesLine.className = 'mt-1';
                    preset.allowed_roles.forEach((role) => {
                        const badge = document.createElement('span');
                        badge.className = 'badge bg-secondary-subtle text-secondary-emphasis me-1 preset-role-pill';
                        badge.textContent = role;
                        rolesLine.appendChild(badge);
                    });
                    infoWrapper.appendChild(rolesLine);
                }
                infoWrapper.appendChild(title);
                infoWrapper.appendChild(meta);
                if (Array.isArray(preset.tags) && preset.tags.length) {
                    const tagsRow = document.createElement('div');
                    tagsRow.className = 'mt-2';
                    preset.tags.forEach((tag) => {
                        const badge = document.createElement('span');
                        badge.className = 'badge bg-light text-dark border me-1';
                        badge.textContent = tag;
                        tagsRow.appendChild(badge);
                    });
                    infoWrapper.appendChild(tagsRow);
                }

                const actions = document.createElement('div');
                actions.className = 'd-flex flex-column gap-2 align-items-end';
                const applyBtn = document.createElement('button');
                applyBtn.type = 'button';
                applyBtn.className = 'btn btn-outline-primary btn-sm';
                applyBtn.textContent = 'Applica';
                applyBtn.dataset.presetAction = 'apply';
                applyBtn.dataset.presetId = String(preset.id);
                actions.appendChild(applyBtn);
                if (preset.can_delete) {
                    const deleteBtn = document.createElement('button');
                    deleteBtn.type = 'button';
                    deleteBtn.className = 'btn btn-link btn-sm text-danger';
                    deleteBtn.textContent = 'Elimina';
                    deleteBtn.dataset.presetAction = 'delete';
                    deleteBtn.dataset.presetId = String(preset.id);
                    actions.appendChild(deleteBtn);
                }

                item.appendChild(infoWrapper);
                item.appendChild(actions);
                presetListEl.appendChild(item);
            });
            updateActivePresetLabel();
        };

        const fetchPresets = async () => {
            presetState.loading = true;
            if (presetListEl) {
                presetListEl.classList.add('opacity-50');
            }
            try {
                const response = await fetch(buildApiUrl(), {
                    method: 'GET',
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const result = await response.json();
                if (!response.ok || result.status !== 'ok') {
                    throw new Error(result.message || 'Impossibile caricare i preset.');
                }
                presetState.list = Array.isArray(result.data) ? result.data : [];
                renderPresetList();
            } catch (error) {
                console.error('Preset API error', error);
                togglePresetFeedback(error.message || 'Errore durante il caricamento dei preset.', 'danger');
            } finally {
                presetState.loading = false;
                if (presetListEl) {
                    presetListEl.classList.remove('opacity-50');
                }
            }
        };

        const savePreset = async () => {
            if (!presetSaveBtn) {
                return;
            }
            try {
                const payload = buildPresetPayload();
                presetSaveBtn.disabled = true;
                const response = await fetch(buildApiUrl(), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-Token': sheetContext.csrfToken,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify(payload),
                });
                const result = await response.json();
                if (!response.ok || result.status !== 'ok') {
                    throw new Error(result.message || 'Errore durante il salvataggio del preset.');
                }
                togglePresetFeedback('Preset salvato correttamente.', 'success');
                resetPresetForm();
                await fetchPresets();
                presetState.activeId = result.data?.id || null;
                if (result.data) {
                    applyPresetToGrid(result.data);
                }
                updateActivePresetLabel();
            } catch (error) {
                togglePresetFeedback(error.message || 'Impossibile salvare il preset.', 'danger');
            } finally {
                presetSaveBtn.disabled = false;
            }
        };

        const deletePreset = async (presetId) => {
            if (!presetId) {
                return;
            }
            try {
                const response = await fetch(buildApiUrl(), {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-Token': sheetContext.csrfToken,
                    },
                    credentials: 'same-origin',
                    body: JSON.stringify({ id: presetId }),
                });
                const result = await response.json();
                if (!response.ok || result.status !== 'ok') {
                    throw new Error(result.message || 'Impossibile eliminare il preset.');
                }
                if (presetState.activeId === presetId) {
                    presetState.activeId = null;
                    clearFiltersPlugin();
                    applyPresetColumns([]);
                }
                await fetchPresets();
                togglePresetFeedback('Preset eliminato.', 'success');
            } catch (error) {
                togglePresetFeedback(error.message || 'Errore durante la rimozione del preset.', 'danger');
            }
        };

        const handlePresetListClick = (event) => {
            const target = event.target;
            if (!(target instanceof Element)) {
                return;
            }
            const actionButton = target.closest('[data-preset-action]');
            if (!actionButton) {
                return;
            }
            const presetId = Number.parseInt(actionButton.dataset.presetId || '0', 10);
            if (!presetId) {
                return;
            }
            const targetPreset = presetState.list.find((preset) => preset.id === presetId);
            if (actionButton.dataset.presetAction === 'apply' && targetPreset) {
                presetState.activeId = presetId;
                applyPresetToGrid(targetPreset);
                updateActivePresetLabel();
                hydrateFilters(targetPreset.filters);
            }
            if (actionButton.dataset.presetAction === 'delete') {
                if (window.confirm('Eliminare definitivamente questo preset?')) {
                    deletePreset(presetId);
                }
            }
        };

        if (presetVisibilitySelect) {
            presetVisibilitySelect.addEventListener('change', () => toggleRoleWrapper());
            toggleRoleWrapper();
        }

        if (presetResetBtn) {
            presetResetBtn.addEventListener('click', () => resetPresetForm());
        }

        if (presetSaveBtn) {
            presetSaveBtn.addEventListener('click', () => savePreset());
        }

        if (presetAddFilterBtn) {
            presetAddFilterBtn.addEventListener('click', () => addFilterRow());
        }

        if (presetListEl) {
            presetListEl.addEventListener('click', handlePresetListClick);
        }

        registerFilterColumnSync();
        fetchPresets();
    })();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
