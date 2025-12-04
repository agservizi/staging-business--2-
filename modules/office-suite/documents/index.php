<?php
declare(strict_types=1);

use App\Services\OfficeSuite\DocumentService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Documenti Office Suite';
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$documentService = new DocumentService($pdo);
$documentsError = null;
$documents = [];

$statusLabels = [
    'draft' => 'Bozza',
    'review' => 'In revisione',
    'published' => 'Pubblicato',
    'archived' => 'Archiviato',
];

try {
    $documents = $documentService->listDocuments(50, $searchQuery ?: null);
} catch (Throwable $exception) {
    error_log('Office documents listing failed: ' . $exception->getMessage());
    $documentsError = 'Impossibile caricare i documenti. Verifica la nuova migrazione office suite.';
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h1 class="h4 mb-1">Documenti</h1>
                <p class="text-muted mb-0">Repository centralizzato: template, bozze e contratti pronti per il nuovo editor.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo asset('modules/office-suite/index.php'); ?>">
                    <i class="fa-solid fa-grip me-2"></i>Hub Office
                </a>
                <a class="btn btn-primary" href="<?php echo asset('modules/office-suite/documents/editor.php'); ?>">
                    <i class="fa-solid fa-file-circle-plus me-2"></i>Nuovo documento
                </a>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white border-0 d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <p class="text-uppercase small fw-semibold text-muted mb-0">Workspace</p>
                    <h2 class="h6 mb-0">Vista elenco</h2>
                </div>
                <form class="input-group input-group-sm" method="get" style="max-width: 360px;">
                    <span class="input-group-text bg-transparent border-end-0"><i class="fa-solid fa-magnifying-glass"></i></span>
                    <input type="search" class="form-control border-start-0" name="q" value="<?php echo sanitize_output($searchQuery); ?>" placeholder="Cerca titolo o categoria">
                    <?php if ($searchQuery !== ''): ?>
                        <button class="btn btn-outline-secondary" type="button" onclick="window.location.href='index.php';">Reset</button>
                    <?php endif; ?>
                </form>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">Titolo</th>
                            <th scope="col">Categoria</th>
                            <th scope="col">Ultima modifica</th>
                            <th scope="col">Stato</th>
                            <th scope="col" class="text-end">Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($documentsError !== null): ?>
                            <tr>
                                <td colspan="5">
                                    <div class="alert alert-warning mb-0" role="alert"><?php echo sanitize_output($documentsError); ?></div>
                                </td>
                            </tr>
                        <?php elseif (!$documents): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">Nessun documento disponibile. Premi "Nuovo documento" per iniziare.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($documents as $document): ?>
                                <tr>
                                    <td class="fw-semibold text-truncate">
                                        <a class="link-dark text-decoration-none" href="<?php echo asset('modules/office-suite/documents/editor.php?id=' . (int) $document['id']); ?>">
                                            <?php echo sanitize_output($document['titolo']); ?>
                                        </a>
                                    </td>
                                    <td><?php echo sanitize_output($document['categoria']); ?></td>
                                    <td class="text-muted small"><?php echo sanitize_output(format_datetime_locale($document['updated_at'] ?? null)); ?></td>
                                    <td>
                                        <?php $statusKey = strtolower((string) ($document['stato'] ?? 'draft')); ?>
                                        <span class="badge bg-light text-dark border"><?php echo sanitize_output($statusLabels[$statusKey] ?? ucfirst($statusKey)); ?></span>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="<?php echo asset('modules/office-suite/documents/editor.php?id=' . (int) $document['id']); ?>">
                                            Apri
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white border-top-0 text-muted small">
                Funzionalita' avanzate (filtri, tag, colonne personalizzate) sono pronte per collegarsi alle API Office Suite e ai permessi CRM.
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h6 mb-3">Prossimi step</h2>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-light h-100">
                            <p class="small text-muted mb-1">Template dinamici</p>
                            <p class="small mb-0">Merge di variabili CRM direttamente dentro i documenti con placeholder protetti.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-light h-100">
                            <p class="small text-muted mb-1">Versioning</p>
                            <p class="small mb-0">Snapshot automatici e diff visivo tra revisioni successive.</p>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="p-3 rounded-3 bg-light h-100">
                            <p class="small text-muted mb-1">Commenti contestuali</p>
                            <p class="small mb-0">Thread sul paragrafo selezionato con menzione utenti e log attività.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
