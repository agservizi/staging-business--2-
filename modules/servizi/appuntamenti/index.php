<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

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

$appointmentSummary = [
    'total' => count($appointments),
    'active' => 0,
    'confirmed' => 0,
    'today' => 0,
    'with_client' => 0,
];

$todayDate = date('Y-m-d');
foreach ($appointments as $appointment) {
    $statusValue = (string) ($appointment['stato'] ?? '');
    $startValue = (string) ($appointment['data_inizio'] ?? '');
    $clientIdValue = (int) ($appointment['cliente_id'] ?? 0);

    if (in_array($statusValue, $activeStatuses, true)) {
        $appointmentSummary['active']++;
    }

    if ($confirmationStatus !== '' && strcasecmp($statusValue, $confirmationStatus) === 0) {
        $appointmentSummary['confirmed']++;
    }

    if ($startValue !== '' && str_starts_with($startValue, $todayDate)) {
        $appointmentSummary['today']++;
    }

    if ($clientIdValue > 0) {
        $appointmentSummary['with_client']++;
    }
}

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
        <style>
            .appointments-shell {
                display: grid;
                gap: 1.5rem;
            }

            .appointments-hero {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(58, 123, 213, 0.14);
                background:
                    radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 34%),
                    radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), transparent 26%),
                    #fff;
            }

            .appointments-pill {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.45rem 0.85rem;
                border-radius: 999px;
                background: rgba(58, 123, 213, 0.10);
                color: #2154d7;
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .appointments-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .appointments-kpi {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.15rem;
                padding: 1rem 1.1rem;
                background: rgba(255, 255, 255, 0.88);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
            }

            .appointments-kpi-label {
                display: block;
                margin-bottom: 0.4rem;
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .appointments-kpi-value {
                display: block;
                color: #0f172a;
                font-size: 1.85rem;
                font-weight: 800;
                line-height: 1;
            }

            .appointments-kpi-note {
                display: block;
                margin-top: 0.45rem;
                color: #64748b;
                font-size: 0.86rem;
            }

            .appointments-panel {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.3rem;
                background: #fff;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
            }

            .appointments-toolbar-grid {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr)) minmax(0, 1.4fr) repeat(2, minmax(0, .9fr)) auto;
                gap: 0.85rem;
                align-items: end;
            }

            .appointments-table-card-body {
                padding: 1.25rem 1.25rem 1.4rem;
            }

            .appointments-table-card-body .table-responsive {
                border: 1px solid rgba(15, 23, 42, 0.06);
                border-radius: 1rem;
                overflow: hidden;
            }

            .appointments-table-card-body .dt-container .dt-layout-row:not(.dt-layout-table) {
                margin: 0;
                padding-inline: 0.15rem;
            }

            .appointments-table-card-body .dt-container .dt-layout-row:first-child {
                padding-bottom: 1rem;
            }

            .appointments-table-card-body .dt-container .dt-layout-row:last-child {
                padding-top: 1rem;
            }

            .appointments-table {
                --bs-table-bg: transparent;
                --bs-table-hover-bg: rgba(37, 99, 235, 0.04);
                margin-bottom: 0;
            }

            .appointments-table thead th {
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                white-space: nowrap;
            }

            .appointments-table td {
                padding-top: 1rem;
                padding-bottom: 1rem;
                border-color: rgba(15, 23, 42, 0.06);
                vertical-align: middle;
            }

            .appointments-id {
                display: inline-flex;
                padding: 0.42rem 0.68rem;
                border-radius: 0.8rem;
                background: #f8fafc;
                color: #0f172a;
                font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
                font-size: 0.8rem;
                font-weight: 700;
            }

            @media (max-width: 1199.98px) {
                .appointments-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }

                .appointments-toolbar-grid {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767.98px) {
                .appointments-kpis,
                .appointments-toolbar-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <div class="appointments-shell">
        <section class="card appointments-hero">
            <div class="card-body p-4 p-xl-5">
                <div class="row g-4 align-items-start">
                    <div class="col-12 col-xl-7">
                        <span class="appointments-pill"><i class="fa-solid fa-calendar-days"></i>Agenda operativa</span>
                        <h1 class="mt-3 mb-2 fw-bold" style="max-width: 12ch;">Appuntamenti più chiari per agenda, responsabilità e priorità.</h1>
                        <p class="text-muted mb-0" style="max-width: 70ch;">
                            Tieni sotto controllo sopralluoghi, incontri e scadenze operative, filtra rapidamente per responsabile o cliente e raggiungi ogni voce con una lettura più ordinata.
                        </p>
                    </div>
                    <div class="col-12 col-xl-5">
                        <div class="appointments-kpis">
                            <div class="appointments-kpi">
                                <span class="appointments-kpi-label">Appuntamenti</span>
                                <span class="appointments-kpi-value"><?php echo (int) $appointmentSummary['total']; ?></span>
                                <span class="appointments-kpi-note">Risultati del filtro attivo</span>
                            </div>
                            <div class="appointments-kpi">
                                <span class="appointments-kpi-label">Attivi</span>
                                <span class="appointments-kpi-value"><?php echo (int) $appointmentSummary['active']; ?></span>
                                <span class="appointments-kpi-note">Negli stati operativi correnti</span>
                            </div>
                            <div class="appointments-kpi">
                                <span class="appointments-kpi-label">Confermati</span>
                                <span class="appointments-kpi-value"><?php echo (int) $appointmentSummary['confirmed']; ?></span>
                                <span class="appointments-kpi-note">Pronti per il calendario</span>
                            </div>
                            <div class="appointments-kpi">
                                <span class="appointments-kpi-label">Oggi</span>
                                <span class="appointments-kpi-value"><?php echo (int) $appointmentSummary['today']; ?></span>
                                <span class="appointments-kpi-note"><?php echo (int) $appointmentSummary['with_client']; ?> con cliente associato</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="card appointments-panel">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <h2 class="h5 mb-1">Filtri agenda</h2>
                        <p class="text-muted small mb-0">Raffina gli appuntamenti per stato, responsabile, cliente o intervallo temporale.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-outline-warning" href="<?php echo dashboard_url(); ?>"><i class="fa-solid fa-gauge-high me-2"></i>Dashboard</a>
                        <a class="btn btn-warning text-dark" href="<?php echo appuntamenti_module_url('create'); ?>"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo appuntamento</a>
                    </div>
                </div>
                <form method="get" role="search">
                    <div class="appointments-toolbar-grid">
                        <div>
                            <label class="form-label small text-uppercase text-muted fw-semibold" for="stato">Stato</label>
                            <select class="form-select" name="stato" id="stato" aria-label="Filtra per stato">
                                <option value="">Tutti gli stati</option>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?php echo sanitize_output($status); ?>" <?php echo $filterStatus === $status ? 'selected' : ''; ?>><?php echo sanitize_output($status); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="form-label small text-uppercase text-muted fw-semibold" for="responsabile">Responsabile</label>
                            <select class="form-select" name="responsabile" id="responsabile" aria-label="Filtra per responsabile">
                                <option value="">Tutti i responsabili</option>
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
                        </div>
                        <div>
                            <label class="form-label small text-uppercase text-muted fw-semibold" for="cliente_id">Cliente</label>
                            <select class="form-select" name="cliente_id" id="cliente_id" aria-label="Filtra per cliente">
                                <option value="">Tutti i clienti</option>
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
                        </div>
                        <div>
                            <label class="form-label small text-uppercase text-muted fw-semibold" for="q">Ricerca</label>
                            <input class="form-control" type="search" id="q" name="q" value="<?php echo sanitize_output($filterSearch); ?>" placeholder="Titolo, luogo o cliente">
                        </div>
                        <div>
                            <label class="form-label small text-uppercase text-muted fw-semibold" for="dal">Dal</label>
                            <input class="form-control" type="date" id="dal" name="dal" value="<?php echo sanitize_output($filterFrom); ?>" aria-label="Dal">
                        </div>
                        <div>
                            <label class="form-label small text-uppercase text-muted fw-semibold" for="al">Al</label>
                            <input class="form-control" type="date" id="al" name="al" value="<?php echo sanitize_output($filterTo); ?>" aria-label="Al">
                        </div>
                        <div class="d-flex gap-2">
                            <button class="btn btn-warning w-100" type="submit"><i class="fa-solid fa-filter me-2"></i>Filtra</button>
                            <a class="btn btn-outline-warning" href="<?php echo appuntamenti_module_url('index'); ?>" title="Reimposta filtri"><i class="fa-solid fa-rotate-left"></i></a>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section class="card appointments-panel">
            <div class="card-header bg-transparent border-0 px-4 pt-4 pb-0">
                <h2 class="h5 mb-1">Agenda appuntamenti</h2>
                <p class="text-muted small mb-0">Elenco operativo di incontri, sopralluoghi e scadenze con cliente, responsabile e stato.</p>
            </div>
            <div class="card-body appointments-table-card-body">
                <?php if ($appointments): ?>
                    <div class="table-responsive">
                        <table class="table appointments-table table-hover align-middle" data-datatable="true">
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
                                        <td><span class="appointments-id">#<?php echo (int) $row['id']; ?></span></td>
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
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo appuntamenti_module_url('view', ['id' => (int) $row['id']]); ?>" title="Dettagli">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo appuntamenti_module_url('edit', ['id' => (int) $row['id']]); ?>" title="Modifica">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                <?php if ($calendarEnabled && $confirmationStatus !== '' && strcasecmp((string) ($row['stato'] ?? ''), $confirmationStatus) === 0): ?>
                                                    <form method="post" action="<?php echo appuntamenti_module_url('sync-calendar'); ?>" class="d-inline">
                                                        <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                        <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                                        <button class="btn btn-icon btn-soft-accent btn-sm" type="submit" title="Sincronizza Google Calendar">
                                                            <i class="fa-solid fa-rotate"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="post" action="<?php echo appuntamenti_module_url('delete'); ?>" class="d-inline" onsubmit="return confirm('Confermi eliminazione dell\'appuntamento?');">
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
                        <a class="btn btn-outline-warning" href="<?php echo appuntamenti_module_url('index'); ?>">Reimposta filtri</a>
                    </div>
                <?php endif; ?>
            </div>
        </section>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
