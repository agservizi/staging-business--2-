<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

express_module_require_access($pdo, (int) ($_SESSION['user_id'] ?? 0));

$pageTitle = 'Vendite Express';

express_module_bootstrap_schema($pdo);
$sales = express_module_sales_rows($pdo);
$salesSummary = express_module_sales_summary($pdo);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <?php express_module_render_nav('sales'); ?>
        <style>
            .express-sales-shell {
                display: grid;
                gap: 1.5rem;
            }

            .express-sales-hero {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(58, 123, 213, 0.14);
                background:
                    radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 34%),
                    radial-gradient(circle at top right, rgba(0, 184, 148, 0.12), transparent 26%),
                    #fff;
            }

            .express-sales-pill {
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

            .express-sales-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .express-sales-kpi {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.15rem;
                padding: 1rem 1.1rem;
                background: rgba(255, 255, 255, 0.88);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
            }

            .express-sales-kpi-label {
                display: block;
                margin-bottom: 0.4rem;
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-sales-kpi-value {
                display: block;
                color: #0f172a;
                font-size: 1.85rem;
                font-weight: 800;
                line-height: 1;
            }

            .express-sales-kpi-note {
                display: block;
                margin-top: 0.45rem;
                color: #64748b;
                font-size: 0.86rem;
            }

            .express-sales-panel {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.3rem;
                background: #fff;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
            }

            .express-sales-table {
                --bs-table-bg: transparent;
                --bs-table-hover-bg: rgba(37, 99, 235, 0.04);
                margin-bottom: 0;
            }

            .express-sales-table thead th {
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-sales-table td {
                padding-top: 1rem;
                padding-bottom: 1rem;
                border-color: rgba(15, 23, 42, 0.06);
                vertical-align: middle;
            }

            .express-sales-id {
                display: inline-flex;
                padding: 0.42rem 0.68rem;
                border-radius: 0.8rem;
                background: #f8fafc;
                color: #0f172a;
                font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
                font-size: 0.84rem;
                font-weight: 700;
            }

            .express-sales-empty {
                padding: 2rem 1rem;
                text-align: center;
                color: #64748b;
            }

            @media (max-width: 1199.98px) {
                .express-sales-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767.98px) {
                .express-sales-kpis {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <div class="express-sales-shell">
            <section class="card express-sales-hero">
                <div class="card-body p-4 p-xl-5">
                    <div class="row g-4 align-items-start">
                        <div class="col-12 col-xl-7">
                            <span class="express-sales-pill"><i class="fa-solid fa-receipt"></i>Registro vendite</span>
                            <h1 class="mt-3 mb-2 fw-bold" style="max-width: 14ch;">Vendite Express più chiare per consultazione e controllo cassa.</h1>
                            <p class="text-muted mb-0" style="max-width: 72ch;">
                                Tieni sotto controllo l'elenco delle vendite, gli importi incassati e i movimenti annullati o rimborsati in una vista più leggibile.
                            </p>
                        </div>
                        <div class="col-12 col-xl-5">
                            <div class="express-sales-kpis">
                                <div class="express-sales-kpi">
                                    <span class="express-sales-kpi-label">Vendite totali</span>
                                    <span class="express-sales-kpi-value"><?php echo (int) $salesSummary['total_sales']; ?></span>
                                    <span class="express-sales-kpi-note">Movimenti presenti nel registro</span>
                                </div>
                                <div class="express-sales-kpi">
                                    <span class="express-sales-kpi-label">Incassato</span>
                                    <span class="express-sales-kpi-value">&euro; <?php echo number_format((float) $salesSummary['completed_revenue'], 0, ',', '.'); ?></span>
                                    <span class="express-sales-kpi-note"><?php echo (int) $salesSummary['completed_sales']; ?> vendite completate</span>
                                </div>
                                <div class="express-sales-kpi">
                                    <span class="express-sales-kpi-label">Ticket medio</span>
                                    <span class="express-sales-kpi-value">&euro; <?php echo number_format((float) $salesSummary['average_ticket'], 0, ',', '.'); ?></span>
                                    <span class="express-sales-kpi-note">Calcolato sulle vendite completate</span>
                                </div>
                                <div class="express-sales-kpi">
                                    <span class="express-sales-kpi-label">Eccezioni</span>
                                    <span class="express-sales-kpi-value"><?php echo (int) $salesSummary['cancelled_sales'] + (int) $salesSummary['refunded_sales']; ?></span>
                                    <span class="express-sales-kpi-note"><?php echo (int) $salesSummary['cancelled_sales']; ?> annullate, <?php echo (int) $salesSummary['refunded_sales']; ?> rimborsate</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card express-sales-panel">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                        <div>
                            <h5 class="card-title mb-1">Elenco vendite</h5>
                            <p class="text-muted small mb-0">
                                Consultazione completa delle vendite Express con cliente, operatore, metodo di pagamento e collegamento al dettaglio.
                                <?php if ($salesSummary['latest_sale_at'] !== ''): ?>
                                    Ultima vendita: <?php echo sanitize_output(format_datetime_locale((string) $salesSummary['latest_sale_at'])); ?>.
                                <?php endif; ?>
                            </p>
                        </div>
                        <a class="btn btn-warning text-dark" href="<?php echo sanitize_output(express_module_url('create_sale')); ?>"><i class="fa-solid fa-circle-plus me-2"></i>Nuova vendita</a>
                    </div>
                    <?php if ($sales === []): ?>
                        <div class="express-sales-empty">Nessuna vendita registrata.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table align-middle express-sales-table">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Cliente</th>
                                        <th>Data</th>
                                        <th>Metodo</th>
                                        <th>Righe</th>
                                        <th>Operatore</th>
                                        <th>Stato</th>
                                        <th class="text-end">Totale</th>
                                        <th class="text-end">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($sales as $sale): ?>
                                        <?php
                                        $status = (string) ($sale['stato'] ?? '');
                                        $statusBadgeClass = 'text-bg-secondary';
                                        if ($status === 'Completata') {
                                            $statusBadgeClass = 'text-bg-success';
                                        } elseif ($status === 'Annullata') {
                                            $statusBadgeClass = 'text-bg-danger';
                                        } elseif ($status === 'Rimborsata') {
                                            $statusBadgeClass = 'text-bg-warning';
                                        }
                                        ?>
                                        <tr>
                                            <td><span class="express-sales-id">#<?php echo (int) $sale['id']; ?></span></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo sanitize_output(express_module_sale_customer_label($sale)); ?></div>
                                                <div class="small text-muted">Documento gestionale Express</div>
                                            </td>
                                            <td class="text-muted"><?php echo sanitize_output(format_datetime_locale((string) ($sale['data_vendita'] ?? ''))); ?></td>
                                            <td><?php echo sanitize_output((string) ($sale['metodo_pagamento'] ?? '')); ?></td>
                                            <td><span class="badge rounded-pill text-bg-light"><?php echo (int) ($sale['righe'] ?? 0); ?> righe</span></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo sanitize_output(trim((string) (($sale['user_nome'] ?? '') . ' ' . ($sale['user_cognome'] ?? '')))); ?></div>
                                                <div class="small text-muted">Operatore cassa</div>
                                            </td>
                                            <td><span class="badge <?php echo $statusBadgeClass; ?>"><?php echo sanitize_output($status !== '' ? $status : '—'); ?></span></td>
                                            <td class="text-end fw-semibold">&euro; <?php echo number_format((float) ($sale['totale'] ?? 0), 2, ',', '.'); ?></td>
                                            <td class="text-end">
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo sanitize_output(express_module_url('view_sale', ['id' => (int) $sale['id']])); ?>" title="Dettagli">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
