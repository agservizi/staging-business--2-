<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

express_module_require_access($pdo, (int) ($_SESSION['user_id'] ?? 0));

$pageTitle = 'Express Telefonia';

express_module_bootstrap_schema($pdo);

$dashboardPeriod = (string) ($_GET['period'] ?? '7d');
$stats = express_module_dashboard_stats($pdo);
$recentSales = express_module_recent_sales($pdo, 8);
$lowStock = express_module_low_stock($pdo);
$lowStockProducts = express_module_low_stock_products($pdo);
$operatorBreakdown = express_module_operator_stock_breakdown($pdo);
$periodSummary = express_module_dashboard_period_summary($pdo, $dashboardPeriod);
$periodDays = $periodSummary['period'] === '30d' ? 30 : ($periodSummary['period'] === 'month' ? 30 : 7);
$salesTrend = express_module_sales_trend($pdo, $periodDays);
$paymentBreakdown = express_module_payment_breakdown($pdo, $periodSummary['period']);
$recentActivity = express_module_recent_activity($pdo, 8);
$topItems = express_module_top_items($pdo, $periodSummary['period'], 5);

$totalOperatorStock = 0;
foreach ($operatorBreakdown as $row) {
    $totalOperatorStock += (int) ($row['available_stock'] ?? 0);
}

$topOperatorName = (string) ($operatorBreakdown[0]['nome'] ?? 'Nessun operatore');
$topOperatorStock = (int) ($operatorBreakdown[0]['available_stock'] ?? 0);
$criticalOperators = count($lowStock);
$criticalProducts = count($lowStockProducts);
$chartSeries = [];
$chartPalette = ['#0f766e', '#2563eb', '#f59e0b', '#ef4444', '#7c3aed', '#14b8a6', '#ea580c', '#475569'];

foreach ($operatorBreakdown as $index => $row) {
    $chartSeries[] = [
        'name' => (string) ($row['nome'] ?? 'Operatore'),
        'y' => (int) ($row['available_stock'] ?? 0),
        'color' => $chartPalette[$index % count($chartPalette)],
    ];
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<style>
.express-dashboard-shell {
    display: grid;
    gap: 1.25rem;
}

.express-hero {
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(var(--ag-accent-rgb), 0.12);
    border-radius: 1.5rem;
    background:
        radial-gradient(circle at top left, rgba(37, 99, 235, 0.22), transparent 28%),
        radial-gradient(circle at 80% 18%, rgba(16, 185, 129, 0.18), transparent 22%),
        linear-gradient(135deg, #f7fbff 0%, #ffffff 45%, #eef6ff 100%);
    box-shadow: 0 24px 60px rgba(15, 23, 42, 0.08);
}

.express-hero::after {
    content: "";
    position: absolute;
    inset: auto -4rem -5rem auto;
    width: 18rem;
    height: 18rem;
    border-radius: 999px;
    background: radial-gradient(circle, rgba(15, 118, 110, 0.16), transparent 65%);
    pointer-events: none;
}

.express-hero-grid {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: minmax(0, 1.4fr) minmax(320px, 0.95fr);
    gap: 1rem;
    align-items: start;
}

.express-hero-copy {
    padding: 1.6rem 1.7rem;
}

.express-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.4rem 0.75rem;
    border-radius: 999px;
    background: rgba(15, 118, 110, 0.1);
    color: #0f766e;
    font-size: 0.74rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}

.express-hero-title {
    margin: 0.85rem 0 0;
    font-size: 2.1rem;
    line-height: 1.05;
    font-weight: 900;
    color: #142235;
}

.express-hero-copy p {
    margin: 0.75rem 0 0;
    max-width: 42rem;
    color: #56657c;
    font-size: 0.98rem;
}

.express-hero-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    margin-top: 1.1rem;
}

.express-period-switch {
    display: inline-flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 1rem;
}

.express-period-switch .btn {
    border-radius: 999px;
}

.express-hero-actions .btn {
    border-radius: 999px;
    padding-inline: 1rem;
}

.express-insight-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.9rem;
    margin-top: 1.3rem;
}

.express-insight {
    padding: 1rem 1.05rem;
    border-radius: 1.15rem;
    background: rgba(255, 255, 255, 0.74);
    border: 1px solid rgba(148, 163, 184, 0.2);
    backdrop-filter: blur(8px);
}

.express-insight-label {
    color: #64748b;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 800;
}

.express-insight-value {
    margin-top: 0.35rem;
    color: #132238;
    font-size: 1.55rem;
    line-height: 1;
    font-weight: 900;
}

.express-insight-subtle {
    margin-top: 0.35rem;
    color: #64748b;
    font-size: 0.8rem;
}

.express-chart-card {
    margin: 1rem;
    padding: 1.1rem;
    border-radius: 1.3rem;
    background: linear-gradient(180deg, rgba(17, 24, 39, 0.96), rgba(30, 41, 59, 0.96));
    color: #fff;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06);
}

.express-chart-card h3 {
    margin: 0;
    font-size: 1rem;
    font-weight: 800;
}

.express-chart-card p {
    margin: 0.3rem 0 0;
    color: rgba(226, 232, 240, 0.8);
    font-size: 0.82rem;
}

#expressOperatorDonut {
    min-height: 320px;
    margin-top: 0.85rem;
}

.express-chart-fallback {
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 320px;
    padding: 1rem;
    color: rgba(226, 232, 240, 0.78);
    font-size: 0.88rem;
    text-align: center;
}

.express-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: 1rem;
}

.express-kpi-card {
    position: relative;
    overflow: hidden;
    border: 1px solid rgba(var(--ag-accent-rgb), 0.1);
    border-radius: 1.25rem;
    background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(247,250,252,0.98));
    box-shadow: 0 16px 36px rgba(15, 23, 42, 0.06);
}

.express-kpi-card .card-body {
    padding: 1.15rem;
}

.express-kpi-icon {
    width: 2.8rem;
    height: 2.8rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 0.95rem;
    margin-bottom: 0.9rem;
    font-size: 1rem;
    background: rgba(var(--ag-accent-rgb), 0.12);
    color: var(--ag-accent-strong);
}

.express-kpi-label {
    font-size: 0.76rem;
    font-weight: 800;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: #64748b;
}

.express-kpi-value {
    margin-top: 0.35rem;
    font-size: 1.9rem;
    line-height: 1;
    font-weight: 900;
    color: #142235;
}

.express-kpi-meta {
    margin-top: 0.45rem;
    color: #64748b;
    font-size: 0.82rem;
}

.express-panels {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(320px, 0.85fr);
    gap: 1rem;
}

.express-main-stack,
.express-side-stack {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.express-surface {
    border: 1px solid rgba(var(--ag-accent-rgb), 0.08);
    border-radius: 1.35rem;
    background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(248,250,252,0.98));
    box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
}

.express-surface-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1.2rem 1.2rem 0;
}

.express-surface-title {
    margin: 0;
    font-size: 1rem;
    font-weight: 850;
    color: #142235;
}

.express-surface-copy {
    margin: 0.25rem 0 0;
    color: #64748b;
    font-size: 0.82rem;
}

.express-sales-table {
    padding: 1rem 1.2rem 1.2rem;
}

.express-sales-table .table {
    --bs-table-bg: transparent;
    --bs-table-color: #1e293b;
    --bs-table-hover-bg: rgba(37, 99, 235, 0.05);
    --bs-table-hover-color: #0f172a;
    margin-bottom: 0;
}

.express-sales-table thead th {
    border-bottom: 0;
    color: #64748b;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    font-weight: 800;
    background: #f8fafc;
}

.express-sales-table tbody td {
    border-color: rgba(148, 163, 184, 0.16);
    vertical-align: middle;
}

.express-alert-list,
.express-product-list {
    display: grid;
    gap: 0.85rem;
    padding: 1rem 1.2rem 1.2rem;
}

.express-alert-item,
.express-product-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.85rem;
    padding: 0.95rem 1rem;
    border-radius: 1rem;
    background: #fff;
    border: 1px solid rgba(148, 163, 184, 0.16);
}

.express-alert-item strong,
.express-product-item strong {
    display: block;
    color: #142235;
}

.express-alert-item small,
.express-product-item small {
    display: block;
    color: #64748b;
    margin-top: 0.18rem;
}

.express-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 3.25rem;
    padding: 0.45rem 0.7rem;
    border-radius: 999px;
    font-size: 0.75rem;
    font-weight: 800;
}

.express-chip-danger {
    background: rgba(239, 68, 68, 0.12);
    color: #b91c1c;
}

.express-chip-warning {
    background: rgba(245, 158, 11, 0.14);
    color: #b45309;
}

.express-empty {
    padding: 1rem 1.2rem 1.2rem;
    color: #64748b;
}

.express-insights-grid {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(320px, 0.85fr);
    gap: 1rem;
    align-items: start;
}

.express-insights-grid > .express-main-stack,
.express-insights-grid > .express-side-stack {
    min-width: 0;
}

.express-chart-body {
    padding: 0.5rem 1rem 0.85rem;
}

#expressSalesTrendChart {
    min-height: 240px;
}

.express-payment-list,
.express-activity-list {
    display: grid;
    gap: 0.8rem;
    padding: 1rem 1.2rem 1.2rem;
}

.express-payment-item,
.express-activity-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.85rem;
    padding: 0.95rem 1rem;
    border-radius: 1rem;
    background: #fff;
    border: 1px solid rgba(148, 163, 184, 0.16);
}

.express-payment-item strong,
.express-activity-item strong {
    display: block;
    color: #142235;
}

.express-payment-item small,
.express-activity-item small {
    display: block;
    color: #64748b;
    margin-top: 0.18rem;
}

.express-chip-success {
    background: rgba(16, 185, 129, 0.12);
    color: #047857;
}

.express-chip-neutral {
    background: rgba(71, 85, 105, 0.12);
    color: #334155;
}

.express-top-list {
    display: grid;
    gap: 0.8rem;
    padding: 1rem 1.2rem 1.2rem;
}

.express-top-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.85rem;
    padding: 0.95rem 1rem;
    border-radius: 1rem;
    background: #fff;
    border: 1px solid rgba(148, 163, 184, 0.16);
}

@media (max-width: 1199.98px) {
    .express-hero-grid,
    .express-panels,
    .express-kpi-grid,
    .express-insights-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 767.98px) {
    .express-insight-grid {
        grid-template-columns: 1fr;
    }

    .express-hero-copy {
        padding: 1.2rem;
    }

    .express-chart-card {
        margin: 0 1rem 1rem;
    }

    .express-kpi-value {
        font-size: 1.55rem;
    }
}
</style>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main id="main-content" class="content-wrapper">
        <?php express_module_render_nav('dashboard'); ?>

        <div class="express-dashboard-shell">
            <section class="express-hero">
                <div class="express-hero-grid">
                    <div class="express-hero-copy">
                        <span class="express-eyebrow"><i class="fa-solid fa-bolt"></i> Express telefonia</span>
                        <h1 class="express-hero-title">Una cabina di regia piu' chiara per vendite, stock e priorita' operative.</h1>
                        <p>Controlli in un colpo solo lo stato delle SIM, il ritmo delle vendite del mese e le aree che richiedono intervento immediato. Il layout privilegia velocita' operativa e lettura rapida a banco.</p>

                        <div class="express-hero-actions">
                            <a class="btn btn-primary" href="create_sale"><i class="fa-solid fa-plus me-2"></i>Nuova vendita</a>
                            <a class="btn btn-outline-secondary" href="stock">Magazzino ICCID</a>
                            <a class="btn btn-outline-secondary" href="reports">Apri report</a>
                        </div>

                        <div class="express-period-switch">
                            <?php foreach (['7d' => '7 giorni', '30d' => '30 giorni', 'month' => 'Mese'] as $periodKey => $periodLabel): ?>
                                <a
                                    class="btn btn-sm <?php echo $periodSummary['period'] === $periodKey ? 'btn-primary' : 'btn-outline-secondary'; ?>"
                                    href="?<?php echo http_build_query(['period' => $periodKey]); ?>"
                                >
                                    <?php echo sanitize_output($periodLabel); ?>
                                </a>
                            <?php endforeach; ?>
                        </div>

                        <div class="express-insight-grid">
                            <div class="express-insight">
                                <div class="express-insight-label">Operatore top stock</div>
                                <div class="express-insight-value"><?php echo sanitize_output($topOperatorName); ?></div>
                                <div class="express-insight-subtle"><?php echo number_format($topOperatorStock, 0, ',', '.'); ?> ICCID disponibili al momento</div>
                            </div>
                            <div class="express-insight">
                                <div class="express-insight-label">Aree da presidiare</div>
                                <div class="express-insight-value"><?php echo number_format($criticalOperators + $criticalProducts, 0, ',', '.'); ?></div>
                                <div class="express-insight-subtle"><?php echo number_format($criticalOperators, 0, ',', '.'); ?> operatori e <?php echo number_format($criticalProducts, 0, ',', '.'); ?> prodotti sotto soglia</div>
                            </div>
                        </div>
                    </div>

                    <div class="express-chart-card">
                        <h3>Distribuzione stock ICCID</h3>
                        <p>Ripartizione per operatore delle SIM attualmente disponibili a magazzino.</p>
                        <div id="expressOperatorDonut" aria-label="Grafico donut stock ICCID per operatore"></div>
                    </div>
                </div>
            </section>

            <section class="express-kpi-grid">
                <article class="express-kpi-card">
                    <div class="card-body">
                        <span class="express-kpi-icon"><i class="fa-solid fa-sim-card"></i></span>
                        <div class="express-kpi-label">SIM disponibili</div>
                        <div class="express-kpi-value"><?php echo number_format((int) $stats['stock_available'], 0, ',', '.'); ?></div>
                        <div class="express-kpi-meta"><?php echo number_format(count($operatorBreakdown), 0, ',', '.'); ?> operatori con stock attivo</div>
                    </div>
                </article>
                <article class="express-kpi-card">
                    <div class="card-body">
                        <span class="express-kpi-icon"><i class="fa-solid fa-receipt"></i></span>
                        <div class="express-kpi-label">Vendite oggi</div>
                        <div class="express-kpi-value"><?php echo number_format((int) $stats['sold_today'], 0, ',', '.'); ?></div>
                        <div class="express-kpi-meta">Monitoraggio cassa della giornata corrente</div>
                    </div>
                </article>
                <article class="express-kpi-card">
                    <div class="card-body">
                        <span class="express-kpi-icon"><i class="fa-solid fa-calendar-days"></i></span>
                        <div class="express-kpi-label">Vendite periodo</div>
                        <div class="express-kpi-value"><?php echo number_format((int) $periodSummary['completed_sales'], 0, ',', '.'); ?></div>
                        <div class="express-kpi-meta"><?php echo sanitize_output((string) $periodSummary['label']); ?></div>
                    </div>
                </article>
                <article class="express-kpi-card">
                    <div class="card-body">
                        <span class="express-kpi-icon"><i class="fa-solid fa-wallet"></i></span>
                        <div class="express-kpi-label">Ricavi periodo</div>
                        <div class="express-kpi-value">&euro; <?php echo number_format((float) $periodSummary['revenue'], 2, ',', '.'); ?></div>
                        <div class="express-kpi-meta"><?php echo sanitize_output((string) $periodSummary['label']); ?></div>
                    </div>
                </article>
                <article class="express-kpi-card">
                    <div class="card-body">
                        <span class="express-kpi-icon"><i class="fa-solid fa-rotate-left"></i></span>
                        <div class="express-kpi-label">Resi periodo</div>
                        <div class="express-kpi-value"><?php echo number_format((int) $periodSummary['refunded_sales'], 0, ',', '.'); ?></div>
                        <div class="express-kpi-meta">Vendite totalmente rimborsate nel periodo selezionato</div>
                    </div>
                </article>
                <article class="express-kpi-card">
                    <div class="card-body">
                        <span class="express-kpi-icon"><i class="fa-solid fa-ban"></i></span>
                        <div class="express-kpi-label">Annulli periodo</div>
                        <div class="express-kpi-value"><?php echo number_format((int) $periodSummary['cancelled_sales'], 0, ',', '.'); ?></div>
                        <div class="express-kpi-meta">Operazioni annullate nel periodo selezionato</div>
                    </div>
                </article>
            </section>

            <section class="express-panels">
                <div class="express-main-stack">
                    <div class="express-surface">
                        <div class="express-surface-header">
                            <div>
                                <h2 class="express-surface-title">Ultime vendite registrate</h2>
                                <p class="express-surface-copy">Panoramica operativa immediata delle ultime transazioni Express.</p>
                            </div>
                            <a class="btn btn-warning text-dark btn-sm" href="sales"><i class="fa-solid fa-list me-2"></i>Apri elenco vendite</a>
                        </div>
                        <?php if ($recentSales === []): ?>
                            <div class="express-empty">Nessuna vendita registrata nel modulo Express.</div>
                        <?php else: ?>
                            <div class="express-sales-table">
                                <div class="table-responsive">
                                    <table class="table align-middle" data-datatable="true" data-page-length="10">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Cliente</th>
                                                <th>Voce</th>
                                                <th>ICCID</th>
                                                <th>Metodo</th>
                                                <th>Data</th>
                                                <th class="text-end">Totale</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recentSales as $sale): ?>
                                                <tr>
                                                    <td><a href="view_sale?id=<?php echo (int) $sale['id']; ?>">#<?php echo (int) $sale['id']; ?></a></td>
                                                    <td><?php echo sanitize_output(express_module_sale_customer_label($sale)); ?></td>
                                                    <td><?php echo sanitize_output((string) ($sale['descrizione'] ?? '')); ?></td>
                                                    <td><?php echo sanitize_output((string) ($sale['iccid'] ?? '-')); ?></td>
                                                    <td><?php echo sanitize_output((string) ($sale['metodo_pagamento'] ?? '')); ?></td>
                                                    <td><?php echo sanitize_output(format_datetime_locale((string) ($sale['data_vendita'] ?? ''))); ?></td>
                                                    <td class="text-end fw-semibold">&euro; <?php echo number_format((float) ($sale['totale'] ?? 0), 2, ',', '.'); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="express-surface">
                        <div class="express-surface-header">
                            <div>
                                <h2 class="express-surface-title">Top articoli del periodo</h2>
                                <p class="express-surface-copy">Le voci con maggiore rotazione commerciale nel periodo selezionato.</p>
                            </div>
                        </div>
                        <?php if ($topItems === []): ?>
                            <div class="express-empty">Nessun articolo venduto nel periodo selezionato.</div>
                        <?php else: ?>
                            <div class="express-top-list">
                                <?php foreach ($topItems as $item): ?>
                                    <div class="express-top-item">
                                        <div>
                                            <strong><?php echo sanitize_output((string) ($item['item_name'] ?? 'Voce Express')); ?></strong>
                                            <small><?php echo sanitize_output((string) strtoupper((string) ($item['tipo'] ?? ''))); ?> · <?php echo (int) ($item['total_quantity'] ?? 0); ?> pezzi</small>
                                        </div>
                                        <span class="express-chip express-chip-success">&euro; <?php echo number_format((float) ($item['revenue'] ?? 0), 2, ',', '.'); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="express-side-stack">
                    <div class="express-surface">
                        <div class="express-surface-header">
                            <div>
                                <h2 class="express-surface-title">Soglie riordino operatori</h2>
                                <p class="express-surface-copy">Operatori con giacenza ICCID da tenere sotto osservazione.</p>
                            </div>
                            <a class="btn btn-soft-accent btn-sm" href="stock">Gestisci stock</a>
                        </div>
                        <?php if ($lowStock === []): ?>
                            <div class="express-empty">Nessun operatore sotto soglia.</div>
                        <?php else: ?>
                            <div class="express-alert-list">
                                <?php foreach ($lowStock as $row): ?>
                                    <div class="express-alert-item">
                                        <div>
                                            <strong><?php echo sanitize_output((string) ($row['nome'] ?? '')); ?></strong>
                                            <small>Soglia minima: <?php echo (int) ($row['soglia_riordino'] ?? 0); ?> ICCID</small>
                                        </div>
                                        <span class="express-chip express-chip-danger"><?php echo (int) ($row['giacenza'] ?? 0); ?> disp.</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="express-surface">
                        <div class="express-surface-header">
                            <div>
                                <h2 class="express-surface-title">Prodotti sotto soglia</h2>
                                <p class="express-surface-copy">Accessori e prodotti commerciali con stock vicino all'esaurimento.</p>
                            </div>
                            <a class="btn btn-outline-secondary btn-sm" href="products">Apri catalogo</a>
                        </div>
                        <?php if ($lowStockProducts === []): ?>
                            <div class="express-empty">Nessun prodotto commerciale sotto soglia.</div>
                        <?php else: ?>
                            <div class="express-product-list">
                                <?php foreach ($lowStockProducts as $product): ?>
                                    <div class="express-product-item">
                                        <div>
                                            <strong><?php echo sanitize_output((string) ($product['nome'] ?? '')); ?></strong>
                                            <small><?php echo sanitize_output((string) ($product['categoria'] ?? 'Categoria non definita')); ?></small>
                                        </div>
                                        <span class="express-chip express-chip-warning"><?php echo (int) ($product['stock_quantita'] ?? 0); ?> / <?php echo (int) ($product['soglia_riordino'] ?? 0); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <section class="express-insights-grid">
                <div class="express-main-stack">
                    <div class="express-surface">
                        <div class="express-surface-header">
                            <div>
                                <h2 class="express-surface-title">Andamento vendite</h2>
                                <p class="express-surface-copy">Volumi giornalieri e ricavi netti per leggere il ritmo operativo del periodo selezionato.</p>
                            </div>
                        </div>
                        <div class="express-chart-body">
                            <div id="expressSalesTrendChart" aria-label="Grafico andamento vendite ultimi sette giorni"></div>
                        </div>
                    </div>

                    <div class="express-surface">
                        <div class="express-surface-header">
                            <div>
                                <h2 class="express-surface-title">Lettura del periodo</h2>
                                <p class="express-surface-copy">Riferimento rapido per capire cosa sta succedendo in cassa e sul modulo.</p>
                            </div>
                        </div>
                        <div class="express-activity-list">
                            <div class="express-activity-item">
                                <div>
                                    <strong>Periodo attivo</strong>
                                    <small><?php echo sanitize_output((string) $periodSummary['label']); ?></small>
                                </div>
                                <span class="express-chip express-chip-neutral"><?php echo sanitize_output((string) $periodSummary['period']); ?></span>
                            </div>
                            <div class="express-activity-item">
                                <div>
                                    <strong>Ricavo medio per vendita</strong>
                                    <small>Calcolato sulle vendite completate del periodo</small>
                                </div>
                                <span class="express-chip express-chip-success">&euro; <?php echo number_format((float) ($periodSummary['completed_sales'] > 0 ? $periodSummary['revenue'] / $periodSummary['completed_sales'] : 0), 2, ',', '.'); ?></span>
                            </div>
                            <div class="express-activity-item">
                                <div>
                                    <strong>Indice di reso</strong>
                                    <small>Rapporto tra vendite rimborsate e completate del periodo</small>
                                </div>
                                <span class="express-chip express-chip-warning"><?php echo number_format((float) ($periodSummary['completed_sales'] > 0 ? ($periodSummary['refunded_sales'] / $periodSummary['completed_sales']) * 100 : 0), 1, ',', '.'); ?>%</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="express-side-stack">
                    <div class="express-surface">
                        <div class="express-surface-header">
                            <div>
                                <h2 class="express-surface-title">Metodi di pagamento</h2>
                                <p class="express-surface-copy">Ripartizione incassi del mese per canale di pagamento.</p>
                            </div>
                        </div>
                        <?php if ($paymentBreakdown === []): ?>
                            <div class="express-empty">Nessun incasso registrato nel mese corrente.</div>
                        <?php else: ?>
                            <div class="express-payment-list">
                                <?php foreach ($paymentBreakdown as $payment): ?>
                                    <div class="express-payment-item">
                                        <div>
                                            <strong><?php echo sanitize_output((string) ($payment['metodo_pagamento'] ?? 'Non definito')); ?></strong>
                                            <small><?php echo (int) ($payment['sale_count'] ?? 0); ?> operazioni nel mese</small>
                                        </div>
                                        <span class="express-chip express-chip-success">&euro; <?php echo number_format((float) ($payment['revenue'] ?? 0), 2, ',', '.'); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="express-surface">
                        <div class="express-surface-header">
                            <div>
                                <h2 class="express-surface-title">Attività recenti</h2>
                                <p class="express-surface-copy">Feed operativo di vendite, annulli e rimborsi registrati nel modulo.</p>
                            </div>
                        </div>
                        <?php if ($recentActivity === []): ?>
                            <div class="express-empty">Nessuna attività recente da mostrare.</div>
                        <?php else: ?>
                            <div class="express-activity-list">
                                <?php foreach ($recentActivity as $activity): ?>
                                    <?php
                                    $status = (string) ($activity['stato'] ?? '');
                                    $statusLabel = $status === 'Annullata' ? 'Annullata' : ($status === 'Rimborsata' ? 'Rimborsata' : 'Completata');
                                    $statusClass = $status === 'Annullata'
                                        ? 'express-chip-danger'
                                        : ($status === 'Rimborsata' ? 'express-chip-warning' : 'express-chip-neutral');
                                    ?>
                                    <div class="express-activity-item">
                                        <div>
                                            <strong>#<?php echo (int) ($activity['id'] ?? 0); ?> · <?php echo sanitize_output(express_module_sale_customer_label($activity)); ?></strong>
                                            <small>
                                                <?php echo sanitize_output($statusLabel); ?>
                                                · <?php echo sanitize_output(format_datetime_locale((string) (($activity['updated_at'] ?? '') !== '' ? $activity['updated_at'] : ($activity['data_vendita'] ?? '')))); ?>
                                            </small>
                                        </div>
                                        <span class="express-chip <?php echo $statusClass; ?>">&euro; <?php echo number_format((float) ($activity['totale'] ?? 0), 2, ',', '.'); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
<script src="https://cdn.jsdelivr.net/npm/highcharts@12.4.0/highcharts.js"></script>
<script>
(function () {
    const chartData = <?php echo json_encode($chartSeries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const totalStock = <?php echo json_encode($totalOperatorStock, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const salesTrend = <?php echo json_encode($salesTrend, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const fallbackMessage = 'Impossibile caricare il grafico in questo momento.';

    function renderExpressDonut(attempt) {
        const chartTarget = document.getElementById('expressOperatorDonut');
        if (!chartTarget || chartTarget.dataset.chartReady === '1') {
            return;
        }

        if (!window.Highcharts) {
            if (attempt < 20) {
                window.setTimeout(function () {
                    renderExpressDonut(attempt + 1);
                }, 250);
            } else {
                chartTarget.innerHTML = '<div class="express-chart-fallback">' + fallbackMessage + '</div>';
            }
            return;
        }

        chartTarget.dataset.chartReady = '1';
        window.Highcharts.chart(chartTarget, {
            chart: {
                type: 'pie',
                backgroundColor: 'transparent',
                height: 320,
                spacing: [10, 10, 10, 10]
            },
            accessibility: {
                enabled: false
            },
            title: {
                text: totalStock > 0 ? String(totalStock) : '0',
                verticalAlign: 'middle',
                y: 6,
                style: {
                    color: '#f8fafc',
                    fontSize: '2rem',
                    fontWeight: '800'
                }
            },
            subtitle: {
                text: 'SIM disponibili',
                verticalAlign: 'middle',
                y: 30,
                style: {
                    color: 'rgba(226, 232, 240, 0.78)',
                    fontSize: '0.8rem'
                }
            },
            credits: {
                enabled: false
            },
            tooltip: {
                pointFormat: '<b>{point.y}</b> ICCID disponibili<br><span style="opacity:.7">{point.percentage:.1f}% del totale</span>'
            },
            legend: {
                align: 'center',
                verticalAlign: 'bottom',
                itemStyle: {
                    color: '#e2e8f0',
                    fontWeight: '600'
                },
                itemHoverStyle: {
                    color: '#ffffff'
                }
            },
            plotOptions: {
                pie: {
                    innerSize: '68%',
                    borderWidth: 0,
                    dataLabels: {
                        enabled: false
                    },
                    showInLegend: true
                },
                series: {
                    states: {
                        inactive: {
                            opacity: 1
                        }
                    }
                }
            },
            series: [{
                name: 'Stock',
                data: chartData.length > 0 ? chartData : [{
                    name: 'Nessuno stock',
                    y: 1,
                    color: '#334155'
                }]
            }]
        });
    }

    function renderSalesTrend(attempt) {
        const chartTarget = document.getElementById('expressSalesTrendChart');
        if (!chartTarget || chartTarget.dataset.chartReady === '1') {
            return;
        }

        if (!window.Highcharts) {
            if (attempt < 20) {
                window.setTimeout(function () {
                    renderSalesTrend(attempt + 1);
                }, 250);
            } else {
                chartTarget.innerHTML = '<div class="express-empty">Impossibile caricare il grafico andamento vendite.</div>';
            }
            return;
        }

        if (!salesTrend || !salesTrend.has_data) {
            chartTarget.dataset.chartReady = '1';
            chartTarget.innerHTML = '<div class="express-empty">Nessuna vendita completata nel periodo selezionato.</div>';
            return;
        }

        chartTarget.dataset.chartReady = '1';
        window.Highcharts.chart(chartTarget, {
            chart: {
                backgroundColor: 'transparent',
                spacing: [8, 8, 8, 8],
                height: 240
            },
            accessibility: {
                enabled: false
            },
            title: {
                text: null
            },
            credits: {
                enabled: false
            },
            xAxis: {
                categories: salesTrend.labels || [],
                lineColor: '#d7e0ea',
                tickColor: '#d7e0ea'
            },
            yAxis: [{
                title: {
                    text: 'Ricavi'
                },
                labels: {
                    formatter: function () {
                        return '€ ' + this.value;
                    }
                },
                gridLineColor: 'rgba(148, 163, 184, 0.18)'
            }, {
                title: {
                    text: 'Vendite'
                },
                opposite: true,
                allowDecimals: false,
                gridLineWidth: 0
            }],
            legend: {
                align: 'left',
                verticalAlign: 'top',
                itemStyle: {
                    color: '#334155',
                    fontWeight: '600'
                }
            },
            tooltip: {
                shared: true
            },
            plotOptions: {
                areaspline: {
                    marker: {
                        radius: 4
                    }
                },
                column: {
                    borderRadius: 6
                }
            },
            series: [{
                name: 'Ricavi',
                type: 'areaspline',
                data: salesTrend.revenue || [],
                color: '#2563eb',
                fillColor: 'rgba(37, 99, 235, 0.15)',
                yAxis: 0
            }, {
                name: 'Vendite',
                type: 'column',
                data: salesTrend.sales_count || [],
                color: '#0f766e',
                yAxis: 1
            }]
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        renderExpressDonut(0);
        renderSalesTrend(0);
    });

    window.addEventListener('load', function () {
        renderExpressDonut(0);
        renderSalesTrend(0);
    });
})();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
