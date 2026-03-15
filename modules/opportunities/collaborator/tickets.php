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
                <a class="btn btn-primary" href="<?php echo opportunities_collaborator_url('ticket-general'); ?>">
                    <i class="fa-solid fa-circle-plus me-2"></i>Nuovo ticket
                </a>
                <a class="btn btn-outline-primary" href="<?php echo opportunities_collaborator_url('list'); ?>">
                    <i class="fa-solid fa-table-list me-2"></i>Elenco opportunity
                </a>
            </div>
        </div>

        <?php
            $ticketCards = [
                'total' => [
                    'value' => (int) $totalTickets,
                    'label' => 'Totali',
                    'tone' => 'neon',
                    'icon' => 'fa-layer-group',
                    'tag' => 'Volume',
                    'hint' => 'Ticket registrati',
                ],
                'open' => [
                    'value' => (int) $openTickets,
                    'label' => 'Aperti',
                    'tone' => 'amber',
                    'icon' => 'fa-rotate-right',
                    'tag' => 'Work in progress',
                    'hint' => 'In lavorazione dal team',
                ],
                'waiting' => [
                    'value' => (int) $waitingTickets,
                    'label' => 'In attesa',
                    'tone' => 'magenta',
                    'icon' => 'fa-hourglass-half',
                    'tag' => 'Serve risposta',
                    'hint' => 'Richiedono un tuo riscontro',
                ],
                'closed' => [
                    'value' => (int) $resolvedTickets,
                    'label' => 'Chiusi',
                    'tone' => 'emerald',
                    'icon' => 'fa-circle-check',
                    'tag' => 'Risolti',
                    'hint' => 'Ticket risolti o archiviati',
                ],
            ];
        ?>
        <div class="row g-3 mb-4">
            <?php foreach ($ticketCards as $card): ?>
                <div class="col-12 col-md-6 col-xl-3">
                    <div class="stat-neo stat-neo--<?php echo sanitize_output($card['tone']); ?> h-100">
                        <div class="stat-neo__glow" aria-hidden="true"></div>
                        <div class="stat-neo__body">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="d-flex flex-column gap-1">
                                    <span class="stat-neo__label"><?php echo sanitize_output($card['label']); ?></span>
                                    <span class="stat-neo__value"><?php echo sanitize_output(number_format($card['value'], 0, ',', '.')); ?></span>
                                </div>
                                <div class="stat-neo__icon" aria-hidden="true">
                                    <i class="fa-solid <?php echo sanitize_output($card['icon']); ?>"></i>
                                </div>
                            </div>
                            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                                <span class="stat-neo__tag"><?php echo sanitize_output($card['tag']); ?></span>
                                <span class="stat-neo__hint"><?php echo sanitize_output($card['hint']); ?></span>
                            </div>
                            <div class="stat-neo__footer">
                                <span class="stat-neo__dot"></span>
                                Aggiornato automaticamente
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
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
                            <a class="btn btn-light w-100" href="<?php echo opportunities_collaborator_url('tickets'); ?>">
                                Reset
                            </a>
                        <?php endif; ?>
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
                                            <a class="btn btn-sm btn-primary" href="<?php echo opportunities_collaborator_url('ticket-general'); ?>">
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
                                    $viewUrl = opportunities_collaborator_url('ticket-view', ['id' => (int) $ticket['id']]);
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
