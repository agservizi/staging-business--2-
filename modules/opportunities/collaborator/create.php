<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/mailer.php';

require_role('Collaboratore');

$pageTitle = 'Nuova Opportunity';
$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$catalog = $opportunityService->getProviderCatalog();
$errors = [];
$formData = $_POST;
$hasSubmitted = $_SERVER['REQUEST_METHOD'] === 'POST';
$draftStorageKey = 'opportunity_collaborator_draft_' . $collaboratorId;
$documentTypePresets = [
    "Carta d'identità" => 'Comune',
    'Passaporto' => 'Ministero Affari Esteri',
    'Patente' => 'MIT UCO Motorizzazione',
];
$documentTypeValue = $formData['document_type'] ?? "Carta d'identità";
$documentTypeHasPreset = array_key_exists($documentTypeValue, $documentTypePresets);
$documentIssuedByValue = $formData['document_issued_by'] ?? ($documentTypePresets[$documentTypeValue] ?? '');
$documentAuthorityOptions = array_values(array_unique(array_merge(
    array_values($documentTypePresets),
    $documentIssuedByValue !== '' ? [$documentIssuedByValue] : []
)));
$csrfToken = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
    try {
        $opportunity = $opportunityService->createOpportunity($_POST, $collaboratorId, $_FILES['documents'] ?? []);
        send_opportunity_confirmation_email([
            'customer_email' => $opportunity['customer_email'] ?? ($_POST['customer_email'] ?? ''),
            'customer_first_name' => $opportunity['customer_first_name'] ?? ($_POST['customer_first_name'] ?? ''),
            'customer_last_name' => $opportunity['customer_last_name'] ?? ($_POST['customer_last_name'] ?? ''),
            'category' => $opportunity['category'] ?? ($_POST['category'] ?? ''),
            'code' => $opportunity['code'] ?? null,
            'provider_label' => $opportunity['provider_label'] ?? null,
            'offer_label' => $opportunity['offer_label'] ?? null,
        ]);

        add_flash('success', 'Opportunity registrata. Riceverai aggiornamenti dal team.');
        header('Location: index.php');
        exit;
    } catch (RuntimeException $exception) {
        $errors[] = $exception->getMessage();
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Opportunity</p>
                <h1 class="h4 mb-0">Nuova opportunity</h1>
                <p class="text-muted mb-0">Registra contratti telefonici, luce e gas per l'approvazione dell'admin.</p>

            <div class="modal fade" id="customerPrefillModal" tabindex="-1" aria-labelledby="customerPrefillModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered modal-lg">
                    <div class="modal-content border-0 shadow-lg">
                        <div class="modal-header border-0">
                            <div>
                                <p class="text-uppercase small text-muted mb-1">Archivio clienti</p>
                                <h5 class="modal-title" id="customerPrefillModalLabel">Dati trovati per il codice fiscale inserito</h5>
                            </div>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted">Conferma per compilare automaticamente il modulo con i dati salvati in precedenza.</p>
                            <div class="table-responsive border rounded-3">
                                <table class="table table-sm mb-0">
                                    <tbody id="customer-prefill-details">
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="modal-footer border-0">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><i class="fa-solid fa-circle-xmark me-2"></i>Annulla</button>
                            <button type="button" class="btn btn-warning" id="customer-prefill-apply"><i class="fa-solid fa-wand-magic-sparkles me-2"></i>Usa questi dati</button>
                        </div>
                    </div>
                </div>
            </div>

            </div>
            <a class="btn btn-outline-secondary" href="<?php echo asset('modules/opportunities/collaborator/index.php'); ?>">
                <i class="fa-solid fa-arrow-left me-2"></i>Torna alla lista
            </a>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger" role="alert">
                <h2 class="h6 mb-2">Controlla i seguenti punti prima di procedere:</h2>
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $message): ?>
                        <li><?php echo sanitize_output($message); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="alert alert-danger d-none" role="alert" id="client-error-summary" aria-live="assertive">
            <h2 class="h6 mb-2">Controlla i seguenti campi prima di inviare:</h2>
            <ul class="mb-0 ps-3" id="client-error-summary-list"></ul>
        </div>

        <div class="alert alert-secondary d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2" role="status">
            <div>
                <strong>Salvataggio automatico bozza</strong>
                <p class="mb-0 small" id="draft-status-label">I dati vengono salvati in locale ogni pochi secondi. Puoi chiudere la pagina e riprendere più tardi.</p>
            </div>
            <button class="btn btn-outline-secondary btn-sm" type="button" id="clear-draft-button">
                <i class="fa-solid fa-eraser me-1"></i>Svuota bozza
            </button>
        </div>

        <form class="row g-4" method="post" enctype="multipart/form-data" id="opportunity-form">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
            <div class="col-12">
                <div class="card shadow-sm opportunity-card">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label text-uppercase small text-muted">Categoria</label>
                                <select class="form-select" name="category" id="category-select" required>
                                    <option value="">Seleziona</option>
                                    <option value="telefonia" <?php echo (isset($formData['category']) && $formData['category'] === 'telefonia') ? 'selected' : ''; ?>>Telefonia</option>
                                    <option value="luce" <?php echo (isset($formData['category']) && $formData['category'] === 'luce') ? 'selected' : ''; ?>>Luce</option>
                                    <option value="gas" <?php echo (isset($formData['category']) && $formData['category'] === 'gas') ? 'selected' : ''; ?>>Gas</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-uppercase small text-muted">Gestore</label>
                                <select class="form-select" name="provider_id" id="provider-select" data-selected="<?php echo isset($formData['provider_id']) ? (int) $formData['provider_id'] : ''; ?>" required></select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-uppercase small text-muted">Offerta</label>
                                <select class="form-select" name="offer_id" id="offer-select" data-selected="<?php echo isset($formData['offer_id']) ? (int) $formData['offer_id'] : ''; ?>">
                                    <option value="">Seleziona offerta</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Dati cliente</h2>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Nome</label>
                                <input class="form-control" type="text" name="customer_first_name" required value="<?php echo sanitize_output($formData['customer_first_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Cognome</label>
                                <input class="form-control" type="text" name="customer_last_name" required value="<?php echo sanitize_output($formData['customer_last_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="customer-tax-code">Codice fiscale</label>
                                <div class="input-group">
                                    <input class="form-control" type="text" name="customer_tax_code" id="customer-tax-code" required value="<?php echo sanitize_output($formData['customer_tax_code'] ?? ''); ?>" placeholder="RSSMRA90A01H501U">
                                    <button class="btn btn-outline-secondary" type="button" id="tax-code-lookup">
                                        <span id="tax-code-lookup-label">Recupera</span>
                                        <span class="spinner-border spinner-border-sm d-none" id="tax-code-lookup-spinner" role="status" aria-hidden="true"></span>
                                    </button>
                                </div>
                                <div class="form-text" id="tax-code-lookup-feedback">Inserisci il codice fiscale e recupera un cliente già registrato.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data di nascita</label>
                                <input class="form-control" type="date" name="customer_birth_date" value="<?php echo sanitize_output($formData['customer_birth_date'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Luogo di nascita</label>
                                <input class="form-control" type="text" name="customer_birth_place" value="<?php echo sanitize_output($formData['customer_birth_place'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Telefono</label>
                                <input class="form-control" type="tel" name="customer_phone" required value="<?php echo sanitize_output($formData['customer_phone'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Email</label>
                                <input class="form-control" type="email" name="customer_email" required value="<?php echo sanitize_output($formData['customer_email'] ?? ''); ?>">
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">Indirizzo completo</label>
                                <input class="form-control" type="text" name="customer_address" value="<?php echo sanitize_output($formData['customer_address'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="customer-city">Città</label>
                                <input
                                    class="form-control"
                                    type="text"
                                    name="customer_city"
                                    id="customer-city"
                                    value="<?php echo sanitize_output($formData['customer_city'] ?? ''); ?>"
                                    data-istat-comune="true"
                                    data-istat-province-target="#customer-province"
                                    data-istat-cap-target="#customer-postal-code"
                                >
                                <div class="form-text text-muted">Suggerimenti dal database ISTAT disponibili durante la digitazione.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="customer-postal-code">CAP</label>
                                <input
                                    class="form-control"
                                    type="text"
                                    name="customer_postal_code"
                                    id="customer-postal-code"
                                    value="<?php echo sanitize_output($formData['customer_postal_code'] ?? ''); ?>"
                                    inputmode="numeric"
                                    maxlength="10"
                                >
                                <div class="form-text text-muted" id="cap-lookup-feedback">Inserisci il CAP per validarlo automaticamente.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="customer-province">Provincia</label>
                                <input class="form-control" type="text" name="customer_province" id="customer-province" value="<?php echo sanitize_output($formData['customer_province'] ?? ''); ?>" maxlength="5">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Documento identità</h2>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label" for="document-type-select">Tipologia</label>
                                <select class="form-select" name="document_type" id="document-type-select" required>
                                    <?php foreach ($documentTypePresets as $label => $authority): ?>
                                        <option value="<?php echo sanitize_output($label); ?>" <?php echo $documentTypeValue === $label ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                    <?php endforeach; ?>
                                    <?php if (!$documentTypeHasPreset && $documentTypeValue !== ''): ?>
                                        <option value="<?php echo sanitize_output($documentTypeValue); ?>" selected><?php echo sanitize_output($documentTypeValue); ?></option>
                                    <?php endif; ?>
                                </select>
                                <div class="form-text text-muted">Scegli il documento consegnato dal cliente.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Numero</label>
                                <input class="form-control" type="text" name="document_number" required value="<?php echo sanitize_output($formData['document_number'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="document-issued-by-select">Autorità rilascio</label>
                                <select class="form-select" name="document_issued_by" id="document-issued-by-select">
                                    <option value="" <?php echo $documentIssuedByValue === '' ? 'selected' : ''; ?>>Seleziona autorità</option>
                                    <?php foreach ($documentAuthorityOptions as $authorityLabel): ?>
                                        <option value="<?php echo sanitize_output($authorityLabel); ?>" <?php echo $documentIssuedByValue === $authorityLabel ? 'selected' : ''; ?>><?php echo sanitize_output($authorityLabel); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text text-muted">Valore aggiornato automaticamente in base alla tipologia.</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data rilascio</label>
                                <input class="form-control" type="date" name="document_issued_at" value="<?php echo sanitize_output($formData['document_issued_at'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Data scadenza</label>
                                <input class="form-control" type="date" name="document_expires_at" required value="<?php echo sanitize_output($formData['document_expires_at'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12" id="telefonia-section" hidden>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Dettagli telefonia</h2>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Operatore attuale</label>
                                <input class="form-control" type="text" name="telefonia_current_operator" value="<?php echo sanitize_output($formData['telefonia_current_operator'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Numero linea</label>
                                <input class="form-control" type="text" name="telefonia_line_number" value="<?php echo sanitize_output($formData['telefonia_line_number'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12" id="luce-section" hidden>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Dettagli luce</h2>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Codice POD</label>
                                <input class="form-control" type="text" name="luce_pod" value="<?php echo sanitize_output($formData['luce_pod'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12" id="gas-section" hidden>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Dettagli gas</h2>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Codice PDR</label>
                                <input class="form-control" type="text" name="gas_pdr" value="<?php echo sanitize_output($formData['gas_pdr'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Pagamento</h2>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">Metodo</label>
                                <select class="form-select" name="payment_method" id="payment-method-select">
                                    <option value="iban" <?php echo (!isset($formData['payment_method']) || $formData['payment_method'] === 'iban') ? 'selected' : ''; ?>>Addebito IBAN</option>
                                    <option value="bollettino" <?php echo (isset($formData['payment_method']) && $formData['payment_method'] === 'bollettino') ? 'selected' : ''; ?>>Bollettino</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">IBAN</label>
                                <input class="form-control" type="text" name="payment_iban" id="payment-iban-field" value="<?php echo sanitize_output($formData['payment_iban'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <input type="hidden" name="payment_holder_is_customer" value="0">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" value="1" id="payment-holder-toggle" name="payment_holder_is_customer" <?php echo (!isset($formData['payment_holder_is_customer']) || $formData['payment_holder_is_customer'] == '1') ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="payment-holder-toggle">IBAN intestato al cliente</label>
                                </div>
                            </div>
                        </div>
                        <div class="row g-3 mt-2" id="payment-holder-fields" hidden>
                            <div class="col-md-4">
                                <label class="form-label">Nome intestatario</label>
                                <input class="form-control" type="text" name="payment_holder_first_name" value="<?php echo sanitize_output($formData['payment_holder_first_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Cognome intestatario</label>
                                <input class="form-control" type="text" name="payment_holder_last_name" value="<?php echo sanitize_output($formData['payment_holder_last_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Codice fiscale intestatario</label>
                                <input class="form-control" type="text" name="payment_holder_tax_code" value="<?php echo sanitize_output($formData['payment_holder_tax_code'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Allegati</h2>
                        <div class="dropzone-area" id="dropzone-area">
                            <p class="mb-1"><i class="fa-solid fa-cloud-arrow-up me-2"></i>Trascina qui i file o clicca per selezionare</p>
                            <p class="text-muted small mb-0">Documenti accettati: PDF, JPG, PNG, ZIP (max 10MB ciascuno).</p>
                            <input class="d-none" type="file" name="documents[]" id="documents-input" multiple>
                        </div>
                        <div class="dropzone-files" id="dropzone-files"></div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Note</h2>
                        <textarea class="form-control" rows="3" name="additional_notes"><?php echo sanitize_output($formData['additional_notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <div class="col-12 d-flex justify-content-end">
                <button class="btn btn-success btn-lg" type="submit">
                    <i class="fa-solid fa-floppy-disk me-2"></i>Registra opportunity
                </button>
            </div>
        </form>
    </main>
</div>
<?php
$istatDatasetUrl = asset('customer-portal/assets/data/comuni.json');
?>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<script>
window.CIEIstatLookupConfig = {
    datasetUrl: '<?php echo sanitize_output($istatDatasetUrl); ?>',
    fallbackUrl: 'https://raw.githubusercontent.com/matteocontrini/comuni-json/master/comuni.json',
    maxResults: 12,
    minChars: 2
};
</script>
<script src="<?php echo asset('assets/js/cie-istat-lookup.js'); ?>"></script>
<script>
    const catalog = <?php echo json_encode($catalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const csrfToken = '<?php echo sanitize_output($csrfToken); ?>';
    const opportunityForm = document.getElementById('opportunity-form');
    const hasSubmitted = <?php echo $hasSubmitted ? 'true' : 'false'; ?>;
    const draftStorageKey = '<?php echo sanitize_output($draftStorageKey); ?>';
    const categorySelect = document.getElementById('category-select');
    const providerSelect = document.getElementById('provider-select');
    const offerSelect = document.getElementById('offer-select');
    const telefoniaSection = document.getElementById('telefonia-section');
    const luceSection = document.getElementById('luce-section');
    const gasSection = document.getElementById('gas-section');
    const paymentMethodSelect = document.getElementById('payment-method-select');
    const paymentIbanField = document.getElementById('payment-iban-field');
    const paymentHolderToggle = document.getElementById('payment-holder-toggle');
    const paymentHolderFields = document.getElementById('payment-holder-fields');
    const dropzoneArea = document.getElementById('dropzone-area');
    const documentsInput = document.getElementById('documents-input');
    const dropzoneFiles = document.getElementById('dropzone-files');
    const taxCodeInput = document.getElementById('customer-tax-code');
    const taxCodeLookupBtn = document.getElementById('tax-code-lookup');
    const taxCodeLookupSpinner = document.getElementById('tax-code-lookup-spinner');
    const taxCodeLookupLabel = document.getElementById('tax-code-lookup-label');
    const taxCodeLookupFeedback = document.getElementById('tax-code-lookup-feedback');
    const taxCodePrefillDetails = document.getElementById('customer-prefill-details');
    const taxCodePrefillApply = document.getElementById('customer-prefill-apply');
    const taxCodePrefillModalEl = document.getElementById('customerPrefillModal');
    const taxCodePrefillModal = window.bootstrap && taxCodePrefillModalEl ? new bootstrap.Modal(taxCodePrefillModalEl) : null;
    const customerCityInput = document.getElementById('customer-city');
    const customerProvinceInput = document.getElementById('customer-province');
    const customerPostalCodeInput = document.getElementById('customer-postal-code');
    const capLookupFeedback = document.getElementById('cap-lookup-feedback');
    const defaultCapFeedbackMessage = 'Inserisci il CAP per validarlo automaticamente.';
    const clearDraftButton = document.getElementById('clear-draft-button');
    const draftStatusLabel = document.getElementById('draft-status-label');
    const clientErrorSummary = document.getElementById('client-error-summary');
    const clientErrorSummaryList = document.getElementById('client-error-summary-list');
    const canUseDrafts = (() => {
        try {
            if (typeof window === 'undefined' || !window.localStorage) {
                return false;
            }
            const testKey = '__opportunity_draft_test__';
            window.localStorage.setItem(testKey, '1');
            window.localStorage.removeItem(testKey);
            return true;
        } catch (error) {
            return false;
        }
    })();
    const documentTypeSelect = document.getElementById('document-type-select');
    const documentIssuedBySelect = document.getElementById('document-issued-by-select');
    const documentAuthorityDefaults = {
        "Carta d'identità": 'Comune',
        Passaporto: 'Ministero Affari Esteri',
        Patente: 'MIT UCO Motorizzazione',
    };
    const paymentMethodBollettinoOption = paymentMethodSelect ? paymentMethodSelect.querySelector('option[value="bollettino"]') : null;
    let lastLookupTaxCode = '';
    let pendingPrefillData = null;
    let capLookupRequestId = 0;

    const escapeHtml = (value) => {
        if (value === null || value === undefined) {
            return '';
        }
        return value
            .toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    };

    const debounce = (callback, delay = 250) => {
        let timer = null;
        return (...args) => {
            if (timer) {
                window.clearTimeout(timer);
            }
            timer = window.setTimeout(() => {
                callback.apply(null, args);
            }, delay);
        };
    };

    const updateInputValue = (input, value) => {
        if (!input || value === null || value === undefined) {
            return;
        }
        const trimmedValue = String(value).trim();
        if (!trimmedValue) {
            return;
        }
        if (input.value.trim() === trimmedValue) {
            return;
        }
        input.value = trimmedValue;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    };

    const ensureSelectOption = (select, value, label) => {
        if (!select || value === null || value === undefined) {
            return;
        }
        const normalizedValue = String(value).trim();
        if (!normalizedValue) {
            return;
        }
        const exists = Array.from(select.options).some((option) => option.value === normalizedValue);
        if (exists) {
            return;
        }
        const option = document.createElement('option');
        option.value = normalizedValue;
        option.textContent = label || normalizedValue;
        select.appendChild(option);
    };

    const setCapFeedback = (message, state = 'muted') => {
        if (!capLookupFeedback) {
            return;
        }
        const classMap = {
            muted: 'text-muted',
            success: 'text-success',
            warning: 'text-warning',
            danger: 'text-danger',
        };
        capLookupFeedback.classList.remove('text-muted', 'text-success', 'text-warning', 'text-danger');
        capLookupFeedback.classList.add(classMap[state] || 'text-muted');
        capLookupFeedback.textContent = message;
    };

    const updateDraftStatus = (message, tone = 'muted') => {
        if (!draftStatusLabel) {
            return;
        }
        draftStatusLabel.classList.remove('text-muted', 'text-success', 'text-warning', 'text-danger');
        const toneClass = {
            muted: 'text-muted',
            success: 'text-success',
            warning: 'text-warning',
            danger: 'text-danger',
        };
        draftStatusLabel.classList.add(toneClass[tone] || 'text-muted');
        draftStatusLabel.textContent = message;
    };

    const getFieldNodes = (name) => {
        if (!opportunityForm || !name) {
            return [];
        }
        const selector = name.replace(/"/g, '\\"');
        return Array.from(opportunityForm.querySelectorAll(`[name="${selector}"]`));
    };

    let lastInvalidFields = [];

    const getFieldLabelText = (field) => {
        if (!field) {
            return 'Campo obbligatorio';
        }
        if (field.dataset?.fieldLabel) {
            return field.dataset.fieldLabel;
        }
        if (field.id) {
            const labelForId = opportunityForm?.querySelector(`label[for="${field.id}"]`);
            if (labelForId) {
                return labelForId.textContent.trim();
            }
        }
        const wrappingLabel = field.closest('label');
        if (wrappingLabel) {
            return wrappingLabel.textContent.trim();
        }
        const formGroupLabel = field.closest('.col-md-4, .col-md-6, .col-md-8, .col-12, .row')?.querySelector('label');
        if (formGroupLabel) {
            return formGroupLabel.textContent.trim();
        }
        return field.name || 'Campo obbligatorio';
    };

    const hideClientErrorSummary = () => {
        if (!clientErrorSummary || !clientErrorSummaryList) {
            return;
        }
        clientErrorSummary.classList.add('d-none');
        clientErrorSummaryList.innerHTML = '';
        lastInvalidFields = [];
    };

    const showClientErrorSummary = (fields) => {
        if (!clientErrorSummary || !clientErrorSummaryList) {
            return;
        }
        const validFields = (fields || []).filter((field) => field instanceof HTMLElement);
        if (!validFields.length) {
            hideClientErrorSummary();
            return;
        }
        lastInvalidFields = validFields;
        clientErrorSummaryList.innerHTML = '';
        validFields.forEach((field, index) => {
            const item = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-link p-0';
            button.dataset.errorIndex = String(index);
            button.textContent = getFieldLabelText(field);
            item.appendChild(button);
            clientErrorSummaryList.appendChild(item);
        });
        clientErrorSummary.classList.remove('d-none');
        clientErrorSummary.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    clientErrorSummaryList?.addEventListener('click', (event) => {
        const target = event.target instanceof HTMLElement ? event.target.closest('button[data-error-index]') : null;
        if (!target) {
            return;
        }
        event.preventDefault();
        const index = Number(target.dataset.errorIndex);
        const field = Number.isNaN(index) ? null : lastInvalidFields[index];
        if (field) {
            field.focus({ preventScroll: true });
            field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });

    const collectDraftPayload = () => {
        if (!opportunityForm) {
            return null;
        }
        const payload = {};
        Array.from(opportunityForm.elements).forEach((field) => {
            if (!field || typeof field.name !== 'string' || field.name === '' || field.type === 'file') {
                return;
            }
            if (field.name === 'csrf_token' || field.name === 'documents[]') {
                return;
            }
            if ((field.type === 'checkbox' || field.type === 'radio') && !field.checked) {
                return;
            }
            payload[field.name] = field.value;
        });
        if (paymentHolderToggle) {
            payload.payment_holder_is_customer = paymentHolderToggle.checked ? '1' : '0';
        }
        if (providerSelect?.value) {
            payload.provider_id = providerSelect.value;
        }
        if (offerSelect?.value) {
            payload.offer_id = offerSelect.value;
        }
        return payload;
    };

    const applyDraftPayload = (payload) => {
        if (!payload || typeof payload !== 'object') {
            return;
        }
        Object.entries(payload).forEach(([name, value]) => {
            if (value === undefined || value === null || name === '') {
                return;
            }
            if (name === 'provider_id' && providerSelect) {
                providerSelect.dataset.selected = value;
                return;
            }
            if (name === 'offer_id' && offerSelect) {
                offerSelect.dataset.selected = value;
                return;
            }
            if (name === 'payment_holder_is_customer' && paymentHolderToggle) {
                paymentHolderToggle.checked = String(value) === '1';
                return;
            }
            const nodes = getFieldNodes(name);
            if (!nodes.length) {
                return;
            }
            nodes.forEach((node) => {
                if (node.type === 'checkbox') {
                    node.checked = String(value) === '1' || node.value === value;
                } else if (node.type === 'radio') {
                    node.checked = node.value === value;
                } else {
                    node.value = value;
                }
            });
        });
        togglePaymentHolderFields();
    };

    const saveDraft = () => {
        if (!canUseDrafts) {
            return;
        }
        const payload = collectDraftPayload();
        if (!payload) {
            return;
        }
        try {
            window.localStorage.setItem(draftStorageKey, JSON.stringify(payload));
            updateDraftStatus('Bozza salvata pochi secondi fa.', 'success');
        } catch (error) {
            updateDraftStatus('Impossibile salvare la bozza su questo dispositivo.', 'warning');
        }
    };

    const loadDraftFromStorage = () => {
        if (!canUseDrafts) {
            return;
        }
        let raw = null;
        try {
            raw = window.localStorage.getItem(draftStorageKey);
        } catch (error) {
            updateDraftStatus('Impossibile accedere alla bozza salvata.', 'warning');
            return;
        }
        if (!raw) {
            return;
        }
        let payload = null;
        try {
            payload = JSON.parse(raw);
        } catch (error) {
            window.localStorage.removeItem(draftStorageKey);
            return;
        }
        applyDraftPayload(payload);
        updateDraftStatus('Bozza ripristinata automaticamente.', 'success');
    };

    const clearDraft = (resetForm = false, statusOverride = null) => {
        if (canUseDrafts) {
            try {
                window.localStorage.removeItem(draftStorageKey);
            } catch (error) {
                // ignore
            }
        }
        if (resetForm && opportunityForm) {
            opportunityForm.reset();
            providerSelect.dataset.selected = '';
            offerSelect.dataset.selected = '';
            togglePaymentHolderFields();
            hideClientErrorSummary();
        }
        const hasOverride = statusOverride && typeof statusOverride === 'object';
        const message = hasOverride && statusOverride.message ? statusOverride.message : 'Bozza eliminata.';
        const tone = hasOverride && statusOverride.tone ? statusOverride.tone : 'muted';
        updateDraftStatus(message, tone);
    };

    if (!canUseDrafts) {
        updateDraftStatus('Il salvataggio automatico non è disponibile su questo browser. Completa il modulo senza chiudere la pagina.', 'warning');
        clearDraftButton?.setAttribute('disabled', 'disabled');
        clearDraftButton?.setAttribute('aria-disabled', 'true');
    }

    const syncDocumentAuthority = (force = false) => {
        if (!documentTypeSelect || !documentIssuedBySelect) {
            return;
        }
        const presetValue = documentAuthorityDefaults[documentTypeSelect.value];
        if (!presetValue) {
            return;
        }
        const isManual = documentIssuedBySelect.dataset.manual === 'true';
        if (isManual && !force) {
            return;
        }
        ensureSelectOption(documentIssuedBySelect, presetValue, presetValue);
        documentIssuedBySelect.value = presetValue;
        documentIssuedBySelect.dataset.manual = 'false';
    };

    const initializeDocumentAuthoritySync = () => {
        if (!documentIssuedBySelect) {
            return;
        }
        const presetValue = documentTypeSelect ? documentAuthorityDefaults[documentTypeSelect.value] : '';
        if (documentIssuedBySelect.value && presetValue && documentIssuedBySelect.value !== presetValue) {
            documentIssuedBySelect.dataset.manual = 'true';
        } else {
            documentIssuedBySelect.dataset.manual = 'false';
            if (!documentIssuedBySelect.value && presetValue) {
                syncDocumentAuthority(true);
            }
        }
        documentIssuedBySelect.addEventListener('change', () => {
            documentIssuedBySelect.dataset.manual = 'true';
        });
        documentTypeSelect?.addEventListener('change', () => {
            documentIssuedBySelect.dataset.manual = 'false';
            syncDocumentAuthority(true);
        });
    };

    const applyIstatMatch = (match) => {
        if (!match) {
            return;
        }
        updateInputValue(customerCityInput, match.nome);
        if (match.sigla) {
            updateInputValue(customerProvinceInput, match.sigla);
        }
        if (Array.isArray(match.cap) && match.cap.length === 1) {
            updateInputValue(customerPostalCodeInput, match.cap[0]);
        }
    };

    const lookupCapViaIstat = (rawValue) => {
        if (!customerPostalCodeInput) {
            return;
        }
        const sanitized = (rawValue || '').trim().toUpperCase();
        if (!sanitized) {
            setCapFeedback(defaultCapFeedbackMessage);
            return;
        }
        if (sanitized.length < 4) {
            setCapFeedback('Inserisci almeno 4 caratteri per avviare la verifica.', 'muted');
            return;
        }
        if (!window.CIEIstatLookup || typeof window.CIEIstatLookup.findByCap !== 'function') {
            setCapFeedback('Catalogo ISTAT non disponibile al momento.', 'warning');
            return;
        }
        const currentRequest = ++capLookupRequestId;
        setCapFeedback('Verifico il CAP nel catalogo ISTAT…');
        window.CIEIstatLookup.findByCap(sanitized)
            .then((matches) => {
                if (currentRequest !== capLookupRequestId) {
                    return;
                }
                if (!Array.isArray(matches) || matches.length === 0) {
                    setCapFeedback('CAP non presente nel catalogo ISTAT.', 'danger');
                    return;
                }
                if (matches.length === 1) {
                    applyIstatMatch(matches[0]);
                    setCapFeedback('Comune e provincia aggiornati con i dati ISTAT.', 'success');
                    return;
                }
                setCapFeedback('CAP associato a più comuni, seleziona quello corretto.', 'warning');
            })
            .catch(() => {
                if (currentRequest === capLookupRequestId) {
                    setCapFeedback('Impossibile completare la verifica ISTAT.', 'danger');
                }
            });
    };

    const bindCapLookup = () => {
        if (!customerPostalCodeInput) {
            return;
        }
        setCapFeedback(defaultCapFeedbackMessage);
        const debouncedLookup = debounce(() => lookupCapViaIstat(customerPostalCodeInput.value), 400);
        customerPostalCodeInput.addEventListener('input', () => {
            const currentValue = customerPostalCodeInput.value.trim();
            if (!currentValue) {
                setCapFeedback(defaultCapFeedbackMessage);
                return;
            }
            if (currentValue.length < 4) {
                setCapFeedback('Inserisci almeno 4 caratteri per avviare la verifica.', 'muted');
                return;
            }
            debouncedLookup();
        });
        customerPostalCodeInput.addEventListener('blur', () => lookupCapViaIstat(customerPostalCodeInput.value));
        if (customerPostalCodeInput.value.trim().length >= 4) {
            lookupCapViaIstat(customerPostalCodeInput.value);
        }
    };

    const refreshProviderOptions = () => {
        const category = categorySelect.value;
        providerSelect.innerHTML = '<option value="">Seleziona gestore</option>';
        offerSelect.innerHTML = '<option value="">Seleziona offerta</option>';
        if (!category || !catalog[category]) {
            return;
        }
        catalog[category].forEach((provider) => {
            const option = document.createElement('option');
            option.value = provider.id;
            option.textContent = provider.name;
            providerSelect.appendChild(option);
        });
        if (providerSelect.dataset.selected) {
            providerSelect.value = providerSelect.dataset.selected;
            if (providerSelect.value !== providerSelect.dataset.selected) {
                providerSelect.dataset.selected = '';
            }
        }
    };

    const getSelectedProvider = () => {
        const category = categorySelect.value;
        const providerId = Number(providerSelect.value);
        if (!category || !providerId || !Array.isArray(catalog[category])) {
            return null;
        }
        return catalog[category].find((entry) => Number(entry.id) === providerId) || null;
    };

    const refreshOfferOptions = () => {
        offerSelect.innerHTML = '<option value="">Seleziona offerta</option>';
        const provider = getSelectedProvider();
        if (!provider) {
            return;
        }
        provider.offers.forEach((offer) => {
            const option = document.createElement('option');
            option.value = offer.id;
            option.textContent = offer.name;
            offerSelect.appendChild(option);
        });
        if (offerSelect.dataset.selected) {
            offerSelect.value = offerSelect.dataset.selected;
            if (offerSelect.value !== offerSelect.dataset.selected) {
                offerSelect.dataset.selected = '';
            }
        }
    };

    const providerSupportsTelefoniaBollettino = (provider) => {
        if (!provider) {
            return false;
        }
        const slug = (provider.slug ?? '').toString().toLowerCase();
        const name = (provider.name ?? '').toString().toLowerCase();
        const slugMatch = slug.includes('enel') && slug.includes('fibra');
        const nameMatch = name.includes('enel') && name.includes('fibra');
        return slugMatch || nameMatch;
    };

    const isBollettinoAllowed = () => {
        const category = categorySelect.value;
        if (category === 'luce' || category === 'gas') {
            return true;
        }
        if (category === 'telefonia') {
            return providerSupportsTelefoniaBollettino(getSelectedProvider());
        }
        return false;
    };

    const updatePaymentMethodOptions = () => {
        if (!paymentMethodSelect) {
            return;
        }
        const bollettinoAllowed = isBollettinoAllowed();
        if (paymentMethodBollettinoOption) {
            paymentMethodBollettinoOption.hidden = !bollettinoAllowed;
            paymentMethodBollettinoOption.disabled = !bollettinoAllowed;
        }
        if (!bollettinoAllowed && paymentMethodSelect.value === 'bollettino') {
            paymentMethodSelect.value = 'iban';
        }
    };

    const toggleCategorySections = () => {
        const category = categorySelect.value;
        telefoniaSection.hidden = category !== 'telefonia';
        luceSection.hidden = category !== 'luce';
        gasSection.hidden = category !== 'gas';
    };

    const togglePaymentHolderFields = () => {
        paymentHolderFields.hidden = paymentHolderToggle.checked;
    };

    const handlePaymentMethodChange = () => {
        if (paymentMethodSelect.value === 'iban') {
            paymentIbanField.disabled = false;
            paymentIbanField.required = true;
        } else {
            paymentIbanField.value = '';
            paymentIbanField.disabled = true;
            paymentIbanField.required = false;
        }
    };

    const renderFileList = () => {
        dropzoneFiles.innerHTML = '';
        Array.from(documentsInput.files).forEach((file, index) => {
            const entry = document.createElement('div');
            entry.className = 'dropzone-file-entry';
            entry.innerHTML = `
                <div class="file-meta">
                    <strong>${file.name}</strong>
                    <small>${(file.size / 1024).toFixed(1)} KB</small>
                </div>
                <div class="file-actions">
                    <button type="button" data-index="${index}" aria-label="Rimuovi"><i class="fa-solid fa-times"></i></button>
                </div>
            `;
            entry.querySelector('button')?.addEventListener('click', (event) => {
                const target = event.currentTarget;
                const removeIndex = Number(target.getAttribute('data-index'));
                const dt = new DataTransfer();
                Array.from(documentsInput.files).forEach((fileItem, idx) => {
                    if (idx !== removeIndex) {
                        dt.items.add(fileItem);
                    }
                });
                documentsInput.files = dt.files;
                renderFileList();
            });
            dropzoneFiles.appendChild(entry);
        });
    };

    const debouncedSaveDraft = debounce(() => {
        saveDraft();
    }, 800);

    const handleFormMutationForDraft = (event) => {
        if (!event) {
            return;
        }
        if (clientErrorSummary && !clientErrorSummary.classList.contains('d-none')) {
            hideClientErrorSummary();
        }
        const target = event.target;
        if (!target || !target.name || target.type === 'file') {
            return;
        }
        if (canUseDrafts) {
            debouncedSaveDraft();
        }
    };

    if (opportunityForm) {
        ['input', 'change'].forEach((eventName) => {
            opportunityForm.addEventListener(eventName, handleFormMutationForDraft);
        });
        opportunityForm.addEventListener('submit', (event) => {
            if (!opportunityForm.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
                const invalidFields = Array.from(opportunityForm.querySelectorAll(':invalid'));
                showClientErrorSummary(invalidFields);
                if (invalidFields[0]) {
                    invalidFields[0].focus({ preventScroll: true });
                    invalidFields[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                if (typeof opportunityForm.reportValidity === 'function') {
                    opportunityForm.reportValidity();
                }
                updateDraftStatus('Completa i campi mancanti per proseguire.', 'warning');
                return;
            }
            hideClientErrorSummary();
            if (canUseDrafts) {
                clearDraft(false, {
                    message: 'Invio in corso, la bozza salvata è stata rimossa.',
                    tone: 'success',
                });
            }
        });
    }

    const setLookupLoading = (loading) => {
        if (!taxCodeLookupBtn) {
            return;
        }
        taxCodeLookupBtn.disabled = loading;
        if (taxCodeLookupSpinner) {
            taxCodeLookupSpinner.classList.toggle('d-none', !loading);
        }
        if (taxCodeLookupLabel) {
            taxCodeLookupLabel.textContent = loading ? 'Ricerca…' : 'Recupera';
        }
    };

    const showLookupMessage = (message, state = 'muted') => {
        if (!taxCodeLookupFeedback) {
            return;
        }
        taxCodeLookupFeedback.textContent = message;
        taxCodeLookupFeedback.className = 'form-text' + (state === 'danger' ? ' text-danger' : (state === 'success' ? ' text-success' : '')); 
    };

    const renderPrefillDetails = (data) => {
        if (!taxCodePrefillDetails) {
            return;
        }
        const rows = [];
        const displayMap = {
            'Nome': `${data.customer_first_name ?? ''} ${data.customer_last_name ?? ''}`.trim(),
            'Codice fiscale': data.customer_tax_code ?? '',
            'Telefono': data.customer_phone ?? '',
            'Email': data.customer_email ?? '',
            'Indirizzo': data.customer_address ?? '',
            'Città': data.customer_city ?? '',
            'CAP': data.customer_postal_code ?? '',
            'Provincia': data.customer_province ?? '',
            'Documento': [data.document_type, data.document_number].filter(Boolean).join(' · '),
            'Operatore telefonia': data.telefonia_current_operator ?? '',
            'POD': data.luce_pod ?? '',
            'PDR': data.gas_pdr ?? '',
        };
        Object.entries(displayMap).forEach(([label, value]) => {
            if (!value) {
                return;
            }
            rows.push(`<tr><th class="text-muted" style="width: 180px;">${escapeHtml(label)}</th><td>${escapeHtml(value)}</td></tr>`);
        });
        taxCodePrefillDetails.innerHTML = rows.length ? rows.join('') : '<tr><td colspan="2" class="text-muted">Nessun dettaglio disponibile.</td></tr>';
    };

    const applyPrefillData = () => {
        if (!pendingPrefillData) {
            return;
        }
        if (!opportunityForm) {
            return;
        }
        const map = {
            customer_first_name: 'customer_first_name',
            customer_last_name: 'customer_last_name',
            customer_tax_code: 'customer_tax_code',
            customer_birth_date: 'customer_birth_date',
            customer_birth_place: 'customer_birth_place',
            customer_phone: 'customer_phone',
            customer_email: 'customer_email',
            customer_address: 'customer_address',
            customer_city: 'customer_city',
            customer_postal_code: 'customer_postal_code',
            customer_province: 'customer_province',
            document_type: 'document_type',
            document_number: 'document_number',
            document_issued_by: 'document_issued_by',
            document_issued_at: 'document_issued_at',
            document_expires_at: 'document_expires_at',
            telefonia_current_operator: 'telefonia_current_operator',
            telefonia_line_number: 'telefonia_line_number',
            luce_pod: 'luce_pod',
            gas_pdr: 'gas_pdr',
            payment_method: 'payment_method',
            payment_iban: 'payment_iban',
            payment_holder_first_name: 'payment_holder_first_name',
            payment_holder_last_name: 'payment_holder_last_name',
            payment_holder_tax_code: 'payment_holder_tax_code',
        };
        Object.entries(map).forEach(([sourceKey, fieldName]) => {
            const value = pendingPrefillData[sourceKey] ?? '';
            const field = opportunityForm.elements.namedItem(fieldName);
            if (field && typeof field.value !== 'undefined' && value !== '') {
                field.value = value;
            }
        });
        const paymentMethodValue = pendingPrefillData.payment_method;
        const paymentMethodField = opportunityForm.elements.namedItem('payment_method');
        if (paymentMethodValue && paymentMethodField && typeof paymentMethodField.value !== 'undefined') {
            paymentMethodField.value = paymentMethodValue;
        }
        const holderIsCustomer = Number(pendingPrefillData.payment_holder_is_customer ?? 1) === 1;
        if (paymentHolderToggle) {
            paymentHolderToggle.checked = holderIsCustomer;
            togglePaymentHolderFields();
        }
        let documentTypeChanged = false;
        if (documentTypeSelect && pendingPrefillData.document_type) {
            ensureSelectOption(documentTypeSelect, pendingPrefillData.document_type, pendingPrefillData.document_type);
            if (documentTypeSelect.value !== pendingPrefillData.document_type) {
                documentTypeSelect.value = pendingPrefillData.document_type;
                documentTypeChanged = true;
            }
        }
        if (documentIssuedBySelect) {
            if (pendingPrefillData.document_issued_by) {
                ensureSelectOption(documentIssuedBySelect, pendingPrefillData.document_issued_by, pendingPrefillData.document_issued_by);
                documentIssuedBySelect.value = pendingPrefillData.document_issued_by;
                documentIssuedBySelect.dataset.manual = 'true';
            } else if (documentTypeChanged || documentIssuedBySelect.value === '') {
                documentIssuedBySelect.dataset.manual = 'false';
                syncDocumentAuthority(true);
            }
        }
        updatePaymentMethodOptions();
        handlePaymentMethodChange();
        showLookupMessage('Dati precompilati dal precedente invio.', 'success');
    };

    const lookupTaxCode = async (auto = false) => {
        if (!taxCodeInput) {
            return;
        }
        const rawValue = taxCodeInput.value.trim().toUpperCase();
        if (rawValue === '' || rawValue.length < 11) {
            if (!auto) {
                showLookupMessage('Inserisci un codice fiscale valido per avviare la ricerca.', 'danger');
            }
            return;
        }
        if (auto && rawValue === lastLookupTaxCode) {
            return;
        }
        lastLookupTaxCode = rawValue;
        setLookupLoading(true);
        showLookupMessage('Ricerca del cliente in corso…');
        try {
            const response = await fetch('<?php echo asset('modules/opportunities/collaborator/customer_lookup.php'); ?>', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams({
                    tax_code: rawValue,
                    csrf_token: csrfToken,
                }),
            });
            const data = await response.json();
            if (!data.success) {
                showLookupMessage(data.message || 'Nessun cliente trovato.', 'danger');
                lastLookupTaxCode = '';
                return;
            }
            pendingPrefillData = data.customer || null;
            if (pendingPrefillData) {
                renderPrefillDetails(pendingPrefillData);
                if (taxCodePrefillModal) {
                    taxCodePrefillModal.show();
                }
                showLookupMessage('Dati trovati. Conferma dal riepilogo per applicarli.', 'success');
            }
        } catch (error) {
            console.error(error);
            showLookupMessage('Errore durante la ricerca. Riprova tra qualche istante.', 'danger');
            lastLookupTaxCode = '';
        } finally {
            setLookupLoading(false);
        }
    };

    taxCodeLookupBtn?.addEventListener('click', () => lookupTaxCode(false));
    taxCodeInput?.addEventListener('blur', () => lookupTaxCode(true));
    taxCodePrefillApply?.addEventListener('click', () => {
        applyPrefillData();
        if (taxCodePrefillModal) {
            taxCodePrefillModal.hide();
        }
    });

    dropzoneArea.addEventListener('click', () => documentsInput.click());
    dropzoneArea.addEventListener('dragover', (event) => {
        event.preventDefault();
        dropzoneArea.classList.add('dragover');
    });
    dropzoneArea.addEventListener('dragleave', () => dropzoneArea.classList.remove('dragover'));
    dropzoneArea.addEventListener('drop', (event) => {
        event.preventDefault();
        dropzoneArea.classList.remove('dragover');
        const dt = new DataTransfer();
        Array.from(documentsInput.files).forEach((file) => dt.items.add(file));
        Array.from(event.dataTransfer?.files || []).forEach((file) => dt.items.add(file));
        documentsInput.files = dt.files;
        renderFileList();
    });
    documentsInput.addEventListener('change', renderFileList);
    bindCapLookup();

    if (!hasSubmitted) {
        loadDraftFromStorage();
    }

    clearDraftButton?.addEventListener('click', () => {
        clearDraft(true);
        renderFileList();
        refreshProviderOptions();
        refreshOfferOptions();
        toggleCategorySections();
        updatePaymentMethodOptions();
        handlePaymentMethodChange();
    });

    categorySelect.addEventListener('change', () => {
        refreshProviderOptions();
        refreshOfferOptions();
        toggleCategorySections();
        updatePaymentMethodOptions();
        handlePaymentMethodChange();
    });
    providerSelect.addEventListener('change', () => {
        refreshOfferOptions();
        updatePaymentMethodOptions();
        handlePaymentMethodChange();
    });
    paymentHolderToggle.addEventListener('change', togglePaymentHolderFields);
    paymentMethodSelect.addEventListener('change', handlePaymentMethodChange);

    refreshProviderOptions();
    refreshOfferOptions();
    toggleCategorySections();
    updatePaymentMethodOptions();
    togglePaymentHolderFields();
    handlePaymentMethodChange();
    renderFileList();
    initializeDocumentAuthoritySync();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
