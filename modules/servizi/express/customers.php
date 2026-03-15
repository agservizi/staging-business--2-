<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
express_module_require_access($pdo, $currentUserId);

$pageTitle = 'Clienti Express';
$search = trim((string) ($_GET['search'] ?? ''));
$perPage = 10;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = express_module_save_customer($pdo, $_POST, $currentUserId);
    add_flash($result['success'] ? 'success' : 'warning', $result['message']);
    header('Location: ' . express_module_url('customers'));
    exit;
}

$customersTotal = express_module_customer_count($pdo, $search);
$customersPageCount = max(1, (int) ceil($customersTotal / $perPage));
$customersCurrentPage = max(1, (int) ($_GET['page'] ?? 1));
$customersCurrentPage = min($customersCurrentPage, $customersPageCount);
$customers = express_module_customer_list($pdo, $search, $customersCurrentPage, $perPage);
$editingCustomer = isset($_GET['edit']) ? express_module_customer_detail($pdo, (int) $_GET['edit']) : null;
$customerSummary = express_module_customer_summary($pdo, $search);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <?php express_module_render_nav('customers'); ?>
        <style>
            .express-customers-shell {
                display: grid;
                gap: 1.5rem;
            }

            .express-customers-hero {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(58, 123, 213, 0.14);
                background:
                    radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 34%),
                    radial-gradient(circle at top right, rgba(0, 184, 148, 0.12), transparent 26%),
                    #fff;
            }

            .express-customers-pill {
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

            .express-customers-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .express-customers-kpi {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.15rem;
                padding: 1rem 1.1rem;
                background: rgba(255, 255, 255, 0.88);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
            }

            .express-customers-kpi-label {
                display: block;
                margin-bottom: 0.4rem;
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-customers-kpi-value {
                display: block;
                color: #0f172a;
                font-size: 1.85rem;
                font-weight: 800;
                line-height: 1;
            }

            .express-customers-kpi-note {
                display: block;
                margin-top: 0.45rem;
                color: #64748b;
                font-size: 0.86rem;
            }

            .express-customers-panel {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.3rem;
                background: #fff;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
            }

            .express-customers-toolbar {
                display: flex;
                flex-wrap: wrap;
                gap: 1rem;
                align-items: end;
            }

            .express-customers-toolbar > * {
                flex: 1 1 14rem;
            }

            .express-customers-toolbar-actions {
                display: flex;
                gap: 0.75rem;
                flex: 0 0 auto;
            }

            .express-customers-table {
                --bs-table-bg: transparent;
                --bs-table-hover-bg: rgba(37, 99, 235, 0.04);
                margin-bottom: 0;
            }

            .express-customers-table thead th {
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-customers-table td {
                padding-top: 1rem;
                padding-bottom: 1rem;
                border-color: rgba(15, 23, 42, 0.06);
                vertical-align: middle;
            }

            .express-customers-id {
                display: inline-flex;
                padding: 0.42rem 0.68rem;
                border-radius: 0.8rem;
                background: #f8fafc;
                color: #0f172a;
                font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
                font-size: 0.8rem;
                font-weight: 700;
            }

            .express-customers-empty {
                padding: 2rem 1rem;
                text-align: center;
                color: #64748b;
            }

            @media (max-width: 1199.98px) {
                .express-customers-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767.98px) {
                .express-customers-kpis {
                    grid-template-columns: 1fr;
                }

                .express-customers-toolbar-actions {
                    width: 100%;
                }

                .express-customers-toolbar-actions .btn {
                    flex: 1 1 0;
                }
            }
        </style>

        <div class="express-customers-shell">
            <section class="card express-customers-hero">
                <div class="card-body p-4 p-xl-5">
                    <div class="row g-4 align-items-start">
                        <div class="col-12 col-xl-7">
                            <span class="express-customers-pill"><i class="fa-solid fa-address-book"></i>Rubrica clienti</span>
                            <h1 class="mt-3 mb-2 fw-bold" style="max-width: 14ch;">Anagrafiche più chiare per vendite, assistenza e follow-up.</h1>
                            <p class="text-muted mb-0" style="max-width: 72ch;">
                                Cerca rapidamente clienti, controlla la qualità dei contatti registrati e aggiorna le anagrafiche del reparto telefonia in un flusso più leggibile.
                            </p>
                        </div>
                        <div class="col-12 col-xl-5">
                            <div class="express-customers-kpis">
                                <div class="express-customers-kpi">
                                    <span class="express-customers-kpi-label"><?php echo $customerSummary['has_filters'] ? 'Risultati' : 'Clienti'; ?></span>
                                    <span class="express-customers-kpi-value"><?php echo (int) $customerSummary['total_customers']; ?></span>
                                    <span class="express-customers-kpi-note"><?php echo $customerSummary['has_filters'] ? 'Anagrafiche trovate con la ricerca attiva' : 'Totale rubrica disponibile'; ?></span>
                                </div>
                                <div class="express-customers-kpi">
                                    <span class="express-customers-kpi-label">Aziende</span>
                                    <span class="express-customers-kpi-value"><?php echo (int) $customerSummary['company_customers']; ?></span>
                                    <span class="express-customers-kpi-note">Ragioni sociali presenti</span>
                                </div>
                                <div class="express-customers-kpi">
                                    <span class="express-customers-kpi-label">Con email</span>
                                    <span class="express-customers-kpi-value"><?php echo (int) $customerSummary['email_customers']; ?></span>
                                    <span class="express-customers-kpi-note">Contatti digitali disponibili</span>
                                </div>
                                <div class="express-customers-kpi">
                                    <span class="express-customers-kpi-label">Con telefono</span>
                                    <span class="express-customers-kpi-value"><?php echo (int) $customerSummary['phone_customers']; ?></span>
                                    <span class="express-customers-kpi-note"><?php echo (int) $customerSummary['noted_customers']; ?> con note registrate</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-4">
                <div class="col-12 col-xl-5">
                    <div class="card express-customers-panel h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                                <div>
                                    <h5 class="card-title mb-1"><?php echo $editingCustomer ? 'Modifica cliente' : 'Nuovo cliente'; ?></h5>
                                    <p class="text-muted small mb-0">Anagrafica completa per vendite Express, documenti gestionali e gestione post-vendita.</p>
                                </div>
                                <?php if ($editingCustomer): ?>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo sanitize_output(express_module_url('customers')); ?>">Annulla</a>
                                <?php else: ?>
                                    <span class="badge rounded-pill text-bg-light"><?php echo (int) $customerSummary['noted_customers']; ?> con note</span>
                                <?php endif; ?>
                            </div>
                            <form method="post" class="row g-3">
                                <input type="hidden" name="_token" value="<?php echo sanitize_output(csrf_token()); ?>">
                                <input type="hidden" name="customer_id" value="<?php echo (int) ($editingCustomer['id'] ?? 0); ?>">
                                <div class="col-12">
                                    <label class="form-label" for="customer_company">Ragione sociale</label>
                                    <input class="form-control" id="customer_company" name="ragione_sociale" maxlength="160" value="<?php echo sanitize_output((string) ($editingCustomer['ragione_sociale'] ?? '')); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="customer_name">Nome</label>
                                    <input class="form-control" id="customer_name" name="nome" maxlength="80" value="<?php echo sanitize_output((string) ($editingCustomer['nome'] ?? '')); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="customer_surname">Cognome</label>
                                    <input class="form-control" id="customer_surname" name="cognome" maxlength="80" value="<?php echo sanitize_output((string) ($editingCustomer['cognome'] ?? '')); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="customer_tax">CF / P.IVA</label>
                                    <input class="form-control" id="customer_tax" name="cf_piva" maxlength="32" value="<?php echo sanitize_output((string) ($editingCustomer['cf_piva'] ?? '')); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="customer_email">Email</label>
                                    <input class="form-control" id="customer_email" name="email" type="email" maxlength="160" value="<?php echo sanitize_output((string) ($editingCustomer['email'] ?? '')); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="customer_phone">Telefono</label>
                                    <input class="form-control" id="customer_phone" name="telefono" maxlength="40" value="<?php echo sanitize_output((string) ($editingCustomer['telefono'] ?? '')); ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="customer_address">Indirizzo</label>
                                    <input class="form-control" id="customer_address" name="indirizzo" maxlength="255" value="<?php echo sanitize_output((string) ($editingCustomer['indirizzo'] ?? '')); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="customer_note">Note</label>
                                    <textarea class="form-control" id="customer_note" name="note" rows="4"><?php echo sanitize_output((string) ($editingCustomer['note'] ?? '')); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-warning w-100" type="submit"><i class="fa-solid fa-save me-2"></i>Salva cliente</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-7">
                    <div class="card express-customers-panel h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                                <div>
                                    <h5 class="card-title mb-1">Lista clienti</h5>
                                    <p class="text-muted small mb-0">Cerca per nome, azienda, email, telefono o CF/P.IVA e lavora su una vista sempre paginata.</p>
                                </div>
                                <span class="badge rounded-pill text-bg-light"><?php echo $customersTotal; ?> clienti</span>
                            </div>

                            <form method="get" class="express-customers-toolbar mb-4">
                                <div style="flex-basis: 22rem;">
                                    <label class="form-label" for="customer_search">Ricerca clienti</label>
                                    <input class="form-control" id="customer_search" name="search" value="<?php echo sanitize_output($search); ?>" placeholder="Nome, email, telefono, CF/P.IVA">
                                </div>
                                <div class="express-customers-toolbar-actions">
                                    <button class="btn btn-warning px-4" type="submit">Cerca</button>
                                    <a class="btn btn-outline-secondary px-4" href="<?php echo sanitize_output(express_module_url('customers')); ?>">Reset</a>
                                </div>
                            </form>

                            <div class="table-responsive">
                                <table class="table align-middle express-customers-table">
                                    <thead>
                                        <tr>
                                            <th>Cliente</th>
                                            <th>Contatti</th>
                                            <th>CF/P.IVA</th>
                                            <th>Note</th>
                                            <th class="text-end">Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($customers === []): ?>
                                            <tr>
                                                <td colspan="5" class="express-customers-empty">Nessun cliente trovato con i filtri attuali.</td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($customers as $customer): ?>
                                            <?php
                                            $contactLine = trim(((string) ($customer['email'] ?? '')) . (((string) ($customer['telefono'] ?? '')) !== '' ? ' · ' . (string) $customer['telefono'] : ''));
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?php echo sanitize_output(express_module_client_label($customer)); ?></div>
                                                    <div class="small text-muted">
                                                        <span class="express-customers-id">#<?php echo (int) $customer['id']; ?></span>
                                                    </div>
                                                </td>
                                                <td class="text-muted"><?php echo sanitize_output($contactLine !== '' ? $contactLine : '—'); ?></td>
                                                <td><?php echo sanitize_output((string) ($customer['cf_piva'] ?? '—')); ?></td>
                                                <td class="text-muted"><?php echo sanitize_output((string) (($customer['note'] ?? '') !== '' ? mb_substr((string) $customer['note'], 0, 80) : '—')); ?></td>
                                                <td class="text-end"><a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo sanitize_output(express_module_url('customers', ['edit' => (int) $customer['id']])); ?>"><i class="fa-solid fa-pen"></i></a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php if ($customersPageCount > 1): ?>
                                <?php
                                $pagesToShow = array_unique([
                                    1,
                                    max(1, $customersCurrentPage - 1),
                                    $customersCurrentPage,
                                    min($customersPageCount, $customersCurrentPage + 1),
                                    $customersPageCount,
                                ]);
                                sort($pagesToShow);
                                ?>
                                <div class="overflow-auto mt-4">
                                    <nav aria-label="Paginazione lista clienti">
                                        <ul class="pagination pagination-sm mb-0 justify-content-end flex-nowrap">
                                            <li class="page-item <?php echo $customersCurrentPage <= 1 ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(['search' => $search, 'page' => max(1, $customersCurrentPage - 1)]); ?>">Precedente</a>
                                            </li>
                                            <?php $previousPage = 0; ?>
                                            <?php foreach ($pagesToShow as $page): ?>
                                                <?php if ($previousPage > 0 && $page - $previousPage > 1): ?>
                                                    <li class="page-item disabled"><span class="page-link">...</span></li>
                                                <?php endif; ?>
                                                <li class="page-item <?php echo $page === $customersCurrentPage ? 'active' : ''; ?>">
                                                    <a class="page-link" href="?<?php echo http_build_query(['search' => $search, 'page' => $page]); ?>"><?php echo $page; ?></a>
                                                </li>
                                                <?php $previousPage = $page; ?>
                                            <?php endforeach; ?>
                                            <li class="page-item <?php echo $customersCurrentPage >= $customersPageCount ? 'disabled' : ''; ?>">
                                                <a class="page-link" href="?<?php echo http_build_query(['search' => $search, 'page' => min($customersPageCount, $customersCurrentPage + 1)]); ?>">Successiva</a>
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
