<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Servizi ANPR';

$statuses = ANPR_ALLOWED_STATUSES;
$types = anpr_practice_types();
$catalog = anpr_service_catalog();
$csrfToken = csrf_token();

$filterStatus = trim($_GET['stato'] ?? '');
$filterType = trim($_GET['tipo_pratica'] ?? '');
$filterQuery = trim($_GET['q'] ?? '');
$filterCliente = (int) ($_GET['cliente_id'] ?? 0);

$filters = [];
if ($filterStatus !== '') {
    $filters['stato'] = $filterStatus;
}
if ($filterType !== '') {
    $filters['tipo_pratica'] = $filterType;
}
if ($filterQuery !== '') {
    $filters['query'] = $filterQuery;
}
if ($filterCliente > 0) {
    $filters['cliente_id'] = $filterCliente;
}

$pratiche = anpr_fetch_pratiche($pdo, $filters);
$clienti = anpr_fetch_clienti($pdo);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Servizi ANPR</h1>
                <p class="text-muted mb-0">Gestione pratiche anagrafiche e certificati digitali.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-warning" href="https://www.anagrafenazionale.interno.it/servizi-al-cittadino/" target="_blank" rel="noopener">
                    <i class="fa-solid fa-up-right-from-square me-2"></i>Portale ANPR
                </a>
                <a class="btn btn-outline-warning" href="certificate_archive.php">
                    <i class="fa-solid fa-box-archive me-2"></i>Archivio certificati
                </a>
                <a class="btn btn-warning text-dark" href="add_request.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuova pratica</a>
            </div>
        </div>
        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Filtri pratiche</h2>
            </div>
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get" action="index.php">
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="stato">Stato</label>
                        <select class="form-select" id="stato" name="stato">
                            <option value="">Tutti</option>
                            <?php foreach ($statuses as $statusOption): ?>
                                <option value="<?php echo sanitize_output($statusOption); ?>" <?php echo $filterStatus === $statusOption ? 'selected' : ''; ?>><?php echo sanitize_output($statusOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
                        <label class="form-label" for="cliente_id">Cliente</label>
                        <select class="form-select" id="cliente_id" name="cliente_id">
                            <option value="">Tutti</option>
                            <?php foreach ($clienti as $cliente): ?>
                                <?php $cid = (int) $cliente['id']; ?>
                                <option value="<?php echo $cid; ?>" <?php echo $filterCliente === $cid ? 'selected' : ''; ?>><?php echo sanitize_output(trim($cliente['ragione_sociale'] ?: (($cliente['cognome'] ?? '') . ' ' . ($cliente['nome'] ?? '')))); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <label class="form-label" for="q">Ricerca</label>
                        <input class="form-control" id="q" name="q" value="<?php echo sanitize_output($filterQuery); ?>" placeholder="Codice pratica o cliente">
                    </div>
                    <div class="col-12 col-lg-3">
                        <button class="btn btn-warning text-dark w-100" type="submit"><i class="fa-solid fa-filter me-2"></i>Applica filtri</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-body">
                <?php if (!$pratiche): ?>
                    <p class="text-muted mb-0">Nessuna pratica trovata con i filtri correnti.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Codice</th>
                                    <th>Cliente</th>
                                    <th>Tipologia</th>
                                    <th>Stato</th>
                                    <th>Operatore</th>
                                    <th>Creato il</th>
                                    <th>Certificato</th>
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
                                        <td><span class="badge ag-badge text-uppercase"><?php echo sanitize_output($pratica['stato']); ?></span></td>
                                        <td><?php echo $pratica['operatore_username'] ? sanitize_output($pratica['operatore_username']) : '<span class="text-muted">N/D</span>'; ?></td>
                                        <td><?php echo sanitize_output(format_datetime_locale($pratica['created_at'] ?? '')); ?></td>
                                        <td>
                                            <?php if (!empty($pratica['certificato_path'])): ?>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo sanitize_output(base_url($pratica['certificato_path'])); ?>" target="_blank" rel="noopener" title="Scarica certificato">
                                                    <i class="fa-solid fa-file-arrow-down"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-muted">Non caricato</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap" role="group">
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="view_request.php?id=<?php echo (int) $pratica['id']; ?>" title="Dettagli">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="edit_request.php?id=<?php echo (int) $pratica['id']; ?>" title="Modifica">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="upload_certificate.php?id=<?php echo (int) $pratica['id']; ?>" title="Carica certificato">
                                                    <i class="fa-solid fa-file-arrow-up"></i>
                                                </a>
                                                <form method="post" action="delete_request.php" class="d-inline"
                                                    data-confirm="Confermi eliminazione della pratica?"
                                                    data-confirm-title="Elimina pratica"
                                                    data-confirm-confirm-label="Elimina"
                                                    data-confirm-class="btn btn-danger">
                                                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                    <input type="hidden" name="id" value="<?php echo (int) $pratica['id']; ?>">
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
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-4 mt-1">
            <div class="col-lg-7">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Servizi da listino</h2>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Aggiorna il listino in base al comune di competenza e comunica al cliente tempi e canali d&apos;invio.</p>
                        <div class="table-responsive">
                            <table class="table table-dark table-striped align-middle">
                                <thead>
                                    <tr>
                                        <th>Servizio</th>
                                        <th class="text-center">Prezzo medio</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($catalog as $service): ?>
                                        <tr>
                                            <td><?php echo sanitize_output($service['servizio']); ?></td>
                                            <td class="text-center fw-semibold"><?php echo sanitize_output($service['prezzo']); ?></td>
                                            <td><?php echo $service['note'] !== '' ? sanitize_output($service['note']) : '<span class="text-muted">—</span>'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <small class="text-muted">I prezzi sono indicativi e vanno adeguati al tariffario interno.</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Idee extra per il servizio</h2>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled mb-4">
                            <li class="d-flex align-items-start mb-3">
                                <i class="fa-solid fa-fingerprint me-3 text-warning"></i>
                                <div>
                                    <strong>Verifica identità SPID</strong>
                                    <p class="text-muted mb-0">Registra la verifica SPID direttamente dalla scheda pratica per servizi pubblici aggiuntivi.</p>
                                </div>
                            </li>
                            <li class="d-flex align-items-start mb-3">
                                <i class="fa-solid fa-signature me-3 text-warning"></i>
                                <div>
                                    <strong>Firma digitale remota</strong>
                                    <p class="text-muted mb-0">Collega provider di firma per far firmare deleghe e moduli con OTP inviato al cliente.</p>
                                </div>
                            </li>
                            <li class="d-flex align-items-start mb-3">
                                <i class="fa-solid fa-database me-3 text-warning"></i>
                                <div>
                                    <strong>Archivio certificati</strong>
                                    <p class="text-muted mb-0">Consulta rapidamente i certificati emessi filtrando per tipologia e data.</p>
                                </div>
                            </li>
                            <li class="d-flex align-items-start">
                                <i class="fa-solid fa-paper-plane me-3 text-warning"></i>
                                <div>
                                    <strong>Invio automatico</strong>
                                    <p class="text-muted mb-0">Spedisci via email o PEC il certificato al cliente direttamente dal gestionale.</p>
                                </div>
                            </li>
                        </ul>
                        <a class="btn btn-outline-warning" href="add_request.php" title="Crea subito una richiesta ANPR">Crea una nuova pratica</a>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
