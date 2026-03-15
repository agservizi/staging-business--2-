<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pageTitle = 'Credenziali Iliad';

$perPage = 20;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

$credentials = $iliadService->listCredentials($page, $perPage);
$totalCredentials = $iliadService->countCredentials();
$totalPages = (int) ceil($totalCredentials / $perPage);

$iliadSummary = [
    'total' => $totalCredentials,
    'fibra' => 0,
    'mobile' => 0,
    'bundle' => 0,
];

foreach ($credentials as $cred) {
    $hasFibra = !empty($cred['include_fibra']);
    $hasMobile = !empty($cred['include_mobile']);
    if ($hasFibra) {
        $iliadSummary['fibra']++;
    }
    if ($hasMobile) {
        $iliadSummary['mobile']++;
    }
    if ($hasFibra && $hasMobile) {
        $iliadSummary['bundle']++;
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <?php render_module_hub_styles(); ?>
    <style>
        .iliad-shell {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .iliad-hero {
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(37, 99, 235, 0.16);
            border-radius: 28px;
            padding: 2rem;
            background:
                radial-gradient(circle at top left, rgba(37, 99, 235, 0.18), transparent 34%),
                radial-gradient(circle at top right, rgba(220, 38, 38, 0.14), transparent 30%),
                linear-gradient(135deg, #ffffff 0%, #f8fbff 54%, #eef5ff 100%);
            box-shadow: 0 28px 60px rgba(15, 23, 42, 0.10);
        }

        .iliad-hero::after {
            content: "";
            position: absolute;
            inset: auto -90px -120px auto;
            width: 250px;
            height: 250px;
            border-radius: 50%;
            background: rgba(220, 38, 38, 0.08);
            filter: blur(12px);
        }

        .iliad-hero-grid {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(320px, 1fr);
            gap: 1.5rem;
            align-items: start;
        }

        .iliad-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            background: rgba(220, 38, 38, 0.10);
            color: #b91c1c;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.12em;
            text-transform: uppercase;
        }

        .iliad-hero h1 {
            margin: 1rem 0 0.75rem;
            font-size: clamp(2rem, 3vw, 2.7rem);
            line-height: 1.05;
            font-weight: 800;
            color: #172033;
            max-width: 11ch;
        }

        .iliad-hero p {
            margin: 0;
            max-width: 62ch;
            color: #52607a;
            font-size: 1rem;
        }

        .iliad-hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            margin-top: 1.5rem;
        }

        .iliad-kpi-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }

        .iliad-kpi-card {
            border-radius: 22px;
            border: 1px solid rgba(148, 163, 184, 0.22);
            background: rgba(255, 255, 255, 0.92);
            padding: 1.15rem 1.2rem;
            box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
        }

        .iliad-kpi-card span {
            display: block;
            margin-bottom: 0.45rem;
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: #607089;
        }

        .iliad-kpi-card strong {
            display: block;
            font-size: 2rem;
            line-height: 1;
            color: #172033;
        }

        .iliad-kpi-card small {
            display: block;
            margin-top: 0.45rem;
            color: #64748b;
            font-size: 0.85rem;
        }

        .iliad-panel {
            border: 1px solid rgba(148, 163, 184, 0.18);
            border-radius: 24px;
            background: #fff;
            box-shadow: 0 22px 45px rgba(15, 23, 42, 0.07);
        }

        .iliad-panel-header {
            padding: 1.35rem 1.5rem 0;
        }

        .iliad-panel-title {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 800;
            color: #172033;
        }

        .iliad-panel-subtitle {
            margin: 0.35rem 0 0;
            color: #64748b;
            font-size: 0.92rem;
        }

        .iliad-table-wrap {
            padding: 1.25rem 1.5rem 1.5rem;
        }

        .iliad-table-shell {
            border: 1px solid rgba(226, 232, 240, 0.95);
            border-radius: 20px;
            overflow: hidden;
            background: linear-gradient(180deg, rgba(248, 250, 252, 0.7), rgba(255, 255, 255, 0.98));
        }

        .iliad-table-shell .table {
            margin-bottom: 0;
        }

        .iliad-table-shell thead th {
            border-bottom: 1px solid rgba(226, 232, 240, 0.95);
            background: rgba(248, 250, 252, 0.95);
            color: #52607a;
            font-size: 0.77rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .iliad-credential {
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

        .iliad-empty {
            padding: 2rem 1.5rem;
            color: #64748b;
        }

        .iliad-pagination {
            padding: 1rem 1.5rem 1.5rem;
        }

        @media (max-width: 1199.98px) {
            .iliad-hero-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 767.98px) {
            .iliad-hero,
            .iliad-table-wrap,
            .iliad-pagination {
                padding-left: 1rem;
                padding-right: 1rem;
            }

            .iliad-kpi-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>

    <main class="content-wrapper">
        <div class="module-hub-shell iliad-shell">
            <section class="iliad-hero">
                <div class="iliad-hero-grid">
                    <div>
                        <span class="iliad-eyebrow"><i class="fa-solid fa-sim-card"></i> Iliad toolkit</span>
                        <h1>Una vista piu' chiara su credenziali, bundle e PDF pronti da consegnare.</h1>
                        <p>Gestisci in un'unica vista le credenziali Iliad fibra e mobile, tieni sotto controllo i bundle completi e genera il PDF consegna in pochi secondi.</p>
                        <div class="iliad-hero-actions">
                            <a class="btn btn-warning text-dark" href="<?php echo iliad_module_url('create'); ?>">
                                <i class="fa-solid fa-plus me-2"></i>Nuove credenziali
                            </a>
                        </div>
                    </div>
                    <div class="iliad-kpi-grid">
                        <article class="iliad-kpi-card">
                            <span>Credenziali totali</span>
                            <strong><?php echo number_format($iliadSummary['total'], 0, ',', '.'); ?></strong>
                            <small>Archivio completo disponibile nel modulo</small>
                        </article>
                        <article class="iliad-kpi-card">
                            <span>Fibra inclusa</span>
                            <strong><?php echo number_format($iliadSummary['fibra'], 0, ',', '.'); ?></strong>
                            <small>Credenziali con accesso fibra disponibile</small>
                        </article>
                        <article class="iliad-kpi-card">
                            <span>Mobile incluso</span>
                            <strong><?php echo number_format($iliadSummary['mobile'], 0, ',', '.'); ?></strong>
                            <small>Profili con accesso area mobile attivo</small>
                        </article>
                        <article class="iliad-kpi-card">
                            <span>Bundle completi</span>
                            <strong><?php echo number_format($iliadSummary['bundle'], 0, ',', '.'); ?></strong>
                            <small>Fibra e mobile presenti nella stessa scheda</small>
                        </article>
                    </div>
                </div>
            </section>

            <section class="iliad-panel">
                <div class="iliad-panel-header">
                    <h2 class="iliad-panel-title">Credenziali registrate</h2>
                    <p class="iliad-panel-subtitle">Vista ordinata di accessi, copertura servizio e documentazione PDF per consegnare rapidamente i dati al cliente.</p>
                </div>
                <div class="iliad-table-wrap">
                <?php if (empty($credentials)): ?>
                    <div class="iliad-empty">Nessuna credenziale presente.</div>
                <?php else: ?>
                    <div class="iliad-table-shell table-responsive">
                        <table class="table table-hover module-hub-table">
                            <thead>
                                <tr>
                                    <th>ID Fibra</th>
                                    <th>ID Mobile</th>
                                    <th>Password</th>
                                    <th>Include Fibra</th>
                                    <th>Include Mobile</th>
                                    <th>Creato il</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($credentials as $cred): ?>
                                    <tr>
                                        <td><?php echo !empty($cred['fibra_id']) ? '<span class="iliad-credential">' . sanitize_output($cred['fibra_id']) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                                        <td><?php echo !empty($cred['mobile_id']) ? '<span class="iliad-credential">' . sanitize_output($cred['mobile_id']) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                                        <td><?php echo sanitize_output($cred['fibra_password']); ?></td>
                                        <td>
                                            <?php if ($cred['include_fibra']): ?>
                                                <i class="fa-solid fa-check text-success"></i>
                                            <?php else: ?>
                                                <i class="fa-solid fa-xmark text-muted"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($cred['include_mobile']): ?>
                                                <i class="fa-solid fa-check text-success"></i>
                                            <?php else: ?>
                                                <i class="fa-solid fa-xmark text-muted"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo format_datetime_locale($cred['created_at']); ?></td>
                                        <td>
                                            <a href="<?php echo iliad_module_url('generate_pdf', ['id' => (int) $cred['id']]); ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                <i class="fa-solid fa-file-pdf me-1"></i>PDF
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav class="iliad-pagination" aria-label="Navigazione pagine">
                            <ul class="pagination justify-content-center mt-4">
                                <?php if ($page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page - 1; ?>">Precedente</a>
                                    </li>
                                <?php endif; ?>

                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>"><?php echo $i; ?></a>
                                    </li>
                                <?php endfor; ?>

                                <?php if ($page < $totalPages): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?page=<?php echo $page + 1; ?>">Successivo</a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
