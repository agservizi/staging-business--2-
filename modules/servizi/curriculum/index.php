<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Gestione Curriculum';

$filters = [
    ':cliente' => isset($_GET['cliente']) ? trim($_GET['cliente']) : '',
    ':status' => isset($_GET['status']) ? trim($_GET['status']) : ''
];

$sql = "SELECT cv.id,
               cv.titolo,
               cv.status,
               cv.created_at,
               cv.updated_at,
               cv.last_generated_at,
               cv.generated_file,
               c.nome,
               c.cognome
        FROM curriculum cv
        LEFT JOIN clienti c ON cv.cliente_id = c.id";

$where = [];
$params = [];

if ($filters[':cliente'] !== '') {
    $where[] = "(c.cognome LIKE :search_client OR c.nome LIKE :search_client)";
    $params[':search_client'] = '%' . $filters[':cliente'] . '%';
}

if ($filters[':status'] !== '') {
    $where[] = 'cv.status = :status';
    $params[':status'] = $filters[':status'];
}

if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}

$sql .= ' ORDER BY cv.updated_at DESC, cv.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$statuses = ['Bozza', 'Pubblicato', 'Archiviato'];
$curriculumSummary = [
    'total' => count($records),
    'draft' => 0,
    'published' => 0,
    'generated' => 0,
];

foreach ($records as $record) {
    $statusValue = (string) ($record['status'] ?? '');
    if ($statusValue === 'Bozza') {
        $curriculumSummary['draft']++;
    } elseif ($statusValue === 'Pubblicato') {
        $curriculumSummary['published']++;
    }

    if (!empty($record['generated_file']) || !empty($record['last_generated_at'])) {
        $curriculumSummary['generated']++;
    }
}

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <style>
            .curriculum-shell {
                display: grid;
                gap: 1.5rem;
            }

            .curriculum-hero {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(58, 123, 213, 0.14);
                background:
                    radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 34%),
                    radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), transparent 26%),
                    #fff;
            }

            .curriculum-pill {
                display: inline-flex;
                align-items: center;
                gap: 0.5rem;
                padding: 0.45rem 0.85rem;
                border-radius: 999px;
                background: rgba(58, 123, 213, 0.10);
                color: #2154d7;
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .curriculum-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .curriculum-kpi {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.15rem;
                padding: 1rem 1.1rem;
                background: rgba(255, 255, 255, 0.88);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
            }

            .curriculum-kpi-label {
                display: block;
                margin-bottom: 0.4rem;
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .curriculum-kpi-value {
                display: block;
                color: #0f172a;
                font-size: 1.85rem;
                font-weight: 800;
                line-height: 1;
            }

            .curriculum-kpi-note {
                display: block;
                margin-top: 0.45rem;
                color: #64748b;
                font-size: 0.86rem;
            }

            .curriculum-panel {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.3rem;
                background: #fff;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
            }

            .curriculum-table-card-body {
                padding: 1.25rem 1.25rem 1.4rem !important;
            }

            .curriculum-table-card-body .table-responsive {
                border: 1px solid rgba(15, 23, 42, 0.06);
                border-radius: 1rem;
                overflow: hidden;
            }

            .curriculum-table-card-body .dt-container .dt-layout-row:not(.dt-layout-table) {
                margin: 0;
                padding-inline: 0.15rem;
            }

            .curriculum-table-card-body .dt-container .dt-layout-row:first-child {
                padding-bottom: 1rem;
            }

            .curriculum-table-card-body .dt-container .dt-layout-row:last-child {
                padding-top: 1rem;
            }

            .curriculum-table {
                --bs-table-bg: transparent;
                --bs-table-hover-bg: rgba(37, 99, 235, 0.04);
                margin-bottom: 0;
            }

            .curriculum-table thead th {
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                white-space: nowrap;
            }

            .curriculum-table td {
                padding-top: 1rem;
                padding-bottom: 1rem;
                border-color: rgba(15, 23, 42, 0.06);
                vertical-align: middle;
            }

            .curriculum-id {
                display: inline-flex;
                padding: 0.42rem 0.68rem;
                border-radius: 0.8rem;
                background: #f8fafc;
                color: #0f172a;
                font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
                font-size: 0.8rem;
                font-weight: 700;
            }

            @media (max-width: 1199.98px) {
                .curriculum-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767.98px) {
                .curriculum-kpis {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <div class="curriculum-shell">
        <section class="card curriculum-hero">
            <div class="card-body p-4 p-xl-5">
                <div class="row g-4 align-items-start">
                    <div class="col-12 col-xl-7">
                        <span class="curriculum-pill"><i class="fa-solid fa-file-lines"></i>Documenti professionali</span>
                        <h1 class="mt-3 mb-2 fw-bold" style="max-width: 12ch;">Curriculum più chiari per bozze, pubblicazioni e PDF pronti.</h1>
                        <p class="text-muted mb-0" style="max-width: 70ch;">
                            Organizza i curriculum dei clienti, filtra rapidamente per stato e mantieni una vista ordinata su bozze, documenti generati e aggiornamenti recenti.
                        </p>
                    </div>
                    <div class="col-12 col-xl-5">
                        <div class="curriculum-kpis">
                            <div class="curriculum-kpi">
                                <span class="curriculum-kpi-label">Curriculum</span>
                                <span class="curriculum-kpi-value"><?php echo (int) $curriculumSummary['total']; ?></span>
                                <span class="curriculum-kpi-note">Risultati del filtro attivo</span>
                            </div>
                            <div class="curriculum-kpi">
                                <span class="curriculum-kpi-label">Bozze</span>
                                <span class="curriculum-kpi-value"><?php echo (int) $curriculumSummary['draft']; ?></span>
                                <span class="curriculum-kpi-note">Ancora in lavorazione</span>
                            </div>
                            <div class="curriculum-kpi">
                                <span class="curriculum-kpi-label">Pubblicati</span>
                                <span class="curriculum-kpi-value"><?php echo (int) $curriculumSummary['published']; ?></span>
                                <span class="curriculum-kpi-note">Pronti per uso o consegna</span>
                            </div>
                            <div class="curriculum-kpi">
                                <span class="curriculum-kpi-label">PDF generati</span>
                                <span class="curriculum-kpi-value"><?php echo (int) $curriculumSummary['generated']; ?></span>
                                <span class="curriculum-kpi-note">Documenti già prodotti</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        <section class="card curriculum-panel">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
                    <div>
                        <h2 class="h5 mb-1">Filtri curriculum</h2>
                        <p class="text-muted small mb-0">Raffina l’archivio per cliente e stato del documento.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a class="btn btn-outline-warning" href="<?php echo dashboard_url(); ?>"><i class="fa-solid fa-gauge-high me-2"></i>Dashboard</a>
                        <a class="btn btn-warning text-dark" href="<?php echo curriculum_module_url('wizard'); ?>"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo curriculum</a>
                    </div>
                </div>
                <form class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="cliente">Cliente</label>
                        <input class="form-control" id="cliente" name="cliente" type="text" value="<?php echo sanitize_output($filters[':cliente']); ?>" placeholder="Cerca cliente">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="status">Stato</label>
                        <select class="form-select" id="status" name="status">
                            <option value="">Tutti</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?php echo $status; ?>" <?php echo $filters[':status'] === $status ? 'selected' : ''; ?>><?php echo $status; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4 align-self-end">
                        <div class="d-flex gap-2">
                            <button class="btn btn-warning text-dark" type="submit">Applica</button>
                            <a class="btn btn-outline-secondary" href="<?php echo curriculum_module_url('index'); ?>">Reimposta</a>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <section class="card curriculum-panel">
            <div class="card-header bg-transparent border-0 px-4 pt-4 pb-0">
                <h2 class="h5 mb-1">Archivio curriculum</h2>
                <p class="text-muted small mb-0">Elenco operativo dei documenti con stato, ultima generazione e azioni rapide.</p>
            </div>
            <div class="card-body curriculum-table-card-body">
                <div class="table-responsive">
                    <table class="table table-hover curriculum-table" data-datatable="true">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Cliente</th>
                                <th>Titolo</th>
                                <th>Stato</th>
                                <th>Ultima generazione</th>
                                <th>Documento</th>
                                <th>Ultimo aggiornamento</th>
                                <th class="text-end">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $cv): ?>
                                <tr>
                                    <td><span class="curriculum-id">#<?php echo (int) $cv['id']; ?></span></td>
                                    <td><?php echo sanitize_output(trim(($cv['cognome'] ?? '') . ' ' . ($cv['nome'] ?? '')) ?: 'N/D'); ?></td>
                                    <td><?php echo sanitize_output($cv['titolo']); ?></td>
                                    <td>
                                        <span class="badge ag-badge text-uppercase text-white <?php echo $cv['status'] === 'Pubblicato' ? 'bg-success' : ($cv['status'] === 'Archiviato' ? 'bg-secondary' : 'bg-warning'); ?>">
                                            <?php echo sanitize_output($cv['status']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($cv['last_generated_at']): ?>
                                            <?php echo sanitize_output(format_datetime_locale($cv['last_generated_at'])); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Mai</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($cv['generated_file']): ?>
                                            <a class="btn btn-icon btn-soft-accent btn-sm" href="../../../<?php echo sanitize_output($cv['generated_file']); ?>" target="_blank" rel="noopener" title="Apri PDF" aria-label="Apri PDF">
                                                <i class="fa-solid fa-file-pdf"></i>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">N/D</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo sanitize_output(format_datetime_locale($cv['updated_at'])); ?></td>
                                    <td class="text-end">
                                        <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap">
                                            <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo curriculum_module_url('view', ['id' => (int) $cv['id']]); ?>" title="Dettagli" aria-label="Dettagli">
                                                <i class="fa-solid fa-eye"></i>
                                            </a>
                                            <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo curriculum_module_url('wizard', ['id' => (int) $cv['id']]); ?>" title="Modifica" aria-label="Modifica">
                                                <i class="fa-solid fa-pen"></i>
                                            </a>
                                            <form method="post" action="<?php echo curriculum_module_url('publish'); ?>" class="d-inline">
                                                <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int) $cv['id']; ?>">
                                                <button class="btn btn-icon btn-soft-accent btn-sm" type="submit" title="Genera PDF" aria-label="Genera PDF">
                                                    <i class="fa-solid fa-file-pdf"></i>
                                                </button>
                                            </form>
                                            <form method="post" action="<?php echo curriculum_module_url('delete'); ?>" class="d-inline" onsubmit="return confirm('Confermi l\'eliminazione del curriculum? Questa operazione è irreversibile.');">
                                                <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                                                <input type="hidden" name="id" value="<?php echo (int) $cv['id']; ?>">
                                                <button class="btn btn-icon btn-soft-danger btn-sm" type="submit" title="Elimina" aria-label="Elimina">
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
            </div>
        </section>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
