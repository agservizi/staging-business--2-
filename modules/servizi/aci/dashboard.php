<?php
declare(strict_types=1);

use App\Services\SettingsService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');

$pageTitle = 'Dashboard pratiche ACI';

$projectRoot = realpath(__DIR__ . '/../../../') ?: __DIR__ . '/../../../';
$settingsService = new SettingsService($pdo, $projectRoot);
$stati = $settingsService->getAciStatuses();
$tipi = $settingsService->getAciTypes();
if (!$stati) {
    $stati = SettingsService::defaultAciStatuses();
}
if (!$tipi) {
    $tipi = SettingsService::defaultAciTypes();
}

$puoCreare = current_user_can('Admin', 'Operatore', 'Manager');

$protocolloWizard = '';
try {
    $protocolloWizard = strtoupper(bin2hex(random_bytes(6)));
} catch (Throwable $e) {
    $fallback = strtoupper(str_replace(['-', '.', ' '], '', uniqid('', true)));
    $protocolloWizard = substr($fallback, 0, 12);
}

$totalCount = (int) $pdo->query('SELECT COUNT(*) FROM servizi_aci_pratiche')->fetchColumn();
$openCount = (int) $pdo->query("SELECT COUNT(*) FROM servizi_aci_pratiche WHERE stato NOT IN ('Chiusa', 'Completata')")->fetchColumn();
$byStatus = $pdo->query('SELECT stato, COUNT(*) AS totale FROM servizi_aci_pratiche GROUP BY stato ORDER BY totale DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
$byType = $pdo->query('SELECT tipo_pratica, COUNT(*) AS totale FROM servizi_aci_pratiche GROUP BY tipo_pratica ORDER BY totale DESC')->fetchAll(PDO::FETCH_ASSOC) ?: [];

$recentPratiche = $pdo->query("SELECT p.id, p.tipo_pratica, p.stato, p.targa, p.telaio, p.data_scadenza, p.updated_at,
        c.ragione_sociale, c.nome, c.cognome
    FROM servizi_aci_pratiche p
    LEFT JOIN clienti c ON p.cliente_id = c.id
    ORDER BY p.updated_at DESC, p.id DESC
    LIMIT 8")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$upcomingStmt = $pdo->query("SELECT p.id, p.tipo_pratica, p.stato, p.data_scadenza,
        c.ragione_sociale, c.nome, c.cognome
    FROM servizi_aci_pratiche p
    LEFT JOIN clienti c ON p.cliente_id = c.id
    WHERE p.data_scadenza IS NOT NULL
      AND p.data_scadenza >= CURDATE()
      AND p.data_scadenza <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
    ORDER BY p.data_scadenza ASC
    LIMIT 8");
$upcomingPratiche = $upcomingStmt ? $upcomingStmt->fetchAll(PDO::FETCH_ASSOC) : [];

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Dashboard pratiche ACI</h1>
                <p class="text-muted mb-0">Panoramica rapida delle pratiche automobilistiche.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-warning" href="<?php echo aci_module_url('index'); ?>"><i class="fa-solid fa-table-list me-2"></i>Elenco pratiche</a>
                <?php if ($puoCreare): ?>
                    <a class="btn btn-warning text-dark" href="<?php echo aci_module_url('create-wizard', ['protocollo' => $protocolloWizard]); ?>"><i class="fa-solid fa-circle-plus me-2"></i>Nuova pratica</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-md-4">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Totale pratiche</div>
                        <div class="fs-2 fw-semibold"><?php echo number_format($totalCount); ?></div>
                        <p class="text-muted mb-0">Pratiche registrate nel sistema.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Pratiche aperte</div>
                        <div class="fs-2 fw-semibold"><?php echo number_format($openCount); ?></div>
                        <p class="text-muted mb-0">Esclude stati chiusi/completati.</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card ag-card h-100">
                    <div class="card-body">
                        <div class="text-muted text-uppercase small">Tipologie attive</div>
                        <div class="fs-2 fw-semibold"><?php echo number_format(count($tipi)); ?></div>
                        <p class="text-muted mb-0">Tipologie configurate nelle impostazioni.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <div class="col-lg-6">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Pratiche per stato</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($byStatus): ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($byStatus as $row): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent text-white">
                                        <span><?php echo sanitize_output($row['stato']); ?></span>
                                        <span class="badge bg-secondary"><?php echo number_format((int) $row['totale']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted mb-0">Nessun dato disponibile.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Pratiche per tipologia</h2>
                    </div>
                    <div class="card-body">
                        <?php if ($byType): ?>
                            <ul class="list-group list-group-flush">
                                <?php foreach ($byType as $row): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-center bg-transparent text-white">
                                        <span><?php echo sanitize_output($row['tipo_pratica']); ?></span>
                                        <span class="badge bg-secondary"><?php echo number_format((int) $row['totale']); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted mb-0">Nessun dato disponibile.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Scadenze in 30 giorni</h2>
                        <a class="btn btn-sm btn-outline-warning" href="<?php echo aci_module_url('index', ['stato' => '']); ?>">Vedi elenco</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Cliente</th>
                                        <th>Tipo</th>
                                        <th>Scadenza</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($upcomingPratiche): ?>
                                        <?php foreach ($upcomingPratiche as $row): ?>
                                            <?php
                                                $clientLabelParts = array_filter([
                                                    $row['ragione_sociale'] ?? null,
                                                    trim(($row['cognome'] ?? '') . ' ' . ($row['nome'] ?? '')) ?: null,
                                                ]);
                                                $clientLabel = $clientLabelParts ? implode(' - ', $clientLabelParts) : '—';
                                            ?>
                                            <tr>
                                                <td>#<?php echo (int) $row['id']; ?></td>
                                                <td><?php echo sanitize_output($clientLabel); ?></td>
                                                <td><?php echo sanitize_output($row['tipo_pratica']); ?></td>
                                                <td><?php echo sanitize_output(format_date_locale($row['data_scadenza'] ?? null)); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">Nessuna scadenza imminente.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h2 class="h5 mb-0">Ultime pratiche aggiornate</h2>
                        <a class="btn btn-sm btn-outline-warning" href="<?php echo aci_module_url('index'); ?>">Vedi elenco</a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-dark table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Cliente</th>
                                        <th>Stato</th>
                                        <th>Aggiornata</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recentPratiche): ?>
                                        <?php foreach ($recentPratiche as $row): ?>
                                            <?php
                                                $clientLabelParts = array_filter([
                                                    $row['ragione_sociale'] ?? null,
                                                    trim(($row['cognome'] ?? '') . ' ' . ($row['nome'] ?? '')) ?: null,
                                                ]);
                                                $clientLabel = $clientLabelParts ? implode(' - ', $clientLabelParts) : '—';
                                            ?>
                                            <tr>
                                                <td>#<?php echo (int) $row['id']; ?></td>
                                                <td><?php echo sanitize_output($clientLabel); ?></td>
                                                <td><span class="badge bg-secondary"><?php echo sanitize_output($row['stato']); ?></span></td>
                                                <td><?php echo sanitize_output(format_datetime_locale($row['updated_at'] ?? null)); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">Nessuna pratica recente.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
