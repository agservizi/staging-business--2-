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

$formError = null;
$sheet = null;
$latestRevision = null;
$gridState = '';
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
        $formData['title'] = (string) ($sheet['titolo'] ?? '');
        $formData['category'] = (string) ($sheet['categoria'] ?? 'Standard');
        $formData['status'] = (string) ($sheet['stato'] ?? 'draft');
        $formData['tags'] = $sheet['tags'] ? implode(', ', (array) $sheet['tags']) : '';
    }
}

if ($gridState === '' || $gridState === '[]') {
    $emptyMatrix = [];
    for ($row = 0; $row < 10; $row++) {
        $emptyMatrix[$row] = array_fill(0, 8, '');
    }
    $gridState = json_encode($emptyMatrix);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    $payload = [
        'id' => isset($_POST['sheet_id']) && $_POST['sheet_id'] !== '' ? (int) $_POST['sheet_id'] : null,
        'title' => $_POST['title'] ?? '',
        'category' => $_POST['category'] ?? 'Standard',
        'status' => $_POST['status'] ?? 'draft',
        'tags' => $_POST['tags'] ?? '',
        'grid' => $_POST['grid'] ?? '',
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
                    <div class="excel-ribbon d-flex flex-wrap align-items-center gap-4">
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
                        <div class="ribbon-group">
                            <p class="small text-uppercase fw-semibold text-muted mb-1">Inserisci</p>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Struttura foglio">
                                <button class="btn btn-outline-secondary" type="button" data-grid-action="add-row" title="Aggiungi riga"><i class="fa-solid fa-plus"></i> R</button>
                                <button class="btn btn-outline-secondary" type="button" data-grid-action="add-column" title="Aggiungi colonna"><i class="fa-solid fa-plus"></i> C</button>
                            </div>
                        </div>
                        <div class="ms-auto text-muted small d-flex align-items-center gap-2">
                            <i class="fa-solid fa-table-list text-primary"></i>
                            <span>Barra in stile Office pronta per il grid engine.</span>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="sheet-layout d-flex flex-column">
                        <div class="formula-bar d-flex align-items-center gap-2">
                            <span class="badge bg-primary text-white">fx</span>
                            <input class="form-control form-control-sm" type="text" placeholder="=SOMMA(A1:A10)" disabled>
                            <?php if ($latestRevision): ?>
                                <span class="text-muted small">Versione <?php echo (int) $latestRevision['versione']; ?> · <?php echo sanitize_output(format_datetime_locale($latestRevision['created_at'] ?? null)); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="grid flex-grow-1">
                            <table id="sheet-grid" class="table table-bordered table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th class="bg-light">&nbsp;</th>
                                        <?php for ($col = 0; $col < 8; $col++): ?>
                                            <th class="bg-light"><?php echo chr(65 + $col); ?></th>
                                        <?php endfor; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($row = 1; $row <= 10; $row++): ?>
                                        <tr>
                                            <th class="bg-light"><?php echo $row; ?></th>
                                            <?php for ($col = 0; $col < 8; $col++): ?>
                                                <td contenteditable="true"></td>
                                            <?php endfor; ?>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="grid-hint text-muted small p-3">
                            Questa tabella placeholder sara' sostituita da Handsontable/Luckysheet con formule e data-link. Nel frattempo viene serializzata in JSON.
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </main>
</div>
<style>
    .spreadsheet .toolbar-group {
        min-width: 150px;
    }
    .excel-ribbon .ribbon-group {
        min-width: 180px;
    }
    .excel-ribbon .btn {
        min-width: 36px;
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
        overflow: auto;
        background: #fff;
    }
    .grid table {
        min-width: 100%;
    }
    .grid td:focus {
        outline: 2px solid #4c6ef5;
    }
    .grid td.active-cell {
        outline: 2px solid #0d6efd;
        box-shadow: inset 0 0 0 1px rgba(13,110,253,0.4);
    }
    .grid.grid-needs-selection {
        animation: grid-pulse 0.35s ease-in-out 0s 2;
    }
    @keyframes grid-pulse {
        0% { box-shadow: 0 0 0 0 rgba(13,110,253,0); }
        50% { box-shadow: 0 0 0 4px rgba(13,110,253,0.35); }
        100% { box-shadow: 0 0 0 0 rgba(13,110,253,0); }
    }
</style>
<script>
    (function () {
        const form = document.getElementById('sheet-editor-form');
        const gridField = document.getElementById('grid-state-field');
        const gridTable = document.getElementById('sheet-grid');
        const toolbarButtons = document.querySelectorAll('[data-grid-action]');
        if (!form || !gridField || !gridTable) {
            return;
        }

        const gridBody = gridTable.querySelector('tbody');
        const headerRow = gridTable.querySelector('thead tr');
        let activeCell = null;

        const attachCellEvents = (cellEl) => {
            if (!cellEl) {
                return;
            }
            cellEl.addEventListener('focus', () => {
                setActiveCell(cellEl);
            });
            cellEl.addEventListener('click', () => {
                setActiveCell(cellEl);
            });
        };

        const setActiveCell = (cell) => {
            if (activeCell === cell) {
                return;
            }
            if (activeCell) {
                activeCell.classList.remove('active-cell');
            }
            activeCell = cell;
            if (activeCell) {
                activeCell.classList.add('active-cell');
            }
        };

        gridTable.querySelectorAll('tbody td').forEach((cell) => attachCellEvents(cell));

        const populateGrid = () => {
            if (!gridField.value) {
                return;
            }

            try {
                const matrix = JSON.parse(gridField.value);
                if (!Array.isArray(matrix)) {
                    return;
                }
                const rows = gridTable.querySelectorAll('tbody tr');
                rows.forEach((rowEl, rowIndex) => {
                    const cells = rowEl.querySelectorAll('td');
                    cells.forEach((cellEl, cellIndex) => {
                        const value = matrix[rowIndex] && matrix[rowIndex][cellIndex] ? String(matrix[rowIndex][cellIndex]) : '';
                        cellEl.textContent = value;
                    });
                });
            } catch (error) {
                console.warn('Impossibile decodificare la matrice del foglio:', error);
            }
        };

        const serializeGrid = () => {
            const rowsData = [];
            gridTable.querySelectorAll('tbody tr').forEach((rowEl) => {
                const rowData = [];
                rowEl.querySelectorAll('td').forEach((cellEl) => {
                    rowData.push(cellEl.textContent.trim());
                });
                rowsData.push(rowData);
            });
            gridField.value = JSON.stringify(rowsData);
        };

        const ensureActiveCell = () => {
            if (activeCell) {
                return true;
            }
            gridTable.classList.add('grid-needs-selection');
            setTimeout(() => gridTable.classList.remove('grid-needs-selection'), 400);
            return false;
        };

        const toggleStyle = (property, activeValue, fallbackValue = '') => {
            if (!ensureActiveCell()) {
                return;
            }
            const current = activeCell.style[property];
            activeCell.style[property] = current === activeValue ? fallbackValue : activeValue;
        };

        const setStyle = (property, value) => {
            if (!ensureActiveCell()) {
                return;
            }
            activeCell.style[property] = value;
        };

        const removeFormatting = () => {
            if (!ensureActiveCell()) {
                return;
            }
            activeCell.removeAttribute('style');
        };

        const parseNumber = (raw) => {
            if (!raw) {
                return null;
            }
            const normalized = raw.replace(/[^0-9,.-]/g, '').replace(',', '.');
            const parsed = Number(normalized);
            return Number.isFinite(parsed) ? parsed : null;
        };

        const formatCurrency = () => {
            if (!ensureActiveCell()) {
                return;
            }
            const raw = activeCell.textContent.trim();
            const value = parseNumber(raw);
            if (value === null) {
                return;
            }
            activeCell.textContent = value.toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
            activeCell.style.textAlign = 'right';
        };

        const formatPercentage = () => {
            if (!ensureActiveCell()) {
                return;
            }
            const raw = activeCell.textContent.trim();
            let value = parseNumber(raw);
            if (value === null) {
                return;
            }
            if (value > 1) {
                value = value / 100;
            }
            activeCell.textContent = (value).toLocaleString('it-IT', { style: 'percent', minimumFractionDigits: 2 });
            activeCell.style.textAlign = 'right';
        };

        const columnLabelFromIndex = (index) => {
            let label = '';
            let current = index;
            while (current >= 0) {
                label = String.fromCharCode((current % 26) + 65) + label;
                current = Math.floor(current / 26) - 1;
            }
            return label;
        };

        const getColumnCount = () => {
            return Math.max(headerRow.querySelectorAll('th').length - 1, 0);
        };

        const addRow = () => {
            const columnCount = getColumnCount();
            const newRow = document.createElement('tr');
            const rowIndex = gridBody.querySelectorAll('tr').length + 1;
            const headerCell = document.createElement('th');
            headerCell.className = 'bg-light';
            headerCell.textContent = rowIndex;
            newRow.appendChild(headerCell);

            for (let col = 0; col < columnCount; col++) {
                const cell = document.createElement('td');
                cell.contentEditable = 'true';
                attachCellEvents(cell);
                newRow.appendChild(cell);
            }

            gridBody.appendChild(newRow);
        };

        const addColumn = () => {
            const columnCount = getColumnCount();
            const newHeader = document.createElement('th');
            newHeader.className = 'bg-light';
            newHeader.textContent = columnLabelFromIndex(columnCount);
            headerRow.appendChild(newHeader);

            gridBody.querySelectorAll('tr').forEach((rowEl) => {
                const cell = document.createElement('td');
                cell.contentEditable = 'true';
                attachCellEvents(cell);
                rowEl.appendChild(cell);
            });
        };

        toolbarButtons.forEach((button) => {
            button.addEventListener('click', () => {
                const action = button.dataset.gridAction;
                switch (action) {
                    case 'bold':
                        toggleStyle('fontWeight', '700');
                        break;
                    case 'italic':
                        toggleStyle('fontStyle', 'italic');
                        break;
                    case 'underline':
                        toggleStyle('textDecorationLine', 'underline');
                        break;
                    case 'highlight':
                        toggleStyle('backgroundColor', 'rgb(255, 243, 205)');
                        break;
                    case 'currency':
                        formatCurrency();
                        break;
                    case 'percent':
                        formatPercentage();
                        break;
                    case 'clear-format':
                        removeFormatting();
                        break;
                    case 'align-left':
                        setStyle('textAlign', 'left');
                        break;
                    case 'align-center':
                        setStyle('textAlign', 'center');
                        break;
                    case 'align-right':
                        setStyle('textAlign', 'right');
                        break;
                    case 'add-row':
                        addRow();
                        break;
                    case 'add-column':
                        addColumn();
                        break;
                    default:
                        break;
                }
            });
        });

        populateGrid();

        form.addEventListener('submit', () => {
            serializeGrid();
        });
    })();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
