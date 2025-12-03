<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/ticket_functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Support');
$pageTitle = 'Ticket di assistenza';

$statusOptions = ticket_status_options();
$priorityOptions = ticket_priority_options();
$channelOptions = ticket_channel_options();
$typeOptions = ticket_type_options();

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = (int) ($_GET['per_page'] ?? 15);
$perPage = $perPage > 50 ? 50 : max($perPage, 5);

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'status' => strtoupper(trim((string) ($_GET['status'] ?? ''))),
    'priority' => strtoupper(trim((string) ($_GET['priority'] ?? ''))),
    'channel' => strtoupper(trim((string) ($_GET['channel'] ?? ''))),
    'type' => strtoupper(trim((string) ($_GET['type'] ?? ''))),
    'customer_id' => (int) ($_GET['customer_id'] ?? 0),
    'assigned_to' => (int) ($_GET['assigned_to'] ?? 0),
    'date_from' => trim((string) ($_GET['date_from'] ?? '')),
    'date_to' => trim((string) ($_GET['date_to'] ?? '')),
];

$filters['customer_id'] = $filters['customer_id'] > 0 ? $filters['customer_id'] : null;
$filters['assigned_to'] = $filters['assigned_to'] > 0 ? $filters['assigned_to'] : null;
$filters['status'] = $filters['status'] !== '' ? $filters['status'] : null;
$filters['priority'] = $filters['priority'] !== '' ? $filters['priority'] : null;
$filters['channel'] = $filters['channel'] !== '' ? $filters['channel'] : null;
$filters['type'] = $filters['type'] !== '' ? $filters['type'] : null;
$filters['search'] = $filters['search'] !== '' ? $filters['search'] : null;
$filters['date_from'] = $filters['date_from'] !== '' ? $filters['date_from'] : null;
$filters['date_to'] = $filters['date_to'] !== '' ? $filters['date_to'] : null;

$collection = ticket_fetch_collection($pdo, $filters, $page, $perPage);
$tickets = $collection['data'];
$totalTickets = $collection['total'];
$totalPages = (int) ceil($totalTickets / $collection['per_page']);
$hasFilters = array_filter($filters) !== [];

$summary = ticket_summary($pdo);
$agents = ticket_assignments($pdo);
$clients = ticket_clients($pdo);
$csrfToken = csrf_token();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100" data-ticket-csrf="<?php echo sanitize_output($csrfToken); ?>" data-ticket-base="/modules/ticket">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Ticket e comunicazioni</h1>
                <p class="text-muted mb-0">Panoramica centralizzata delle richieste dei clienti e delle attività interne.</p>
            </div>
            <div class="toolbar-actions d-flex flex-wrap gap-2">
                <a class="btn btn-warning text-dark" href="new.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo ticket</a>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="card ag-card shadow-sm">
                    <div class="card-body">
                        <p class="text-muted text-uppercase fw-semibold small mb-1">Totale</p>
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="display-6 mb-0 fw-bold"><?php echo (int) $summary['total']; ?></span>
                            <i class="fa-solid fa-layer-group fa-2x text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card ag-card shadow-sm">
                    <div class="card-body">
                        <p class="text-muted text-uppercase fw-semibold small mb-1">Aperti</p>
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="display-6 mb-0 fw-bold text-primary"><?php echo (int) $summary['open']; ?></span>
                            <i class="fa-solid fa-inbox fa-2x text-primary"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card ag-card shadow-sm">
                    <div class="card-body">
                        <p class="text-muted text-uppercase fw-semibold small mb-1">In attesa</p>
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="display-6 mb-0 fw-bold text-warning"><?php echo (int) $summary['waiting']; ?></span>
                            <i class="fa-solid fa-hourglass-half fa-2x text-warning"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-sm-6 col-lg-3">
                <div class="card ag-card shadow-sm">
                    <div class="card-body">
                        <p class="text-muted text-uppercase fw-semibold small mb-1">Ticket fuori SLA</p>
                        <div class="d-flex align-items-center justify-content-between">
                            <span class="display-6 mb-0 fw-bold text-danger"><?php echo (int) $summary['overdue']; ?></span>
                            <i class="fa-solid fa-triangle-exclamation fa-2x text-danger"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Filtri</h2>
            </div>
            <div class="card-body">
                <form class="row g-3" method="get" autocomplete="off" id="ticket-filters">
                    <div class="col-md-4">
                        <label class="form-label" for="filter-search">Ricerca libera</label>
                        <input type="search" class="form-control" id="filter-search" name="search" placeholder="ID, cliente o oggetto" value="<?php echo sanitize_output($filters['search'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="filter-status">Stato</label>
                        <select class="form-select" id="filter-status" name="status">
                            <option value="">Tutti</option>
                            <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>" <?php echo ($filters['status'] ?? '') === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="filter-priority">Priorità</label>
                        <select class="form-select" id="filter-priority" name="priority">
                            <option value="">Tutte</option>
                            <?php foreach ($priorityOptions as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>" <?php echo ($filters['priority'] ?? '') === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="filter-channel">Canale</label>
                        <select class="form-select" id="filter-channel" name="channel">
                            <option value="">Tutti</option>
                            <?php foreach ($channelOptions as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>" <?php echo ($filters['channel'] ?? '') === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="filter-type">Tipologia</label>
                        <select class="form-select" id="filter-type" name="type">
                            <option value="">Tutte</option>
                            <?php foreach ($typeOptions as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>" <?php echo ($filters['type'] ?? '') === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="filter-customer">Cliente</label>
                        <select class="form-select" id="filter-customer" name="customer_id">
                            <option value="">Tutti</option>
                            <?php foreach ($clients as $client): ?>
                                <?php $label = trim(($client['ragione_sociale'] ?? '') . ' ' . ($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? '')); ?>
                                <option value="<?php echo (int) $client['id']; ?>" <?php echo (int) ($filters['customer_id'] ?? 0) === (int) $client['id'] ? 'selected' : ''; ?>><?php echo sanitize_output($label !== '' ? $label : 'Cliente #' . $client['id']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="filter-assigned">Assegnato a</label>
                        <select class="form-select" id="filter-assigned" name="assigned_to">
                            <option value="">Team</option>
                            <?php foreach ($agents as $agent): ?>
                                <?php $agentLabel = trim(($agent['cognome'] ?? '') . ' ' . ($agent['nome'] ?? '') . ' (' . ($agent['username'] ?? '') . ')'); ?>
                                <option value="<?php echo (int) $agent['id']; ?>" <?php echo (int) ($filters['assigned_to'] ?? 0) === (int) $agent['id'] ? 'selected' : ''; ?>><?php echo sanitize_output($agentLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="filter-date-from">Dal</label>
                        <input type="date" class="form-control" id="filter-date-from" name="date_from" value="<?php echo sanitize_output($filters['date_from'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="filter-date-to">Al</label>
                        <input type="date" class="form-control" id="filter-date-to" name="date_to" value="<?php echo sanitize_output($filters['date_to'] ?? ''); ?>">
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-2">
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-filter me-1"></i>Filtra</button>
                        <?php if ($hasFilters): ?>
                            <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-rotate-left me-1"></i>Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h2 class="h5 mb-0">Elenco ticket</h2>
                    <small class="text-muted">Aggiornato alle <?php echo date('H:i'); ?></small>
                </div>
                <span class="badge ag-badge"><?php echo $totalTickets; ?> risultati</span>
            </div>
            <div class="card-body">
                <?php if (!$tickets): ?>
                    <div class="text-center py-5">
                        <p class="text-muted mb-3">Nessun ticket trovato. Puoi crearne uno nuovo o rimuovere i filtri.</p>
                        <div class="d-flex justify-content-center gap-2 flex-wrap">
                            <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-broom me-2"></i>Pulisci filtri</a>
                            <a class="btn btn-primary" href="new.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo ticket</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle" id="ticket-table">
                            <thead>
                                <tr>
                                    <th>Ticket</th>
                                    <th>Cliente</th>
                                    <th>Assegnato</th>
                                    <th>Priorità</th>
                                    <th>Stato</th>
                                    <th>Ultimo aggiornamento</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tickets as $ticket): ?>
                                    <?php
                                        $statusBadge = ticket_status_badge((string) $ticket['status']);
                                        $priorityBadge = ticket_priority_badge((string) $ticket['priority']);
                                        $customerLabel = trim((string) ($ticket['company_name'] ?? ''));
                                        if ($customerLabel === '') {
                                            $customerLabel = trim((string) (($ticket['customer_last_name'] ?? '') . ' ' . ($ticket['customer_first_name'] ?? '')));
                                        }
                                        $customerLabel = $customerLabel !== '' ? $customerLabel : 'Cliente #' . (int) ($ticket['customer_id'] ?? 0);
                                        $agentLabel = trim((string) (($ticket['agent_lastname'] ?? '') . ' ' . ($ticket['agent_name'] ?? '')));
                                        $agentLabel = $agentLabel !== '' ? $agentLabel : 'Da assegnare';
                                    ?>
                                    <tr data-ticket-row="<?php echo (int) $ticket['id']; ?>">
                                        <td>
                                            <div class="fw-semibold">#<?php echo sanitize_output($ticket['codice'] ?? $ticket['id']); ?> · <?php echo sanitize_output($ticket['subject'] ?? ''); ?></div>
                                            <small class="text-muted text-uppercase">Canale: <?php echo sanitize_output($ticket['channel'] ?? 'PORTAL'); ?> · Tipo: <?php echo sanitize_output($ticket['type'] ?? 'SUPPORT'); ?></small>
                                        </td>
                                        <td><?php echo sanitize_output($customerLabel); ?></td>
                                        <td><?php echo sanitize_output($agentLabel); ?></td>
                                        <td><span class="badge <?php echo $priorityBadge; ?> text-uppercase"><?php echo sanitize_output($ticket['priority']); ?></span></td>
                                        <td><span class="badge <?php echo $statusBadge; ?> text-uppercase"><?php echo sanitize_output($ticket['status']); ?></span></td>
                                        <td><?php echo sanitize_output(date('d/m/Y H:i', strtotime((string) $ticket['updated_at']))); ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2 justify-content-end flex-wrap">
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $ticket['id']; ?>" title="Apri">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <button class="btn btn-icon btn-soft-warning btn-sm" type="button" data-ticket-assign="<?php echo (int) $ticket['id']; ?>" title="Assegna">
                                                    <i class="fa-solid fa-user-check"></i>
                                                </button>
                                                <button class="btn btn-icon btn-soft-danger btn-sm" type="button" data-ticket-archive="<?php echo (int) $ticket['id']; ?>" title="Archivia">
                                                    <i class="fa-solid fa-box-archive"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav class="mt-4" aria-label="Paginazione ticket">
                            <ul class="pagination justify-content-end flex-wrap">
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <?php $query = http_build_query(array_merge($_GET, ['page' => $i])); ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="index.php?<?php echo sanitize_output($query); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<div class="modal fade" id="ticketAssignModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Assegna ticket</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <form id="ticket-assign-form" class="modal-body">
                <input type="hidden" name="ticket_id" id="assign-ticket-id">
                <div class="mb-3">
                    <label class="form-label" for="assign-user-id">Operatore</label>
                    <select class="form-select" name="assigned_to" id="assign-user-id" required>
                        <option value="">Seleziona</option>
                        <?php foreach ($agents as $agent): ?>
                            <?php $agentLabel = trim(($agent['cognome'] ?? '') . ' ' . ($agent['nome'] ?? '') . ' · ' . ($agent['username'] ?? '')); ?>
                            <option value="<?php echo (int) $agent['id']; ?>"><?php echo sanitize_output($agentLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Salva</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="ticketArchiveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Archivia ticket</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <form id="ticket-archive-form" class="modal-body">
                <input type="hidden" name="ticket_id" id="archive-ticket-id">
                <p class="mb-4">Confermi l'archiviazione del ticket selezionato? Potrai comunque visualizzarlo in futuro.</p>
                <div class="d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-danger">Archivia</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<script src="<?php echo asset('assets/js/ticket.js'); ?>" defer></script>
