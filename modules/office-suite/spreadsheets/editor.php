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
</style>
<script>
    (function () {
        const form = document.getElementById('sheet-editor-form');
        const gridField = document.getElementById('grid-state-field');
        const gridTable = document.getElementById('sheet-grid');
        if (!form || !gridField || !gridTable) {
            return;
        }

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

        populateGrid();

        form.addEventListener('submit', () => {
            serializeGrid();
        });
    })();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
