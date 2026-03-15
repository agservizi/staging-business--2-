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
<div class="flex-grow-1 d-flex flex-column min-vh-100" data-ticket-csrf="<?php echo sanitize_output($csrfToken); ?>" data-ticket-base="<?php echo sanitize_output(rtrim(base_url('modules/ticket'), '/')); ?>">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <?php render_module_hub_styles(); ?>
    <style>
        .ticket-shell {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .ticket-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(37, 99, 235, 0.16);
            border-radius: 28px;
            padding: 2rem;
            background:
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.18), transparent 34%),
                radial-gradient(circle at top right, rgba(245, 158, 11, 0.16), transparent 30%),
                linear-gradient(135deg, #ffffff 0%, #f8fbff 54%, #eef5ff 100%);
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.10);
        }

        .ticket-hero::after {
            content: "";
            position: absolute;
            inset: auto -90px -120px auto;
            width: 260px;
            height: 260px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.08);
            filter: blur(12px);
        }

        .ticket-hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
            gap: 1.5rem;
            align-items: start;
        }

        .ticket-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.10);
            color: #1d4ed8;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .ticket-hero h1 {
            margin: 1rem 0 0.75rem;
            font-size: clamp(2rem, 3vw, 2.7rem);
            line-height: 1.05;
            font-weight: 800;
            color: #172033;
            max-width: 11ch;
        }

        .ticket-hero p {
            margin: 0;
            max-width: 62ch;
            color: #52607a;
            font-size: 1rem;
        }

        .ticket-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            margin-top: 1.5rem;
        }

        .ticket-kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .ticket-kpi-card {
            border-radius: 22px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.92);
            padding: 1.15rem 1.2rem;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
        }

        .ticket-kpi-card span {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #607089;
        }

        .ticket-kpi-card strong {
            display: block;
            font-size: 2rem;
            line-height: 1;
            color: #172033;
        }

        .ticket-kpi-card small {
            display: block;
            margin-top: 0.45rem;
            color: #64748b;
            font-size: 0.85rem;
        }

        .ticket-panel {
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 22px 45px rgba(15, 23, 42, 0.07);
        }

        .ticket-panel-header {
            padding: 1.35rem 1.5rem 0;
        }

        .ticket-panel-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 800;
            color: #172033;
        }

        .ticket-panel-subtitle {
            margin: 0.35rem 0 0;
            color: #64748b;
            font-size: 0.92rem;
        }

        .ticket-filter-form {
            padding: 1.35rem 1.5rem 1.5rem;
        }

        .ticket-filter-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 1rem;
        }

        .ticket-field label {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.78rem;
            font-weight: 700;
            color: #52607a;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .ticket-field .form-control,
        .ticket-field .form-select {
            min-height: 48px;
            border-radius: 15px;
            border-color: #d7dfeb;
            box-shadow: none;
        }

        .ticket-search-field {
            grid-column: span 2;
        }

        .ticket-filter-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .ticket-table-wrap {
            padding: 1.25rem 1.5rem 1.5rem;
        }

        .ticket-table-shell {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.7), rgba(255, 255, 255, 0.98));
        }

        .ticket-table-shell .table {
            margin-bottom: 0;
        }

        .ticket-table-shell thead th {
            border-bottom: 1px solid rgba(226, 232, 240, 0.95);
            background: rgba(248, 250, 252, 0.95);
            color: #52607a;
            font-size: 0.77rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .ticket-code {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.38rem 0.7rem;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.10);
            color: #1d4ed8;
            font-weight: 700;
            font-size: 0.8rem;
        }

        .ticket-pagination {
            padding-top: 1rem;
        }

        .ticket-empty {
            padding: 2.5rem 1.5rem;
            text-align: center;
            color: #64748b;
        }

        @media (max-width: 1199.98px) {
            .ticket-hero-grid,
            .ticket-filter-grid {
                grid-template-columns: 1fr;
            }

            .ticket-search-field {
                grid-column: span 1;
            }
        }

        @media (max-width: 767.98px) {
            .ticket-hero,
            .ticket-filter-form,
            .ticket-table-wrap {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .ticket-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <main class="content-wrapper">
        <div class="module-hub-shell ticket-shell">
            <section class="ticket-hero">
                <div class="ticket-hero-grid">
                    <div>
                        <span class="ticket-eyebrow"><i class="fa-solid fa-headset"></i> Helpdesk center</span>
                        <h1>Una cabina di regia piu' chiara per ticket, SLA e assegnazioni.</h1>
                        <p>Monitora le richieste dei clienti, intercetta subito i ticket critici e gestisci da un'unica vista canale, priorita', assegnazione e stato operativo.</p>
                        <div class="ticket-hero-actions">
                            <a class="btn btn-warning text-dark" href="<?php echo ticket_module_url('new'); ?>"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo ticket</a>
                        </div>
                    </div>
                    <div class="ticket-kpi-grid">
                        <article class="ticket-kpi-card">
                            <span>Totale ticket</span>
                            <strong><?php echo (int) $summary['total']; ?></strong>
                            <small>Panoramica completa dell'helpdesk</small>
                        </article>
                        <article class="ticket-kpi-card">
                            <span>Aperti</span>
                            <strong><?php echo (int) $summary['open']; ?></strong>
                            <small>Richieste da seguire o prendere in carico</small>
                        </article>
                        <article class="ticket-kpi-card">
                            <span>In attesa</span>
                            <strong><?php echo (int) $summary['waiting']; ?></strong>
                            <small>Ticket in pending lato cliente o interno</small>
                        </article>
                        <article class="ticket-kpi-card">
                            <span>Fuori SLA</span>
                            <strong><?php echo (int) $summary['overdue']; ?></strong>
                            <small>Segnalazioni da trattare con priorita'</small>
                        </article>
                    </div>
                </div>
            </section>

            <section class="ticket-panel">
                <div class="ticket-panel-header">
                    <h2 class="ticket-panel-title">Filtri operativi</h2>
                    <p class="ticket-panel-subtitle">Riduci la vista per stato, priorita', canale, cliente o assegnatario per concentrarti sui ticket davvero urgenti.</p>
                </div>
                <form class="ticket-filter-form" method="get" autocomplete="off" id="ticket-filters">
                    <div class="ticket-filter-grid">
                        <div class="ticket-field ticket-search-field">
                            <label for="filter-search">Ricerca libera</label>
                            <input type="search" class="form-control" id="filter-search" name="search" placeholder="ID, cliente o oggetto" value="<?php echo sanitize_output($filters['search'] ?? ''); ?>">
                        </div>
                        <div class="ticket-field">
                            <label for="filter-status">Stato</label>
                            <select class="form-select" id="filter-status" name="status">
                                <option value="">Tutti</option>
                                <?php foreach ($statusOptions as $value => $label): ?>
                                    <option value="<?php echo sanitize_output($value); ?>" <?php echo ($filters['status'] ?? '') === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ticket-field">
                            <label for="filter-priority">Priorità</label>
                            <select class="form-select" id="filter-priority" name="priority">
                                <option value="">Tutte</option>
                                <?php foreach ($priorityOptions as $value => $label): ?>
                                    <option value="<?php echo sanitize_output($value); ?>" <?php echo ($filters['priority'] ?? '') === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ticket-field">
                            <label for="filter-channel">Canale</label>
                            <select class="form-select" id="filter-channel" name="channel">
                                <option value="">Tutti</option>
                                <?php foreach ($channelOptions as $value => $label): ?>
                                    <option value="<?php echo sanitize_output($value); ?>" <?php echo ($filters['channel'] ?? '') === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ticket-field">
                            <label for="filter-type">Tipologia</label>
                            <select class="form-select" id="filter-type" name="type">
                                <option value="">Tutte</option>
                                <?php foreach ($typeOptions as $value => $label): ?>
                                    <option value="<?php echo sanitize_output($value); ?>" <?php echo ($filters['type'] ?? '') === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ticket-field">
                            <label for="filter-customer">Cliente</label>
                            <select class="form-select" id="filter-customer" name="customer_id">
                                <option value="">Tutti</option>
                                <?php foreach ($clients as $client): ?>
                                    <?php $label = trim(($client['ragione_sociale'] ?? '') . ' ' . ($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? '')); ?>
                                    <option value="<?php echo (int) $client['id']; ?>" <?php echo (int) ($filters['customer_id'] ?? 0) === (int) $client['id'] ? 'selected' : ''; ?>><?php echo sanitize_output($label !== '' ? $label : 'Cliente #' . $client['id']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ticket-field">
                            <label for="filter-assigned">Assegnato a</label>
                            <select class="form-select" id="filter-assigned" name="assigned_to">
                                <option value="">Qualsiasi</option>
                                <?php foreach ($agents as $agent): ?>
                                    <?php $agentLabel = trim(($agent['cognome'] ?? '') . ' ' . ($agent['nome'] ?? '') . ' (' . ($agent['username'] ?? '') . ')'); ?>
                                    <option value="<?php echo (int) $agent['id']; ?>" <?php echo (int) ($filters['assigned_to'] ?? 0) === (int) $agent['id'] ? 'selected' : ''; ?>><?php echo sanitize_output($agentLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="ticket-field">
                            <label for="filter-date-from">Dal</label>
                            <input type="date" class="form-control" id="filter-date-from" name="date_from" value="<?php echo sanitize_output($filters['date_from'] ?? ''); ?>">
                        </div>
                        <div class="ticket-field">
                            <label for="filter-date-to">Al</label>
                            <input type="date" class="form-control" id="filter-date-to" name="date_to" value="<?php echo sanitize_output($filters['date_to'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="ticket-filter-actions">
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-filter me-1"></i>Filtra</button>
                        <?php if ($hasFilters): ?>
                            <a class="btn btn-outline-secondary" href="<?php echo ticket_module_url('index'); ?>"><i class="fa-solid fa-rotate-left me-1"></i>Reimposta</a>
                        <?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="ticket-panel">
                <div class="ticket-panel-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <div>
                        <h2 class="ticket-panel-title">Elenco ticket</h2>
                        <p class="ticket-panel-subtitle">Aggiornato alle <?php echo date('H:i'); ?> con cliente, assegnazione, priorita' e stato in una vista unica.</p>
                    </div>
                    <span class="badge ag-badge"><?php echo $totalTickets; ?> risultati</span>
                </div>
                <div class="ticket-table-wrap">
                <?php if (!$tickets): ?>
                    <div class="ticket-empty">
                        <p class="mb-3">Nessun ticket trovato. Puoi crearne uno nuovo o rimuovere i filtri.</p>
                        <div class="d-flex justify-content-center gap-2 flex-wrap">
                            <a class="btn btn-outline-secondary" href="<?php echo ticket_module_url('index'); ?>"><i class="fa-solid fa-broom me-2"></i>Pulisci filtri</a>
                            <a class="btn btn-primary" href="<?php echo ticket_module_url('new'); ?>"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo ticket</a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="ticket-table-shell table-responsive">
                        <table class="table table-hover align-middle module-hub-table" id="ticket-table">
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
                                        $statusLabel = ticket_status_label((string) ($ticket['status'] ?? ''));
                                        $priorityLabel = ticket_priority_label((string) ($ticket['priority'] ?? ''));
                                        $channelLabel = ticket_channel_label((string) ($ticket['channel'] ?? ''));
                                        $typeLabel = ticket_type_label((string) ($ticket['type'] ?? ''));
                                    ?>
                                    <tr data-ticket-row="<?php echo (int) $ticket['id']; ?>">
                                        <td>
                                            <div class="fw-semibold"><span class="ticket-code">#<?php echo sanitize_output($ticket['codice'] ?? $ticket['id']); ?></span> · <?php echo sanitize_output($ticket['subject'] ?? ''); ?></div>
                                            <small class="text-muted text-uppercase">Canale: <?php echo sanitize_output($channelLabel !== '' ? $channelLabel : ($ticket['channel'] ?? '')); ?> · Tipo: <?php echo sanitize_output($typeLabel !== '' ? $typeLabel : ($ticket['type'] ?? '')); ?></small>
                                        </td>
                                        <td><?php echo sanitize_output($customerLabel); ?></td>
                                        <td><?php echo sanitize_output($agentLabel); ?></td>
                                        <td><span class="badge <?php echo $priorityBadge; ?> text-uppercase"><?php echo sanitize_output($priorityLabel !== '' ? $priorityLabel : ($ticket['priority'] ?? '')); ?></span></td>
                                        <td><span class="badge <?php echo $statusBadge; ?> text-uppercase"><?php echo sanitize_output($statusLabel !== '' ? $statusLabel : ($ticket['status'] ?? '')); ?></span></td>
                                        <td><?php echo sanitize_output(date('d/m/Y H:i', strtotime((string) $ticket['updated_at']))); ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2 justify-content-end flex-wrap">
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo ticket_module_url('view', ['id' => (int) $ticket['id']]); ?>" title="Apri" data-bs-toggle="tooltip" data-bs-placement="top">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <button class="btn btn-icon btn-warning text-dark btn-sm" type="button" data-ticket-assign="<?php echo (int) $ticket['id']; ?>" title="Assegna" data-bs-toggle="tooltip" data-bs-placement="top">
                                                    <i class="fa-solid fa-user-check"></i>
                                                </button>
                                                <button class="btn btn-icon btn-soft-danger btn-sm" type="button" data-ticket-archive="<?php echo (int) $ticket['id']; ?>" title="Archivia" data-bs-toggle="tooltip" data-bs-placement="top">
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
                        <nav class="ticket-pagination" aria-label="Paginazione ticket">
                            <ul class="pagination justify-content-end flex-wrap">
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <?php $query = http_build_query(array_merge($_GET, ['page' => $i])); ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo ticket_module_url('index') . '?' . sanitize_output($query); ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
            </section>
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
