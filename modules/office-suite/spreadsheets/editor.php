<?php
declare(strict_types=1);

use App\Services\OfficeSuite\SpreadsheetService;

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
                    <p class="text-muted mb-0">Shell stile Excel con storage versione Office Suite.</p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-secondary" href="<?php echo asset('modules/office-suite/spreadsheets/index.php'); ?>">
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
                            Handsontable + HyperFormula sono attivi per calcoli client-side e serializzazione Office Suite.
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
            multiColumnSorting: true,
            licenseKey: window.HOT_LICENSE_KEY || 'non-commercial-and-evaluation',
            formulas: {
                engine: hyperFormulaInstance,
            },
        });

        applyInitialMeta(parseMetaPayload(initialMeta));
        persistState();

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

        form.addEventListener('submit', () => {
            persistState();
        });
    })();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
