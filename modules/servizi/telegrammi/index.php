<?php
declare(strict_types=1);

use App\Services\ServiziWeb\TelegrammiService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Telegrammi Ufficio Postale';

$tokenValue = env('UFFICIO_POSTALE_TOKEN') ?? env('UFFICIO_POSTALE_SANDBOX_TOKEN') ?? '';
$tokenConfigured = trim((string) $tokenValue) !== '';
$baseUri = (string) (env('UFFICIO_POSTALE_BASE_URI', 'https://ws.ufficiopostale.com') ?: 'https://ws.ufficiopostale.com');

$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$statusFilter = isset($_GET['status']) ? strtoupper(trim((string) $_GET['status'])) : '';
$productFilter = isset($_GET['product']) ? trim((string) $_GET['product']) : '';
$confirmedFilter = isset($_GET['confirmed']) ? trim((string) $_GET['confirmed']) : '';
$clienteFilter = isset($_GET['cliente_id']) ? (int) $_GET['cliente_id'] : 0;

$filters = [];
if ($search !== '') {
    $filters['search'] = $search;
}
if ($statusFilter !== '') {
    $filters['stato'] = $statusFilter;
}
if ($productFilter !== '') {
    $filters['prodotto'] = $productFilter;
}
if ($confirmedFilter !== '') {
    $filters['confirmed'] = in_array($confirmedFilter, ['1', 'true', 'on'], true) ? 1 : 0;
}
if ($clienteFilter > 0) {
    $filters['cliente_id'] = $clienteFilter;
}

$serviceError = null;
$records = [];

try {
    $service = new TelegrammiService($pdo);
    $records = $service->list($filters);
} catch (Throwable $exception) {
    $serviceError = $exception->getMessage();
}

$statusLabels = [
    'NEW' => 'Nuovo',
    'VALIDATED' => 'Validato',
    'ACCEPTED' => 'Accettato',
    'QUEUED' => 'In coda',
    'SENT' => 'Inviato',
    'DELIVERED' => 'Consegnato',
    'ERROR' => 'Errore',
    'CANCELLED' => 'Annullato',
];

$statusBadge = [
    'NEW' => 'bg-primary',
    'VALIDATED' => 'bg-info text-dark',
    'ACCEPTED' => 'bg-info text-dark',
    'QUEUED' => 'bg-warning text-dark',
    'SENT' => 'bg-success',
    'DELIVERED' => 'bg-success',
    'ERROR' => 'bg-danger',
    'CANCELLED' => 'bg-secondary',
];

$hasFilters = $filters !== [];
$recordsCount = count($records);
$telegramSummary = [
    'total' => $recordsCount,
    'confirmed' => 0,
    'delivered' => 0,
    'errors' => 0,
];

foreach ($records as $record) {
    $statusKey = strtoupper((string) ($record['stato'] ?? 'NEW'));
    if (!empty($record['confirmed'])) {
        $telegramSummary['confirmed']++;
    }
    if ($statusKey === 'DELIVERED') {
        $telegramSummary['delivered']++;
    }
    if ($statusKey === 'ERROR') {
        $telegramSummary['errors']++;
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <style>
            .telegrammi-shell { display:grid; gap:1.5rem; }
            .telegrammi-hero {
                position:relative; overflow:hidden; border:1px solid rgba(58,123,213,.14);
                background: radial-gradient(circle at top left, rgba(58,123,213,.16), transparent 34%),
                            radial-gradient(circle at top right, rgba(16,185,129,.12), transparent 26%), #fff;
            }
            .telegrammi-pill {
                display:inline-flex; align-items:center; gap:.5rem; padding:.45rem .85rem; border-radius:999px;
                background:rgba(58,123,213,.10); color:#2154d7; font-size:.72rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase;
            }
            .telegrammi-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1rem; }
            .telegrammi-kpi {
                border:1px solid rgba(15,23,42,.08); border-radius:1.15rem; padding:1rem 1.1rem;
                background:rgba(255,255,255,.88); box-shadow:0 16px 36px rgba(15,23,42,.05);
            }
            .telegrammi-kpi-label { display:block; margin-bottom:.4rem; color:#64748b; font-size:.76rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
            .telegrammi-kpi-value { display:block; color:#0f172a; font-size:1.85rem; font-weight:800; line-height:1; }
            .telegrammi-kpi-note { display:block; margin-top:.45rem; color:#64748b; font-size:.86rem; }
            .telegrammi-panel { border:1px solid rgba(15,23,42,.08); border-radius:1.3rem; background:#fff; box-shadow:0 18px 44px rgba(15,23,42,.05); }
            .telegrammi-table-card-body { padding:1.25rem 1.25rem 1.4rem !important; }
            .telegrammi-table-card-body .table-responsive { border:1px solid rgba(15,23,42,.06); border-radius:1rem; overflow:hidden; }
            .telegrammi-table-card-body .dt-container .dt-layout-row:not(.dt-layout-table) { margin:0; padding-inline:.15rem; }
            .telegrammi-table-card-body .dt-container .dt-layout-row:first-child { padding-bottom:1rem; }
            .telegrammi-table-card-body .dt-container .dt-layout-row:last-child { padding-top:1rem; }
            .telegrammi-table { --bs-table-bg:transparent; --bs-table-hover-bg:rgba(37,99,235,.04); margin-bottom:0; }
            .telegrammi-table thead th { border-bottom:1px solid rgba(15,23,42,.08); color:#64748b; font-size:.76rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; white-space:nowrap; }
            .telegrammi-table td { padding-top:1rem; padding-bottom:1rem; border-color:rgba(15,23,42,.06); vertical-align:middle; }
            .telegrammi-id {
                display:inline-flex; padding:.42rem .68rem; border-radius:.8rem; background:#f8fafc; color:#0f172a;
                font-family:"SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace; font-size:.8rem; font-weight:700;
            }
            @media (max-width:1199.98px) { .telegrammi-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
            @media (max-width:767.98px) { .telegrammi-kpis { grid-template-columns:1fr; } }
        </style>
        <div class="telegrammi-shell">
        <section class="card telegrammi-hero">
            <div class="card-body p-4 p-xl-5">
                <div class="row g-4 align-items-start">
                    <div class="col-12 col-xl-7">
                        <span class="telegrammi-pill"><i class="fa-solid fa-envelope-open-text"></i>Comunicazioni postali</span>
                        <h1 class="mt-3 mb-2 fw-bold" style="max-width:12ch;">Telegrammi più chiari per invii, conferme e stato operazioni.</h1>
                        <p class="text-muted mb-0" style="max-width:70ch;">Gestisci invii, sincronizza lo stato dal portale Ufficio Postale e controlla rapidamente conferme, consegne ed errori.</p>
                    </div>
                    <div class="col-12 col-xl-5">
                        <div class="telegrammi-kpis">
                            <div class="telegrammi-kpi"><span class="telegrammi-kpi-label">Invii</span><span class="telegrammi-kpi-value"><?php echo (int) $telegramSummary['total']; ?></span><span class="telegrammi-kpi-note">Risultati del filtro attivo</span></div>
                            <div class="telegrammi-kpi"><span class="telegrammi-kpi-label">Confermati</span><span class="telegrammi-kpi-value"><?php echo (int) $telegramSummary['confirmed']; ?></span><span class="telegrammi-kpi-note">Segnati come verificati</span></div>
                            <div class="telegrammi-kpi"><span class="telegrammi-kpi-label">Consegnati</span><span class="telegrammi-kpi-value"><?php echo (int) $telegramSummary['delivered']; ?></span><span class="telegrammi-kpi-note">Esito finale positivo</span></div>
                            <div class="telegrammi-kpi"><span class="telegrammi-kpi-label">Errori</span><span class="telegrammi-kpi-value"><?php echo (int) $telegramSummary['errors']; ?></span><span class="telegrammi-kpi-note">Richiedono intervento</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card telegrammi-panel">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <h2 class="h5 mb-1">Filtri invii</h2>
                        <p class="text-muted small mb-0">Raffina il registro per stato, prodotto, conferma o riferimento cliente.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-warning text-dark" href="<?php echo sanitize_output(telegrammi_module_url('create')); ?>">
                            <i class="fa-solid fa-circle-plus me-2"></i>Nuovo telegramma
                        </a>
                    </div>
                </div>
                <form class="row g-3 align-items-end" method="get" autocomplete="off">
                    <div class="col-sm-6 col-lg-4">
                        <label class="form-label" for="filter-search">Ricerca libera</label>
                        <input type="search" class="form-control" id="filter-search" name="search" placeholder="ID telegramma o cliente" value="<?php echo sanitize_output($search); ?>">
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
                    <div class="col-sm-6 col-lg-2">
                        <label class="form-label" for="filter-product">Prodotto</label>
                        <input type="text" class="form-control" id="filter-product" name="product" placeholder="es. telegramma" value="<?php echo sanitize_output($productFilter); ?>">
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label class="form-label" for="filter-confirmed">Confermato</label>
                        <select class="form-select" id="filter-confirmed" name="confirmed">
                            <option value="">Tutti</option>
                            <option value="1" <?php echo $confirmedFilter === '1' ? 'selected' : ''; ?>>Sì</option>
                            <option value="0" <?php echo $confirmedFilter === '0' ? 'selected' : ''; ?>>No</option>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label class="form-label" for="filter-cliente">ID cliente</label>
                        <input type="number" class="form-control" id="filter-cliente" name="cliente_id" min="1" value="<?php echo $clienteFilter > 0 ? sanitize_output((string) $clienteFilter) : ''; ?>">
                    </div>
                    <div class="col-12 col-lg-3 d-flex align-items-end gap-2">
                        <button class="btn btn-warning text-dark flex-fill" type="submit">
                            <i class="fa-solid fa-filter me-1"></i>Applica filtri
                        </button>
                        <?php if ($hasFilters): ?>
                        <a class="btn btn-outline-warning" href="<?php echo sanitize_output(telegrammi_module_url('index')); ?>" title="Pulisci filtri">
                            <i class="fa-solid fa-rotate-left"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>
        <?php if ($serviceError !== null): ?>
        <div class="alert alert-danger" role="alert">
            <strong>Errore:</strong> <?php echo sanitize_output($serviceError); ?>
        </div>
        <?php endif; ?>

        <?php if (!$tokenConfigured): ?>
        <div class="alert alert-warning" role="alert">
            Configura <code>UFFICIO_POSTALE_TOKEN</code> (o la variante sandbox) nel file <code>.env</code> per abilitare l'invio e la sincronizzazione. Endpoint corrente: <span class="fw-semibold"><?php echo sanitize_output($baseUri); ?></span>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12 col-xxl-9 d-flex flex-column gap-4">
                <div class="card telegrammi-panel flex-grow-1">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <h2 class="h5 mb-0">Invii registrati</h2>
                        <span class="badge ag-badge"><?php echo $recordsCount; ?> risultati</span>
                    </div>
                    <div class="card-body telegrammi-table-card-body">
                        <?php if (!$records): ?>
                        <div class="text-center py-5">
                            <?php if ($hasFilters): ?>
                            <p class="text-muted mb-3">Nessun telegramma corrisponde ai filtri selezionati.</p>
                            <a href="<?php echo sanitize_output(telegrammi_module_url('index')); ?>" class="btn btn-outline-secondary">
                                <i class="fa-solid fa-broom me-2"></i>Rimuovi filtri
                            </a>
                            <?php else: ?>
                            <p class="text-muted mb-4">Non sono presenti telegrammi sincronizzati al momento. Puoi inviare un nuovo telegramma; la sincronizzazione avviene automaticamente in background.</p>
                            <div class="d-flex justify-content-center gap-2 flex-wrap">
                                <a href="<?php echo sanitize_output(telegrammi_module_url('create')); ?>" class="btn btn-primary">
                                    <i class="fa-solid fa-circle-plus me-2"></i>Nuovo telegramma
                                </a>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle telegrammi-table" data-datatable="true">
                                <thead>
                                    <tr>
                                        <th scope="col">Telegramma</th>
                                        <th scope="col">Cliente</th>
                                        <th scope="col">Stato</th>
                                        <th scope="col">Confermato</th>
                                        <th scope="col">Creato</th>
                                        <th scope="col">Aggiornato</th>
                                        <th scope="col" class="text-end">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($records as $record): ?>
                                    <?php
                                        $statusKey = strtoupper((string) ($record['stato'] ?? 'NEW'));
                                        $statusLabel = $statusLabels[$statusKey] ?? ucfirst(strtolower($statusKey));
                                        $badgeClass = $statusBadge[$statusKey] ?? 'bg-secondary';
                                        $confirmed = !empty($record['confirmed']);
                                        $telegrammaId = (string) ($record['telegramma_id'] ?? '');
                                        $rowId = 'telegram-' . preg_replace('/[^a-z0-9]/i', '-', $telegrammaId);
                                    ?>
                                    <tr id="<?php echo sanitize_output($rowId); ?>">
                                        <td class="fw-semibold">
                                            <a href="<?php echo sanitize_output(telegrammi_module_url('view', ['id' => $telegrammaId])); ?>" class="text-decoration-none text-body">
                                                <span class="telegrammi-id"><?php echo sanitize_output($telegrammaId); ?></span>
                                            </a>
                                            <?php if (!empty($record['riferimento'])): ?>
                                            <div class="small text-muted">Rif: <?php echo sanitize_output((string) $record['riferimento']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small text-muted"><?php echo sanitize_output($record['cliente_display'] ?? 'Non associato'); ?></div>
                                        </td>
                                        <td>
                                            <span class="badge <?php echo $badgeClass; ?> text-uppercase"><?php echo sanitize_output($statusLabel); ?></span>
                                            <?php if (!empty($record['substate'])): ?>
                                            <div class="small text-muted mt-1"><?php echo sanitize_output((string) $record['substate']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($confirmed): ?>
                                            <span class="badge bg-success"><i class="fa-solid fa-check me-1"></i>Sì</span>
                                            <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="small"><?php echo sanitize_output(format_datetime_locale($record['creation_timestamp'] ?? $record['created_at'] ?? null)); ?></div>
                                        </td>
                                        <td>
                                            <div class="small"><?php echo sanitize_output(format_datetime_locale($record['update_timestamp'] ?? $record['updated_at'] ?? null)); ?></div>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap">
                                                <form action="<?php echo sanitize_output(telegrammi_module_url('confirm')); ?>" method="post" class="d-inline">
                                                    <input type="hidden" name="_token" value="<?php echo csrf_token(); ?>">
                                                    <input type="hidden" name="telegramma_id" value="<?php echo sanitize_output($telegrammaId); ?>">
                                                    <input type="hidden" name="confirmed" value="<?php echo $confirmed ? '0' : '1'; ?>">
                                                    <button type="submit" class="btn btn-icon btn-soft-accent btn-sm" title="<?php echo $confirmed ? 'Segna come non confermato' : 'Conferma invio'; ?>" <?php echo $tokenConfigured ? '' : 'disabled'; ?>>
                                                        <i class="fa-solid <?php echo $confirmed ? 'fa-rotate-left' : 'fa-circle-check'; ?>"></i>
                                                    </button>
                                                </form>
                                                <a href="<?php echo sanitize_output(telegrammi_module_url('view', ['id' => $telegrammaId])); ?>" class="btn btn-icon btn-soft-accent btn-sm" title="Dettagli">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
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

                <div class="card telegrammi-panel">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h6 mb-0">Stato integrazione</h2>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0 small">
                            <dt class="col-6 text-muted">Token</dt>
                            <dd class="col-6 text-<?php echo $tokenConfigured ? 'success' : 'danger'; ?> fw-semibold"><?php echo $tokenConfigured ? 'Configurato' : 'Assente'; ?></dd>
                            <dt class="col-6 text-muted">Endpoint</dt>
                            <dd class="col-6"><code class="text-break"><?php echo sanitize_output($baseUri); ?></code></dd>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
