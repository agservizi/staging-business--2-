<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Manager', 'Operatore');

$pageTitle = 'Consulenza fiscale rapida';
$service = consulenza_fiscale_service($pdo);
$statusOptions = consulenza_fiscale_status_options();
$modelOptions = consulenza_fiscale_model_options();
$frequencyOptions = consulenza_fiscale_frequency_options();
$reminderFilters = [
    '' => 'Tutti',
    'oggi' => 'Promemoria oggi',
    'settimana' => 'Entro 7 giorni',
    'scaduti' => 'Promemoria scaduti',
];

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'tipo_modello' => in_array($_GET['tipo_modello'] ?? '', array_keys($modelOptions), true) ? (string) $_GET['tipo_modello'] : '',
    'stato' => in_array($_GET['stato'] ?? '', array_keys($statusOptions), true) ? (string) $_GET['stato'] : '',
    'scadenza_dal' => isset($_GET['scadenza_dal']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['scadenza_dal']) ? (string) $_GET['scadenza_dal'] : '',
    'scadenza_al' => isset($_GET['scadenza_al']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $_GET['scadenza_al']) ? (string) $_GET['scadenza_al'] : '',
    'promemoria' => array_key_exists($_GET['promemoria'] ?? '', $reminderFilters) ? (string) ($_GET['promemoria'] ?? '') : '',
];

try {
    $consulenze = $service->list($filters);
    $summary = $service->summary();
    $loadError = null;
} catch (Throwable $exception) {
    $consulenze = [];
    $summary = [
        'statuses' => array_fill_keys(array_keys($statusOptions), 0),
        'reminders' => ['today' => 0, 'upcoming' => 0, 'overdue' => 0],
        'open_rates' => 0,
    ];
    $loadError = 'Impossibile caricare i dati della consulenza fiscale. Verifica le migrazioni del database.';
    error_log('Consulenza Fiscale index error: ' . $exception->getMessage());
}

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Consulenza fiscale rapida</h1>
                <p class="text-muted mb-0">Gestisci F24 e 730, calcola rate e monitora promemoria con archivio documentale firmato.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-warning" href="../../../dashboard.php">
                    <i class="fa-solid fa-gauge-high me-2"></i>Dashboard
                </a>
                <a class="btn btn-warning text-dark" href="create.php">
                    <i class="fa-solid fa-circle-plus me-2"></i>Nuova consulenza
                </a>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-xl-3 col-md-6">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <p class="text-muted text-uppercase small mb-1">Promemoria oggi</p>
                        <h2 class="h3 mb-0"><?php echo (int) ($summary['reminders']['today'] ?? 0); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <p class="text-muted text-uppercase small mb-1">Prossimi 7 giorni</p>
                        <h2 class="h3 mb-0"><?php echo (int) ($summary['reminders']['upcoming'] ?? 0); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <p class="text-muted text-uppercase small mb-1">Promemoria scaduti</p>
                        <h2 class="h3 mb-0"><?php echo (int) ($summary['reminders']['overdue'] ?? 0); ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-xl-3 col-md-6">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <p class="text-muted text-uppercase small mb-1">Rate aperte</p>
                        <h2 class="h3 mb-0"><?php echo (int) ($summary['open_rates'] ?? 0); ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Filtri</h2>
            </div>
            <div class="card-body">
                <form class="row g-3" method="get" role="search">
                    <div class="col-md-4">
                        <label class="form-label" for="search">Ricerca</label>
                        <input class="form-control" id="search" name="search" type="search" value="<?php echo sanitize_output($filters['search']); ?>" placeholder="Codice pratica, intestatario o note">
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label" for="tipo_modello">Modello</label>
                        <select class="form-select" id="tipo_modello" name="tipo_modello">
                            <option value="">Tutti</option>
                            <?php foreach ($modelOptions as $key => $label): ?>
                                <option value="<?php echo sanitize_output($key); ?>" <?php echo $filters['tipo_modello'] === $key ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label" for="stato">Stato</label>
                        <select class="form-select" id="stato" name="stato">
                            <option value="">Tutti</option>
                            <?php foreach ($statusOptions as $key => $label): ?>
                                <option value="<?php echo sanitize_output($key); ?>" <?php echo $filters['stato'] === $key ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label" for="scadenza_dal">Scadenza dal</label>
                        <input class="form-control" id="scadenza_dal" name="scadenza_dal" type="date" value="<?php echo sanitize_output($filters['scadenza_dal']); ?>">
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label" for="scadenza_al">Scadenza al</label>
                        <input class="form-control" id="scadenza_al" name="scadenza_al" type="date" value="<?php echo sanitize_output($filters['scadenza_al']); ?>">
                    </div>
                    <div class="col-md-3 col-lg-2">
                        <label class="form-label" for="promemoria">Promemoria</label>
                        <select class="form-select" id="promemoria" name="promemoria">
                            <?php foreach ($reminderFilters as $key => $label): ?>
                                <option value="<?php echo sanitize_output($key); ?>" <?php echo $filters['promemoria'] === $key ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-filter me-2"></i>Applica filtri</button>
                        <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-rotate-left me-2"></i>Reimposta</a>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($loadError !== null): ?>
            <div class="alert alert-warning" role="alert"><?php echo sanitize_output($loadError); ?></div>
        <?php endif; ?>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Consulenze registrate</h2>
                <span class="badge ag-badge"><?php echo count($consulenze); ?></span>
            </div>
            <div class="card-body p-0">
                <?php if ($consulenze): ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Codice</th>
                                    <th>Cliente</th>
                                    <th>Modello</th>
                                    <th>Importo</th>
                                    <th>Rate</th>
                                    <th>Prossima rata</th>
                                    <th>Promemoria</th>
                                    <th>Stato</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($consulenze as $row): ?>
                                    <?php
                                        $totalRate = (int) ($row['rate_totali'] ?? 0);
                                        $paidRate = (int) ($row['rate_pag'] ?? 0);
                                        $progress = $totalRate > 0 ? round(($paidRate / $totalRate) * 100) : 0;
                                        $customerParts = array_filter([
                                            $row['ragione_sociale'] ?? null,
                                            trim(($row['cliente_cognome'] ?? '') . ' ' . ($row['cliente_nome'] ?? '')) ?: null,
                                        ]);
                                        $customerLabel = $customerParts ? implode(' - ', $customerParts) : 'Cliente non associato';
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo sanitize_output($row['codice']); ?></strong>
                                            <div class="text-muted small">Anno <?php echo sanitize_output((string) ($row['anno_riferimento'] ?? '—')); ?></div>
                                        </td>
                                        <td><?php echo sanitize_output($customerLabel); ?></td>
                                        <td><?php echo sanitize_output($modelOptions[$row['tipo_modello']] ?? $row['tipo_modello']); ?></td>
                                        <td><?php echo sanitize_output(format_currency((float) ($row['importo_totale'] ?? 0))); ?></td>
                                        <td>
                                            <div class="d-flex align-items-center gap-2">
                                                <div class="progress flex-grow-1" style="height: 6px;">
                                                    <div class="progress-bar bg-warning" role="progressbar" style="width: <?php echo $progress; ?>%;" aria-valuenow="<?php echo $progress; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                                </div>
                                                <small><?php echo $paidRate; ?>/<?php echo $totalRate; ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['prossima_scadenza'])): ?>
                                                <?php echo sanitize_output(date('d/m/Y', strtotime((string) $row['prossima_scadenza']))); ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['promemoria_scadenza'])): ?>
                                                <?php echo sanitize_output(date('d/m/Y', strtotime((string) $row['promemoria_scadenza']))); ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge ag-badge text-uppercase"><?php echo sanitize_output(consulenza_fiscale_status_label($row['stato'] ?? '')); ?></span></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-2">
                                                <a class="btn btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $row['id']; ?>" title="Dettagli"><i class="fa-solid fa-eye"></i></a>
                                                <a class="btn btn-soft-accent btn-sm" href="edit.php?id=<?php echo (int) $row['id']; ?>" title="Modifica"><i class="fa-solid fa-pen"></i></a>
                                                <form method="post" action="delete.php" onsubmit="return confirm('Confermi la rimozione della consulenza <?php echo sanitize_output($row['codice']); ?>?');">
                                                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                    <input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
                                                    <button class="btn btn-soft-danger btn-sm" type="submit" title="Elimina"><i class="fa-solid fa-trash"></i></button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="p-5 text-center text-muted">
                        <i class="fa-solid fa-file-circle-plus fa-2x mb-3"></i>
                        <p class="mb-2">Non sono presenti consulenze fiscali con i filtri selezionati.</p>
                        <a class="btn btn-warning text-dark" href="create.php">Crea la prima consulenza</a>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
