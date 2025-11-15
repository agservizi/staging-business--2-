<?php

use App\Services\ServiziWeb\VisureService;
use Throwable;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Visure & Catasto';
$docUrl = 'https://console.openapi.com/it/apis/catasto/documentation?_gl=1*1bujcfo*_gcl_aw*R0NMLjE3NjE2ODkzMDkuQ2p3S0NBancwNEhJQmhCOEVpd0E4akdOYlNqZ1JyYjQ5R3BaU2xwWC1vQmJVQ1hhX1hteHh2VkhYc1pUZ3lPOWFLTThQSzJHNHVNaUF4b0NZQ2NRQXZEX0J3RQ..*_gcl_au*NDE1MTk4OTI3LjE3NjA2NDM1MDk.*_ga*MjA4NjEzNDgyOC4xNzYwNjQzNTEy*_ga_NWG43T6K5G*czE3NjIxMDcyNjMkbzckZzAkdDE3NjIxMDcyNjMkajYwJGwwJGgw#tag/Visura-Catastale/paths/~1visura_catastale~1%7Bid%7D~1documento/get';
$sampleRequest = <<<HTTP
GET https://api.openapi.com/catasto/v1/visura_catastale/{id}/documento
Authorization: Bearer <token>
Accept: application/pdf
HTTP;

$apiKeyValue = env('OPENAPI_CATASTO_API_KEY') ?? env('OPENAPI_SANDBOX_API_KEY') ?? '';
$tokenValue = env('OPENAPI_CATASTO_TOKEN') ?? env('OPENAPI_CATASTO_SANDBOX_TOKEN') ?? '';
$apiKeyAvailable = trim((string) $apiKeyValue) !== '';
$tokenAvailable = trim((string) $tokenValue) !== '';
$catastoConfigured = $apiKeyAvailable && $tokenAvailable;
$baseUri = (string) (env('OPENAPI_CATASTO_BASE_URI', 'https://api.openapi.com/catasto/v1') ?: 'https://api.openapi.com/catasto/v1');

$statusLabels = [
    'in_erogazione' => 'In elaborazione',
    'evasa' => 'Evasa',
    'errore' => 'Errore',
];

$statusBadgeClass = [
    'in_erogazione' => 'bg-warning text-dark',
    'evasa' => 'bg-success',
    'errore' => 'bg-danger',
];

$visureRecords = [];
$visureError = null;
$searchTerm = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$statusFilter = isset($_GET['status']) && array_key_exists((string) $_GET['status'], $statusLabels) ? (string) $_GET['status'] : '';
$codiceFiscaleFilter = isset($_GET['codice_fiscale']) ? trim((string) $_GET['codice_fiscale']) : '';
$foglioFilter = isset($_GET['foglio']) ? trim((string) $_GET['foglio']) : '';
$particellaFilter = isset($_GET['particella']) ? trim((string) $_GET['particella']) : '';
$subalternoFilter = isset($_GET['subalterno']) ? trim((string) $_GET['subalterno']) : '';
$filters = [];

if ($searchTerm !== '') {
    $filters['search'] = $searchTerm;
}

if ($statusFilter !== '') {
    $filters['status'] = $statusFilter;
}

if ($codiceFiscaleFilter !== '') {
    $filters['codice_fiscale'] = $codiceFiscaleFilter;
}

if ($foglioFilter !== '') {
    $filters['foglio'] = $foglioFilter;
}

if ($particellaFilter !== '') {
    $filters['particella'] = $particellaFilter;
}

if ($subalternoFilter !== '') {
    $filters['subalterno'] = $subalternoFilter;
}

try {
    $visureService = new VisureService($pdo, dirname(__DIR__, 3));
    $visureRecords = $visureService->listVisure($filters);
} catch (Throwable $exception) {
    $visureError = $exception->getMessage();
}

$visureCount = count($visureRecords);
$hasActiveFilters = $searchTerm !== '' || $statusFilter !== '' || $codiceFiscaleFilter !== '' || $foglioFilter !== '' || $particellaFilter !== '' || $subalternoFilter !== '';
$activeFilterLabels = [];
if ($searchTerm !== '') {
    $activeFilterLabels[] = 'Testo: "' . $searchTerm . '"';
}
if ($statusFilter !== '') {
    $activeFilterLabels[] = 'Stato: ' . ($statusLabels[$statusFilter] ?? $statusFilter);
}
if ($codiceFiscaleFilter !== '') {
    $activeFilterLabels[] = 'Codice fiscale: ' . strtoupper($codiceFiscaleFilter);
}
if ($foglioFilter !== '') {
    $activeFilterLabels[] = 'Foglio: ' . $foglioFilter;
}
if ($particellaFilter !== '') {
    $activeFilterLabels[] = 'Particella: ' . $particellaFilter;
}
if ($subalternoFilter !== '') {
    $activeFilterLabels[] = 'Subalterno: ' . $subalternoFilter;
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Visure catastali</h1>
                <p class="text-muted mb-0">Monitora le pratiche importate dal Catasto, archivia i documenti PDF e gestisci le notifiche ai clienti.</p>
            </div>
            <div class="toolbar-actions d-flex flex-wrap gap-2">
                <a class="btn btn-primary" href="create.php">
                    <i class="fa-solid fa-circle-plus me-2"></i>Nuova visura
                </a>
            </div>
        </div>
        <?php if ($visureError !== null): ?>
        <div class="alert alert-danger" role="alert">
            <strong>Errore:</strong> <?php echo sanitize_output($visureError); ?>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12 col-xxl-9 d-flex flex-column gap-4">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Filtri</h2>
                    </div>
                    <div class="card-body">
                        <form class="row g-3 align-items-end" method="get" autocomplete="off">
                            <div class="col-sm-6 col-lg-4">
                                <label class="form-label" for="filter-search">Ricerca libera</label>
                                <input type="search" class="form-control" id="filter-search" name="search" placeholder="ID visura, cliente o owner" value="<?php echo sanitize_output($searchTerm); ?>">
                            </div>
                            <div class="col-sm-6 col-lg-2">
                                <label class="form-label" for="filter-status">Stato</label>
                                <select class="form-select" id="filter-status" name="status">
                                    <option value="">Tutti</option>
                                    <?php foreach ($statusLabels as $value => $label): ?>
                                        <option value="<?php echo sanitize_output($value); ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label" for="filter-codice-fiscale">Codice fiscale</label>
                                <input type="text" class="form-control" id="filter-codice-fiscale" name="codice_fiscale" placeholder="RSSMRA80A01H501Z" value="<?php echo sanitize_output(strtoupper($codiceFiscaleFilter)); ?>">
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label" for="filter-foglio">Foglio</label>
                                <input type="text" class="form-control" id="filter-foglio" name="foglio" placeholder="es. 112" value="<?php echo sanitize_output($foglioFilter); ?>">
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label" for="filter-particella">Particella</label>
                                <input type="text" class="form-control" id="filter-particella" name="particella" placeholder="es. 345" value="<?php echo sanitize_output($particellaFilter); ?>">
                            </div>
                            <div class="col-sm-6 col-lg-3">
                                <label class="form-label" for="filter-subalterno">Subalterno</label>
                                <input type="text" class="form-control" id="filter-subalterno" name="subalterno" placeholder="es. 12" value="<?php echo sanitize_output($subalternoFilter); ?>">
                            </div>
                            <div class="col-12 col-lg-3 d-flex align-items-end gap-2">
                                <button class="btn btn-warning text-dark flex-fill" type="submit">
                                    <i class="fa-solid fa-filter me-1"></i>Applica filtri
                                </button>
                                <?php if ($hasActiveFilters): ?>
                                <a class="btn btn-outline-secondary" href="index.php" title="Pulisci filtri">
                                    <i class="fa-solid fa-rotate-left"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </form>
                        <?php if ($hasActiveFilters && $activeFilterLabels): ?>
                        <div class="mt-3 small text-muted d-flex align-items-center flex-wrap gap-1">
                            <i class="fa-solid fa-filter me-1"></i>
                            <span>Filtri attivi:</span>
                            <?php foreach ($activeFilterLabels as $label): ?>
                                <span class="badge bg-light text-body ms-1"><?php echo sanitize_output($label); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card ag-card flex-grow-1">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h2 class="h5 mb-0">Visure sincronizzate</h2>
                        <span class="badge ag-badge"><?php echo $visureCount; ?> risultati</span>
                    </div>
                    <div class="card-body">
                        <?php if (!$catastoConfigured): ?>
                        <div class="alert alert-warning" role="alert">
                            Configura <code>OPENAPI_CATASTO_API_KEY</code> e <code>OPENAPI_CATASTO_TOKEN</code> (o le variabili sandbox legacy) nel file <code>.env</code> per abilitare la sincronizzazione automatica. Endpoint attuale: <span class="fw-semibold"><?php echo sanitize_output($baseUri); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if (!$visureRecords): ?>
                        <div class="text-center py-5">
                            <?php if ($hasActiveFilters): ?>
                            <p class="text-muted mb-3">Nessuna visura corrisponde ai filtri selezionati.</p>
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="fa-solid fa-broom me-2"></i>Rimuovi filtri
                            </a>
                            <?php else: ?>
                            <p class="text-muted mb-4">Non sono presenti visure sincronizzate al momento. Avvia una sincronizzazione o registra una nuova visura manualmente.</p>
                            <div class="d-flex justify-content-center gap-2 flex-wrap">
                                <a href="create.php" class="btn btn-primary">
                                    <i class="fa-solid fa-circle-plus me-2"></i>Nuova visura
                                </a>
                                <form action="sync.php" method="post" class="d-inline" autocomplete="off">
                                    <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                                    <button type="submit" class="btn btn-outline-secondary" <?php echo $catastoConfigured ? '' : 'disabled'; ?>>
                                        <i class="fa-solid fa-rotate me-2"></i>Sincronizza ora
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-dark table-hover align-middle" data-datatable="true">
                                <thead>
                                    <tr>
                                        <th scope="col">Visura</th>
                                        <th scope="col">Cliente</th>
                                        <th scope="col">Stato</th>
                                        <th scope="col">Tipo</th>
                                        <th scope="col">Richiesta</th>
                                        <th scope="col">Documento</th>
                                        <th scope="col">Aggiornata il</th>
                                        <th scope="col" class="text-end">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($visureRecords as $visura): ?>
                                    <?php
                                        $statusKey = $visura['stato'] ?? 'in_erogazione';
                                        $statusLabel = $statusLabels[$statusKey] ?? ucfirst(str_replace('_', ' ', (string) $statusKey));
                                        $badgeClass = $statusBadgeClass[$statusKey] ?? 'bg-secondary';
                                        $documentAvailable = !empty($visura['documento_path']);
                                        $documentUrl = $documentAvailable ? base_url($visura['documento_path']) : null;
                                        $richiesta = $visura['richiesta_timestamp'] ?? null;
                                        $tipoVisura = $visura['tipo_visura'] ?? ($visura['entita'] ?? '');
                                        $sanitizedVisuraId = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($visura['visura_id'] ?? uniqid()));
                                        $downloadFormId = 'download-form-' . $sanitizedVisuraId;
                                        $deleteFormId = 'delete-form-' . $sanitizedVisuraId;
                                    ?>
                                    <tr>
                                        <td class="fw-semibold">
                                            <a href="view.php?id=<?php echo urlencode((string) $visura['visura_id']); ?>" class="text-decoration-none text-body">
                                                <?php echo sanitize_output((string) $visura['visura_id']); ?>
                                            </a>
                                            <?php if (!empty($visura['owner'])): ?>
                                            <div class="small text-muted">Owner: <?php echo sanitize_output((string) $visura['owner']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small text-muted"><?php echo sanitize_output($visura['cliente_display'] ?? 'Non associato'); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $badgeClass; ?> text-uppercase">
                                                <?php echo sanitize_output($statusLabel); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-nowrap"><?php echo sanitize_output($tipoVisura !== '' ? $tipoVisura : '—'); ?></span>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <?php echo sanitize_output(format_datetime_locale($richiesta)); ?>
                                            </div>
                                            <?php if (!empty($visura['esito'])): ?>
                                            <div class="small text-muted">Esito: <?php echo sanitize_output($visura['esito']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($documentAvailable): ?>
                                            <a href="<?php echo sanitize_output($documentUrl); ?>" target="_blank" rel="noopener" class="text-decoration-none">
                                                <i class="fa-solid fa-file-pdf me-1 text-danger"></i><?php echo sanitize_output((string) $visura['documento_nome']); ?>
                                            </a>
                                            <?php else: ?>
                                            <span class="text-muted small">Non archiviato</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small"><?php echo sanitize_output(format_datetime_locale($visura['updated_at'] ?? null)); ?></div>
                                            <?php if (!empty($visura['documento_aggiornato_il'])): ?>
                                            <div class="small text-muted">Documento: <?php echo sanitize_output(format_datetime_locale($visura['documento_aggiornato_il'])); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <form action="download_document.php" method="post" id="<?php echo sanitize_output($downloadFormId); ?>" class="d-none">
                                                <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="request_id" value="<?php echo sanitize_output((string) $visura['visura_id']); ?>">
                                                <input type="hidden" name="archive" value="1">
                                                <input type="hidden" name="redirect" value="index">
                                            </form>
                                            <form action="delete.php" method="post" id="<?php echo sanitize_output($deleteFormId); ?>" class="d-none">
                                                <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                                                <input type="hidden" name="visura_id" value="<?php echo sanitize_output((string) $visura['visura_id']); ?>">
                                            </form>
                                            <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap">
                                                <a href="view.php?id=<?php echo urlencode((string) $visura['visura_id']); ?>" class="btn btn-icon btn-soft-accent btn-sm" title="Dettagli">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <button type="submit" form="<?php echo sanitize_output($downloadFormId); ?>" class="btn btn-icon btn-soft-accent btn-sm" title="Scarica PDF e aggiorna archivio" <?php echo $catastoConfigured ? '' : 'disabled'; ?>>
                                                    <i class="fa-solid fa-file-arrow-down"></i>
                                                </button>
                                                <button type="submit" form="<?php echo sanitize_output($deleteFormId); ?>" class="btn btn-icon btn-soft-danger btn-sm" title="Elimina visura" onclick="return confirm('Confermi l\'eliminazione della visura selezionata?');">
                                                    <i class="fa-solid fa-trash"></i>
                                                </button>
                                            </div>
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
            <div class="col-12 col-xxl-3 d-flex flex-column gap-4">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Sincronizzazione API</h2>
                    </div>
                    <div class="card-body d-flex flex-column gap-3">
                        <form action="sync.php" method="post" class="d-flex flex-column gap-3" autocomplete="off">
                            <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch" name="auto_download" id="auto-download-switch" value="1" <?php echo $catastoConfigured ? '' : 'disabled'; ?>>
                                <label class="form-check-label" for="auto-download-switch">Scarica automaticamente il PDF quando disponibile</label>
                            </div>
                            <button type="submit" class="btn btn-outline-primary" <?php echo $catastoConfigured ? '' : 'disabled'; ?>>
                                <i class="fa-solid fa-rotate me-2"></i>Sincronizza ora
                            </button>
                        </form>
                        <p class="text-muted small mb-0">La sincronizzazione recupera le pratiche disponibili sul portale OpenAPI e aggiorna stato, dettagli e documenti archiviati.</p>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Stato integrazione</h2>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0 small">
                            <dt class="col-6 text-muted">API Key</dt>
                            <dd class="col-6 text-<?php echo $apiKeyAvailable ? 'success' : 'danger'; ?> fw-semibold"><?php echo $apiKeyAvailable ? 'Configurata' : 'Assente'; ?></dd>
                            <dt class="col-6 text-muted">Token Catasto</dt>
                            <dd class="col-6 text-<?php echo $tokenAvailable ? 'success' : 'danger'; ?> fw-semibold"><?php echo $tokenAvailable ? 'Configurato' : 'Assente'; ?></dd>
                            <dt class="col-6 text-muted">Endpoint</dt>
                            <dd class="col-6"><code class="text-break"><?php echo sanitize_output($baseUri); ?></code></dd>
                        </dl>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
