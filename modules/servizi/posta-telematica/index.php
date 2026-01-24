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

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Posta Telematica</h1>
                <p class="text-muted mb-0">Gestisci invii Email e PEC e traccia lo storico delle comunicazioni.</p>
            </div>
            <div class="toolbar-actions d-flex flex-wrap gap-2">
                <a class="btn btn-primary" href="create.php">
                    <i class="fa-solid fa-circle-plus me-2"></i>Nuovo invio
                </a>
                <a class="btn btn-outline-secondary" href="inbox.php">
                    <i class="fa-solid fa-inbox me-2"></i>Inbox PEC
                </a>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Filtri</h2>
            </div>
            <div class="card-body">
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
                            <a class="btn btn-outline-secondary" href="index.php" title="Rimuovi filtri">
                                <i class="fa-solid fa-rotate-left"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h2 class="h5 mb-0">Invii registrati</h2>
                <span class="badge ag-badge"><?php echo count($records); ?> risultati</span>
            </div>
            <div class="card-body">
                <?php if (!$records): ?>
                    <div class="text-center py-5">
                        <?php if ($hasFilters): ?>
                            <p class="text-muted mb-3">Nessun invio corrisponde ai filtri selezionati.</p>
                            <a href="index.php" class="btn btn-outline-secondary">
                                <i class="fa-solid fa-broom me-2"></i>Rimuovi filtri
                            </a>
                        <?php else: ?>
                            <p class="text-muted mb-4">Non ci sono invii registrati. Crea il primo invio.</p>
                            <a href="create.php" class="btn btn-primary">
                                <i class="fa-solid fa-circle-plus me-2"></i>Nuovo invio
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle" data-datatable="true">
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
                                        <td><?php echo (int) $record['id']; ?></td>
                                        <td><span class="badge bg-light text-dark border"><?php echo sanitize_output($channelLabel); ?></span></td>
                                        <td><?php echo sanitize_output($record['recipient_email'] ?? ''); ?></td>
                                        <td class="text-truncate" style="max-width: 260px;"><?php echo sanitize_output($record['subject'] ?? ''); ?></td>
                                        <td><?php echo sanitize_output($clienteLabel); ?></td>
                                        <td><span class="badge <?php echo $statusClass; ?>"><?php echo sanitize_output($statusLabel); ?></span></td>
                                        <td><?php echo sanitize_output(format_datetime_locale($record['created_at'] ?? null)); ?></td>
                                        <td class="text-end">
                                            <a class="btn btn-sm btn-outline-primary" href="view.php?id=<?php echo (int) $record['id']; ?>">
                                                Dettagli
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
