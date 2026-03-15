<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
express_module_require_access($pdo, $currentUserId);

$pageTitle = 'Nuova vendita Express';

express_module_bootstrap_schema($pdo);

$settings = express_module_get_settings($pdo);

if (($_GET['action'] ?? '') === 'refund_lookup') {
    header('Content-Type: application/json; charset=utf-8');

    $saleId = (int) ($_GET['sale_id'] ?? 0);
    $sale = $saleId > 0 ? express_module_sale_detail($pdo, $saleId) : null;
    if ($sale === null) {
        echo json_encode(['success' => false, 'message' => 'Vendita non trovata.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $items = [];
    foreach (($sale['items'] ?? []) as $item) {
        $soldQuantity = max(1, (int) ($item['quantita'] ?? 1));
        $alreadyRefunded = max(0, (int) ($item['quantita_resa'] ?? 0));
        $availableRefund = max(0, $soldQuantity - $alreadyRefunded);
        $items[] = [
            'id' => (int) ($item['id'] ?? 0),
            'description' => (string) ($item['descrizione'] ?? ''),
            'type' => (string) ($item['tipo'] ?? ''),
            'operator' => (string) ($item['operatore'] ?? ''),
            'iccid' => (string) ($item['iccid'] ?? ''),
            'quantity' => $soldQuantity,
            'refunded_quantity' => $alreadyRefunded,
            'available_refund' => $availableRefund,
        ];
    }

    echo json_encode([
        'success' => true,
        'sale' => [
            'id' => (int) ($sale['id'] ?? 0),
            'status' => (string) ($sale['stato'] ?? ''),
            'total' => (float) ($sale['totale'] ?? 0),
            'customer' => express_module_sale_customer_label($sale),
            'items' => $items,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'create_sale');

    if ($action === 'cancel_sale') {
        $result = express_module_cancel_sale(
            $pdo,
            (int) ($_POST['sale_id_to_cancel'] ?? 0),
            trim((string) ($_POST['cancel_reason'] ?? '')),
            $currentUserId
        );
        add_flash($result['success'] ? 'success' : 'warning', $result['message']);
        header('Location: create_sale.php');
        exit;
    }

    if ($action === 'refund_sale') {
        $saleIdToRefund = (int) ($_POST['sale_id_to_refund'] ?? 0);
        $refundQuantities = is_array($_POST['refund_quantity'] ?? null) ? $_POST['refund_quantity'] : [];
        $hasPartialSelection = false;
        foreach ($refundQuantities as $refundQuantity) {
            if ((int) $refundQuantity > 0) {
                $hasPartialSelection = true;
                break;
            }
        }

        $result = $hasPartialSelection
            ? express_module_refund_sale_partial(
                $pdo,
                $saleIdToRefund,
                $refundQuantities,
                trim((string) ($_POST['refund_reason'] ?? '')),
                $currentUserId
            )
            : express_module_refund_sale(
                $pdo,
                $saleIdToRefund,
                trim((string) ($_POST['refund_reason'] ?? '')),
                $currentUserId
            );
        add_flash($result['success'] ? 'success' : 'warning', $result['message']);
        header('Location: create_sale.php');
        exit;
    }

    $result = express_module_create_sale($pdo, $_POST, $currentUserId);
    add_flash($result['success'] ? 'success' : 'warning', $result['message']);

    if ($result['success'] && !empty($result['sale_id'])) {
        header('Location: view_sale.php?id=' . (int) $result['sale_id'] . '&show_document=1&autoprint=1');
        exit;
    }
}

$clients = express_module_client_options($pdo);
$providers = express_module_provider_options($pdo);
$availableIccids = express_module_available_iccids($pdo);
$products = express_module_product_options($pdo);
$offers = express_module_offer_options($pdo);
$availableIccidsMap = [];
foreach ($availableIccids as $stock) {
    $iccidValue = preg_replace('/\D+/', '', (string) ($stock['iccid'] ?? ''));
    if ($iccidValue === '') {
        continue;
    }
    $availableIccidsMap[$iccidValue] = [
        'id' => (int) ($stock['id'] ?? 0),
        'operatorId' => (int) ($stock['operatore_id'] ?? 0),
        'operator' => (string) ($stock['operatore'] ?? ''),
    ];
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<style>
.express-sale-shell {
    display: grid;
    grid-template-columns: minmax(0, 1.9fr) minmax(320px, 0.95fr);
    gap: 1rem;
}

.express-sale-hero {
    background:
        radial-gradient(circle at top left, rgba(var(--ag-accent-rgb), 0.18), transparent 34%),
        linear-gradient(135deg, rgba(255, 255, 255, 0.98), rgba(247, 249, 252, 0.96));
    border: 1px solid rgba(var(--ag-accent-rgb), 0.12);
}

.express-sale-hero .hero-title {
    font-size: 1.8rem;
    font-weight: 800;
    margin: 0;
    color: #132238;
}

.express-sale-hero .hero-copy {
    color: #4b5b72;
    margin: 0.35rem 0 0;
    max-width: 52rem;
}

.express-sale-main-card,
.express-sale-side-card {
    overflow: hidden;
}

.express-sale-section + .express-sale-section {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid rgba(var(--ag-accent-rgb), 0.08);
}

.express-sale-section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 0.85rem;
}

.express-sale-section-title {
    font-size: 1rem;
    font-weight: 800;
    color: #1c2c42;
    margin: 0;
}

.express-sale-section-copy {
    font-size: 0.8rem;
    color: #64748b;
    margin: 0.2rem 0 0;
}

.express-sale-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.35rem 0.7rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    background: rgba(var(--ag-accent-rgb), 0.1);
    color: var(--ag-accent-strong);
}

.express-sale-note-box {
    padding: 0.9rem 1rem;
    border: 1px solid rgba(var(--ag-accent-rgb), 0.12);
    border-radius: 0.9rem;
    background: linear-gradient(180deg, rgba(255,255,255,0.96), rgba(248,250,253,0.96));
}

.express-sale-note-box strong {
    display: block;
    color: #1f2f45;
    margin-bottom: 0.15rem;
}

.express-sale-note-box small {
    display: block;
    line-height: 1.45;
    color: #5f6f86;
}

.express-sale-form .form-label {
    font-size: 0.78rem;
    font-weight: 700;
    color: #213247;
    margin-bottom: 0.35rem;
}

.express-sale-form .form-control,
.express-sale-form .form-select {
    min-height: 2.85rem;
    border-radius: 0.8rem;
    border-color: #cad5e3;
    background: #fff;
}

.express-sale-form textarea.form-control {
    min-height: 7rem;
}

.express-sale-form .form-text {
    font-size: 0.72rem;
    color: #71839a;
}

.express-sale-side-card .card-body {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.express-sale-side-muted {
    font-size: 0.78rem;
    color: #6f8096;
    line-height: 1.45;
}

.express-sale-mock-table {
    border: 1px solid rgba(var(--ag-accent-rgb), 0.08);
    border-radius: 0.9rem;
    background: rgba(248, 250, 253, 0.95);
    padding: 0.85rem;
}

.express-sale-mock-row {
    display: grid;
    grid-template-columns: 1.4fr 0.9fr 0.8fr 1fr;
    gap: 0.75rem;
    font-size: 0.74rem;
    color: #5b6c83;
}

.express-sale-mock-row + .express-sale-mock-row {
    margin-top: 0.55rem;
}

.express-sale-mock-head {
    font-weight: 800;
    color: #223349;
}

.express-sale-side-actions {
    display: flex;
    flex-direction: column;
    gap: 0.6rem;
    margin-top: 0.5rem;
}

.express-sale-side-gap {
    margin-bottom: 0.5rem;
}

.express-sale-side-actions .btn {
    width: 100%;
}

.express-sale-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 1rem;
    margin-top: 1.5rem;
    padding-top: 1rem;
    border-top: 1px solid rgba(var(--ag-accent-rgb), 0.08);
}

.express-sale-footer-note {
    font-size: 0.74rem;
    color: #71839a;
    line-height: 1.45;
}

.express-sale-line-summary {
    margin-top: 1rem;
    border: 1px solid rgba(var(--ag-accent-rgb), 0.08);
    border-radius: 1rem;
    overflow: hidden;
    background: #fff;
}

.express-sale-line-summary table {
    margin-bottom: 0;
}

.express-sale-line-summary thead th {
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #64748b;
    background: #f8fafc;
    border-bottom: 1px solid rgba(148, 163, 184, 0.18);
}

.express-sale-line-summary td {
    vertical-align: middle;
}

.express-sale-line-empty {
    padding: 1rem;
    font-size: 0.82rem;
    color: #71839a;
}

.express-sale-total-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    padding: 0.9rem 1rem;
    border-top: 1px solid rgba(148, 163, 184, 0.18);
    background: #f8fafc;
}

.express-sale-total-bar strong {
    font-size: 1rem;
    color: #132238;
}

.express-sale-add-line-row {
    margin-top: 1.25rem;
    padding-top: 0.75rem;
}

#add_sale_line_button {
    transform: none !important;
}

.express-refund-grid {
    display: grid;
    gap: 0.75rem;
}

.express-refund-toolbar {
    display: flex;
    gap: 0.6rem;
}

.express-refund-toolbar .form-control {
    flex: 1 1 auto;
}

.express-refund-sale-meta {
    font-size: 0.76rem;
    color: #64748b;
    line-height: 1.45;
}

.express-refund-table th,
.express-refund-table td {
    font-size: 0.76rem;
    vertical-align: middle;
}

.express-refund-table input {
    min-width: 4.5rem;
}

@media (max-width: 1199px) {
    .express-sale-shell {
        grid-template-columns: 1fr;
    }
}
</style>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <?php express_module_render_nav('new-sale'); ?>
        <div class="card ag-card express-sale-hero mb-4">
            <div class="card-body">
                <h2 class="hero-title">Nuova vendita</h2>
                <p class="hero-copy">Cassa rapida con scontrino termico 80 mm, scansione ICCID e compilazione assistita di offerte e prodotti.</p>
            </div>
        </div>

        <div class="express-sale-shell">
            <div class="card ag-card express-sale-main-card">
                <div class="card-body">
                    <form method="post" class="express-sale-form">
                        <input type="hidden" name="action" value="create_sale">
                        <input type="hidden" name="_token" value="<?php echo sanitize_output(csrf_token()); ?>">
                        <div id="sale_line_inputs"></div>

                        <section class="express-sale-section">
                            <div class="express-sale-section-heading">
                                <div>
                                    <h3 class="express-sale-section-title">Cliente e pagamento</h3>
                                    <p class="express-sale-section-copy">Compila l'anagrafica essenziale e definisci il metodo di incasso.</p>
                                </div>
                                <span class="express-sale-pill">Cassa rapida</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-lg-7">
                                    <label class="form-label" for="cliente_id">Cliente registrato</label>
                                    <select class="form-select" id="cliente_id" name="cliente_id">
                                        <option value="">Cliente libero / non selezionato</option>
                                        <?php foreach ($clients as $client): ?>
                                            <option value="<?php echo (int) $client['id']; ?>"><?php echo sanitize_output(express_module_client_label($client)); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Puoi usare un cliente registrato oppure lasciare il campo vuoto e stampare comunque il documento.</div>
                                </div>
                                <div class="col-lg-5">
                                    <label class="form-label" for="metodo_pagamento">Pagamento</label>
                                    <select class="form-select" id="metodo_pagamento" name="metodo_pagamento">
                                        <?php foreach (($settings['payment_methods'] ?? []) as $method): ?>
                                            <option value="<?php echo sanitize_output((string) $method); ?>"<?php echo $method === ($settings['default_payment_method'] ?? '') ? ' selected' : ''; ?>><?php echo sanitize_output((string) $method); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="operatore_id">Operatore</label>
                                    <select class="form-select" id="operatore_id" name="operatore_id">
                                        <option value="">Non specificato</option>
                                        <?php foreach ($providers as $provider): ?>
                                            <option value="<?php echo (int) $provider['id']; ?>"><?php echo sanitize_output((string) $provider['nome']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="data_vendita">Data vendita</label>
                                    <input class="form-control" id="data_vendita" name="data_vendita" type="datetime-local" value="<?php echo date('Y-m-d\TH:i'); ?>">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label" for="tipo">Tipologia riga</label>
                                    <select class="form-select" id="tipo" name="tipo">
                                        <option value="sim">SIM</option>
                                        <option value="prodotto">Prodotto</option>
                                        <option value="servizio">Servizio</option>
                                    </select>
                                </div>
                            </div>
                        </section>

                        <section class="express-sale-section">
                            <div class="express-sale-section-heading">
                                <div>
                                    <h3 class="express-sale-section-title">Regime IVA e contesto vendita</h3>
                                    <p class="express-sale-section-copy">Nota rapida per l'operatore e spazio appunti interni.</p>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-lg-4">
                                    <div class="express-sale-note-box h-100">
                                        <strong>Regime IVA</strong>
                                        <small>Operazione non soggetta a IVA ai sensi dell'art. 74 DPR 633/72 quando la vendita riguarda esclusivamente SIM.</small>
                                    </div>
                                </div>
                                <div class="col-lg-8">
                                    <label class="form-label" for="note">Note vendita</label>
                                    <textarea class="form-control" id="note" name="note" rows="4" placeholder="Appunti interni, dettaglio promo, seriali aggiuntivi..."></textarea>
                                </div>
                            </div>
                        </section>

                        <section class="express-sale-section">
                            <div class="express-sale-section-heading">
                                <div>
                                    <h3 class="express-sale-section-title">Articoli e scansione</h3>
                                    <p class="express-sale-section-copy">Scansiona ICCID o seleziona un'offerta per precompilare la vendita.</p>
                                </div>
                                <span class="express-sale-pill">Assistito</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-lg-6">
                                    <label class="form-label" for="iccid_scan">Scansione barcode ICCID</label>
                                    <input
                                        class="form-control"
                                        id="iccid_scan"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        placeholder="Scansiona qui l'ICCID"
                                    >
                                    <input type="hidden" id="iccid_stock_id" name="iccid_stock_id" value="">
                                    <div class="form-text" id="iccid_scan_feedback">La scansione seleziona automaticamente l'ICCID disponibile a magazzino.</div>
                                </div>
                                <div class="col-lg-6">
                                    <label class="form-label" for="offer_id">Offerta / listino</label>
                                    <select class="form-select" id="offer_id" name="offer_id">
                                        <option value="">Nessuna offerta</option>
                                        <?php foreach ($offers as $offer): ?>
                                            <option
                                                value="<?php echo (int) $offer['id']; ?>"
                                                data-name="<?php echo sanitize_output((string) $offer['titolo']); ?>"
                                                data-price="<?php echo sanitize_output(number_format((float) $offer['prezzo'], 2, '.', '')); ?>"
                                                data-operator-id="<?php echo (int) ($offer['operatore_id'] ?? 0); ?>"
                                                data-description="<?php echo sanitize_output((string) ($offer['descrizione'] ?? '')); ?>"
                                            >
                                                <?php echo sanitize_output(((string) (($offer['operatore'] ?? '') !== '' ? $offer['operatore'] . ' · ' : '')) . (string) $offer['titolo'] . ' · € ' . number_format((float) $offer['prezzo'], 2, ',', '.')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Le offerte vengono filtrate in automatico quando scansioni un ICCID.</div>
                                </div>
                                <div class="col-lg-6">
                                    <label class="form-label" for="product_id">Prodotto di catalogo</label>
                                    <select class="form-select" id="product_id" name="product_id">
                                        <option value="">Nessun prodotto</option>
                                        <?php foreach ($products as $product): ?>
                                            <option
                                                value="<?php echo (int) $product['id']; ?>"
                                                data-name="<?php echo sanitize_output((string) $product['nome']); ?>"
                                                data-price="<?php echo sanitize_output(number_format((float) $product['prezzo'], 2, '.', '')); ?>"
                                                data-vat="<?php echo sanitize_output(number_format((float) $product['aliquota_iva'], 2, '.', '')); ?>"
                                                data-stock="<?php echo (int) $product['stock_quantita']; ?>"
                                            >
                                                <?php echo sanitize_output((string) $product['categoria'] . ' · ' . $product['nome'] . ' · € ' . number_format((float) $product['prezzo'], 2, ',', '.')); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Selezionando un prodotto vengono compilati descrizione, prezzo e IVA.</div>
                                </div>
                                <div class="col-lg-6">
                                    <label class="form-label" for="descrizione">Descrizione</label>
                                    <input class="form-control" id="descrizione" name="descrizione" maxlength="255" placeholder="Es. SIM Fastweb 150GB + attivazione">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="prezzo_unitario">Prezzo (€)</label>
                                    <input class="form-control" id="prezzo_unitario" name="prezzo_unitario" type="number" min="0" step="0.01" value="0.00">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="quantita">Q.tà</label>
                                    <input class="form-control" id="quantita" name="quantita" type="number" min="1" step="1" value="1">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label" for="aliquota_iva">IVA (%)</label>
                                    <input class="form-control" id="aliquota_iva" name="aliquota_iva" type="number" min="0" max="100" step="0.01" value="<?php echo sanitize_output((string) number_format((float) ($settings['default_vat'] ?? 22), 2, '.', '')); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Pronto per stampa</label>
                                    <div class="express-sale-note-box h-100">
                                        <strong>Uscita termica</strong>
                                        <small>Alla conferma apriamo il documento gestionale con stampa 80 mm già pronta.</small>
                                    </div>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end express-sale-add-line-row">
                                <button class="btn btn-outline-primary" type="button" id="add_sale_line_button">
                                    <i class="fa-solid fa-plus me-2"></i>Aggiungi riga alla vendita
                                </button>
                            </div>

                            <div class="express-sale-line-summary">
                                <div class="table-responsive">
                                    <table class="table align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th>Tipo</th>
                                                <th>Descrizione</th>
                                                <th>Operatore</th>
                                                <th>Q.tà</th>
                                                <th class="text-end">Prezzo</th>
                                                <th class="text-end">Totale</th>
                                                <th class="text-end">Azioni</th>
                                            </tr>
                                        </thead>
                                        <tbody id="sale_lines_table_body">
                                            <tr id="sale_lines_empty_state">
                                                <td class="express-sale-line-empty" colspan="7">Nessuna riga aggiunta. Scansiona un ICCID o seleziona un prodotto/offerta, poi usa “Aggiungi riga alla vendita”.</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="express-sale-total-bar">
                                    <span class="text-muted small">Righe pronte per il documento gestionale</span>
                                    <strong id="sale_lines_total">Totale vendita: € 0,00</strong>
                                </div>
                            </div>
                        </section>

                        <div class="express-sale-footer">
                            <div class="express-sale-footer-note">
                                Stampa: 80 mm termico<br>
                                Shortcut operativo: scanner ICCID + invio
                            </div>
                            <div class="d-flex gap-2 flex-wrap">
                                <a class="btn btn-outline-secondary" href="sales.php">Annulla</a>
                                <button class="btn btn-warning" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva e stampa</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <div class="d-flex flex-column gap-3">
                <div class="card ag-card express-sale-side-card">
                    <div class="card-body">
                        <div>
                            <div class="express-sale-section-title">Annulla scontrino</div>
                            <p class="express-sale-side-muted mb-0">Ritiro articoli e ripristino stock. Layout pronto, operatività da collegare nella prossima fase.</p>
                        </div>
                        <form method="post" class="express-sale-form">
                            <input type="hidden" name="action" value="cancel_sale">
                            <input type="hidden" name="_token" value="<?php echo sanitize_output(csrf_token()); ?>">
                            <div>
                                <label class="form-label" for="express_void_receipt">Numero scontrino</label>
                                <input class="form-control" id="express_void_receipt" name="sale_id_to_cancel" type="number" min="1" step="1" placeholder="Inserisci il numero documento" required>
                            </div>
                            <div>
                                <label class="form-label" for="express_void_reason">Motivazione</label>
                                <textarea class="form-control" id="express_void_reason" name="cancel_reason" rows="4" placeholder="Merce difettosa, errore operatore..."></textarea>
                            </div>
                            <div class="express-sale-side-actions">
                                <button class="btn btn-outline-secondary" type="submit">Annulla e ripristina stock</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card ag-card express-sale-side-card">
                    <div class="card-body">
                        <div>
                            <div class="express-sale-section-title">Reso rapido</div>
                            <p class="express-sale-side-muted mb-0">Carica una vendita, seleziona le quantità da rimborsare e aggiorna automaticamente stock e movimento economico.</p>
                        </div>
                        <form method="post" class="express-sale-form">
                            <input type="hidden" name="action" value="refund_sale">
                            <input type="hidden" name="_token" value="<?php echo sanitize_output(csrf_token()); ?>">
                            <div class="express-refund-grid">
                                <div class="express-sale-side-gap">
                                    <label class="form-label" for="express_return_receipt">Numero scontrino</label>
                                    <div class="express-refund-toolbar">
                                        <input class="form-control" id="express_return_receipt" name="sale_id_to_refund" type="number" min="1" step="1" placeholder="Cerca il documento originale" required>
                                        <button class="btn btn-outline-secondary" type="button" id="express_return_load">Carica dettagli</button>
                                    </div>
                                    <div class="form-text" id="express_refund_feedback">Inserisci il numero vendita e carica le righe da rimborsare.</div>
                                </div>
                                <div class="express-refund-sale-meta" id="express_refund_sale_meta">Nessuna vendita caricata.</div>
                                <div class="express-sale-mock-table">
                                    <div class="table-responsive">
                                        <table class="table align-middle mb-0 express-refund-table">
                                            <thead>
                                                <tr>
                                                    <th>Articolo</th>
                                                    <th>Disponibile</th>
                                                    <th>Q.tà reso</th>
                                                    <th>Tipo</th>
                                                </tr>
                                            </thead>
                                            <tbody id="express_refund_rows">
                                                <tr>
                                                    <td colspan="4" class="text-muted small">Carica prima uno scontrino per vedere le righe disponibili al reso.</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="form-label" for="express_return_note">Note reso</label>
                                <textarea class="form-control" id="express_return_note" name="refund_reason" rows="3" placeholder="Motivo restituzione"></textarea>
                            </div>
                            <div class="express-sale-side-actions">
                                <button class="btn btn-soft-accent" type="submit">Registra reso</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const availableIccids = <?php echo json_encode($availableIccidsMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const productSelect = document.getElementById('product_id');
    const offerSelect = document.getElementById('offer_id');
    const operatorSelect = document.getElementById('operatore_id');
    const iccidScanInput = document.getElementById('iccid_scan');
    const iccidScanFeedback = document.getElementById('iccid_scan_feedback');
    const iccidStockIdInput = document.getElementById('iccid_stock_id');
    const typeSelect = document.getElementById('tipo');
    const descriptionInput = document.getElementById('descrizione');
    const priceInput = document.getElementById('prezzo_unitario');
    const quantityInput = document.getElementById('quantita');
    const vatInput = document.getElementById('aliquota_iva');
    const addSaleLineButton = document.getElementById('add_sale_line_button');
    const saleLineInputs = document.getElementById('sale_line_inputs');
    const saleLinesTableBody = document.getElementById('sale_lines_table_body');
    const saleLinesEmptyState = document.getElementById('sale_lines_empty_state');
    const saleLinesTotal = document.getElementById('sale_lines_total');
    const saleForm = document.querySelector('.express-sale-form');
    const refundReceiptInput = document.getElementById('express_return_receipt');
    const refundLoadButton = document.getElementById('express_return_load');
    const refundFeedback = document.getElementById('express_refund_feedback');
    const refundRows = document.getElementById('express_refund_rows');
    const refundSaleMeta = document.getElementById('express_refund_sale_meta');
    const refundLookupUrlBase = <?php echo json_encode(base_url('modules/servizi/express/create_sale.php?action=refund_lookup'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const offerOptions = offerSelect ? Array.from(offerSelect.options) : [];
    const saleLines = [];
    const escapeHtml = (value) => String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const euroFormatter = new Intl.NumberFormat('it-IT', {
        style: 'currency',
        currency: 'EUR'
    });

    const clearDraftLine = () => {
        if (typeSelect) {
            typeSelect.value = 'sim';
        }
        if (descriptionInput) {
            descriptionInput.value = '';
        }
        if (priceInput) {
            priceInput.value = '0.00';
        }
        if (quantityInput) {
            quantityInput.value = '1';
        }
        if (vatInput) {
            vatInput.value = <?php echo json_encode((string) number_format((float) ($settings['default_vat'] ?? 22), 2, '.', '')); ?>;
        }
        if (productSelect) {
            productSelect.value = '';
        }
        if (offerSelect) {
            offerSelect.value = '';
        }
        if (operatorSelect) {
            operatorSelect.value = '';
        }
        if (iccidScanInput) {
            iccidScanInput.value = '';
        }
        if (iccidStockIdInput) {
            iccidStockIdInput.value = '';
        }
        filterOffersByOperator('');
        resetIccidFeedback('La scansione seleziona automaticamente l\'ICCID disponibile a magazzino.');
    };

    const renderSaleLines = () => {
        if (!saleLineInputs || !saleLinesTableBody || !saleLinesTotal) {
            return;
        }

        saleLineInputs.innerHTML = '';

        const existingRows = saleLinesTableBody.querySelectorAll('tr[data-sale-line-index]');
        existingRows.forEach((row) => row.remove());

        if (saleLinesEmptyState) {
            saleLinesEmptyState.style.display = saleLines.length === 0 ? '' : 'none';
        }

        let grandTotal = 0;

        saleLines.forEach((line, index) => {
            const lineTotal = Number(line.quantity) * Number(line.price);
            grandTotal += lineTotal;

            const hiddenFields = {
                line_type: line.type,
                line_description: line.description,
                line_quantity: String(line.quantity),
                line_price: String(line.price),
                line_vat: String(line.vat),
                line_operator_id: String(line.operatorId || ''),
                line_iccid_stock_id: String(line.iccidStockId || ''),
                line_product_id: String(line.productId || ''),
                line_offer_id: String(line.offerId || '')
            };

            Object.entries(hiddenFields).forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name + '[]';
                input.value = value;
                saleLineInputs.appendChild(input);
            });

            const row = document.createElement('tr');
            row.dataset.saleLineIndex = String(index);
            row.innerHTML = `
                <td>${escapeHtml(line.typeLabel)}</td>
                <td>
                    <div class="fw-semibold">${escapeHtml(line.description)}</div>
                    <div class="small text-muted">${line.meta ? escapeHtml(line.meta) : '&mdash;'}</div>
                </td>
                <td>${line.operator ? escapeHtml(line.operator) : '&mdash;'}</td>
                <td>${line.quantity}</td>
                <td class="text-end">${euroFormatter.format(line.price)}</td>
                <td class="text-end fw-semibold">${euroFormatter.format(lineTotal)}</td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-danger" data-remove-sale-line="${index}">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </td>
            `;
            saleLinesTableBody.appendChild(row);
        });

        saleLinesTotal.textContent = 'Totale vendita: ' + euroFormatter.format(grandTotal);
    };

    const resetIccidFeedback = (message, isError = false) => {
        if (!iccidScanFeedback) {
            return;
        }
        iccidScanFeedback.textContent = message;
        iccidScanFeedback.classList.toggle('text-danger', isError);
        iccidScanFeedback.classList.toggle('text-success', !isError && message !== '');
    };

    const setRefundFeedback = (message, isError = false) => {
        if (!refundFeedback) {
            return;
        }
        refundFeedback.textContent = message;
        refundFeedback.classList.toggle('text-danger', isError);
        refundFeedback.classList.toggle('text-success', !isError && message !== '');
    };

    const renderRefundRows = (sale) => {
        if (!refundRows || !refundSaleMeta) {
            return;
        }

        refundRows.innerHTML = '';
        const items = Array.isArray(sale?.items) ? sale.items : [];

        if (items.length === 0) {
            refundRows.innerHTML = '<tr><td colspan="4" class="text-muted small">La vendita non contiene righe rimborsabili.</td></tr>';
            refundSaleMeta.textContent = 'Vendita #' + String(sale?.id || '') + ' · nessuna riga disponibile.';
            return;
        }

        refundSaleMeta.textContent = 'Vendita #' + sale.id + ' · ' + (sale.customer || 'Cliente libero') + ' · Totale € ' + Number(sale.total || 0).toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        items.forEach((item) => {
            const availableRefund = Number(item.available_refund || 0);
            const descriptionParts = [item.description || 'Voce Express'];
            if (item.operator) {
                descriptionParts.push(item.operator);
            }
            if (item.iccid) {
                descriptionParts.push('ICCID ' + item.iccid);
            }

            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <div class="fw-semibold">${escapeHtml(descriptionParts[0])}</div>
                    <div class="text-muted small">${escapeHtml(descriptionParts.slice(1).join(' · ') || '—')}</div>
                </td>
                <td>
                    <span class="fw-semibold">${availableRefund}</span>
                    <div class="text-muted small">Venduti ${escapeHtml(String(item.quantity || 0))} · Resi ${escapeHtml(String(item.refunded_quantity || 0))}</div>
                </td>
                <td>
                    <input class="form-control form-control-sm" type="number" min="0" max="${availableRefund}" step="1" value="0" name="refund_quantity[${item.id}]">
                </td>
                <td>${escapeHtml(String(item.type || ''))}</td>
            `;
            refundRows.appendChild(row);
        });
    };

    const filterOffersByOperator = (operatorId) => {
        if (!offerSelect) {
            return;
        }

        const normalizedOperatorId = String(operatorId || '').trim();
        let currentSelectionAllowed = false;

        offerOptions.forEach((option, index) => {
            if (index === 0) {
                option.hidden = false;
                return;
            }

            const optionOperatorId = String(option.dataset.operatorId || '').trim();
            const shouldShow = normalizedOperatorId === '' || optionOperatorId === normalizedOperatorId;
            option.hidden = !shouldShow;

            if (shouldShow && option.value === offerSelect.value) {
                currentSelectionAllowed = true;
            }
        });

        if (offerSelect.value !== '' && !currentSelectionAllowed) {
            offerSelect.value = '';
        }
    };

    const applyIccidSelection = (rawValue) => {
        if (!iccidStockIdInput || !iccidScanInput) {
            return false;
        }

        const normalizedValue = String(rawValue || '').replace(/\D+/g, '');
        if (normalizedValue === '') {
            iccidStockIdInput.value = '';
            filterOffersByOperator('');
            resetIccidFeedback('La scansione seleziona automaticamente l\'ICCID disponibile a magazzino.');
            return false;
        }

        const match = availableIccids[normalizedValue] || null;
        if (!match || !match.id) {
            iccidStockIdInput.value = '';
            filterOffersByOperator('');
            resetIccidFeedback('ICCID non trovato nello stock disponibile.', true);
            return false;
        }

        iccidStockIdInput.value = String(match.id);
        filterOffersByOperator(match.operatorId || '');
        if (operatorSelect && match.operatorId) {
            operatorSelect.value = String(match.operatorId);
        }
        if (typeSelect) {
            typeSelect.value = 'sim';
        }
        resetIccidFeedback('ICCID selezionato correttamente' + (match.operator ? ' (' + match.operator + ')' : '') + '.');
        return true;
    };

    const applyProduct = () => {
        const option = productSelect.options[productSelect.selectedIndex];
        if (!option || !option.value) {
            return;
        }
        descriptionInput.value = option.dataset.name || descriptionInput.value;
        priceInput.value = option.dataset.price || priceInput.value;
        vatInput.value = option.dataset.vat || vatInput.value;
        typeSelect.value = 'prodotto';
    };

    const applyOffer = () => {
        const option = offerSelect.options[offerSelect.selectedIndex];
        if (!option || !option.value) {
            return;
        }
        if (!descriptionInput.value) {
            descriptionInput.value = option.dataset.name || '';
        }
        if (parseFloat(priceInput.value || '0') <= 0) {
            priceInput.value = option.dataset.price || priceInput.value;
        }
        if (operatorSelect.value === '' && option.dataset.operatorId && option.dataset.operatorId !== '0') {
            operatorSelect.value = option.dataset.operatorId;
        }
        if (typeSelect.value === 'sim' && document.getElementById('iccid_stock_id').value === '') {
            typeSelect.value = 'servizio';
        }
    };

    const buildDraftLine = () => {
        const description = String(descriptionInput?.value || '').trim();
        const quantity = Math.max(1, parseInt(quantityInput?.value || '1', 10) || 1);
        const price = Math.max(0, parseFloat(priceInput?.value || '0') || 0);
        const vat = Math.max(0, parseFloat(vatInput?.value || '0') || 0);
        const type = String(typeSelect?.value || 'sim').trim() || 'sim';
        const operatorId = parseInt(operatorSelect?.value || '0', 10) || 0;
        const iccidStockId = parseInt(iccidStockIdInput?.value || '0', 10) || 0;
        const productId = parseInt(productSelect?.value || '0', 10) || 0;
        const offerId = parseInt(offerSelect?.value || '0', 10) || 0;
        const operator = operatorSelect?.selectedOptions?.[0]?.textContent?.trim() || '';
        const scannedIccid = String(iccidScanInput?.value || '').replace(/\D+/g, '');

        if (description === '' && iccidStockId <= 0 && productId <= 0 && offerId <= 0) {
            return { error: 'Compila una riga prima di aggiungerla alla vendita.' };
        }

        if (description === '') {
            return { error: 'Inserisci una descrizione per la riga vendita.' };
        }

        if (productId > 0 && iccidStockId > 0) {
            return { error: 'Una riga non può avere insieme prodotto e ICCID.' };
        }

        const typeLabels = {
            sim: 'SIM',
            prodotto: 'Prodotto',
            servizio: 'Servizio'
        };

        const metaParts = [];
        if (scannedIccid !== '') {
            metaParts.push('ICCID ' + scannedIccid);
        }
        if (offerId > 0 && offerSelect?.selectedOptions?.[0]) {
            metaParts.push('Offerta: ' + offerSelect.selectedOptions[0].textContent.trim());
        }
        if (productId > 0 && productSelect?.selectedOptions?.[0]) {
            metaParts.push('Prodotto: ' + productSelect.selectedOptions[0].textContent.trim());
        }

        return {
            line: {
                type: type,
                typeLabel: typeLabels[type] || 'Voce',
                description: description,
                quantity: quantity,
                price: Number(price.toFixed(2)),
                vat: Number(vat.toFixed(2)),
                operatorId: operatorId,
                operator: operatorId > 0 ? operator : '',
                iccidStockId: iccidStockId,
                productId: productId,
                offerId: offerId,
                meta: metaParts.join(' · ')
            }
        };
    };

    addSaleLineButton?.addEventListener('click', function () {
        const draft = buildDraftLine();
        if (draft.error) {
            resetIccidFeedback(draft.error, true);
            return;
        }

        saleLines.push(draft.line);
        renderSaleLines();
        clearDraftLine();
    });

    saleLinesTableBody?.addEventListener('click', function (event) {
        const trigger = event.target instanceof HTMLElement ? event.target.closest('[data-remove-sale-line]') : null;
        if (!trigger) {
            return;
        }

        const index = parseInt(trigger.getAttribute('data-remove-sale-line') || '-1', 10);
        if (index < 0 || index >= saleLines.length) {
            return;
        }

        saleLines.splice(index, 1);
        renderSaleLines();
    });

    saleForm?.addEventListener('submit', function (event) {
        if (saleLines.length === 0) {
            const draft = buildDraftLine();
            if (!draft.error) {
                saleLines.push(draft.line);
                renderSaleLines();
            }
        }

        if (saleLines.length === 0) {
            event.preventDefault();
            resetIccidFeedback('Aggiungi almeno una riga prima di salvare la vendita.', true);
        }
    });

    productSelect?.addEventListener('change', applyProduct);
    offerSelect?.addEventListener('change', applyOffer);
    filterOffersByOperator('');
    iccidScanInput?.addEventListener('input', function () {
        applyIccidSelection(iccidScanInput.value);
    });
    iccidScanInput?.addEventListener('change', function () {
        applyIccidSelection(iccidScanInput.value);
    });
    iccidScanInput?.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (applyIccidSelection(iccidScanInput.value)) {
                descriptionInput?.focus();
            }
        }
    });
    refundLoadButton?.addEventListener('click', function () {
        const saleId = parseInt(refundReceiptInput?.value || '0', 10) || 0;
        if (saleId <= 0) {
            setRefundFeedback('Inserisci un numero scontrino valido.', true);
            return;
        }

        setRefundFeedback('Caricamento dettagli vendita...');
        fetch(refundLookupUrlBase + '&sale_id=' + encodeURIComponent(String(saleId)), {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then((response) => response.json())
            .then((payload) => {
                if (!payload || !payload.success || !payload.sale) {
                    throw new Error(payload?.message || 'Vendita non trovata.');
                }
                renderRefundRows(payload.sale);
                setRefundFeedback('Dettagli vendita caricati correttamente.');
            })
            .catch((error) => {
                if (refundRows) {
                    refundRows.innerHTML = '<tr><td colspan="4" class="text-danger small">Impossibile caricare i dettagli del reso.</td></tr>';
                }
                if (refundSaleMeta) {
                    refundSaleMeta.textContent = 'Nessuna vendita caricata.';
                }
                setRefundFeedback(error.message || 'Impossibile caricare la vendita.', true);
            });
    });
    renderSaleLines();
});
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
