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

$summary = [
    'total' => count($richieste),
    'completed' => 0,
    'processing' => 0,
    'rejected' => 0,
    'companies' => 0,
];

foreach ($richieste as $row) {
    $stato = trim((string) ($row['stato'] ?? ''));
    if (strcasecmp($stato, 'Completata') === 0) {
        $summary['completed']++;
    } elseif (strcasecmp($stato, 'Rifiutata') === 0) {
        $summary['rejected']++;
    } elseif ($stato !== '') {
        $summary['processing']++;
    }

    if (($row['tipo_visura'] ?? '') === 'persona_giuridica') {
        $summary['companies']++;
    }
}

$puoCreare = current_user_can('Admin', 'Operatore', 'Manager');

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <?php render_module_hub_styles(); ?>
    <style>
        .visure-shell {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .visure-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(37, 99, 235, 0.16);
            border-radius: 28px;
            padding: 2rem;
            background:
                radial-gradient(circle at top left, rgba(59, 130, 246, 0.18), transparent 34%),
                radial-gradient(circle at top right, rgba(16, 185, 129, 0.16), transparent 30%),
                linear-gradient(135deg, #ffffff 0%, #f8fbff 55%, #eef6ff 100%);
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.10);
        }

        .visure-hero::after {
            content: "";
            position: absolute;
            inset: auto -80px -120px auto;
            width: 240px;
            height: 240px;
            border-radius: 50%;
            background: rgba(37, 99, 235, 0.08);
            filter: blur(14px);
        }

        .visure-hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
            gap: 1.5rem;
            align-items: start;
        }

        .visure-eyebrow {
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

        .visure-hero h1 {
            margin: 1rem 0 0.75rem;
            font-size: clamp(2rem, 3vw, 2.7rem);
            line-height: 1.05;
            font-weight: 800;
            color: #172033;
            max-width: 11ch;
        }

        .visure-hero p {
            margin: 0;
            max-width: 62ch;
            color: #52607a;
            font-size: 1rem;
        }

        .visure-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            margin-top: 1.5rem;
        }

        .visure-kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .visure-kpi-card {
            border-radius: 22px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.92);
            padding: 1.15rem 1.2rem;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
        }

        .visure-kpi-card span {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #607089;
        }

        .visure-kpi-card strong {
            display: block;
            font-size: 2rem;
            line-height: 1;
            color: #172033;
        }

        .visure-kpi-card small {
            display: block;
            margin-top: 0.45rem;
            color: #64748b;
            font-size: 0.85rem;
        }

        .visure-panel {
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 22px 45px rgba(15, 23, 42, 0.07);
        }

        .visure-panel-header {
            padding: 1.35rem 1.5rem 0;
        }

        .visure-panel-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 800;
            color: #172033;
        }

        .visure-panel-subtitle {
            margin: 0.35rem 0 0;
            color: #64748b;
            font-size: 0.92rem;
        }

        .visure-filter-form {
            padding: 1.35rem 1.5rem 1.5rem;
        }

        .visure-filter-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }

        .visure-field label {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.78rem;
            font-weight: 700;
            color: #52607a;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .visure-field .form-control,
        .visure-field .form-select {
            min-height: 48px;
            border-radius: 15px;
            border-color: #d7dfeb;
            box-shadow: none;
        }

        .visure-filter-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: flex-end;
            margin-top: 1rem;
        }

        .visure-table-wrap {
            padding: 1.25rem 1.5rem 1.5rem;
        }

        .visure-table-shell {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.7), rgba(255, 255, 255, 0.98));
        }

        .visure-table-shell .table {
            margin-bottom: 0;
        }

        .visure-table-shell thead th {
            border-bottom: 1px solid rgba(226, 232, 240, 0.95);
            background: rgba(248, 250, 252, 0.95);
            color: #52607a;
            font-size: 0.77rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .visure-id-badge {
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

        .visure-type-pill {
            display: inline-flex;
            align-items: center;
            padding: 0.42rem 0.78rem;
            border-radius: 999px;
            background: rgba(16, 185, 129, 0.12);
            color: #047857;
            font-size: 0.78rem;
            font-weight: 700;
        }

        .visure-empty {
            padding: 2rem 1.5rem;
            color: #64748b;
        }

        @media (max-width: 1199.98px) {
            .visure-hero-grid,
            .visure-filter-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767.98px) {
            .visure-hero,
            .visure-filter-form,
            .visure-table-wrap {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .visure-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
    <main class="content-wrapper">
        <div class="module-hub-shell visure-shell">
            <section class="visure-hero">
                <div class="visure-hero-grid">
                    <div>
                        <span class="visure-eyebrow"><i class="fa-solid fa-file-shield"></i> Centrale rischi</span>
                        <h1>Una vista piu' chiara su richieste, esiti e soggetti verificati.</h1>
                        <p>Controlla rapidamente le richieste di visura, separa persone fisiche e giuridiche e individua subito quelle da completare o da riallineare.</p>
                        <div class="visure-hero-actions">
                            <a class="btn btn-outline-warning" href="<?php echo sanitize_output(visure_cr_module_url('dashboard')); ?>">
                                <i class="fa-solid fa-gauge-high me-2"></i>Dashboard
                            </a>
                            <?php if ($puoCreare): ?>
                                <a class="btn btn-warning text-dark" href="<?php echo sanitize_output(visure_cr_module_url('create')); ?>">
                                    <i class="fa-solid fa-circle-plus me-2"></i>Nuova richiesta
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="visure-kpi-grid">
                        <article class="visure-kpi-card">
                            <span>Richieste visibili</span>
                            <strong><?php echo number_format($summary['total'], 0, ',', '.'); ?></strong>
                            <small>Risultati presenti nella vista filtrata</small>
                        </article>
                        <article class="visure-kpi-card">
                            <span>In lavorazione</span>
                            <strong><?php echo number_format($summary['processing'], 0, ',', '.'); ?></strong>
                            <small>Bozze, inviate o ancora da completare</small>
                        </article>
                        <article class="visure-kpi-card">
                            <span>Completate</span>
                            <strong><?php echo number_format($summary['completed'], 0, ',', '.'); ?></strong>
                            <small>Pratiche concluse con esito disponibile</small>
                        </article>
                        <article class="visure-kpi-card">
                            <span>Persone giuridiche</span>
                            <strong><?php echo number_format($summary['companies'], 0, ',', '.'); ?></strong>
                            <small>Richieste aziendali nel periodo filtrato</small>
                        </article>
                    </div>
                </div>
            </section>

            <section class="visure-panel">
                <div class="visure-panel-header">
                    <h2 class="visure-panel-title">Filtri operativi</h2>
                    <p class="visure-panel-subtitle">Riduci l'elenco per stato, tipo di visura o nominativo per lavorare con piu' velocita' sulle richieste aperte.</p>
                </div>
                <form class="visure-filter-form" method="get" role="search">
                    <div class="visure-filter-grid">
                        <div class="visure-field">
                            <label for="stato">Stato richiesta</label>
                            <select class="form-select" id="stato" name="stato" aria-label="Filtra per stato">
                                <option value="">Tutti gli stati</option>
                                <?php foreach ($stati as $stato): ?>
                                    <option value="<?php echo sanitize_output($stato); ?>" <?php echo $filters['stato'] === $stato ? 'selected' : ''; ?>><?php echo sanitize_output($stato); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="visure-field">
                            <label for="tipo">Tipo soggetto</label>
                            <select class="form-select" id="tipo" name="tipo" aria-label="Filtra per tipo">
                                <option value="">Tutti i tipi</option>
                                <?php foreach ($tipi as $value => $label): ?>
                                    <option value="<?php echo sanitize_output($value); ?>" <?php echo $filters['tipo'] === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="visure-field">
                            <label for="search">Ricerca libera</label>
                            <input class="form-control" id="search" type="search" name="search" value="<?php echo sanitize_output($filters['search']); ?>" placeholder="Nome, CF, ragione sociale o P.IVA">
                        </div>
                    </div>
                    <div class="visure-filter-actions">
                        <button class="btn btn-warning" type="submit" title="Applica filtri">
                            <i class="fa-solid fa-filter me-2"></i>Applica filtri
                        </button>
                        <a class="btn btn-outline-secondary" href="<?php echo sanitize_output(visure_cr_module_url('index')); ?>" title="Reimposta filtri">
                            <i class="fa-solid fa-rotate-left me-2"></i>Reset
                        </a>
                    </div>
                </form>
            </section>

            <section class="visure-panel">
                <div class="visure-panel-header">
                    <h2 class="visure-panel-title">Richieste visura</h2>
                    <p class="visure-panel-subtitle">Elenco ordinato di richiedenti, contatti ed esiti per intervenire subito sulle pratiche da completare o verificare.</p>
                </div>
                <div class="visure-table-wrap">
                <?php if ($richieste): ?>
                    <div class="visure-table-shell table-responsive">
                        <table class="table table-hover align-middle module-hub-table" data-datatable="true">
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
                                        <td><span class="visure-id-badge">#<?php echo (int) $row['id']; ?></span></td>
                                        <td><span class="visure-type-pill"><?php echo sanitize_output($tipi[$row['tipo_visura']] ?? $row['tipo_visura']); ?></span></td>
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
                    <div class="visure-empty">Nessuna richiesta presente con i filtri correnti.</div>
                <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
