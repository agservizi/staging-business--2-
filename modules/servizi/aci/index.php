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

$today = new DateTimeImmutable('today');
$aciSummary = [
    'total' => count($pratiche),
    'active' => 0,
    'completed' => 0,
    'expiring' => 0,
    'without_client' => 0,
];

foreach ($pratiche as $pratica) {
    $stato = trim((string) ($pratica['stato'] ?? ''));
    if ($stato !== '' && strcasecmp($stato, 'Completata') === 0) {
        $aciSummary['completed']++;
    } elseif ($stato !== '') {
        $aciSummary['active']++;
    }

    if (empty($pratica['cliente_id'])) {
        $aciSummary['without_client']++;
    }

    if (!empty($pratica['data_scadenza'])) {
        try {
            $deadline = new DateTimeImmutable((string) $pratica['data_scadenza']);
            if ($deadline >= $today && $deadline <= $today->modify('+7 days')) {
                $aciSummary['expiring']++;
            }
        } catch (Throwable $e) {
            // Ignore invalid dates in dashboard summary.
        }
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <?php render_module_hub_styles(); ?>
    <style>
        .aci-shell {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .aci-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(37, 99, 235, 0.16);
            border-radius: 28px;
            padding: 2rem;
            background:
                radial-gradient(circle at top left, rgba(245, 158, 11, 0.20), transparent 36%),
                radial-gradient(circle at top right, rgba(37, 99, 235, 0.18), transparent 32%),
                linear-gradient(135deg, #ffffff 0%, #f8fbff 54%, #eef5ff 100%);
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.10);
        }

        .aci-hero::after {
            content: "";
            position: absolute;
            inset: auto -90px -120px auto;
            width: 260px;
            height: 260px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.08);
            filter: blur(10px);
        }

        .aci-hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
            gap: 1.5rem;
            align-items: start;
        }

        .aci-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            background: rgba(37, 99, 235, 0.10);
            color: #0f766e;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .aci-hero h1 {
            margin: 1rem 0 0.75rem;
            font-size: clamp(2rem, 3vw, 2.7rem);
            line-height: 1.05;
            font-weight: 800;
            color: #172033;
            max-width: 11ch;
        }

        .aci-hero p {
            margin: 0;
            max-width: 62ch;
            color: #52607a;
            font-size: 1rem;
        }

        .aci-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            margin-top: 1.5rem;
        }

        .aci-kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .aci-kpi-card {
            border-radius: 22px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.90);
            padding: 1.15rem 1.2rem;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
        }

        .aci-kpi-card span {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #607089;
        }

        .aci-kpi-card strong {
            display: block;
            font-size: 2rem;
            line-height: 1;
            color: #172033;
        }

        .aci-kpi-card small {
            display: block;
            margin-top: 0.45rem;
            color: #64748b;
            font-size: 0.85rem;
        }

        .aci-panel {
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 22px 45px rgba(15, 23, 42, 0.07);
        }

        .aci-panel-header {
            padding: 1.35rem 1.5rem 0;
        }

        .aci-panel-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 800;
            color: #172033;
        }

        .aci-panel-subtitle {
            margin: 0.35rem 0 0;
            color: #64748b;
            font-size: 0.92rem;
        }

        .aci-filter-form {
            padding: 1.35rem 1.5rem 1.5rem;
        }

        .aci-filter-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem;
        }

        .aci-field label {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.78rem;
            font-weight: 700;
            color: #52607a;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .aci-field .form-control,
        .aci-field .form-select {
            min-height: 48px;
            border-radius: 15px;
            border-color: #d7dfeb;
            box-shadow: none;
        }

        .aci-filter-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .aci-table-wrap {
            padding: 1.25rem 1.5rem 1.5rem;
        }

        .aci-table-shell {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.7), rgba(255, 255, 255, 0.98));
        }

        .aci-table-shell .table {
            margin-bottom: 0;
        }

        .aci-table-shell thead th {
            border-bottom: 1px solid rgba(226, 232, 240, 0.95);
            background: rgba(248, 250, 252, 0.95);
            color: #52607a;
            font-size: 0.77rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .aci-id-badge {
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

        .aci-type-pill {
            display: inline-flex;
            align-items: center;
            padding: 0.42rem 0.78rem;
            border-radius: 999px;
            background: rgba(245, 158, 11, 0.12);
            color: #b45309;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .aci-empty {
            padding: 2rem 1.5rem;
            color: #64748b;
        }

        @media (max-width: 1199.98px) {
            .aci-hero-grid,
            .aci-filter-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767.98px) {
            .aci-hero,
            .aci-filter-form,
            .aci-table-wrap {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .aci-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <main class="content-wrapper">
        <div class="module-hub-shell aci-shell">
            <section class="aci-hero">
                <div class="aci-hero-grid">
                    <div>
                        <span class="aci-eyebrow"><i class="fa-solid fa-car-side"></i> Servizi automobilistici</span>
                        <h1>Una cabina di regia piu' chiara per pratiche, scadenze e clienti.</h1>
                        <p>Monitora le pratiche ACI in lavorazione, individua subito quelle in scadenza e tieni in ordine protocollo, cliente e stato operativo in un'unica vista.</p>
                        <div class="aci-hero-actions">
                            <a class="btn btn-warning text-dark" href="https://visurenet.aci.it/auth/login" target="_blank" rel="noopener" title="Apri in finestra anonima">
                                <i class="fa-solid fa-arrow-up-right-from-square me-2"></i>Apri Visurenet
                            </a>
                            <a class="btn btn-outline-warning" href="<?php echo aci_module_url('dashboard'); ?>">
                                <i class="fa-solid fa-gauge-high me-2"></i>Dashboard
                            </a>
                            <?php if ($puoCreare): ?>
                                <a class="btn btn-outline-primary" href="<?php echo aci_module_url('create-wizard', ['protocollo' => $protocolloWizard]); ?>">
                                    <i class="fa-solid fa-circle-plus me-2"></i>Nuova pratica
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="aci-kpi-grid">
                        <article class="aci-kpi-card">
                            <span>Pratiche visibili</span>
                            <strong><?php echo number_format($aciSummary['total'], 0, ',', '.'); ?></strong>
                            <small>Risultati nel perimetro filtrato attuale</small>
                        </article>
                        <article class="aci-kpi-card">
                            <span>In lavorazione</span>
                            <strong><?php echo number_format($aciSummary['active'], 0, ',', '.'); ?></strong>
                            <small>Pratiche ancora operative o da presidiare</small>
                        </article>
                        <article class="aci-kpi-card">
                            <span>In scadenza</span>
                            <strong><?php echo number_format($aciSummary['expiring'], 0, ',', '.'); ?></strong>
                            <small>Scadenze previste entro i prossimi 7 giorni</small>
                        </article>
                        <article class="aci-kpi-card">
                            <span>Senza cliente</span>
                            <strong><?php echo number_format($aciSummary['without_client'], 0, ',', '.'); ?></strong>
                            <small>Pratiche ancora da associare ad anagrafica</small>
                        </article>
                    </div>
                </div>
            </section>

            <section class="aci-panel">
                <div class="aci-panel-header">
                    <h2 class="aci-panel-title">Filtri operativi</h2>
                    <p class="aci-panel-subtitle">Stringi la vista per stato, tipologia, cliente o riferimento veicolo senza uscire dal flusso di lavoro.</p>
                </div>
                <form class="aci-filter-form" method="get" role="search">
                    <div class="aci-filter-grid">
                        <div class="aci-field">
                            <label for="stato">Stato pratica</label>
                            <select class="form-select" id="stato" name="stato" aria-label="Filtra per stato">
                                <option value="">Tutti gli stati</option>
                                <?php foreach ($stati as $stato): ?>
                                    <option value="<?php echo sanitize_output($stato); ?>" <?php echo $filters['stato'] === $stato ? 'selected' : ''; ?>><?php echo sanitize_output($stato); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="aci-field">
                            <label for="tipo">Tipo pratica</label>
                            <select class="form-select" id="tipo" name="tipo" aria-label="Filtra per tipo">
                                <option value="">Tutte le tipologie</option>
                                <?php foreach ($tipi as $tipo): ?>
                                    <option value="<?php echo sanitize_output($tipo); ?>" <?php echo $filters['tipo'] === $tipo ? 'selected' : ''; ?>><?php echo sanitize_output($tipo); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="aci-field">
                            <label for="cliente_id">Cliente associato</label>
                            <select class="form-select" id="cliente_id" name="cliente_id" aria-label="Filtra per cliente">
                                <option value="">Tutti i clienti</option>
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
                        </div>
                        <div class="aci-field">
                            <label for="search">Ricerca libera</label>
                            <input class="form-control" id="search" type="search" name="search" value="<?php echo sanitize_output($filters['search']); ?>" placeholder="Targa, telaio, protocollo o cliente">
                        </div>
                    </div>
                    <div class="aci-filter-actions">
                        <button class="btn btn-warning" type="submit" title="Applica filtri">
                            <i class="fa-solid fa-filter me-2"></i>Applica filtri
                        </button>
                        <a class="btn btn-outline-secondary" href="<?php echo aci_module_url('index'); ?>" title="Reimposta filtri">
                            <i class="fa-solid fa-rotate-left me-2"></i>Reset
                        </a>
                    </div>
                </form>
            </section>

            <section class="aci-panel">
                <div class="aci-panel-header">
                    <h2 class="aci-panel-title">Pratiche registrate</h2>
                    <p class="aci-panel-subtitle">Vista ordinata di protocollo, cliente, riferimenti veicolo e scadenze per le lavorazioni ACI in corso.</p>
                </div>
                <div class="aci-table-wrap">
                <?php if ($pratiche): ?>
                    <div class="aci-table-shell table-responsive">
                        <table class="table table-hover align-middle module-hub-table" data-datatable="true">
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
                                        <td><span class="aci-id-badge">#<?php echo (int) $pratica['id']; ?></span></td>
                                        <td>
                                            <span class="aci-type-pill"><?php echo sanitize_output($pratica['tipo_pratica']); ?></span>
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
                                                <a class="btn btn-outline-light px-2 py-1" href="<?php echo aci_module_url('view', ['id' => (int) $pratica['id']]); ?>"><i class="fa-solid fa-eye"></i></a>
                                                <?php if (strcasecmp(trim((string) ($pratica['stato'] ?? '')), 'Completata') === 0): ?>
                                                    <a class="btn btn-outline-primary px-2 py-1" href="<?php echo aci_module_url('receipt', ['id' => (int) $pratica['id']]); ?>"><i class="fa-solid fa-file-pdf"></i></a>
                                                <?php endif; ?>
                                                <?php if ($puoModificare): ?>
                                                    <a class="btn btn-outline-light px-2 py-1" href="<?php echo aci_module_url('edit', ['id' => (int) $pratica['id']]); ?>"><i class="fa-solid fa-pen"></i></a>
                                                <?php endif; ?>
                                                <?php if ($puoEliminare): ?>
                                                    <button
                                                        class="btn btn-outline-danger px-2 py-1"
                                                        type="button"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#deletePraticaModal"
                                                        data-pratica-id="<?php echo (int) $pratica['id']; ?>"
                                                        data-pratica-protocollo="<?php echo sanitize_output($pratica['protocollo'] ?? ''); ?>"
                                                    >
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="aci-empty">Nessuna pratica registrata con i filtri correnti.</div>
                <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</div>

<?php if ($puoEliminare): ?>
    <div class="modal fade" id="deletePraticaModal" tabindex="-1" aria-labelledby="deletePraticaModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="deletePraticaModalLabel">Elimina pratica</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Confermi la rimozione della pratica selezionata? L'azione non è reversibile.</p>
                    <small class="text-muted d-block mt-2" id="deletePraticaModalMeta"></small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                    <a class="btn btn-danger" id="deletePraticaModalConfirm" href="#">
                        <i class="fa-solid fa-trash"></i> Elimina
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var deleteModal = document.getElementById('deletePraticaModal');
            if (!deleteModal) {
                return;
            }

            deleteModal.addEventListener('show.bs.modal', function (event) {
                var trigger = event.relatedTarget;
                if (!trigger) {
                    return;
                }

                var praticaId = trigger.getAttribute('data-pratica-id');
                var protocollo = trigger.getAttribute('data-pratica-protocollo') || '';
                var confirmLink = deleteModal.querySelector('#deletePraticaModalConfirm');
                var meta = deleteModal.querySelector('#deletePraticaModalMeta');

                if (confirmLink && praticaId) {
                    confirmLink.setAttribute('href', <?php echo json_encode(aci_module_url('delete')); ?> + '?id=' + praticaId);
                }

                if (meta) {
                    var metaText = praticaId ? ('Pratica #' + praticaId) : '';
                    if (protocollo) {
                        metaText += (metaText ? ' • ' : '') + ('Protocollo: ' + protocollo);
                    }
                    meta.textContent = metaText;
                }
            });
        })();
    </script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
