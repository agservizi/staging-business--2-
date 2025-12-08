<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';
require_once __DIR__ . '/../../../includes/ticket_functions.php';

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$statusOptions = ticket_status_options();
$priorityOptions = ticket_priority_options();

$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));
if ($statusFilter !== '' && !isset($statusOptions[$statusFilter])) {
    $statusFilter = '';
}

$searchFilter = trim((string) ($_GET['q'] ?? ''));

$conditions = ['created_by = :creator'];
$params = [':creator' => $collaboratorId];

if ($statusFilter !== '') {
    $conditions[] = 'status = :status';
    $params[':status'] = $statusFilter;
}

if ($searchFilter !== '') {
    $conditions[] = '(subject LIKE :search OR codice LIKE :search OR customer_name LIKE :search)';
    $params[':search'] = '%' . $searchFilter . '%';
}

$whereClause = implode(' AND ', $conditions);

$listStmt = $pdo->prepare(
    'SELECT id, codice, subject, status, priority, updated_at, created_at, last_message_at
     FROM tickets
     WHERE ' . $whereClause . '
     ORDER BY updated_at DESC'
);
foreach ($params as $placeholder => $value) {
    $listStmt->bindValue($placeholder, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
}
$listStmt->execute();
$tickets = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$statsStmt = $pdo->prepare(
    'SELECT status, COUNT(*) AS total
     FROM tickets
     WHERE created_by = :creator
     GROUP BY status'
);
$statsStmt->execute([':creator' => $collaboratorId]);
$statusCounts = array_fill_keys(array_keys($statusOptions), 0);
while ($row = $statsStmt->fetch(PDO::FETCH_ASSOC)) {
    $status = (string) ($row['status'] ?? '');
    if ($status !== '' && isset($statusCounts[$status])) {
        $statusCounts[$status] = (int) ($row['total'] ?? 0);
    }
}
$totalTickets = array_sum($statusCounts);
$openTickets = $statusCounts['OPEN'] + $statusCounts['IN_PROGRESS'];
$waitingTickets = $statusCounts['WAITING_CLIENT'] + $statusCounts['WAITING_PARTNER'];
$resolvedTickets = $statusCounts['RESOLVED'] + $statusCounts['CLOSED'];

$ticketCreateHint = 'Apri un ticket per assistenza su opportunity, problemi tecnici al portale o richieste informative.';

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Supporto</p>
                <h1 class="h4 mb-0">Ticket e richieste</h1>
                <p class="text-muted mb-0">Apri ticket su opportunity, problemi tecnici al portale o richieste di informazioni.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-primary" href="<?php echo asset('modules/opportunities/collaborator/ticket-general.php'); ?>">
                    <i class="fa-solid fa-circle-plus me-2"></i>Nuovo ticket
                </a>
                <a class="btn btn-outline-primary" href="<?php echo asset('modules/opportunities/collaborator/list.php'); ?>">
                    <i class="fa-solid fa-table-list me-2"></i>Elenco opportunity
                </a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Totali</p>
                        <h2 class="display-6 mb-0"><?php echo (int) $totalTickets; ?></h2>
                        <p class="text-muted small mb-0">Ticket registrati da questo account.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Aperti</p>
                        <h2 class="display-6 mb-0 text-warning"><?php echo (int) $openTickets; ?></h2>
                        <p class="text-muted small mb-0">In lavorazione dal team.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">In attesa</p>
                        <h2 class="display-6 mb-0 text-info"><?php echo (int) $waitingTickets; ?></h2>
                        <p class="text-muted small mb-0">Richiedono un tuo riscontro.</p>
                    </div>
                </div>
            </div>
            <div class="col-12 col-md-3">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Chiusi</p>
                        <h2 class="display-6 mb-0 text-success"><?php echo (int) $resolvedTickets; ?></h2>
                        <p class="text-muted small mb-0">Ticket risolti o archiviati.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-md-3">
                        <label class="form-label text-uppercase small text-muted" for="status">Stato</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">Tutti</option>
                            <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>>
                                    <?php echo sanitize_output($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label text-uppercase small text-muted" for="search">Ricerca</label>
                        <input class="form-control" type="search" id="search" name="q" placeholder="Codice, oggetto o cliente" value="<?php echo sanitize_output($searchFilter); ?>">
                    </div>
                    <div class="col-md-4 d-flex gap-2">
                        <button class="btn btn-primary w-100" type="submit">
                            <i class="fa-solid fa-filter me-2"></i>Filtra
                        </button>
                        <?php if ($statusFilter !== '' || $searchFilter !== ''): ?>
                            <a class="btn btn-light w-100" href="<?php echo asset('modules/opportunities/collaborator/tickets.php'); ?>">
                                Reset
                            </a>
                        <?php endif; ?>
                        <a class="btn btn-success w-100" href="<?php echo asset('modules/opportunities/collaborator/ticket-general.php'); ?>">
                            <i class="fa-solid fa-circle-plus me-2"></i>Nuovo ticket
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Ticket</th>
                                <th>Oggetto</th>
                                <th>Stato</th>
                                <th>Priorità</th>
                                <th>Ultimo aggiornamento</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$tickets): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5 text-muted">
                                        Nessun ticket trovato. <?php echo sanitize_output($ticketCreateHint); ?>
                                        <div class="mt-3">
                                            <a class="btn btn-sm btn-primary" href="<?php echo asset('modules/opportunities/collaborator/ticket-general.php'); ?>">
                                                <i class="fa-solid fa-circle-plus me-2"></i>Apri ticket
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($tickets as $ticket): ?>
                                <?php
                                    $statusBadge = ticket_status_badge((string) ($ticket['status'] ?? 'OPEN'));
                                    $priorityBadge = ticket_priority_badge((string) ($ticket['priority'] ?? 'MEDIUM'));
                                    $updatedAt = $ticket['last_message_at'] ?? $ticket['updated_at'] ?? $ticket['created_at'];
                                    $viewUrl = asset('modules/opportunities/collaborator/ticket-view.php?id=' . (int) $ticket['id']);
                                ?>
                                <tr>
                                    <td>
                                        <strong>#<?php echo sanitize_output($ticket['codice'] ?? $ticket['id']); ?></strong>
                                        <div class="text-muted small">Creato il <?php echo sanitize_output(format_datetime_locale($ticket['created_at'] ?? null)); ?></div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold"><?php echo sanitize_output($ticket['subject'] ?? ''); ?></div>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $statusBadge; ?> text-uppercase"><?php echo sanitize_output($ticket['status'] ?? 'OPEN'); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $priorityBadge; ?> text-uppercase"><?php echo sanitize_output($ticket['priority'] ?? 'MEDIUM'); ?></span>
                                    </td>
                                    <td>
                                        <div><?php echo sanitize_output(format_datetime_locale($updatedAt)); ?></div>
                                        <div class="text-muted small">Ultimo messaggio</div>
                                    </td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="<?php echo sanitize_output($viewUrl); ?>">
                                            <i class="fa-solid fa-eye me-1"></i>Apri
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
