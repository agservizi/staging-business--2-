<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
express_module_require_access($pdo, $currentUserId);

$pageTitle = 'Stock ICCID Express';
$perPage = 10;
$stockOperatorFilter = (int) ($_GET['operatore_id'] ?? 0);
$stockIccidFilter = trim((string) ($_GET['iccid'] ?? ''));

express_module_bootstrap_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add_provider') {
        $result = express_module_add_provider(
            $pdo,
            (string) ($_POST['provider_name'] ?? ''),
            (int) ($_POST['provider_threshold'] ?? 10),
            $currentUserId
        );
        add_flash($result['success'] ? 'success' : 'warning', $result['message']);
        header('Location: stock.php');
        exit;
    }

    if ($action === 'import_iccid') {
        try {
            $result = express_module_import_iccids(
                $pdo,
                (int) ($_POST['operatore_id'] ?? 0),
                express_module_parse_iccid_input((string) ($_POST['iccid_bulk'] ?? '')),
                trim((string) ($_POST['note'] ?? '')),
                $currentUserId
            );
            add_flash($result['success'] ? 'success' : 'warning', $result['message']);
        } catch (Throwable $exception) {
            error_log('Express stock import failed: ' . $exception->getMessage());
            add_flash('danger', 'Errore interno durante l\'importazione dello stock.');
        }

        header('Location: stock.php');
        exit;
    }
}

$providers = express_module_provider_options($pdo);
$stockFilters = [
    'operatore_id' => $stockOperatorFilter,
    'iccid' => $stockIccidFilter,
];
$stockSummary = express_module_stock_summary($pdo, $stockFilters);
$stockTotalRows = express_module_stock_count($pdo, $stockFilters);
$stockPageCount = max(1, (int) ceil($stockTotalRows / $perPage));
$stockCurrentPage = max(1, (int) ($_GET['page'] ?? 1));
$stockCurrentPage = min($stockCurrentPage, $stockPageCount);
$stockRows = express_module_stock_rows($pdo, $stockCurrentPage, $perPage, $stockFilters);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <?php express_module_render_nav('stock'); ?>
        <style>
            .express-stock-shell {
                display: grid;
                gap: 1.5rem;
            }

            .express-stock-hero {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(58, 123, 213, 0.14);
                background:
                    radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 34%),
                    radial-gradient(circle at top right, rgba(0, 184, 148, 0.12), transparent 26%),
                    #fff;
            }

            .express-stock-hero::after {
                content: "";
                position: absolute;
                inset: auto 1.5rem 1rem auto;
                width: 12rem;
                height: 12rem;
                border-radius: 999px;
                background: radial-gradient(circle, rgba(58, 123, 213, 0.10), transparent 70%);
                pointer-events: none;
            }

            .express-stock-pill {
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

            .express-stock-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .express-stock-kpi {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.15rem;
                padding: 1rem 1.1rem;
                background: rgba(255, 255, 255, 0.88);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
            }

            .express-stock-kpi-label {
                display: block;
                margin-bottom: 0.4rem;
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-stock-kpi-value {
                display: block;
                color: #0f172a;
                font-size: 1.85rem;
                font-weight: 800;
                line-height: 1;
            }

            .express-stock-kpi-note {
                display: block;
                margin-top: 0.45rem;
                color: #64748b;
                font-size: 0.86rem;
            }

            .express-stock-panel {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.3rem;
                background: #fff;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
            }

            .express-stock-toolbar {
                display: flex;
                flex-wrap: wrap;
                gap: 1rem;
                align-items: end;
            }

            .express-stock-toolbar > * {
                flex: 1 1 12rem;
            }

            .express-stock-toolbar-actions {
                display: flex;
                gap: 0.75rem;
                justify-content: flex-end;
                flex: 0 0 auto;
            }

            .express-stock-operator-list {
                display: grid;
                gap: 0.85rem;
            }

            .express-stock-operator-item {
                display: flex;
                justify-content: space-between;
                gap: 1rem;
                align-items: center;
                padding: 0.95rem 1rem;
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1rem;
                background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            }

            .express-stock-table {
                --bs-table-bg: transparent;
                --bs-table-hover-bg: rgba(37, 99, 235, 0.04);
                margin-bottom: 0;
            }

            .express-stock-table thead th {
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-stock-table td {
                padding-top: 1rem;
                padding-bottom: 1rem;
                border-color: rgba(15, 23, 42, 0.06);
                vertical-align: middle;
            }

            .express-stock-code {
                display: inline-flex;
                padding: 0.45rem 0.7rem;
                border-radius: 0.8rem;
                background: #f8fafc;
                color: #0f172a;
                font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
                font-size: 0.92rem;
                font-weight: 700;
                letter-spacing: 0.03em;
            }

            .express-stock-empty {
                padding: 2rem 1rem;
                text-align: center;
                color: #64748b;
            }

            @media (max-width: 1199.98px) {
                .express-stock-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767.98px) {
                .express-stock-kpis {
                    grid-template-columns: 1fr;
                }

                .express-stock-toolbar-actions {
                    width: 100%;
                }

                .express-stock-toolbar-actions .btn {
                    flex: 1 1 0;
                }
            }
        </style>

        <div class="express-stock-shell">
            <section class="card express-stock-hero">
                <div class="card-body p-4 p-xl-5">
                    <div class="row g-4 align-items-start">
                        <div class="col-12 col-xl-7">
                            <span class="express-stock-pill"><i class="fa-solid fa-sim-card"></i>Magazzino operativo</span>
                            <h1 class="mt-3 mb-2 fw-bold" style="max-width: 12ch;">Controllo ICCID più rapido per stock, rientri e vendite.</h1>
                            <p class="text-muted mb-0" style="max-width: 72ch;">
                                Tieni sotto controllo disponibilità, filtri attivi e ultimi lotti importati in un unico punto. La vista si aggiorna anche in base ai filtri applicati.
                            </p>
                        </div>
                        <div class="col-12 col-xl-5">
                            <div class="express-stock-kpis">
                                <div class="express-stock-kpi">
                                    <span class="express-stock-kpi-label">Disponibili</span>
                                    <span class="express-stock-kpi-value"><?php echo (int) $stockSummary['available_rows']; ?></span>
                                    <span class="express-stock-kpi-note">SIM pronte per la vendita</span>
                                </div>
                                <div class="express-stock-kpi">
                                    <span class="express-stock-kpi-label">Prenotati</span>
                                    <span class="express-stock-kpi-value"><?php echo (int) $stockSummary['reserved_rows']; ?></span>
                                    <span class="express-stock-kpi-note">ICCID associati a una lavorazione</span>
                                </div>
                                <div class="express-stock-kpi">
                                    <span class="express-stock-kpi-label">Venduti</span>
                                    <span class="express-stock-kpi-value"><?php echo (int) $stockSummary['sold_rows']; ?></span>
                                    <span class="express-stock-kpi-note">Movimentati dal modulo Express</span>
                                </div>
                                <div class="express-stock-kpi">
                                    <span class="express-stock-kpi-label"><?php echo $stockSummary['has_filters'] ? 'Vista filtrata' : 'Totale stock'; ?></span>
                                    <span class="express-stock-kpi-value"><?php echo (int) $stockSummary['total_rows']; ?></span>
                                    <span class="express-stock-kpi-note">
                                        <?php
                                        echo sanitize_output(
                                            $stockSummary['has_filters']
                                                ? 'Operatore: ' . (string) $stockSummary['operator_label']
                                                : 'Ultimo carico ' . ($stockSummary['last_import_at'] !== '' ? format_datetime_locale((string) $stockSummary['last_import_at']) : 'non disponibile')
                                        );
                                        ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-4">
                <div class="col-12 col-xl-4">
                    <div class="card express-stock-panel h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                                <div>
                                    <h5 class="card-title mb-1">Operatori e soglie</h5>
                                    <p class="text-muted small mb-0">Configura gli operatori disponibili e controlla la soglia di riordino.</p>
                                </div>
                                <span class="badge rounded-pill text-bg-light"><?php echo count($providers); ?> attivi</span>
                            </div>
                            <form method="post" class="mb-4">
                                <input type="hidden" name="action" value="add_provider">
                                <input type="hidden" name="_token" value="<?php echo sanitize_output(csrf_token()); ?>">
                                <div class="mb-3">
                                    <label class="form-label" for="provider_name">Nuovo operatore</label>
                                    <input class="form-control" id="provider_name" name="provider_name" maxlength="120" placeholder="Es. Vodafone">
                                </div>
                                <div class="mb-3">
                                    <label class="form-label" for="provider_threshold">Soglia riordino</label>
                                    <input class="form-control" id="provider_threshold" name="provider_threshold" type="number" min="1" step="1" value="10">
                                </div>
                                <button class="btn btn-warning w-100" type="submit"><i class="fa-solid fa-save me-2"></i>Salva operatore</button>
                            </form>

                            <div class="express-stock-operator-list">
                                <?php foreach ($providers as $provider): ?>
                                    <div class="express-stock-operator-item">
                                        <div>
                                            <div class="fw-semibold"><?php echo sanitize_output((string) $provider['nome']); ?></div>
                                            <div class="small text-muted">Operatore abilitato al caricamento ICCID</div>
                                        </div>
                                        <span class="badge rounded-pill text-bg-light">Soglia <?php echo (int) $provider['soglia_riordino']; ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-8">
                    <div class="card express-stock-panel h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                                <div>
                                    <h5 class="card-title mb-1">Import rapido ICCID</h5>
                                    <p class="text-muted small mb-0">Carica un nuovo lotto in blocco. I duplicati vengono ignorati automaticamente.</p>
                                </div>
                                <span class="badge rounded-pill text-bg-light">Formato 19-20 cifre</span>
                            </div>
                            <form method="post">
                                <input type="hidden" name="action" value="import_iccid">
                                <input type="hidden" name="_token" value="<?php echo sanitize_output(csrf_token()); ?>">
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label" for="operatore_id">Operatore</label>
                                        <select class="form-select" id="operatore_id" name="operatore_id" required>
                                            <option value="">Seleziona...</option>
                                            <?php foreach ($providers as $provider): ?>
                                                <option value="<?php echo (int) $provider['id']; ?>"><?php echo sanitize_output((string) $provider['nome']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-8">
                                        <label class="form-label" for="stock_notes">Note lotto</label>
                                        <input class="form-control" id="stock_notes" name="note" maxlength="255" placeholder="Es. lotto marzo 2026">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label" for="iccid_bulk">Elenco ICCID</label>
                                        <textarea class="form-control" id="iccid_bulk" name="iccid_bulk" rows="8" placeholder="Incolla uno o più ICCID, separati da spazi, virgole o righe." required></textarea>
                                        <div class="form-text">Accettiamo valori separati da righe, spazi o virgole. I codici già presenti restano fuori dall'import.</div>
                                    </div>
                                </div>
                                <div class="mt-3 d-flex justify-content-end">
                                    <button class="btn btn-warning" type="submit"><i class="fa-solid fa-file-import me-2"></i>Importa stock</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </section>

            <section class="card express-stock-panel">
                <div class="card-body p-4">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
                        <div>
                            <h5 class="card-title mb-1">Magazzino ICCID</h5>
                            <p class="text-muted small mb-0">
                                Ricerca rapida per operatore e ICCID. <?php echo $stockSummary['has_filters'] ? 'Stai guardando una vista filtrata.' : 'Panoramica completa del magazzino attuale.'; ?>
                            </p>
                        </div>
                        <span class="badge rounded-pill text-bg-light px-3 py-2"><?php echo $stockTotalRows; ?> righe visibili</span>
                    </div>

                    <form method="get" class="express-stock-toolbar mb-4">
                        <div>
                            <label class="form-label" for="stock_filter_operator">Operatore</label>
                            <select class="form-select" id="stock_filter_operator" name="operatore_id">
                                <option value="">Tutti</option>
                                <?php foreach ($providers as $provider): ?>
                                    <option value="<?php echo (int) $provider['id']; ?>"<?php echo $stockOperatorFilter === (int) $provider['id'] ? ' selected' : ''; ?>>
                                        <?php echo sanitize_output((string) $provider['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div style="flex-basis: 20rem;">
                            <label class="form-label" for="stock_filter_iccid">Ricerca ICCID</label>
                            <input class="form-control" id="stock_filter_iccid" name="iccid" value="<?php echo sanitize_output($stockIccidFilter); ?>" placeholder="Inserisci tutto o parte dell'ICCID">
                        </div>
                        <div class="express-stock-toolbar-actions">
                            <button class="btn btn-warning px-4" type="submit">Filtra</button>
                            <a class="btn btn-outline-secondary px-4" href="stock.php">Reset</a>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table align-middle express-stock-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Operatore</th>
                                    <th>ICCID</th>
                                    <th>Stato</th>
                                    <th>Note</th>
                                    <th>Vendita</th>
                                    <th>Caricato il</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($stockRows === []): ?>
                                    <tr>
                                        <td colspan="7" class="express-stock-empty">Nessun ICCID trovato con i filtri attuali.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($stockRows as $row): ?>
                                    <tr>
                                        <td class="fw-semibold text-muted">#<?php echo (int) $row['id']; ?></td>
                                        <td>
                                            <div class="fw-semibold"><?php echo sanitize_output((string) ($row['operatore'] ?? '')); ?></div>
                                            <div class="small text-muted">Stock Express</div>
                                        </td>
                                        <td><span class="express-stock-code"><?php echo sanitize_output((string) ($row['iccid'] ?? '')); ?></span></td>
                                        <td>
                                            <?php
                                            $statusLabel = 'Sconosciuto';
                                            $badgeClass = 'text-bg-secondary';
                                            if (($row['stato'] ?? '') === 'InStock') {
                                                $badgeClass = 'text-bg-success';
                                                $statusLabel = 'Disponibile';
                                            } elseif (($row['stato'] ?? '') === 'Reserved') {
                                                $badgeClass = 'text-bg-warning';
                                                $statusLabel = 'Prenotato';
                                            } elseif (($row['stato'] ?? '') === 'Sold') {
                                                $badgeClass = 'text-bg-danger';
                                                $statusLabel = 'Venduto';
                                            }
                                            ?>
                                            <span class="badge <?php echo $badgeClass; ?>"><?php echo sanitize_output($statusLabel); ?></span>
                                        </td>
                                        <td class="text-muted">
                                            <?php echo sanitize_output(($cleanNote = express_module_clean_stock_note((string) ($row['note'] ?? ''))) !== '' ? $cleanNote : '—'); ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($row['vendita_id'])): ?>
                                                <a href="view_sale.php?id=<?php echo (int) $row['vendita_id']; ?>" class="fw-semibold text-decoration-none">#<?php echo (int) $row['vendita_id']; ?></a>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted"><?php echo sanitize_output(format_datetime_locale((string) ($row['created_at'] ?? ''))); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($stockPageCount > 1): ?>
                        <?php
                        $pagesToShow = array_unique([
                            1,
                            max(1, $stockCurrentPage - 1),
                            $stockCurrentPage,
                            min($stockPageCount, $stockCurrentPage + 1),
                            $stockPageCount,
                        ]);
                        sort($pagesToShow);
                        ?>
                        <div class="overflow-auto mt-4">
                            <nav aria-label="Paginazione magazzino ICCID">
                                <ul class="pagination pagination-sm mb-0 justify-content-end flex-nowrap">
                                    <li class="page-item <?php echo $stockCurrentPage <= 1 ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(['operatore_id' => $stockOperatorFilter, 'iccid' => $stockIccidFilter, 'page' => max(1, $stockCurrentPage - 1)]); ?>">Precedente</a>
                                    </li>
                                    <?php $previousPage = 0; ?>
                                    <?php foreach ($pagesToShow as $page): ?>
                                        <?php if ($previousPage > 0 && $page - $previousPage > 1): ?>
                                            <li class="page-item disabled"><span class="page-link">...</span></li>
                                        <?php endif; ?>
                                        <li class="page-item <?php echo $page === $stockCurrentPage ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(['operatore_id' => $stockOperatorFilter, 'iccid' => $stockIccidFilter, 'page' => $page]); ?>"><?php echo $page; ?></a>
                                        </li>
                                        <?php $previousPage = $page; ?>
                                    <?php endforeach; ?>
                                    <li class="page-item <?php echo $stockCurrentPage >= $stockPageCount ? 'disabled' : ''; ?>">
                                        <a class="page-link" href="?<?php echo http_build_query(['operatore_id' => $stockOperatorFilter, 'iccid' => $stockIccidFilter, 'page' => min($stockPageCount, $stockCurrentPage + 1)]); ?>">Successiva</a>
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
