<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

express_module_require_access($pdo, (int) ($_SESSION['user_id'] ?? 0));

$pageTitle = 'Report Express';

express_module_bootstrap_schema($pdo);

$filters = [
    'view' => $_GET['view'] ?? 'daily',
    'date' => $_GET['date'] ?? date('Y-m-d'),
    'month' => $_GET['month'] ?? date('Y-m'),
    'year' => $_GET['year'] ?? date('Y'),
    'payment' => $_GET['payment'] ?? '',
    'operator_id' => $_GET['operator_id'] ?? '',
];
$report = express_module_report_data($pdo, $filters);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <?php express_module_render_nav('reports'); ?>
        <style>
            .express-reports-shell {
                display: grid;
                gap: 1.5rem;
            }

            .express-reports-hero {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(58, 123, 213, 0.14);
                background:
                    radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 34%),
                    radial-gradient(circle at top right, rgba(0, 184, 148, 0.12), transparent 26%),
                    #fff;
            }

            .express-reports-pill {
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

            .express-reports-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .express-reports-kpi {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.15rem;
                padding: 1rem 1.1rem;
                background: rgba(255, 255, 255, 0.88);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
            }

            .express-reports-kpi-label {
                display: block;
                margin-bottom: 0.4rem;
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-reports-kpi-value {
                display: block;
                color: #0f172a;
                font-size: 1.85rem;
                font-weight: 800;
                line-height: 1;
            }

            .express-reports-kpi-note {
                display: block;
                margin-top: 0.45rem;
                color: #64748b;
                font-size: 0.86rem;
            }

            .express-reports-panel {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.3rem;
                background: #fff;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
            }

            .express-reports-table {
                --bs-table-bg: transparent;
                --bs-table-hover-bg: rgba(37, 99, 235, 0.04);
                margin-bottom: 0;
            }

            .express-reports-table thead th {
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-reports-table td {
                padding-top: 0.95rem;
                padding-bottom: 0.95rem;
                border-color: rgba(15, 23, 42, 0.06);
                vertical-align: middle;
            }

            @media (max-width: 1199.98px) {
                .express-reports-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767.98px) {
                .express-reports-kpis {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <div class="express-reports-shell">
            <section class="card express-reports-hero">
                <div class="card-body p-4 p-xl-5">
                    <div class="row g-4 align-items-start">
                        <div class="col-12 col-xl-7">
                            <span class="express-reports-pill"><i class="fa-solid fa-chart-line"></i>Analisi Express</span>
                            <h1 class="mt-3 mb-2 fw-bold" style="max-width: 14ch;">Report più chiari per periodo, metodo e operatore.</h1>
                            <p class="text-muted mb-0" style="max-width: 72ch;">
                                Analizza andamento vendite, incassi e composizione del mix commerciale con filtri rapidi e una lettura più pulita dei dati del modulo.
                            </p>
                        </div>
                        <div class="col-12 col-xl-5">
                            <div class="express-reports-kpis">
                                <div class="express-reports-kpi">
                                    <span class="express-reports-kpi-label">Vendite</span>
                                    <span class="express-reports-kpi-value"><?php echo (int) $report['totals']['sales_count']; ?></span>
                                    <span class="express-reports-kpi-note"><?php echo sanitize_output((string) $report['period_label']); ?></span>
                                </div>
                                <div class="express-reports-kpi">
                                    <span class="express-reports-kpi-label">Incasso lordo</span>
                                    <span class="express-reports-kpi-value">&euro; <?php echo number_format((float) $report['totals']['gross_revenue'], 0, ',', '.'); ?></span>
                                    <span class="express-reports-kpi-note">Totale del periodo selezionato</span>
                                </div>
                                <div class="express-reports-kpi">
                                    <span class="express-reports-kpi-label">Sconti</span>
                                    <span class="express-reports-kpi-value">&euro; <?php echo number_format((float) $report['totals']['discount_total'], 0, ',', '.'); ?></span>
                                    <span class="express-reports-kpi-note">Riduzioni applicate alle vendite</span>
                                </div>
                                <div class="express-reports-kpi">
                                    <span class="express-reports-kpi-label">Netto medio</span>
                                    <span class="express-reports-kpi-value">&euro; <?php echo number_format((float) $report['totals']['average_ticket'], 0, ',', '.'); ?></span>
                                    <span class="express-reports-kpi-note">Ticket medio per vendita</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card express-reports-panel">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                        <div>
                            <h5 class="card-title mb-1">Filtri report</h5>
                            <p class="text-muted small mb-0">Scegli la vista temporale e raffina l’analisi per pagamento o operatore.</p>
                        </div>
                        <span class="badge rounded-pill text-bg-light"><?php echo sanitize_output((string) $report['period_label']); ?></span>
                    </div>
                    <form method="get" class="row g-3 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label" for="report_view">Vista</label>
                            <select class="form-select" id="report_view" name="view">
                                <option value="daily"<?php echo $report['view'] === 'daily' ? ' selected' : ''; ?>>Giornaliera</option>
                                <option value="monthly"<?php echo $report['view'] === 'monthly' ? ' selected' : ''; ?>>Mensile</option>
                                <option value="yearly"<?php echo $report['view'] === 'yearly' ? ' selected' : ''; ?>>Annuale</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="report_date">Giorno</label>
                            <input class="form-control" id="report_date" name="date" type="date" value="<?php echo sanitize_output((string) $report['filters']['date']); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="report_month">Mese</label>
                            <input class="form-control" id="report_month" name="month" type="month" value="<?php echo sanitize_output((string) $report['filters']['month']); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="report_year">Anno</label>
                            <input class="form-control" id="report_year" name="year" type="number" min="2020" max="2100" value="<?php echo sanitize_output((string) $report['filters']['year']); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="report_payment">Pagamento</label>
                            <select class="form-select" id="report_payment" name="payment">
                                <option value="">Tutti</option>
                                <?php foreach ($report['payment_options'] as $method): ?>
                                    <option value="<?php echo sanitize_output((string) $method); ?>"<?php echo (string) $report['filters']['payment'] === (string) $method ? ' selected' : ''; ?>><?php echo sanitize_output((string) $method); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label" for="report_operator">Operatore</label>
                            <select class="form-select" id="report_operator" name="operator_id">
                                <option value="">Tutti</option>
                                <?php foreach ($report['operator_options'] as $operator): ?>
                                    <option value="<?php echo (int) $operator['id']; ?>"<?php echo (string) $report['filters']['operator_id'] === (string) $operator['id'] ? ' selected' : ''; ?>><?php echo sanitize_output((string) $operator['nome']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 d-flex gap-2 flex-wrap">
                            <button class="btn btn-warning" type="submit"><i class="fa-solid fa-filter me-2"></i>Applica filtri</button>
                            <a class="btn btn-outline-secondary" href="<?php echo sanitize_output(express_module_url('reports')); ?>">Reset</a>
                        </div>
                    </form>
                </div>
            </section>

            <section class="row g-4">
                <div class="col-12 col-xl-4">
                    <div class="card express-reports-panel h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <div>
                                    <h5 class="card-title mb-1">Per metodo pagamento</h5>
                                    <p class="text-muted small mb-0">Distribuzione delle vendite per canale di incasso.</p>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table align-middle express-reports-table mb-0">
                                    <thead><tr><th>Metodo</th><th class="text-end">Vendite</th><th class="text-end">Netto</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($report['payments'] as $row): ?>
                                            <tr><td><?php echo sanitize_output((string) $row['method']); ?></td><td class="text-end"><?php echo (int) $row['sale_count']; ?></td><td class="text-end">&euro; <?php echo number_format((float) $row['net_revenue'], 2, ',', '.'); ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-4">
                    <div class="card express-reports-panel h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <div>
                                    <h5 class="card-title mb-1">Per operatore</h5>
                                    <p class="text-muted small mb-0">Chi sta generando più volumi e ricavi nel periodo.</p>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table align-middle express-reports-table mb-0">
                                    <thead><tr><th>Operatore</th><th class="text-end">Vendite</th><th class="text-end">Netto</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($report['operators'] as $row): ?>
                                            <tr><td><?php echo sanitize_output((string) $row['operator_name']); ?></td><td class="text-end"><?php echo (int) $row['sale_count']; ?></td><td class="text-end">&euro; <?php echo number_format((float) $row['net_revenue'], 2, ',', '.'); ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-4">
                    <div class="card express-reports-panel h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                                <div>
                                    <h5 class="card-title mb-1">Top articoli / servizi</h5>
                                    <p class="text-muted small mb-0">Le voci più vendute per quantità e ricavo netto.</p>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table align-middle express-reports-table mb-0">
                                    <thead><tr><th>Voce</th><th>Tipo</th><th class="text-end">Q.tà</th><th class="text-end">Netto</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($report['products'] as $row): ?>
                                            <tr><td><?php echo sanitize_output((string) $row['item_name']); ?></td><td><?php echo sanitize_output((string) $row['tipo']); ?></td><td class="text-end"><?php echo (int) $row['total_quantity']; ?></td><td class="text-end">&euro; <?php echo number_format((float) $row['net_revenue'], 2, ',', '.'); ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card express-reports-panel">
                <div class="card-body p-4">
                    <div class="d-flex justify-content-between align-items-start gap-3 mb-3">
                        <div>
                            <h5 class="card-title mb-1">Trend periodo</h5>
                            <p class="text-muted small mb-0">Andamento sintetico del periodo selezionato per volume vendite e ricavo netto.</p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle express-reports-table mb-0">
                            <thead><tr><th>Periodo</th><th class="text-end">Vendite</th><th class="text-end">Netto</th></tr></thead>
                            <tbody>
                                <?php foreach ($report['trend'] as $point): ?>
                                    <tr><td><?php echo sanitize_output((string) $point['label']); ?></td><td class="text-end"><?php echo (int) $point['sale_count']; ?></td><td class="text-end">&euro; <?php echo number_format((float) $point['net_revenue'], 2, ',', '.'); ?></td></tr>
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
