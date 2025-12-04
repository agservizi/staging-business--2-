<?php
declare(strict_types=1);

use App\Services\OfficeSuite\SpreadsheetService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Fogli Office Suite';
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$sheetService = new SpreadsheetService($pdo);
$sheets = [];
$sheetsError = null;

$statusLabels = [
    'draft' => 'Bozza',
    'review' => 'Revisione',
    'published' => 'Pubblicato',
    'archived' => 'Archiviato',
];

try {
    $sheets = $sheetService->listSheets(50, $searchQuery ?: null);
} catch (Throwable $exception) {
    error_log('Office sheets listing failed: ' . $exception->getMessage());
    $sheetsError = 'Impossibile caricare i fogli. Controlla la nuova migrazione Office Suite.';
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h1 class="h4 mb-1">Fogli dinamici</h1>
                <p class="text-muted mb-0">Workspace pronto per l'engine stile Excel: formule, filtri e connessioni dati.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo asset('modules/office-suite/index.php'); ?>">
                    <i class="fa-solid fa-grip me-2"></i>Hub Office
                </a>
                <a class="btn btn-primary" href="<?php echo asset('modules/office-suite/spreadsheets/editor.php'); ?>">
                    <i class="fa-solid fa-table me-2"></i>Nuovo foglio
                </a>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <p class="text-uppercase small fw-semibold text-muted mb-0">Workspace</p>
                    <h2 class="h6 mb-0">Fogli condivisi</h2>
                </div>
                <form class="input-group input-group-sm" method="get" style="max-width: 360px;">
                    <span class="input-group-text bg-transparent border-end-0"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="search" class="form-control border-start-0" name="q" value="<?php echo sanitize_output($searchQuery); ?>" placeholder="Cerca titolo o categoria">
                    <?php if ($searchQuery !== ''): ?>
                        <button class="btn btn-outline-secondary" type="button" onclick="window.location.href='index.php';">Reset</button>
                    <?php endif; ?>
                </form>
            </div>
            <div class="card-body">
                <?php if ($sheetsError !== null): ?>
                    <div class="alert alert-warning mb-0" role="alert"><?php echo sanitize_output($sheetsError); ?></div>
                <?php elseif (!$sheets): ?>
                    <p class="text-muted mb-0 text-center">Nessun foglio presente. Premi "Nuovo foglio" per crearne uno.</p>
                <?php else: ?>
                    <div class="row g-3">
                        <?php foreach ($sheets as $sheet): ?>
                            <div class="col-md-4">
                                <div class="card border-0 shadow-sm h-100">
                                    <div class="card-body d-flex flex-column">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h2 class="h6 mb-0 text-truncate"><?php echo sanitize_output($sheet['titolo']); ?></h2>
                                            <?php $statusKey = strtolower((string) ($sheet['stato'] ?? 'draft')); ?>
                                            <span class="badge bg-success-subtle text-success"><?php echo sanitize_output($statusLabels[$statusKey] ?? ucfirst($statusKey)); ?></span>
                                        </div>
                                        <p class="text-muted small mb-2">Owner ID: <?php echo sanitize_output($sheet['owner_id'] ?? '—'); ?></p>
                                        <p class="text-muted small mb-4">Ultimo aggiornamento: <?php echo sanitize_output(format_datetime_locale($sheet['updated_at'] ?? null)); ?></p>
                                        <div class="mt-auto">
                                            <a class="btn btn-outline-success btn-sm" href="<?php echo asset('modules/office-suite/spreadsheets/editor.php?id=' . (int) $sheet['id']); ?>">
                                                Apri foglio
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6 mb-3">Componenti previsti</h2>
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="p-3 rounded-3 bg-light h-100">
                            <p class="small text-muted mb-1">Engine</p>
                            <p class="small mb-0">Handsontable/Luckysheet per editing grid con formule A1-style.</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 rounded-3 bg-light h-100">
                            <p class="small text-muted mb-1">Connettori</p>
                            <p class="small mb-0">Binding a viste SQL e API interne per popolare i dataset.</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 rounded-3 bg-light h-100">
                            <p class="small text-muted mb-1">Automazioni</p>
                            <p class="small mb-0">Trigger programmabili per inviare notifiche o aggiornare pratiche.</p>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="p-3 rounded-3 bg-light h-100">
                            <p class="small text-muted mb-1">Sicurezza</p>
                            <p class="small mb-0">Permessi cell-level e audit trail sulle modifiche.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
