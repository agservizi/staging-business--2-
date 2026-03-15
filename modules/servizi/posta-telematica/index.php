<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Posta Telematica';

$search = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$channelFilter = trim((string) ($_GET['channel'] ?? ''));
$clienteFilter = isset($_GET['cliente_id']) ? (int) $_GET['cliente_id'] : 0;

$filters = [];
if ($search !== '') {
    $filters['search'] = $search;
}
if ($statusFilter !== '') {
    $filters['status'] = $statusFilter;
}
if ($channelFilter !== '') {
    $filters['channel'] = $channelFilter;
}
if ($clienteFilter > 0) {
    $filters['cliente_id'] = $clienteFilter;
}

$records = posta_telematica_list_messages($pdo, $filters);
$hasFilters = $filters !== [];

$statusLabels = [
    'pending' => 'In attesa',
    'sent' => 'Inviato',
    'failed' => 'Fallito',
];
$statusBadge = [
    'pending' => 'bg-warning text-dark',
    'sent' => 'bg-success',
    'failed' => 'bg-danger',
];
$channelLabels = [
    'email' => 'Email',
    'pec' => 'PEC',
];
$recordsCount = count($records);
$mailSummary = [
    'total' => $recordsCount,
    'pec' => 0,
    'sent' => 0,
    'failed' => 0,
];

foreach ($records as $record) {
    $channelKey = (string) ($record['channel'] ?? 'email');
    $statusKey = (string) ($record['status'] ?? 'pending');

    if ($channelKey === 'pec') {
        $mailSummary['pec']++;
    }
    if ($statusKey === 'sent') {
        $mailSummary['sent']++;
    } elseif ($statusKey === 'failed') {
        $mailSummary['failed']++;
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <style>
            .mail-shell { display:grid; gap:1.5rem; }
            .mail-hero {
                position:relative; overflow:hidden; border:1px solid rgba(58,123,213,.14);
                background: radial-gradient(circle at top left, rgba(58,123,213,.16), transparent 34%),
                            radial-gradient(circle at top right, rgba(16,185,129,.12), transparent 26%), #fff;
            }
            .mail-pill {
                display:inline-flex; align-items:center; gap:.5rem; padding:.45rem .85rem; border-radius:999px;
                background:rgba(58,123,213,.10); color:#2154d7; font-size:.72rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase;
            }
            .mail-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:1rem; }
            .mail-kpi {
                border:1px solid rgba(15,23,42,.08); border-radius:1.15rem; padding:1rem 1.1rem;
                background:rgba(255,255,255,.88); box-shadow:0 16px 36px rgba(15,23,42,.05);
            }
            .mail-kpi-label { display:block; margin-bottom:.4rem; color:#64748b; font-size:.76rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
            .mail-kpi-value { display:block; color:#0f172a; font-size:1.85rem; font-weight:800; line-height:1; }
            .mail-kpi-note { display:block; margin-top:.45rem; color:#64748b; font-size:.86rem; }
            .mail-panel { border:1px solid rgba(15,23,42,.08); border-radius:1.3rem; background:#fff; box-shadow:0 18px 44px rgba(15,23,42,.05); }
            .mail-table-card-body { padding:1.25rem 1.25rem 1.4rem !important; }
            .mail-table-card-body .table-responsive { border:1px solid rgba(15,23,42,.06); border-radius:1rem; overflow:hidden; }
            .mail-table-card-body .dt-container .dt-layout-row:not(.dt-layout-table) { margin:0; padding-inline:.15rem; }
            .mail-table-card-body .dt-container .dt-layout-row:first-child { padding-bottom:1rem; }
            .mail-table-card-body .dt-container .dt-layout-row:last-child { padding-top:1rem; }
            .mail-table { --bs-table-bg:transparent; --bs-table-hover-bg:rgba(37,99,235,.04); margin-bottom:0; }
            .mail-table thead th { border-bottom:1px solid rgba(15,23,42,.08); color:#64748b; font-size:.76rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; white-space:nowrap; }
            .mail-table td { padding-top:1rem; padding-bottom:1rem; border-color:rgba(15,23,42,.06); vertical-align:middle; }
            .mail-id {
                display:inline-flex; padding:.42rem .68rem; border-radius:.8rem; background:#f8fafc; color:#0f172a;
                font-family:"SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace; font-size:.8rem; font-weight:700;
            }
            @media (max-width:1199.98px) { .mail-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } }
            @media (max-width:767.98px) { .mail-kpis { grid-template-columns:1fr; } }
        </style>
        <div class="mail-shell">
        <section class="card mail-hero">
            <div class="card-body p-4 p-xl-5">
                <div class="row g-4 align-items-start">
                    <div class="col-12 col-xl-7">
                        <span class="mail-pill"><i class="fa-solid fa-envelope-circle-check"></i>Messaggistica operativa</span>
                        <h1 class="mt-3 mb-2 fw-bold" style="max-width:12ch;">Posta telematica più chiara per invii, PEC e stato recapiti.</h1>
                        <p class="text-muted mb-0" style="max-width:70ch;">Gestisci invii Email e PEC, filtra rapidamente lo storico e mantieni una vista ordinata su recapiti, esiti e clienti coinvolti.</p>
                    </div>
                    <div class="col-12 col-xl-5">
                        <div class="mail-kpis">
                            <div class="mail-kpi"><span class="mail-kpi-label">Invii</span><span class="mail-kpi-value"><?php echo (int) $mailSummary['total']; ?></span><span class="mail-kpi-note">Risultati del filtro attivo</span></div>
                            <div class="mail-kpi"><span class="mail-kpi-label">PEC</span><span class="mail-kpi-value"><?php echo (int) $mailSummary['pec']; ?></span><span class="mail-kpi-note">Canale certificato</span></div>
                            <div class="mail-kpi"><span class="mail-kpi-label">Inviati</span><span class="mail-kpi-value"><?php echo (int) $mailSummary['sent']; ?></span><span class="mail-kpi-note">Con esito positivo</span></div>
                            <div class="mail-kpi"><span class="mail-kpi-label">Falliti</span><span class="mail-kpi-value"><?php echo (int) $mailSummary['failed']; ?></span><span class="mail-kpi-note">Da verificare</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card mail-panel">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <h2 class="h5 mb-1">Filtri invii</h2>
                        <p class="text-muted small mb-0">Raffina il registro per destinatario, canale, stato o cliente associato.</p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-warning text-dark" href="<?php echo sanitize_output(posta_telematica_module_url('create')); ?>">
                            <i class="fa-solid fa-circle-plus me-2"></i>Nuovo invio
                        </a>
                        <a class="btn btn-outline-warning" href="<?php echo sanitize_output(posta_telematica_module_url('inbox')); ?>">
                            <i class="fa-solid fa-inbox me-2"></i>Inbox PEC
                        </a>
                    </div>
                </div>
                <form class="row g-3 align-items-end" method="get" autocomplete="off">
                    <div class="col-sm-6 col-lg-4">
                        <label class="form-label" for="filter-search">Ricerca</label>
                        <input type="search" class="form-control" id="filter-search" name="search" placeholder="Destinatario o oggetto" value="<?php echo sanitize_output($search); ?>">
                    </div>
                    <div class="col-sm-6 col-lg-2">
                        <label class="form-label" for="filter-channel">Canale</label>
                        <select class="form-select" id="filter-channel" name="channel">
                            <option value="">Tutti</option>
                            <option value="email" <?php echo $channelFilter === 'email' ? 'selected' : ''; ?>>Email</option>
                            <option value="pec" <?php echo $channelFilter === 'pec' ? 'selected' : ''; ?>>PEC</option>
                        </select>
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
                        <label class="form-label" for="filter-cliente">ID Cliente</label>
                        <input type="number" class="form-control" id="filter-cliente" name="cliente_id" min="1" value="<?php echo $clienteFilter > 0 ? sanitize_output((string) $clienteFilter) : ''; ?>">
                    </div>
                    <div class="col-12 col-lg-2 d-flex gap-2">
                        <button class="btn btn-warning text-dark flex-fill" type="submit">
                            <i class="fa-solid fa-filter me-1"></i>Applica
                        </button>
                        <?php if ($hasFilters): ?>
                            <a class="btn btn-outline-secondary" href="<?php echo sanitize_output(posta_telematica_module_url('index')); ?>" title="Rimuovi filtri">
                                <i class="fa-solid fa-rotate-left"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </section>

        <section class="card mail-panel">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h2 class="h5 mb-0">Invii registrati</h2>
                <span class="badge ag-badge"><?php echo (int) $recordsCount; ?> risultati</span>
            </div>
            <div class="card-body mail-table-card-body">
                <?php if (!$records): ?>
                    <div class="text-center py-5">
                        <?php if ($hasFilters): ?>
                            <p class="text-muted mb-3">Nessun invio corrisponde ai filtri selezionati.</p>
                            <a href="<?php echo sanitize_output(posta_telematica_module_url('index')); ?>" class="btn btn-outline-secondary">
                                <i class="fa-solid fa-broom me-2"></i>Rimuovi filtri
                            </a>
                        <?php else: ?>
                            <p class="text-muted mb-4">Non ci sono invii registrati. Crea il primo invio.</p>
                            <a href="<?php echo sanitize_output(posta_telematica_module_url('create')); ?>" class="btn btn-primary">
                                <i class="fa-solid fa-circle-plus me-2"></i>Nuovo invio
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mail-table" data-datatable="true">
                            <thead>
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">Canale</th>
                                    <th scope="col">Destinatario</th>
                                    <th scope="col">Oggetto</th>
                                    <th scope="col">Cliente</th>
                                    <th scope="col">Stato</th>
                                    <th scope="col">Creato</th>
                                    <th scope="col" class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($records as $record): ?>
                                    <?php
                                        $statusKey = $record['status'] ?? 'pending';
                                        $statusLabel = $statusLabels[$statusKey] ?? ucfirst((string) $statusKey);
                                        $statusClass = $statusBadge[$statusKey] ?? 'bg-secondary';
                                        $channelKey = $record['channel'] ?? 'email';
                                        $channelLabel = $channelLabels[$channelKey] ?? strtoupper((string) $channelKey);
                                        $clienteLabel = posta_telematica_build_cliente_label($record);
                                    ?>
                                    <tr>
                                        <td><span class="mail-id">#<?php echo (int) $record['id']; ?></span></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo sanitize_output($channelLabel); ?></span></td>
                                        <td><?php echo sanitize_output($record['recipient_email'] ?? ''); ?></td>
                                        <td class="text-truncate" style="max-width: 260px;"><?php echo sanitize_output($record['subject'] ?? ''); ?></td>
                                        <td><?php echo sanitize_output($clienteLabel); ?></td>
                                        <td><span class="badge <?php echo $statusClass; ?>"><?php echo sanitize_output($statusLabel); ?></span></td>
                                        <td><?php echo sanitize_output(format_datetime_locale($record['created_at'] ?? null)); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="<?php echo sanitize_output(posta_telematica_module_url('view', ['id' => (int) $record['id']])); ?>">
                                                Dettagli
                                            </a>
                                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo sanitize_output(posta_telematica_module_url('receipt', ['id' => (int) $record['id']])); ?>" target="_blank">
                                                <i class="fa-solid fa-print me-1"></i>Stampa
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
