<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Servizi Digitali & Web';

$statusLabels = [
    'preventivo' => 'Preventivo',
    'in_attesa_cliente' => 'In attesa cliente',
    'in_lavorazione' => 'In lavorazione',
    'consegnato' => 'Consegnato',
    'annullato' => 'Annullato',
];

$statusBadgeClass = [
    'preventivo' => 'bg-secondary',
    'in_attesa_cliente' => 'bg-warning text-dark',
    'in_lavorazione' => 'bg-info text-dark',
    'consegnato' => 'bg-success',
    'annullato' => 'bg-danger',
];

$filters = [
    'stato' => null,
    'search' => '',
];

if (isset($_GET['stato']) && in_array($_GET['stato'], SERVIZI_WEB_ALLOWED_STATUSES, true)) {
    $filters['stato'] = $_GET['stato'];
}

if (!empty($_GET['search'])) {
    $filters['search'] = trim((string) $_GET['search']);
}

$projects = servizi_web_fetch_projects($pdo, $filters);

$statsStmt = $pdo->query("SELECT stato, COUNT(*) AS total FROM servizi_web_progetti GROUP BY stato");
$statusCounts = array_fill_keys(SERVIZI_WEB_ALLOWED_STATUSES, 0);
foreach ($statsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $status = $row['stato'] ?? null;
    if ($status !== null && isset($statusCounts[$status])) {
        $statusCounts[$status] = (int) $row['total'];
    }
}

$totalProjects = array_sum($statusCounts);
$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Servizi Digitali &amp; Web</h1>
                <p class="text-muted mb-0">Gestisci preventivi, ordini e avanzamento dei progetti digitali dei clienti.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-rotate"></i></a>
                <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo progetto</a>
            </div>
        </div>
        <div class="row g-3 mb-4">
            <div class="col-sm-6 col-lg-3">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Totale progetti</div>
                        <div class="fs-3 fw-semibold"><?php echo number_format($totalProjects, 0, ',', '.'); ?></div>
                    </div>
                </div>
            </div>
            <?php foreach (SERVIZI_WEB_ALLOWED_STATUSES as $statusKey): ?>
                <div class="col-sm-6 col-lg-2">
                    <div class="card ag-card h-100">
                        <div class="card-body">
                            <div class="text-muted text-uppercase small"><?php echo sanitize_output($statusLabels[$statusKey]); ?></div>
                            <div class="fs-4 fw-semibold"><?php echo number_format($statusCounts[$statusKey], 0, ',', '.'); ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get" action="">
                    <div class="col-md-4">
                        <label class="form-label" for="search">Ricerca</label>
                        <input class="form-control" id="search" name="search" placeholder="Cerca per cliente, titolo o codice" value="<?php echo sanitize_output($filters['search']); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="stato">Stato</label>
                        <select class="form-select" id="stato" name="stato">
                            <option value="">Tutti</option>
                            <?php foreach ($statusLabels as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>" <?php echo $filters['stato'] === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <button class="btn btn-outline-warning text-dark mt-1" type="submit"><i class="fa-solid fa-magnifying-glass me-2"></i>Filtra</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark table-hover" data-datatable="true">
                        <thead>
                        <tr>
                            <th>Codice</th>
                            <th>Cliente</th>
                            <th>Titolo</th>
                            <th>Servizio</th>
                            <th>Dominio</th>
                            <th>Preventivo</th>
                            <th>Ordine</th>
                            <th>Consegna</th>
                            <th>Stato</th>
                            <th class="text-end">Azioni</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($projects as $project): ?>
                            <tr>
                                <td><span class="badge bg-secondary">#<?php echo sanitize_output($project['codice']); ?></span></td>
                                <td><?php echo sanitize_output(servizi_web_format_cliente($project)); ?></td>
                                <td><?php echo sanitize_output($project['titolo']); ?></td>
                                <td><?php echo sanitize_output($project['tipo_servizio']); ?></td>
                                <td><?php echo $project['dominio_richiesto'] ? sanitize_output($project['dominio_richiesto']) : '<span class="text-muted">—</span>'; ?></td>
                                <td>
                                    <?php if ($project['preventivo_numero']): ?>
                                        <div class="fw-semibold"><?php echo sanitize_output($project['preventivo_numero']); ?></div>
                                        <small class="text-muted"><?php echo $project['preventivo_importo'] !== null ? format_currency((float) $project['preventivo_importo']) : '—'; ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($project['ordine_numero']): ?>
                                        <div class="fw-semibold"><?php echo sanitize_output($project['ordine_numero']); ?></div>
                                        <small class="text-muted"><?php echo $project['ordine_importo'] !== null ? format_currency((float) $project['ordine_importo']) : '—'; ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo sanitize_output(format_date_locale($project['consegna_prevista'])); ?></td>
                                <td>
                                    <?php $status = $project['stato']; ?>
                                    <span class="badge <?php echo $statusBadgeClass[$status] ?? 'bg-dark'; ?>">
                                        <?php echo sanitize_output($statusLabels[$status] ?? $status); ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap">
                                        <a class="btn btn-icon btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $project['id']; ?>" title="Dettagli">
                                            <i class="fa-solid fa-eye"></i>
                                        </a>
                                        <a class="btn btn-icon btn-soft-accent btn-sm" href="edit.php?id=<?php echo (int) $project['id']; ?>" title="Modifica">
                                            <i class="fa-solid fa-pen"></i>
                                        </a>
                                        <form method="post" action="delete.php" onsubmit="return confirm('Confermi la cancellazione del progetto web selezionato?');">
                                            <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                            <input type="hidden" name="id" value="<?php echo (int) $project['id']; ?>">
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
                    <?php if (!$projects): ?>
                        <p class="text-muted mb-0">Nessun progetto trovato.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
