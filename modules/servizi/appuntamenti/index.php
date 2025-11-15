<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

use PDO;

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Appuntamenti';

$statusConfig = get_appointment_status_config($pdo);
$availableStatuses = $statusConfig['available'];
$activeStatuses = $statusConfig['active'] ?: $availableStatuses;
$confirmationStatus = $statusConfig['confirmation'];
$clientsStmt = $pdo->query('SELECT id, nome, cognome, ragione_sociale FROM clienti ORDER BY ragione_sociale, cognome, nome');
$clients = $clientsStmt ? $clientsStmt->fetchAll() : [];

$responsabileDirectory = [];
$userDirectoryStmt = $pdo->query("SELECT username, nome, cognome FROM users WHERE ruolo IN ('Admin', 'Manager', 'Operatore')");
if ($userDirectoryStmt) {
    $userDirectoryRows = $userDirectoryStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($userDirectoryRows as $row) {
        $fullName = trim(((string) ($row['nome'] ?? '')) . ' ' . ((string) ($row['cognome'] ?? '')));
        $label = $fullName !== '' ? $fullName : (string) ($row['username'] ?? '');
        if ($label !== '') {
            $responsabileDirectory[strtolower((string) ($row['username'] ?? ''))] = $label;
        }
    }
}

$params = [];
$filterStatus = trim($_GET['stato'] ?? '');
$filterOwner = trim($_GET['responsabile'] ?? '');
$filterFrom = trim($_GET['dal'] ?? '');
$filterTo = trim($_GET['al'] ?? '');
$filterClientId = isset($_GET['cliente_id']) && ctype_digit($_GET['cliente_id']) ? (int) $_GET['cliente_id'] : null;
$filterSearch = trim($_GET['q'] ?? '');

$sql = "SELECT sa.id, sa.titolo, sa.tipo_servizio, sa.responsabile, sa.stato, sa.data_inizio, sa.data_fine, sa.luogo, c.nome, c.cognome
    , c.ragione_sociale, c.id AS cliente_id
    FROM servizi_appuntamenti sa
    LEFT JOIN clienti c ON sa.cliente_id = c.id";

$where = [];
if ($filterStatus !== '') {
    $where[] = 'sa.stato = :stato';
    $params[':stato'] = $filterStatus;
}
if ($filterOwner !== '') {
    $where[] = 'sa.responsabile = :responsabile';
    $params[':responsabile'] = $filterOwner;
}
if ($filterClientId !== null) {
    $where[] = 'sa.cliente_id = :cliente_id';
    $params[':cliente_id'] = $filterClientId;
}
if ($filterFrom !== '') {
    $fromDate = DateTimeImmutable::createFromFormat('Y-m-d', $filterFrom) ?: null;
    if ($fromDate) {
        $where[] = 'sa.data_inizio >= :dal';
        $params[':dal'] = $fromDate->format('Y-m-d 00:00:00');
    } else {
        $filterFrom = '';
    }
}
if ($filterTo !== '') {
    $toDate = DateTimeImmutable::createFromFormat('Y-m-d', $filterTo) ?: null;
    if ($toDate) {
        $where[] = 'sa.data_inizio <= :al';
        $params[':al'] = $toDate->format('Y-m-d 23:59:59');
    } else {
        $filterTo = '';
    }
}
if ($filterSearch !== '') {
    $where[] = '(sa.titolo LIKE :search OR sa.tipo_servizio LIKE :search OR sa.luogo LIKE :search OR c.nome LIKE :search OR c.cognome LIKE :search OR c.ragione_sociale LIKE :search)';
    $params[':search'] = '%' . $filterSearch . '%';
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY sa.data_inizio DESC, sa.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$appointments = $stmt->fetchAll();

$statuses = $availableStatuses;
$dbStatuses = $pdo->query('SELECT DISTINCT stato FROM servizi_appuntamenti ORDER BY stato')->fetchAll(PDO::FETCH_COLUMN);
if ($dbStatuses) {
    foreach ($dbStatuses as $dbStatus) {
        if (!in_array($dbStatus, $statuses, true)) {
            $statuses[] = $dbStatus;
        }
    }
}
if (!$statuses) {
    $statuses = $dbStatuses ?: ['Programmato', 'Confermato', 'In corso', 'Completato', 'Annullato'];
}

$owners = $pdo->query("SELECT DISTINCT responsabile FROM servizi_appuntamenti WHERE responsabile IS NOT NULL AND responsabile <> '' ORDER BY responsabile")->fetchAll(PDO::FETCH_COLUMN);
$calendarService = new \App\Services\GoogleCalendarService();
$calendarEnabled = $calendarService->isEnabled();
$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Appuntamenti</h1>
                <p class="text-muted mb-0">Agenda appuntamenti, sopralluoghi e scadenze operative.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo appuntamento</a>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Filtri</h2>
            </div>
            <div class="card-body">
                <form class="toolbar-search" method="get" role="search">
                    <div class="input-group flex-wrap flex-xl-nowrap">
                        <select class="form-select" name="stato" id="stato" aria-label="Filtra per stato">
                            <option value="">Stato: tutti</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo sanitize_output($status); ?>" <?php echo $filterStatus === $status ? 'selected' : ''; ?>><?php echo sanitize_output($status); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select" name="responsabile" id="responsabile" aria-label="Filtra per responsabile">
                            <option value="">Responsabile: tutti</option>
                            <?php foreach ($owners as $owner): ?>
                                <?php
                                    $ownerLabel = $owner;
                                    $ownerKey = strtolower($owner);
                                    if (isset($responsabileDirectory[$ownerKey])) {
                                        $ownerLabel = $responsabileDirectory[$ownerKey];
                                    }
                                ?>
                                <option value="<?php echo sanitize_output($owner); ?>" <?php echo $filterOwner === $owner ? 'selected' : ''; ?>><?php echo sanitize_output($ownerLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select" name="cliente_id" id="cliente_id" aria-label="Filtra per cliente">
                            <option value="">Cliente: tutti</option>
                            <?php foreach ($clients as $client): ?>
                                <?php
                                    $company = trim((string) ($client['ragione_sociale'] ?? ''));
                                    $person = trim(($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? ''));
                                    $label = $company !== '' && $person !== '' ? $company . ' - ' . $person : ($company !== '' ? $company : $person);
                                    if ($label === '') {
                                        $label = 'Cliente #' . (int) $client['id'];
                                    }
                                ?>
                                <option value="<?php echo (int) $client['id']; ?>" <?php echo $filterClientId === (int) $client['id'] ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input class="form-control" type="search" id="q" name="q" value="<?php echo sanitize_output($filterSearch); ?>" placeholder="Cerca titolo, luogo o cliente">
                        <input class="form-control" type="date" id="dal" name="dal" value="<?php echo sanitize_output($filterFrom); ?>" aria-label="Dal">
                        <input class="form-control" type="date" id="al" name="al" value="<?php echo sanitize_output($filterTo); ?>" aria-label="Al">
                        <button class="btn btn-warning" type="submit" title="Applica filtri"><i class="fa-solid fa-filter"></i></button>
                        <a class="btn btn-outline-warning" href="index.php" title="Reimposta filtri"><i class="fa-solid fa-rotate-left"></i></a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Agenda appuntamenti</h2>
            </div>
            <div class="card-body">
                <?php if ($appointments): ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle" data-datatable="true">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Cliente</th>
                                    <th>Titolo</th>
                                    <th>Tipo</th>
                                    <th>Responsabile</th>
                                    <th>Inizio</th>
                                    <th>Fine</th>
                                    <th>Stato</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appointments as $row): ?>
                                    <tr>
                                        <td>#<?php echo (int) $row['id']; ?></td>
                                        <td>
                                            <?php
                                                $company = trim((string) ($row['ragione_sociale'] ?? ''));
                                                $person = trim(($row['cognome'] ?? '') . ' ' . ($row['nome'] ?? ''));
                                                $label = $company !== '' && $person !== '' ? $company . ' - ' . $person : ($company !== '' ? $company : $person);
                                                echo $label !== '' ? sanitize_output($label) : '<span class="text-muted">N/D</span>';
                                            ?>
                                        </td>
                                        <td>
                                            <strong><?php echo sanitize_output($row['titolo'] ?? ''); ?></strong><br>
                                            <?php if (!empty($row['luogo'])): ?>
                                                <small class="text-muted"><?php echo sanitize_output($row['luogo']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo sanitize_output($row['tipo_servizio'] ?? ''); ?></td>
                                        <td>
                                            <?php
                                                $responsabileValue = trim((string) ($row['responsabile'] ?? ''));
                                                $label = $responsabileValue;
                                                $lookupKey = strtolower($responsabileValue);
                                                if ($responsabileValue !== '' && isset($responsabileDirectory[$lookupKey])) {
                                                    $label = $responsabileDirectory[$lookupKey];
                                                }
                                                echo $label !== '' ? sanitize_output($label) : '<span class="text-muted">N/D</span>';
                                            ?>
                                        </td>
                                        <td>
                                            <?php
                                                $startAt = format_datetime_locale($row['data_inizio'] ?? '');
                                                echo $startAt !== '' ? sanitize_output($startAt) : '<span class="text-muted">—</span>';
                                            ?>
                                        </td>
                                        <td><?php echo $row['data_fine'] ? sanitize_output(format_datetime_locale($row['data_fine'])) : '<span class="text-muted">—</span>'; ?></td>
                                        <td><span class="badge ag-badge text-uppercase"><?php echo sanitize_output($row['stato'] ?? ''); ?></span></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap">
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $row['id']; ?>" title="Dettagli">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="edit.php?id=<?php echo (int) $row['id']; ?>" title="Modifica">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                <?php if ($calendarEnabled && $confirmationStatus !== '' && strcasecmp((string) ($row['stato'] ?? ''), $confirmationStatus) === 0): ?>
                                                    <form method="post" action="sync-calendar.php" class="d-inline">
                                                        <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                                        <button class="btn btn-icon btn-soft-accent btn-sm" type="submit" title="Sincronizza Google Calendar">
                                                            <i class="fa-solid fa-rotate"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('Confermi eliminazione dell\'appuntamento?');">
                                                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                                    <button class="btn btn-icon btn-soft-danger btn-sm" type="submit" title="Elimina">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-5">
                        <i class="fa-solid fa-calendar-xmark fa-2x mb-3"></i>
                        <p class="mb-1">Nessun appuntamento corrisponde ai filtri selezionati.</p>
                        <a class="btn btn-outline-warning" href="index.php">Reimposta filtri</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
