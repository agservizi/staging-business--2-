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

$summary = [
    'total' => count($pratiche),
    'completed' => 0,
    'processing' => 0,
    'with_certificate' => 0,
    'spid' => 0,
];

foreach ($pratiche as $pratica) {
    $status = trim((string) ($pratica['stato'] ?? ''));
    if (strcasecmp($status, 'Completata') === 0) {
        $summary['completed']++;
    } elseif ($status !== '') {
        $summary['processing']++;
    }

    if (!empty($pratica['certificato_path'])) {
        $summary['with_certificate']++;
    }

    if (stripos((string) ($pratica['tipo_pratica'] ?? ''), 'spid') !== false) {
        $summary['spid']++;
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <?php render_module_hub_styles(); ?>
    <style>
        .anpr-shell {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .anpr-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(37, 99, 235, 0.16);
            border-radius: 28px;
            padding: 2rem;
            background:
                radial-gradient(circle at top left, rgba(56, 189, 248, 0.16), transparent 34%),
                radial-gradient(circle at top right, rgba(245, 158, 11, 0.16), transparent 30%),
                linear-gradient(135deg, #ffffff 0%, #f8fbff 55%, #eef6ff 100%);
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.10);
        }

        .anpr-hero::after {
            content: "";
            position: absolute;
            inset: auto -90px -120px auto;
            width: 260px;
            height: 260px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.08);
            filter: blur(12px);
        }

        .anpr-hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
            gap: 1.5rem;
            align-items: start;
        }

        .anpr-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.10);
            color: #0369a1;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .anpr-hero h1 {
            margin: 1rem 0 0.75rem;
            font-size: clamp(2rem, 3vw, 2.7rem);
            line-height: 1.05;
            font-weight: 800;
            color: #172033;
            max-width: 11ch;
        }

        .anpr-hero p {
            margin: 0;
            max-width: 62ch;
            color: #52607a;
            font-size: 1rem;
        }

        .anpr-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            margin-top: 1.5rem;
        }

        .anpr-kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .anpr-kpi-card {
            border-radius: 22px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.92);
            padding: 1.15rem 1.2rem;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
        }

        .anpr-kpi-card span {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #607089;
        }

        .anpr-kpi-card strong {
            display: block;
            font-size: 2rem;
            line-height: 1;
            color: #172033;
        }

        .anpr-kpi-card small {
            display: block;
            margin-top: 0.45rem;
            color: #64748b;
            font-size: 0.85rem;
        }

        .anpr-panel {
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 22px 45px rgba(15, 23, 42, 0.07);
        }

        .anpr-panel-header {
            padding: 1.35rem 1.5rem 0;
        }

        .anpr-panel-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 800;
            color: #172033;
        }

        .anpr-panel-subtitle {
            margin: 0.35rem 0 0;
            color: #64748b;
            font-size: 0.92rem;
        }

        .anpr-filter-form {
            padding: 1.35rem 1.5rem 1.5rem;
        }

        .anpr-filter-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .anpr-field label {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.78rem;
            font-weight: 700;
            color: #52607a;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .anpr-field .form-control,
        .anpr-field .form-select {
            min-height: 48px;
            border-radius: 15px;
            border-color: #d7dfeb;
            box-shadow: none;
        }

        .anpr-filter-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .anpr-table-wrap,
        .anpr-panel-body {
            padding: 1.25rem 1.5rem 1.5rem;
        }

        .anpr-table-shell {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.7), rgba(255, 255, 255, 0.98));
        }

        .anpr-table-shell .table {
            margin-bottom: 0;
        }

        .anpr-table-shell thead th {
            border-bottom: 1px solid rgba(226, 232, 240, 0.95);
            background: rgba(248, 250, 252, 0.95);
            color: #52607a;
            font-size: 0.77rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .anpr-code-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.38rem 0.7rem;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.10);
            color: #1d4ed8;
            font-weight: 700;
            font-size: 0.8rem;
        }

        .anpr-type-pill {
            display: inline-flex;
            align-items: center;
            padding: 0.42rem 0.78rem;
            border-radius: 999px;
            background: rgba(245, 158, 11, 0.12);
            color: #b45309;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .anpr-empty {
            padding: 2rem 1.5rem;
            color: #64748b;
        }

        .anpr-ideas {
            list-style: none;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .anpr-ideas li {
            display: flex;
            align-items: flex-start;
            gap: 0.9rem;
        }

        .anpr-ideas i {
            width: 2.2rem;
            height: 2.2rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 14px;
            background: rgba(245, 158, 11, 0.12);
            color: #b45309;
        }

        @media (max-width: 1199.98px) {
            .anpr-hero-grid,
            .anpr-filter-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767.98px) {
            .anpr-hero,
            .anpr-filter-form,
            .anpr-table-wrap,
            .anpr-panel-body {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .anpr-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <main class="content-wrapper">
        <div class="module-hub-shell anpr-shell">
            <section class="anpr-hero">
                <div class="anpr-hero-grid">
                    <div>
                        <span class="anpr-eyebrow"><i class="fa-solid fa-landmark"></i> Anagrafe digitale</span>
                        <h1>Una cabina di regia piu' chiara per pratiche, certificati e verifiche.</h1>
                        <p>Controlla le pratiche ANPR in un'unica vista, separa quelle da completare, verifica lo stato dei certificati e velocizza i passaggi tra richiesta, upload e consegna.</p>
                        <div class="anpr-hero-actions">
                            <a class="btn btn-outline-warning" href="https://www.anagrafenazionale.interno.it/servizi-al-cittadino/" target="_blank" rel="noopener">
                                <i class="fa-solid fa-up-right-from-square me-2"></i>Portale ANPR
                            </a>
                            <a class="btn btn-outline-primary" href="<?php echo sanitize_output(anpr_module_url('certificate_archive')); ?>">
                                <i class="fa-solid fa-box-archive me-2"></i>Archivio certificati
                            </a>
                            <a class="btn btn-warning text-dark" href="<?php echo sanitize_output(anpr_module_url('add_request')); ?>">
                                <i class="fa-solid fa-circle-plus me-2"></i>Nuova pratica
                            </a>
                        </div>
                    </div>
                    <div class="anpr-kpi-grid">
                        <article class="anpr-kpi-card">
                            <span>Pratiche visibili</span>
                            <strong><?php echo number_format($summary['total'], 0, ',', '.'); ?></strong>
                            <small>Richieste nel perimetro filtrato corrente</small>
                        </article>
                        <article class="anpr-kpi-card">
                            <span>In lavorazione</span>
                            <strong><?php echo number_format($summary['processing'], 0, ',', '.'); ?></strong>
                            <small>Pratiche aperte o ancora da completare</small>
                        </article>
                        <article class="anpr-kpi-card">
                            <span>Con certificato</span>
                            <strong><?php echo number_format($summary['with_certificate'], 0, ',', '.'); ?></strong>
                            <small>Dossier con documento gia' caricato</small>
                        </article>
                        <article class="anpr-kpi-card">
                            <span>Pratiche SPID</span>
                            <strong><?php echo number_format($summary['spid'], 0, ',', '.'); ?></strong>
                            <small>Richieste legate a verifiche o identita' digitale</small>
                        </article>
                    </div>
                </div>
            </section>

            <section class="anpr-panel">
                <div class="anpr-panel-header">
                    <h2 class="anpr-panel-title">Filtri pratiche</h2>
                    <p class="anpr-panel-subtitle">Riduci l'elenco per stato, tipologia, cliente o codice pratica per lavorare con piu' velocita' sulle richieste aperte.</p>
                </div>
                <form class="anpr-filter-form" method="get" action="<?php echo sanitize_output(anpr_module_url('index')); ?>">
                    <div class="anpr-filter-grid">
                        <div class="anpr-field">
                            <label for="stato">Stato</label>
                            <select class="form-select" id="stato" name="stato">
                                <option value="">Tutti</option>
                                <?php foreach ($statuses as $statusOption): ?>
                                    <option value="<?php echo sanitize_output($statusOption); ?>" <?php echo $filterStatus === $statusOption ? 'selected' : ''; ?>><?php echo sanitize_output($statusOption); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="anpr-field">
                            <label for="tipo_pratica">Tipologia</label>
                            <select class="form-select" id="tipo_pratica" name="tipo_pratica">
                                <option value="">Tutte</option>
                                <?php foreach ($types as $type): ?>
                                    <option value="<?php echo sanitize_output($type); ?>" <?php echo $filterType === $type ? 'selected' : ''; ?>><?php echo sanitize_output($type); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="anpr-field">
                            <label for="cliente_id">Cliente</label>
                            <select class="form-select" id="cliente_id" name="cliente_id">
                                <option value="">Tutti</option>
                                <?php foreach ($clienti as $cliente): ?>
                                    <?php $cid = (int) $cliente['id']; ?>
                                    <option value="<?php echo $cid; ?>" <?php echo $filterCliente === $cid ? 'selected' : ''; ?>><?php echo sanitize_output(trim($cliente['ragione_sociale'] ?: (($cliente['cognome'] ?? '') . ' ' . ($cliente['nome'] ?? '')))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="anpr-field">
                            <label for="q">Ricerca</label>
                            <input class="form-control" id="q" name="q" value="<?php echo sanitize_output($filterQuery); ?>" placeholder="Codice pratica o cliente">
                        </div>
                    </div>
                    <div class="anpr-filter-actions">
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-filter me-2"></i>Applica filtri</button>
                        <a class="btn btn-outline-secondary" href="<?php echo sanitize_output(anpr_module_url('index')); ?>"><i class="fa-solid fa-rotate-left me-2"></i>Reset</a>
                    </div>
                </form>
            </section>

            <section class="anpr-panel">
                <div class="anpr-panel-header">
                    <h2 class="anpr-panel-title">Pratiche registrate</h2>
                    <p class="anpr-panel-subtitle">Vista operativa di codice pratica, cliente, tipologia e stato per muoversi rapidamente tra certificati e lavorazioni attive.</p>
                </div>
                <div class="anpr-table-wrap">
                <?php if (!$pratiche): ?>
                    <div class="anpr-empty">Nessuna pratica trovata con i filtri correnti.</div>
                <?php else: ?>
                    <div class="anpr-table-shell table-responsive">
                        <table class="table table-hover align-middle module-hub-table">
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
                                        <td><span class="anpr-code-badge"><?php echo sanitize_output($pratica['pratica_code']); ?></span></td>
                                        <td>
                                            <?php
                                                $displayName = trim(($pratica['ragione_sociale'] ?? '') !== ''
                                                    ? $pratica['ragione_sociale']
                                                    : trim(($pratica['cognome'] ?? '') . ' ' . ($pratica['nome'] ?? '')));
                                                echo $displayName !== '' ? sanitize_output($displayName) : '<span class="text-muted">N/D</span>';
                                            ?>
                                        </td>
                                        <td><span class="anpr-type-pill"><?php echo sanitize_output($pratica['tipo_pratica']); ?></span></td>
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
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo sanitize_output(anpr_module_url('view_request', ['id' => (int) $pratica['id']])); ?>" title="Dettagli">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo sanitize_output(anpr_module_url('edit_request', ['id' => (int) $pratica['id']])); ?>" title="Modifica">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo sanitize_output(anpr_module_url('upload_certificate', ['id' => (int) $pratica['id']])); ?>" title="Carica certificato">
                                                    <i class="fa-solid fa-file-arrow-up"></i>
                                                </a>
                                                <form method="post" action="<?php echo sanitize_output(anpr_module_url('delete_request')); ?>" class="d-inline"
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
            </section>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="anpr-panel h-100">
                    <div class="anpr-panel-header">
                        <h2 class="anpr-panel-title">Servizi da listino</h2>
                        <p class="anpr-panel-subtitle">Promemoria rapido su servizi, fascia prezzo e note operative da comunicare al cliente in fase di richiesta.</p>
                    </div>
                    <div class="anpr-panel-body">
                        <div class="anpr-table-shell table-responsive">
                            <table class="table table-striped align-middle module-hub-table">
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
                        <small class="text-muted d-block mt-3">I prezzi sono indicativi e vanno adeguati al tariffario interno.</small>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="anpr-panel h-100">
                    <div class="anpr-panel-header">
                        <h2 class="anpr-panel-title">Idee extra per il servizio</h2>
                        <p class="anpr-panel-subtitle">Spunti operativi per ampliare il servizio ANPR senza uscire dal presidio documentale del gestionale.</p>
                    </div>
                    <div class="anpr-panel-body">
                        <ul class="anpr-ideas mb-4">
                            <li>
                                <i class="fa-solid fa-fingerprint"></i>
                                <div>
                                    <strong>Verifica identità SPID</strong>
                                    <p class="text-muted mb-0">Registra la verifica SPID direttamente dalla scheda pratica per servizi pubblici aggiuntivi.</p>
                                </div>
                            </li>
                            <li>
                                <i class="fa-solid fa-signature"></i>
                                <div>
                                    <strong>Firma digitale remota</strong>
                                    <p class="text-muted mb-0">Collega provider di firma per far firmare deleghe e moduli con OTP inviato al cliente.</p>
                                </div>
                            </li>
                            <li>
                                <i class="fa-solid fa-database"></i>
                                <div>
                                    <strong>Archivio certificati</strong>
                                    <p class="text-muted mb-0">Consulta rapidamente i certificati emessi filtrando per tipologia e data.</p>
                                </div>
                            </li>
                            <li>
                                <i class="fa-solid fa-paper-plane"></i>
                                <div>
                                    <strong>Invio automatico</strong>
                                    <p class="text-muted mb-0">Spedisci via email o PEC il certificato al cliente direttamente dal gestionale.</p>
                                </div>
                            </li>
                        </ul>
                        <a class="btn btn-outline-warning" href="<?php echo sanitize_output(anpr_module_url('add_request')); ?>" title="Crea subito una richiesta ANPR">Crea una nuova pratica</a>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
