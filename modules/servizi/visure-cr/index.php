<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');

$pageTitle = 'Visure CR';

$stati = ['Bozza', 'Inviata', 'In lavorazione', 'Completata', 'Rifiutata'];
$tipi = ['persona_fisica' => 'Persona fisica', 'persona_giuridica' => 'Persona giuridica'];

$filters = [
    'stato' => isset($_GET['stato']) && in_array($_GET['stato'], $stati, true) ? $_GET['stato'] : null,
    'tipo' => isset($_GET['tipo']) && array_key_exists($_GET['tipo'], $tipi) ? $_GET['tipo'] : null,
    'search' => trim($_GET['search'] ?? ''),
];

$params = [];
$sql = "SELECT * FROM servizi_visure_cr_pratiche WHERE 1 = 1";

if ($filters['stato']) {
    $sql .= ' AND stato = :stato';
    $params[':stato'] = $filters['stato'];
}
if ($filters['tipo']) {
    $sql .= ' AND tipo_visura = :tipo';
    $params[':tipo'] = $filters['tipo'];
}
if ($filters['search'] !== '') {
    $sql .= ' AND (nome LIKE :search OR cognome LIKE :search OR codice_fiscale LIKE :search OR ragione_sociale LIKE :search OR partita_iva LIKE :search)';
    $params[':search'] = '%' . $filters['search'] . '%';
}

$sql .= ' ORDER BY updated_at DESC, id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$richieste = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$puoCreare = current_user_can('Admin', 'Operatore', 'Manager');

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Visure CR</h1>
                <p class="text-muted mb-0">Gestione richieste visura Centrale Rischi.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-warning" href="<?php echo sanitize_output(visure_cr_module_url('dashboard')); ?>"><i class="fa-solid fa-gauge-high me-2"></i>Dashboard</a>
                <?php if ($puoCreare): ?>
                    <a class="btn btn-warning text-dark" href="<?php echo sanitize_output(visure_cr_module_url('create')); ?>"><i class="fa-solid fa-circle-plus me-2"></i>Nuova richiesta</a>
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
                            <?php foreach ($tipi as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>" <?php echo $filters['tipo'] === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input class="form-control me-2 mb-2" style="min-width: 220px;" id="search" type="search" name="search" value="<?php echo sanitize_output($filters['search']); ?>" placeholder="Cerca nome, CF o P.IVA">
                        <button class="btn btn-warning mb-2" type="submit" title="Applica filtri"><i class="fa-solid fa-filter"></i></button>
                        <a class="btn btn-outline-warning mb-2" href="<?php echo sanitize_output(visure_cr_module_url('index')); ?>" title="Reimposta filtri"><i class="fa-solid fa-rotate-left"></i></a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Richieste visura</h2>
            </div>
            <div class="card-body">
                <?php if ($richieste): ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle" data-datatable="true">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Tipo</th>
                                    <th>Richiedente</th>
                                    <th>Contatti</th>
                                    <th>Stato</th>
                                    <th>Ultimo aggiornamento</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($richieste as $row): ?>
                                    <?php
                                        $isFisica = $row['tipo_visura'] === 'persona_fisica';
                                        $displayName = $isFisica
                                            ? trim(($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? ''))
                                            : (string) ($row['ragione_sociale'] ?? '');
                                        $email = $isFisica ? ($row['email'] ?? '') : ($row['email_aziendale'] ?? '');
                                        $telefono = $isFisica ? ($row['telefono'] ?? '') : ($row['telefono_aziendale'] ?? '');
                                    ?>
                                    <tr>
                                        <td>#<?php echo (int) $row['id']; ?></td>
                                        <td><?php echo sanitize_output($tipi[$row['tipo_visura']] ?? $row['tipo_visura']); ?></td>
                                        <td><?php echo sanitize_output($displayName ?: '—'); ?></td>
                                        <td>
                                            <?php echo sanitize_output($email ?: '—'); ?>
                                            <?php if ($telefono): ?>
                                                <small class="d-block text-muted"><?php echo sanitize_output($telefono); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="badge bg-secondary"><?php echo sanitize_output($row['stato']); ?></span></td>
                                        <td><?php echo sanitize_output(format_datetime_locale($row['updated_at'] ?? null)); ?></td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a class="btn btn-outline-light" href="<?php echo sanitize_output(visure_cr_module_url('view', ['id' => (int) $row['id']])); ?>"><i class="fa-solid fa-eye"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0">Nessuna richiesta presente.</p>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
