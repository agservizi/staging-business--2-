<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
express_module_require_access($pdo, $currentUserId);

$pageTitle = 'Offerte Express';
$perPage = 10;

express_module_bootstrap_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = express_module_save_offer($pdo, $_POST, $currentUserId);
    add_flash($result['success'] ? 'success' : 'warning', $result['message']);
    header('Location: ' . express_module_url('offers'));
    exit;
}

$providers = express_module_provider_options($pdo);
$offersTotal = express_module_offer_count($pdo);
$offersPageCount = max(1, (int) ceil($offersTotal / $perPage));
$offersCurrentPage = max(1, (int) ($_GET['page'] ?? 1));
$offersCurrentPage = min($offersCurrentPage, $offersPageCount);
$offers = express_module_offer_list($pdo, $offersCurrentPage, $perPage);
$editingOffer = isset($_GET['edit']) ? express_module_offer_detail($pdo, (int) $_GET['edit']) : null;
$offerSummary = express_module_offer_summary($pdo);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <?php express_module_render_nav('offers'); ?>
        <style>
            .express-offers-shell {
                display: grid;
                gap: 1.5rem;
            }

            .express-offers-hero {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(58, 123, 213, 0.14);
                background:
                    radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 34%),
                    radial-gradient(circle at top right, rgba(0, 184, 148, 0.12), transparent 26%),
                    #fff;
            }

            .express-offers-pill {
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

            .express-offers-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .express-offers-kpi {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.15rem;
                padding: 1rem 1.1rem;
                background: rgba(255, 255, 255, 0.88);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
            }

            .express-offers-kpi-label {
                display: block;
                margin-bottom: 0.4rem;
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-offers-kpi-value {
                display: block;
                color: #0f172a;
                font-size: 1.85rem;
                font-weight: 800;
                line-height: 1;
            }

            .express-offers-kpi-note {
                display: block;
                margin-top: 0.45rem;
                color: #64748b;
                font-size: 0.86rem;
            }

            .express-offers-panel {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.3rem;
                background: #fff;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
            }

            .express-offers-table {
                --bs-table-bg: transparent;
                --bs-table-hover-bg: rgba(37, 99, 235, 0.04);
                margin-bottom: 0;
            }

            .express-offers-table thead th {
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-offers-table td {
                padding-top: 1rem;
                padding-bottom: 1rem;
                border-color: rgba(15, 23, 42, 0.06);
                vertical-align: middle;
            }

            .express-offers-operator {
                display: inline-flex;
                align-items: center;
                padding: 0.42rem 0.7rem;
                border-radius: 0.8rem;
                background: #f8fafc;
                color: #0f172a;
                font-size: 0.86rem;
                font-weight: 700;
            }

            .express-offers-empty {
                padding: 2rem 1rem;
                text-align: center;
                color: #64748b;
            }

            @media (max-width: 1199.98px) {
                .express-offers-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767.98px) {
                .express-offers-kpis {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <div class="express-offers-shell">
            <section class="card express-offers-hero">
                <div class="card-body p-4 p-xl-5">
                    <div class="row g-4 align-items-start">
                        <div class="col-12 col-xl-7">
                            <span class="express-offers-pill"><i class="fa-solid fa-tags"></i>Listini commerciali</span>
                            <h1 class="mt-3 mb-2 fw-bold" style="max-width: 14ch;">Offerte più leggibili per operatore, stato e scadenza.</h1>
                            <p class="text-muted mb-0" style="max-width: 72ch;">
                                Gestisci il listino Express con una vista più chiara su offerte attive, campagne in scadenza e copertura dei vari operatori.
                            </p>
                        </div>
                        <div class="col-12 col-xl-5">
                            <div class="express-offers-kpis">
                                <div class="express-offers-kpi">
                                    <span class="express-offers-kpi-label">Attive</span>
                                    <span class="express-offers-kpi-value"><?php echo (int) $offerSummary['active_offers']; ?></span>
                                    <span class="express-offers-kpi-note">Disponibili per la vendita</span>
                                </div>
                                <div class="express-offers-kpi">
                                    <span class="express-offers-kpi-label">Archiviate</span>
                                    <span class="express-offers-kpi-value"><?php echo (int) $offerSummary['inactive_offers']; ?></span>
                                    <span class="express-offers-kpi-note">Fuori listino o sospese</span>
                                </div>
                                <div class="express-offers-kpi">
                                    <span class="express-offers-kpi-label">In scadenza</span>
                                    <span class="express-offers-kpi-value"><?php echo (int) $offerSummary['expiring_soon']; ?></span>
                                    <span class="express-offers-kpi-note">Entro i prossimi 7 giorni</span>
                                </div>
                                <div class="express-offers-kpi">
                                    <span class="express-offers-kpi-label">Prezzo medio</span>
                                    <span class="express-offers-kpi-value">&euro; <?php echo number_format((float) $offerSummary['average_price'], 0, ',', '.'); ?></span>
                                    <span class="express-offers-kpi-note"><?php echo (int) $offerSummary['covered_operators']; ?> operatori coperti</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-4">
                <div class="col-12 col-xl-5">
                    <div class="card express-offers-panel h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                                <div>
                                    <h5 class="card-title mb-1"><?php echo $editingOffer ? 'Modifica offerta' : 'Nuova offerta'; ?></h5>
                                    <p class="text-muted small mb-0">Configura operatore, finestra di validità e posizionamento economico dell'offerta.</p>
                                </div>
                                <?php if ($editingOffer): ?>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo sanitize_output(express_module_url('offers')); ?>">Annulla</a>
                                <?php else: ?>
                                    <span class="badge rounded-pill text-bg-light"><?php echo (int) $offerSummary['total_offers']; ?> totali</span>
                                <?php endif; ?>
                            </div>
                            <form method="post" class="row g-3">
                                <input type="hidden" name="_token" value="<?php echo sanitize_output(csrf_token()); ?>">
                                <input type="hidden" name="offer_id" value="<?php echo (int) ($editingOffer['id'] ?? 0); ?>">
                                <div class="col-md-6">
                                    <label class="form-label" for="offer_operator">Operatore</label>
                                    <select class="form-select" id="offer_operator" name="operatore_id">
                                        <option value="">Generico</option>
                                        <?php foreach ($providers as $provider): ?>
                                            <option value="<?php echo (int) $provider['id']; ?>"<?php echo (int) ($editingOffer['operatore_id'] ?? 0) === (int) $provider['id'] ? ' selected' : ''; ?>><?php echo sanitize_output((string) $provider['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="offer_status">Stato</label>
                                    <select class="form-select" id="offer_status" name="status">
                                        <option value="Active"<?php echo (($editingOffer['stato'] ?? 'Active') === 'Active') ? ' selected' : ''; ?>>Attiva</option>
                                        <option value="Inactive"<?php echo (($editingOffer['stato'] ?? '') === 'Inactive') ? ' selected' : ''; ?>>Archiviata</option>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="offer_title">Titolo</label>
                                    <input class="form-control" id="offer_title" name="title" maxlength="150" required value="<?php echo sanitize_output((string) ($editingOffer['titolo'] ?? '')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="offer_price">Prezzo</label>
                                    <input class="form-control" id="offer_price" name="price" type="number" min="0" step="0.01" value="<?php echo sanitize_output(number_format((float) ($editingOffer['prezzo'] ?? 0), 2, '.', '')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="offer_valid_from">Valida dal</label>
                                    <input class="form-control" id="offer_valid_from" name="valid_from" type="date" value="<?php echo sanitize_output((string) ($editingOffer['valid_from'] ?? '')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="offer_valid_to">Valida al</label>
                                    <input class="form-control" id="offer_valid_to" name="valid_to" type="date" value="<?php echo sanitize_output((string) ($editingOffer['valid_to'] ?? '')); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="offer_description">Descrizione</label>
                                    <textarea class="form-control" id="offer_description" name="description" rows="4"><?php echo sanitize_output((string) ($editingOffer['descrizione'] ?? '')); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-warning w-100" type="submit"><i class="fa-solid fa-save me-2"></i>Salva offerta</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-7">
                    <div class="card express-offers-panel h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                                <div>
                                    <h5 class="card-title mb-1">Listini & offerte</h5>
                                    <p class="text-muted small mb-0">Catalogo commerciale Express con operatore associato, finestra di validità e stato attuale.</p>
                                </div>
                                <span class="badge rounded-pill text-bg-light"><?php echo $offersTotal; ?> offerte</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table align-middle express-offers-table">
                                    <thead>
                                        <tr>
                                            <th>Operatore</th>
                                            <th>Offerta</th>
                                            <th class="text-end">Prezzo</th>
                                            <th>Validità</th>
                                            <th>Stato</th>
                                            <th class="text-end">Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($offers === []): ?>
                                            <tr>
                                                <td colspan="6" class="express-offers-empty">Nessuna offerta presente nel listino.</td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($offers as $offer): ?>
                                            <tr>
                                                <td><span class="express-offers-operator"><?php echo sanitize_output((string) ($offer['operatore'] ?? 'Generico')); ?></span></td>
                                                <td>
                                                    <div class="fw-semibold"><?php echo sanitize_output((string) $offer['titolo']); ?></div>
                                                    <div class="small text-muted"><?php echo sanitize_output((string) ($offer['descrizione'] ?? '')); ?></div>
                                                </td>
                                                <td class="text-end fw-semibold">&euro; <?php echo number_format((float) $offer['prezzo'], 2, ',', '.'); ?></td>
                                                <td><?php echo sanitize_output((string) (($offer['valid_from'] ?? '') !== '' || ($offer['valid_to'] ?? '') !== '' ? (($offer['valid_from'] ?? '—') . ' → ' . ($offer['valid_to'] ?? '—')) : '—')); ?></td>
                                                <td><span class="badge <?php echo ($offer['stato'] ?? 'Active') === 'Active' ? 'text-bg-success' : 'text-bg-secondary'; ?>"><?php echo ($offer['stato'] ?? 'Active') === 'Active' ? 'Attiva' : 'Archiviata'; ?></span></td>
                                                <td class="text-end"><a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo sanitize_output(express_module_url('offers', ['edit' => (int) $offer['id']])); ?>"><i class="fa-solid fa-pen"></i></a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($offersPageCount > 1): ?>
                                <?php
                                $pagesToShow = array_unique([
                                    1,
                                    max(1, $offersCurrentPage - 1),
                                    $offersCurrentPage,
                                    min($offersPageCount, $offersCurrentPage + 1),
                                    $offersPageCount,
                                ]);
                                sort($pagesToShow);
                                ?>
                                <div class="overflow-auto mt-4">
                                    <nav aria-label="Paginazione listini e offerte">
                                        <ul class="pagination pagination-sm mb-0 justify-content-end flex-nowrap">
                                            <li class="page-item <?php echo $offersCurrentPage <= 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo max(1, $offersCurrentPage - 1); ?>">Precedente</a>
                                            </li>
                                            <?php $previousPage = 0; ?>
                                            <?php foreach ($pagesToShow as $page): ?>
                                                <?php if ($previousPage > 0 && $page - $previousPage > 1): ?>
                                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                                <?php endif; ?>
                                                <li class="page-item <?php echo $page === $offersCurrentPage ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?page=<?php echo $page; ?>"><?php echo $page; ?></a>
                                                </li>
                                                <?php $previousPage = $page; ?>
                                            <?php endforeach; ?>
                                            <li class="page-item <?php echo $offersCurrentPage >= $offersPageCount ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?page=<?php echo min($offersPageCount, $offersCurrentPage + 1); ?>">Successiva</a>
                                            </li>
                                        </ul>
                                    </nav>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
