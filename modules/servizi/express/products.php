<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
express_module_require_access($pdo, $currentUserId);

$pageTitle = 'Prodotti Express';
$perPage = 10;

express_module_bootstrap_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = express_module_save_product($pdo, $_POST, $currentUserId);
    add_flash($result['success'] ? 'success' : 'warning', $result['message']);
    header('Location: products.php');
    exit;
}

$productsTotal = express_module_product_count($pdo);
$productsPageCount = max(1, (int) ceil($productsTotal / $perPage));
$productsCurrentPage = max(1, (int) ($_GET['page'] ?? 1));
$productsCurrentPage = min($productsCurrentPage, $productsPageCount);
$products = express_module_product_list($pdo, $productsCurrentPage, $perPage);
$editingProduct = isset($_GET['edit']) ? express_module_product_detail($pdo, (int) $_GET['edit']) : null;
$categoryOptions = express_module_product_category_options();
$lowStockProducts = express_module_low_stock_products($pdo);
$productSummary = express_module_product_summary($pdo);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <?php express_module_render_nav('products'); ?>
        <style>
            .express-products-shell {
                display: grid;
                gap: 1.5rem;
            }

            .express-products-hero {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(58, 123, 213, 0.14);
                background:
                    radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 34%),
                    radial-gradient(circle at top right, rgba(0, 184, 148, 0.12), transparent 26%),
                    #fff;
            }

            .express-products-pill {
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

            .express-products-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .express-products-kpi {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.15rem;
                padding: 1rem 1.1rem;
                background: rgba(255, 255, 255, 0.88);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
            }

            .express-products-kpi-label {
                display: block;
                margin-bottom: 0.4rem;
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-products-kpi-value {
                display: block;
                color: #0f172a;
                font-size: 1.85rem;
                font-weight: 800;
                line-height: 1;
            }

            .express-products-kpi-note {
                display: block;
                margin-top: 0.45rem;
                color: #64748b;
                font-size: 0.86rem;
            }

            .express-products-panel {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.3rem;
                background: #fff;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
            }

            .express-products-low-stock {
                display: grid;
                gap: 0.9rem;
            }

            .express-products-low-stock-item {
                display: flex;
                justify-content: space-between;
                gap: 1rem;
                align-items: center;
                padding: 1rem;
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1rem;
                background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            }

            .express-products-table {
                --bs-table-bg: transparent;
                --bs-table-hover-bg: rgba(37, 99, 235, 0.04);
                margin-bottom: 0;
            }

            .express-products-table thead th {
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-products-table td {
                padding-top: 1rem;
                padding-bottom: 1rem;
                border-color: rgba(15, 23, 42, 0.06);
                vertical-align: middle;
            }

            .express-products-code {
                display: inline-flex;
                padding: 0.42rem 0.68rem;
                border-radius: 0.8rem;
                background: #f8fafc;
                color: #0f172a;
                font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
                font-size: 0.86rem;
                font-weight: 700;
            }

            .express-products-empty {
                padding: 2rem 1rem;
                text-align: center;
                color: #64748b;
            }

            @media (max-width: 1199.98px) {
                .express-products-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767.98px) {
                .express-products-kpis {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <div class="express-products-shell">
            <section class="card express-products-hero">
                <div class="card-body p-4 p-xl-5">
                    <div class="row g-4 align-items-start">
                        <div class="col-12 col-xl-7">
                            <span class="express-products-pill"><i class="fa-solid fa-boxes-stacked"></i>Catalogo operativo</span>
                            <h1 class="mt-3 mb-2 fw-bold" style="max-width: 14ch;">Prodotti, scorte e riordini in una vista più chiara.</h1>
                            <p class="text-muted mb-0" style="max-width: 72ch;">
                                Gestisci il catalogo del reparto telefonia con un colpo d'occhio su disponibilità, valore di stock e prodotti da presidiare.
                            </p>
                        </div>
                        <div class="col-12 col-xl-5">
                            <div class="express-products-kpis">
                                <div class="express-products-kpi">
                                    <span class="express-products-kpi-label">Prodotti attivi</span>
                                    <span class="express-products-kpi-value"><?php echo (int) $productSummary['active_products']; ?></span>
                                    <span class="express-products-kpi-note">Disponibili nel catalogo vendita</span>
                                </div>
                                <div class="express-products-kpi">
                                    <span class="express-products-kpi-label">Unità a stock</span>
                                    <span class="express-products-kpi-value"><?php echo (int) $productSummary['stock_units']; ?></span>
                                    <span class="express-products-kpi-note">Quantità totali in magazzino</span>
                                </div>
                                <div class="express-products-kpi">
                                    <span class="express-products-kpi-label">Sotto soglia</span>
                                    <span class="express-products-kpi-value"><?php echo (int) $productSummary['low_stock_products']; ?></span>
                                    <span class="express-products-kpi-note">Articoli da monitorare</span>
                                </div>
                                <div class="express-products-kpi">
                                    <span class="express-products-kpi-label">Valore stock</span>
                                    <span class="express-products-kpi-value">&euro; <?php echo number_format((float) $productSummary['stock_value'], 0, ',', '.'); ?></span>
                                    <span class="express-products-kpi-note"><?php echo (int) $productSummary['total_products']; ?> referenze totali</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-4">
                <div class="col-12 col-xl-5">
                    <div class="card express-products-panel h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                                <div>
                                    <h5 class="card-title mb-1"><?php echo $editingProduct ? 'Modifica prodotto' : 'Nuovo prodotto'; ?></h5>
                                    <p class="text-muted small mb-0">Scheda prodotto completa con prezzo, stock, soglia e metadati utili alla vendita.</p>
                                </div>
                                <?php if ($editingProduct): ?>
                                    <a class="btn btn-outline-secondary btn-sm" href="products.php">Annulla</a>
                                <?php else: ?>
                                    <span class="badge rounded-pill text-bg-light"><?php echo (int) $productSummary['inactive_products']; ?> inattivi</span>
                                <?php endif; ?>
                            </div>
                            <form method="post" class="row g-3">
                                <input type="hidden" name="_token" value="<?php echo sanitize_output(csrf_token()); ?>">
                                <input type="hidden" name="product_id" value="<?php echo (int) ($editingProduct['id'] ?? 0); ?>">
                                <div class="col-md-8">
                                    <label class="form-label" for="product_name">Nome prodotto</label>
                                    <input class="form-control" id="product_name" name="name" maxlength="150" required value="<?php echo sanitize_output((string) ($editingProduct['nome'] ?? '')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="product_category">Categoria</label>
                                    <select class="form-select" id="product_category" name="category" required>
                                        <option value="">Seleziona...</option>
                                        <?php foreach ($categoryOptions as $category): ?>
                                            <option value="<?php echo sanitize_output($category); ?>"<?php echo $category === (string) ($editingProduct['categoria'] ?? '') ? ' selected' : ''; ?>><?php echo sanitize_output($category); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="product_sku">SKU</label>
                                    <input class="form-control" id="product_sku" name="sku" maxlength="100" value="<?php echo sanitize_output((string) ($editingProduct['sku'] ?? '')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="product_imei">IMEI</label>
                                    <input class="form-control" id="product_imei" name="imei" maxlength="100" value="<?php echo sanitize_output((string) ($editingProduct['imei'] ?? '')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="product_price">Prezzo</label>
                                    <input class="form-control" id="product_price" name="price" type="number" min="0" step="0.01" value="<?php echo sanitize_output(number_format((float) ($editingProduct['prezzo'] ?? 0), 2, '.', '')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="product_vat">IVA</label>
                                    <input class="form-control" id="product_vat" name="tax_rate" type="number" min="0" max="100" step="0.01" value="<?php echo sanitize_output(number_format((float) ($editingProduct['aliquota_iva'] ?? 22), 2, '.', '')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="product_stock">Stock disponibile</label>
                                    <input class="form-control" id="product_stock" name="stock_quantity" type="number" min="0" step="1" value="<?php echo (int) ($editingProduct['stock_quantita'] ?? 0); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="product_threshold">Soglia riordino</label>
                                    <input class="form-control" id="product_threshold" name="reorder_threshold" type="number" min="0" step="1" value="<?php echo (int) ($editingProduct['soglia_riordino'] ?? 0); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="product_notes">Note</label>
                                    <textarea class="form-control" id="product_notes" name="notes" rows="3"><?php echo sanitize_output((string) ($editingProduct['note'] ?? '')); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" id="product_active" name="is_active" type="checkbox" value="1"<?php echo !isset($editingProduct['attivo']) || (int) $editingProduct['attivo'] === 1 ? ' checked' : ''; ?>>
                                        <label class="form-check-label" for="product_active">Prodotto attivo</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-warning w-100" type="submit"><i class="fa-solid fa-save me-2"></i>Salva prodotto</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-7">
                    <div class="card express-products-panel h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                                <div>
                                    <h5 class="card-title mb-1">Prodotti sotto soglia</h5>
                                    <p class="text-muted small mb-0">Riepilogo rapido degli articoli che richiedono riordino o presidio immediato.</p>
                                </div>
                                <span class="badge rounded-pill text-bg-light"><?php echo count($lowStockProducts); ?> criticità</span>
                            </div>
                            <?php if ($lowStockProducts === []): ?>
                                <div class="express-products-empty">Nessun prodotto sotto soglia riordino.</div>
                            <?php else: ?>
                                <div class="express-products-low-stock">
                                    <?php foreach ($lowStockProducts as $product): ?>
                                        <div class="express-products-low-stock-item">
                                            <div>
                                                <div class="fw-semibold"><?php echo sanitize_output((string) $product['nome']); ?></div>
                                                <div class="small text-muted"><?php echo sanitize_output((string) ($product['categoria'] ?? '')); ?></div>
                                            </div>
                                            <div class="text-end">
                                                <div class="fw-semibold"><?php echo (int) $product['stock_quantita']; ?> pezzi</div>
                                                <div class="small text-muted">Soglia <?php echo (int) $product['soglia_riordino']; ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card express-products-panel">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                        <div>
                            <h5 class="card-title mb-1">Catalogo prodotti</h5>
                            <p class="text-muted small mb-0">Vista completa del catalogo Express con prezzo, stock e stato operativo per ogni referenza.</p>
                        </div>
                        <span class="badge rounded-pill text-bg-light px-3 py-2"><?php echo $productsTotal; ?> prodotti</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table align-middle express-products-table">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Categoria</th>
                                    <th>SKU</th>
                                    <th>IMEI</th>
                                    <th class="text-end">Prezzo</th>
                                    <th class="text-end">Stock</th>
                                    <th>Stato</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($products === []): ?>
                                    <tr>
                                        <td colspan="8" class="express-products-empty">Nessun prodotto presente nel catalogo.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?php echo sanitize_output((string) $product['nome']); ?></div>
                                            <div class="small text-muted">ID #<?php echo (int) $product['id']; ?></div>
                                        </td>
                                        <td><?php echo sanitize_output((string) ($product['categoria'] ?? '')); ?></td>
                                        <td>
                                            <span class="express-products-code"><?php echo sanitize_output((string) ($product['sku'] ?? '—')); ?></span>
                                        </td>
                                        <td>
                                            <span class="express-products-code"><?php echo sanitize_output((string) ($product['imei'] ?? '—')); ?></span>
                                        </td>
                                        <td class="text-end fw-semibold">&euro; <?php echo number_format((float) $product['prezzo'], 2, ',', '.'); ?></td>
                                        <td class="text-end">
                                            <span class="fw-semibold"><?php echo (int) $product['stock_quantita']; ?></span>
                                        </td>
                                        <td><span class="badge <?php echo (int) $product['attivo'] === 1 ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo (int) $product['attivo'] === 1 ? 'Attivo' : 'Inattivo'; ?></span></td>
                                        <td class="text-end"><a class="btn btn-icon btn-soft-accent btn-sm" href="products.php?edit=<?php echo (int) $product['id']; ?>"><i class="fa-solid fa-pen"></i></a></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($productsPageCount > 1): ?>
                        <?php
                        $pagesToShow = array_unique([
                            1,
                            max(1, $productsCurrentPage - 1),
                            $productsCurrentPage,
                            min($productsPageCount, $productsCurrentPage + 1),
                            $productsPageCount,
                        ]);
                        sort($pagesToShow);
                        ?>
                        <div class="overflow-auto mt-4">
                            <nav aria-label="Paginazione catalogo prodotti">
                                <ul class="pagination pagination-sm mb-0 justify-content-end flex-nowrap">
                                    <li class="page-item <?php echo $productsCurrentPage <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo max(1, $productsCurrentPage - 1); ?>">Precedente</a>
                                    </li>
                                    <?php $previousPage = 0; ?>
                                    <?php foreach ($pagesToShow as $page): ?>
                                        <?php if ($previousPage > 0 && $page - $previousPage > 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item <?php echo $page === $productsCurrentPage ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $page; ?>"><?php echo $page; ?></a>
                                        </li>
                                        <?php $previousPage = $page; ?>
                                    <?php endforeach; ?>
                                    <li class="page-item <?php echo $productsCurrentPage >= $productsPageCount ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo min($productsPageCount, $productsCurrentPage + 1); ?>">Successiva</a>
                                    </li>
                                </ul>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
