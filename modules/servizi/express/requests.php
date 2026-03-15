<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
express_module_require_access($pdo, $currentUserId);

$pageTitle = 'Richieste Express';
$currentRole = (string) ($_SESSION['role'] ?? 'Operatore');

express_module_bootstrap_schema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = express_module_save_request($pdo, $_POST, $currentUserId, $currentRole);
    add_flash($result['success'] ? 'success' : 'warning', $result['message']);
    header('Location: requests.php');
    exit;
}

$requests = express_module_request_list($pdo);
$editingRequest = isset($_GET['edit']) ? express_module_request_detail($pdo, (int) $_GET['edit']) : null;
$customers = express_module_client_options($pdo);
$products = express_module_product_options($pdo);
$statuses = express_module_request_status_options();
$types = express_module_request_type_options();
$settings = express_module_get_settings($pdo);
$requestSummary = express_module_request_summary($pdo);
$requestTypeLabels = [
    'Purchase' => 'Acquisto',
    'Reservation' => 'Prenotazione',
    'Deposit' => 'Acconto',
    'Installment' => 'Rateizzazione',
    'Support' => 'Assistenza',
];
$requestStatusLabels = [
    'Pending' => 'In attesa',
    'InReview' => 'In valutazione',
    'Confirmed' => 'Confermata',
    'Completed' => 'Completata',
    'Cancelled' => 'Annullata',
    'Declined' => 'Rifiutata',
];

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <?php express_module_render_nav('requests'); ?>
        <style>
            .express-requests-shell {
                display: grid;
                gap: 1.5rem;
            }

            .express-requests-hero {
                position: relative;
                overflow: hidden;
                border: 1px solid rgba(58, 123, 213, 0.14);
                background:
                    radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 34%),
                    radial-gradient(circle at top right, rgba(0, 184, 148, 0.12), transparent 26%),
                    #fff;
            }

            .express-requests-pill {
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

            .express-requests-kpis {
                display: grid;
                grid-template-columns: repeat(4, minmax(0, 1fr));
                gap: 1rem;
            }

            .express-requests-kpi {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.15rem;
                padding: 1rem 1.1rem;
                background: rgba(255, 255, 255, 0.88);
                box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
            }

            .express-requests-kpi-label {
                display: block;
                margin-bottom: 0.4rem;
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-requests-kpi-value {
                display: block;
                color: #0f172a;
                font-size: 1.85rem;
                font-weight: 800;
                line-height: 1;
            }

            .express-requests-kpi-note {
                display: block;
                margin-top: 0.45rem;
                color: #64748b;
                font-size: 0.86rem;
            }

            .express-requests-panel {
                border: 1px solid rgba(15, 23, 42, 0.08);
                border-radius: 1.3rem;
                background: #fff;
                box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
            }

            .express-requests-table {
                --bs-table-bg: transparent;
                --bs-table-hover-bg: rgba(37, 99, 235, 0.04);
                margin-bottom: 0;
            }

            .express-requests-table thead th {
                border-bottom: 1px solid rgba(15, 23, 42, 0.08);
                color: #64748b;
                font-size: 0.76rem;
                font-weight: 700;
                letter-spacing: 0.08em;
                text-transform: uppercase;
            }

            .express-requests-table td {
                padding-top: 1rem;
                padding-bottom: 1rem;
                border-color: rgba(15, 23, 42, 0.06);
                vertical-align: middle;
            }

            .express-requests-type {
                display: inline-flex;
                align-items: center;
                padding: 0.42rem 0.7rem;
                border-radius: 0.8rem;
                background: #f8fafc;
                color: #0f172a;
                font-size: 0.86rem;
                font-weight: 700;
            }

            .express-requests-empty {
                padding: 2rem 1rem;
                text-align: center;
                color: #64748b;
            }

            @media (max-width: 1199.98px) {
                .express-requests-kpis {
                    grid-template-columns: repeat(2, minmax(0, 1fr));
                }
            }

            @media (max-width: 767.98px) {
                .express-requests-kpis {
                    grid-template-columns: 1fr;
                }
            }
        </style>

        <div class="express-requests-shell">
            <section class="card express-requests-hero">
                <div class="card-body p-4 p-xl-5">
                    <div class="row g-4 align-items-start">
                        <div class="col-12 col-xl-7">
                            <span class="express-requests-pill"><i class="fa-solid fa-clipboard-list"></i>Flusso richieste</span>
                            <h1 class="mt-3 mb-2 fw-bold" style="max-width: 14ch;">Richieste cliente più ordinate per stato, tipo e priorità.</h1>
                            <p class="text-muted mb-0" style="max-width: 72ch;">
                                Gestisci prenotazioni, acquisti, assistenze e pratiche con una vista più chiara su avanzamento, chiusure e carico operativo del reparto.
                            </p>
                        </div>
                        <div class="col-12 col-xl-5">
                            <div class="express-requests-kpis">
                                <div class="express-requests-kpi">
                                    <span class="express-requests-kpi-label">Totali</span>
                                    <span class="express-requests-kpi-value"><?php echo (int) $requestSummary['total_requests']; ?></span>
                                    <span class="express-requests-kpi-note">Pratiche registrate nel modulo</span>
                                </div>
                                <div class="express-requests-kpi">
                                    <span class="express-requests-kpi-label">In attesa</span>
                                    <span class="express-requests-kpi-value"><?php echo (int) $requestSummary['pending_requests']; ?></span>
                                    <span class="express-requests-kpi-note">Da prendere in carico</span>
                                </div>
                                <div class="express-requests-kpi">
                                    <span class="express-requests-kpi-label">Completate</span>
                                    <span class="express-requests-kpi-value"><?php echo (int) $requestSummary['completed_requests']; ?></span>
                                    <span class="express-requests-kpi-note">Chiuse positivamente</span>
                                </div>
                                <div class="express-requests-kpi">
                                    <span class="express-requests-kpi-label">Mix operativo</span>
                                    <span class="express-requests-kpi-value"><?php echo (int) $requestSummary['purchase_requests']; ?></span>
                                    <span class="express-requests-kpi-note"><?php echo (int) $requestSummary['support_requests']; ?> assistenze, <?php echo (int) $requestSummary['closed_requests']; ?> chiuse</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-4">
                <div class="col-12 col-xl-5">
                    <div class="card express-requests-panel h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                                <div>
                                    <h5 class="card-title mb-1"><?php echo $editingRequest ? 'Gestisci richiesta' : 'Nuova richiesta'; ?></h5>
                                    <p class="text-muted small mb-0">Compila la scheda operativa con cliente, tipo richiesta, stato e dettagli economici se presenti.</p>
                                </div>
                                <?php if ($editingRequest): ?>
                                    <a class="btn btn-outline-secondary btn-sm" href="requests.php">Annulla</a>
                                <?php else: ?>
                                    <span class="badge rounded-pill text-bg-light"><?php echo count($requests); ?> richieste</span>
                                <?php endif; ?>
                            </div>
                            <form method="post" class="row g-3">
                                <input type="hidden" name="_token" value="<?php echo sanitize_output(csrf_token()); ?>">
                                <input type="hidden" name="request_id" value="<?php echo (int) ($editingRequest['id'] ?? 0); ?>">
                                <div class="col-12">
                                    <label class="form-label" for="request_customer">Cliente</label>
                                    <select class="form-select" id="request_customer" name="cliente_id" required>
                                        <option value="">Seleziona...</option>
                                        <?php foreach ($customers as $customer): ?>
                                            <option value="<?php echo (int) $customer['id']; ?>"<?php echo (int) ($editingRequest['cliente_id'] ?? 0) === (int) $customer['id'] ? ' selected' : ''; ?>><?php echo sanitize_output(express_module_client_label($customer)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="request_product">Prodotto</label>
                                    <select class="form-select" id="request_product" name="product_id">
                                        <option value="">Nessun prodotto</option>
                                        <?php foreach ($products as $product): ?>
                                            <option value="<?php echo (int) $product['id']; ?>"<?php echo (int) ($editingRequest['prodotto_id'] ?? 0) === (int) $product['id'] ? ' selected' : ''; ?>><?php echo sanitize_output((string) $product['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="request_type">Tipo richiesta</label>
                                    <select class="form-select" id="request_type" name="tipo_richiesta">
                                        <?php foreach ($types as $type): ?>
                                            <option value="<?php echo sanitize_output($type); ?>"<?php echo ($editingRequest['tipo_richiesta'] ?? 'Purchase') === $type ? ' selected' : ''; ?>><?php echo sanitize_output($requestTypeLabels[$type] ?? $type); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label" for="request_title">Titolo</label>
                                    <input class="form-control" id="request_title" name="titolo" maxlength="150" required value="<?php echo sanitize_output((string) ($editingRequest['titolo'] ?? '')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="request_status">Stato</label>
                                    <select class="form-select" id="request_status" name="stato">
                                        <?php foreach ($statuses as $status): ?>
                                            <option value="<?php echo sanitize_output($status); ?>"<?php echo ($editingRequest['stato'] ?? 'Pending') === $status ? ' selected' : ''; ?>><?php echo sanitize_output($requestStatusLabels[$status] ?? $status); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="request_deposit">Acconto</label>
                                    <input class="form-control" id="request_deposit" name="importo_acconto" type="number" min="0" step="0.01" value="<?php echo sanitize_output(number_format((float) ($editingRequest['importo_acconto'] ?? 0), 2, '.', '')); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="request_installments">Rate</label>
                                    <input class="form-control" id="request_installments" name="numero_rate" type="number" min="0" step="1" value="<?php echo (int) ($editingRequest['numero_rate'] ?? 0); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="request_payment">Metodo pagamento</label>
                                    <select class="form-select" id="request_payment" name="metodo_pagamento">
                                        <option value="">Non definito</option>
                                        <?php foreach (($settings['payment_methods'] ?? []) as $method): ?>
                                            <option value="<?php echo sanitize_output((string) $method); ?>"<?php echo (string) ($editingRequest['metodo_pagamento'] ?? '') === (string) $method ? ' selected' : ''; ?>><?php echo sanitize_output((string) $method); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="request_date">Data desiderata</label>
                                    <input class="form-control" id="request_date" name="data_desiderata" type="date" value="<?php echo sanitize_output((string) ($editingRequest['data_desiderata'] ?? '')); ?>">
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="request_customer_note">Note cliente</label>
                                    <textarea class="form-control" id="request_customer_note" name="note_cliente" rows="3"><?php echo sanitize_output((string) ($editingRequest['note_cliente'] ?? '')); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="request_internal_note">Nota interna</label>
                                    <textarea class="form-control" id="request_internal_note" name="nota_interna" rows="3"><?php echo sanitize_output((string) ($editingRequest['nota_interna'] ?? '')); ?></textarea>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-warning w-100" type="submit"><i class="fa-solid fa-save me-2"></i>Salva richiesta</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-7">
                    <div class="card express-requests-panel h-100">
                        <div class="card-body p-4">
                            <div class="d-flex justify-content-between align-items-start gap-3 mb-4">
                                <div>
                                    <h5 class="card-title mb-1">Richieste clienti</h5>
                                    <p class="text-muted small mb-0">Vista operativa completa delle richieste con cliente, tipologia, stato corrente e prodotto associato.</p>
                                </div>
                                <span class="badge rounded-pill text-bg-light"><?php echo count($requests); ?> richieste</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table align-middle express-requests-table">
                                    <thead>
                                        <tr>
                                            <th>Cliente</th>
                                            <th>Titolo</th>
                                            <th>Tipo</th>
                                            <th>Stato</th>
                                            <th>Prodotto</th>
                                            <th class="text-end">Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if ($requests === []): ?>
                                            <tr>
                                                <td colspan="6" class="express-requests-empty">Nessuna richiesta registrata.</td>
                                            </tr>
                                        <?php endif; ?>
                                        <?php foreach ($requests as $request): ?>
                                            <?php
                                            $statusKey = (string) ($request['stato'] ?? '');
                                            $statusBadgeClass = 'text-bg-secondary';
                                            if ($statusKey === 'Pending') {
                                                $statusBadgeClass = 'text-bg-warning';
                                            } elseif ($statusKey === 'Completed' || $statusKey === 'Confirmed') {
                                                $statusBadgeClass = 'text-bg-success';
                                            } elseif ($statusKey === 'Cancelled' || $statusKey === 'Declined') {
                                                $statusBadgeClass = 'text-bg-danger';
                                            } elseif ($statusKey === 'InReview') {
                                                $statusBadgeClass = 'text-bg-info';
                                            }
                                            ?>
                                            <tr>
                                                <td>
                                                    <div class="fw-semibold"><?php echo sanitize_output(trim(((string) ($request['ragione_sociale'] ?? '')) !== '' ? (string) $request['ragione_sociale'] : ((string) ($request['cognome'] ?? '') . ' ' . (string) ($request['nome'] ?? '')))); ?></div>
                                                    <div class="small text-muted">Richiesta #<?php echo (int) $request['id']; ?></div>
                                                </td>
                                                <td><?php echo sanitize_output((string) $request['titolo']); ?></td>
                                                <td><span class="express-requests-type"><?php echo sanitize_output($requestTypeLabels[(string) ($request['tipo_richiesta'] ?? '')] ?? (string) ($request['tipo_richiesta'] ?? '')); ?></span></td>
                                                <td><span class="badge <?php echo $statusBadgeClass; ?>"><?php echo sanitize_output($requestStatusLabels[(string) ($request['stato'] ?? '')] ?? (string) ($request['stato'] ?? '')); ?></span></td>
                                                <td><?php echo sanitize_output((string) (($request['prodotto_nome'] ?? '') !== '' ? $request['prodotto_nome'] : '—')); ?></td>
                                                <td class="text-end"><a class="btn btn-icon btn-soft-accent btn-sm" href="requests.php?edit=<?php echo (int) $request['id']; ?>"><i class="fa-solid fa-pen"></i></a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
