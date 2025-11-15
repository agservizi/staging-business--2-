<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Archivio certificati ANPR';

$types = anpr_practice_types();
$csrfToken = csrf_token();

$filterType = trim($_GET['tipo_pratica'] ?? '');
$filterQuery = trim($_GET['q'] ?? '');
$filterFrom = trim($_GET['dal'] ?? '');
$filterTo = trim($_GET['al'] ?? '');

$filters = [
    'has_certificate' => true,
    'order_by' => 'ap.certificato_caricato_at',
    'order_dir' => 'DESC',
];

if ($filterType !== '') {
    $filters['tipo_pratica'] = $filterType;
}
if ($filterQuery !== '') {
    $filters['query'] = $filterQuery;
}
if ($filterFrom !== '') {
    $filters['certificate_from'] = $filterFrom;
}
if ($filterTo !== '') {
    $filters['certificate_to'] = $filterTo;
}

$pratiche = anpr_fetch_pratiche($pdo, $filters);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Archivio certificati ANPR</h1>
                <p class="text-muted mb-0">Ricerca rapida dei certificati emessi e azioni di invio al cliente.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Elenco pratiche</a>
                <a class="btn btn-outline-warning" href="https://www.anagrafenazionale.interno.it/servizi-al-cittadino/" target="_blank" rel="noopener"><i class="fa-solid fa-up-right-from-square me-2"></i>Portale ANPR</a>
            </div>
        </div>
        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Filtra certificati</h2>
            </div>
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get" action="certificate_archive.php">
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="tipo_pratica">Tipologia</label>
                        <select class="form-select" id="tipo_pratica" name="tipo_pratica">
                            <option value="">Tutte</option>
                            <?php foreach ($types as $type): ?>
                                <option value="<?php echo sanitize_output($type); ?>" <?php echo $filterType === $type ? 'selected' : ''; ?>><?php echo sanitize_output($type); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="q">Ricerca libera</label>
                        <input class="form-control" id="q" name="q" value="<?php echo sanitize_output($filterQuery); ?>" placeholder="Codice pratica o cliente">
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="dal">Dal</label>
                        <input class="form-control" type="date" id="dal" name="dal" value="<?php echo sanitize_output($filterFrom); ?>">
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="al">Al</label>
                        <input class="form-control" type="date" id="al" name="al" value="<?php echo sanitize_output($filterTo); ?>">
                    </div>
                    <div class="col-12 col-lg-3">
                        <button class="btn btn-warning text-dark w-100" type="submit"><i class="fa-solid fa-filter me-2"></i>Filtra certificati</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-body">
                <?php if (!$pratiche): ?>
                    <p class="text-muted mb-0">Nessun certificato trovato con i filtri correnti.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Codice pratica</th>
                                    <th>Cliente</th>
                                    <th>Tipologia</th>
                                    <th>Caricato il</th>
                                    <th>Hash</th>
                                    <th>Invio al cliente</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pratiche as $pratica): ?>
                                    <tr>
                                        <td><strong><?php echo sanitize_output($pratica['pratica_code']); ?></strong></td>
                                        <td>
                                            <?php
                                                $displayName = trim(($pratica['ragione_sociale'] ?? '') !== ''
                                                    ? $pratica['ragione_sociale']
                                                    : trim(($pratica['cognome'] ?? '') . ' ' . ($pratica['nome'] ?? '')));
                                                echo $displayName !== '' ? sanitize_output($displayName) : '<span class="text-muted">N/D</span>';
                                            ?>
                                        </td>
                                        <td><?php echo sanitize_output($pratica['tipo_pratica']); ?></td>
                                        <td><?php echo sanitize_output(format_datetime_locale($pratica['certificato_caricato_at'] ?? '')); ?></td>
                                        <td class="small text-break"><?php echo !empty($pratica['certificato_hash']) ? '<code>' . sanitize_output($pratica['certificato_hash']) . '</code>' : '<span class="text-muted">N/D</span>'; ?></td>
                                        <td>
                                            <?php if (!empty($pratica['certificato_inviato_at'])): ?>
                                                <span class="badge bg-warning text-dark">Inviato <?php echo sanitize_output(format_datetime_locale($pratica['certificato_inviato_at'])); ?></span>
                                                <div class="text-muted small mt-1">
                                                    via <?php echo sanitize_output(strtoupper($pratica['certificato_inviato_via'] ?? '')); ?><br>
                                                    <?php echo sanitize_output($pratica['certificato_inviato_destinatario'] ?? ''); ?>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">In attesa</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group" role="group">
                                                <a class="btn btn-sm btn-outline-warning" href="view_request.php?id=<?php echo (int) $pratica['id']; ?>" title="Dettagli pratica"><i class="fa-solid fa-eye"></i></a>
                                                <a class="btn btn-sm btn-outline-warning" href="<?php echo sanitize_output(base_url($pratica['certificato_path'])); ?>" target="_blank" rel="noopener" title="Scarica PDF"><i class="fa-solid fa-file-pdf"></i></a>
                                                <?php if (!empty($pratica['cliente_email'])): ?>
                                                    <form method="post" action="send_certificate.php" class="d-inline">
                                                        <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                        <input type="hidden" name="pratica_id" value="<?php echo (int) $pratica['id']; ?>">
                                                        <input type="hidden" name="channel" value="email">
                                                        <input type="hidden" name="recipient" value="<?php echo sanitize_output($pratica['cliente_email']); ?>">
                                                        <button class="btn btn-sm btn-outline-warning" type="submit" title="Invia via email"><i class="fa-solid fa-paper-plane"></i></button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
