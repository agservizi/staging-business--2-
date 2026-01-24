<?php
declare(strict_types=1);

use App\Services\SettingsService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');
$pageTitle = 'Pratiche ACI';

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

$clientsStmt = $pdo->query('SELECT id, nome, cognome, ragione_sociale FROM clienti ORDER BY ragione_sociale, cognome, nome');
$clients = $clientsStmt ? $clientsStmt->fetchAll() : [];

$puoCreare = current_user_can('Admin', 'Operatore', 'Manager');
$puoModificare = current_user_can('Admin', 'Operatore', 'Manager');
$puoEliminare = current_user_can('Admin');

$protocolloWizard = '';
try {
    $protocolloWizard = strtoupper(bin2hex(random_bytes(6)));
} catch (Throwable $e) {
    $fallback = strtoupper(str_replace(['-', '.', ' '], '', uniqid('', true)));
    $protocolloWizard = substr($fallback, 0, 12);
}

$filters = [
    'stato' => isset($_GET['stato']) && in_array($_GET['stato'], $stati, true) ? $_GET['stato'] : null,
    'tipo' => isset($_GET['tipo']) && in_array($_GET['tipo'], $tipi, true) ? $_GET['tipo'] : null,
    'cliente_id' => isset($_GET['cliente_id'])
        ? ($_GET['cliente_id'] === 'none'
            ? 'none'
            : (ctype_digit($_GET['cliente_id']) ? (int) $_GET['cliente_id'] : null))
        : null,
    'search' => trim($_GET['search'] ?? ''),
];

$params = [];
$sql = "SELECT p.*, c.nome, c.cognome, c.ragione_sociale
    FROM servizi_aci_pratiche p
    LEFT JOIN clienti c ON p.cliente_id = c.id
    WHERE 1 = 1";

if ($filters['stato']) {
    $sql .= ' AND p.stato = :stato';
    $params[':stato'] = $filters['stato'];
}

if ($filters['tipo']) {
    $sql .= ' AND p.tipo_pratica = :tipo';
    $params[':tipo'] = $filters['tipo'];
}

if ($filters['cliente_id'] !== null) {
    if ($filters['cliente_id'] === 'none') {
        $sql .= ' AND p.cliente_id IS NULL';
    } else {
        $sql .= ' AND p.cliente_id = :cliente_id';
        $params[':cliente_id'] = $filters['cliente_id'];
    }
}

if ($filters['search'] !== '') {
    $sql .= ' AND (p.targa LIKE :search OR p.telaio LIKE :search OR p.intestatario LIKE :search OR p.protocollo LIKE :search OR c.ragione_sociale LIKE :search OR c.nome LIKE :search OR c.cognome LIKE :search)';
    $params[':search'] = '%' . $filters['search'] . '%';
}

$sql .= ' ORDER BY p.updated_at DESC, p.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pratiche = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Pratiche ACI</h1>
                <p class="text-muted mb-0">Gestione pratiche automobilistiche per i clienti.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-primary" href="https://visurenet.aci.it/auth/login" target="_blank" rel="noopener" title="Apri in finestra anonima">Apri Visurenet (incognito)</a>
                <a class="btn btn-outline-warning" href="dashboard.php"><i class="fa-solid fa-gauge-high me-2"></i>Dashboard</a>
                <?php if ($puoCreare): ?>
                    <a class="btn btn-warning text-dark" href="create-wizard.php?protocollo=<?php echo urlencode($protocolloWizard); ?>"><i class="fa-solid fa-circle-plus me-2"></i>Nuova pratica</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Filtri</h2>
            </div>
            <div class="card-body">
                <form class="toolbar-search" method="get" role="search">
                    <div class="input-group flex-wrap flex-xl-nowrap">
                        <select class="form-select form-select-sm w-auto me-2 mb-2" id="stato" name="stato" aria-label="Filtra per stato">
                            <option value="">Stato: tutti</option>
                            <?php foreach ($stati as $stato): ?>
                                <option value="<?php echo sanitize_output($stato); ?>" <?php echo $filters['stato'] === $stato ? 'selected' : ''; ?>><?php echo sanitize_output($stato); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select form-select-sm w-auto me-2 mb-2" id="tipo" name="tipo" aria-label="Filtra per tipo">
                            <option value="">Tipo: tutti</option>
                            <?php foreach ($tipi as $tipo): ?>
                                <option value="<?php echo sanitize_output($tipo); ?>" <?php echo $filters['tipo'] === $tipo ? 'selected' : ''; ?>><?php echo sanitize_output($tipo); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select form-select-sm w-auto me-2 mb-2" id="cliente_id" name="cliente_id" aria-label="Filtra per cliente">
                            <option value="">Cliente: tutti</option>
                            <option value="none" <?php echo $filters['cliente_id'] === 'none' ? 'selected' : ''; ?>>Cliente non associato</option>
                            <?php foreach ($clients as $client): ?>
                                <?php
                                    $clientLabelParts = array_filter([
                                        $client['ragione_sociale'] ?: null,
                                        trim(($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? '')) ?: null,
                                    ]);
                                    $clientLabel = $clientLabelParts ? implode(' - ', $clientLabelParts) : ('#' . $client['id']);
                                ?>
                                <option value="<?php echo (int) $client['id']; ?>" <?php echo $filters['cliente_id'] === (int) $client['id'] ? 'selected' : ''; ?>><?php echo sanitize_output($clientLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input class="form-control me-2 mb-2" style="min-width: 220px;" id="search" type="search" name="search" value="<?php echo sanitize_output($filters['search']); ?>" placeholder="Cerca targa, telaio o cliente">
                        <button class="btn btn-warning mb-2" type="submit" title="Applica filtri"><i class="fa-solid fa-filter"></i></button>
                        <a class="btn btn-outline-warning mb-2" href="index.php" title="Reimposta filtri"><i class="fa-solid fa-rotate-left"></i></a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Pratiche registrate</h2>
            </div>
            <div class="card-body">
                <?php if ($pratiche): ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle" data-datatable="true">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tipo</th>
                                    <th>Cliente</th>
                                    <th>Targa/Telaio</th>
                                    <th>Stato</th>
                                    <th>Costi</th>
                                    <th>Apertura</th>
                                    <th>Scadenza</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pratiche as $pratica): ?>
                                    <tr>
                                        <td>#<?php echo (int) $pratica['id']; ?></td>
                                        <td>
                                            <strong><?php echo sanitize_output($pratica['tipo_pratica']); ?></strong>
                                            <?php if (!empty($pratica['protocollo'])): ?>
                                                <small class="d-block text-muted">Prot. <?php echo sanitize_output($pratica['protocollo']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                                $clientLabelParts = array_filter([
                                                    $pratica['ragione_sociale'] ?? null,
                                                    trim(($pratica['cognome'] ?? '') . ' ' . ($pratica['nome'] ?? '')) ?: null,
                                                ]);
                                                $clientLabel = $clientLabelParts ? implode(' - ', $clientLabelParts) : null;
                                            ?>
                                            <?php if ($pratica['cliente_id']): ?>
                                                <?php if ($clientLabel): ?>
                                                    <?php echo sanitize_output($clientLabel); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Cliente #<?php echo (int) $pratica['cliente_id']; ?></span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo sanitize_output($pratica['targa'] ?: '—'); ?>
                                            <?php if (!empty($pratica['telaio'])): ?>
                                                <small class="d-block text-muted">Telaio: <?php echo sanitize_output($pratica['telaio']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo sanitize_output($pratica['stato']); ?></span>
                                        </td>
                                        <td><?php echo sanitize_output(format_currency((float) ($pratica['costo'] ?? 0))); ?></td>
                                        <td><?php echo sanitize_output(format_date_locale($pratica['data_apertura'] ?? null)); ?></td>
                                        <td><?php echo sanitize_output(format_date_locale($pratica['data_scadenza'] ?? null)); ?></td>
                                        <td class="text-end">
                                            <div class="d-inline-flex gap-1" role="group">
                                                <a class="btn btn-outline-light px-2 py-1" href="view.php?id=<?php echo (int) $pratica['id']; ?>"><i class="fa-solid fa-eye"></i></a>
                                                <?php if ($puoModificare): ?>
                                                    <a class="btn btn-outline-light px-2 py-1" href="edit.php?id=<?php echo (int) $pratica['id']; ?>"><i class="fa-solid fa-pen"></i></a>
                                                <?php endif; ?>
                                                <?php if ($puoEliminare): ?>
                                                    <a class="btn btn-outline-danger px-2 py-1" href="delete.php?id=<?php echo (int) $pratica['id']; ?>" onclick="return confirm('Confermi la rimozione della pratica?');"><i class="fa-solid fa-trash"></i></a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">Nessuna pratica registrata.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
