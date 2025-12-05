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
            </div>
            <a class="btn btn-outline-secondary" href="<?php echo asset('modules/opportunities/collaborator/index.php'); ?>">
                <i class="fa-solid fa-arrow-left me-2"></i>Torna alla lista
            </a>
        </div>

        <?php foreach ($errors as $message): ?>
            <div class="alert alert-warning" role="alert"><?php echo sanitize_output($message); ?></div>
        <?php endforeach; ?>

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
                                <label class="form-label">Codice fiscale</label>
                                <input class="form-control" type="text" name="customer_tax_code" required value="<?php echo sanitize_output($formData['customer_tax_code'] ?? ''); ?>">
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
                                <label class="form-label">Città</label>
                                <input class="form-control" type="text" name="customer_city" value="<?php echo sanitize_output($formData['customer_city'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">CAP</label>
                                <input class="form-control" type="text" name="customer_postal_code" value="<?php echo sanitize_output($formData['customer_postal_code'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Provincia</label>
                                <input class="form-control" type="text" name="customer_province" value="<?php echo sanitize_output($formData['customer_province'] ?? ''); ?>">
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
                                <label class="form-label">Tipologia</label>
                                <input class="form-control" type="text" name="document_type" required value="<?php echo sanitize_output($formData['document_type'] ?? 'Carta d\'identità'); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Numero</label>
                                <input class="form-control" type="text" name="document_number" required value="<?php echo sanitize_output($formData['document_number'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Autorità rilascio</label>
                                <input class="form-control" type="text" name="document_issued_by" value="<?php echo sanitize_output($formData['document_issued_by'] ?? ''); ?>">
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
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<script>
    const catalog = <?php echo json_encode($catalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
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

    const refreshOfferOptions = () => {
        const category = categorySelect.value;
        const providerId = Number(providerSelect.value);
        offerSelect.innerHTML = '<option value="">Seleziona offerta</option>';
        if (!category || !providerId) {
            return;
        }
        const provider = catalog[category]?.find((entry) => Number(entry.id) === providerId);
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

    const toggleCategorySections = () => {
        const category = categorySelect.value;
        telefoniaSection.hidden = category !== 'telefonia';
        luceSection.hidden = category !== 'luce';
        gasSection.hidden = category !== 'gas';
        if (category === 'telefonia') {
            paymentMethodSelect.value = 'iban';
            paymentMethodSelect.disabled = true;
            paymentIbanField.required = true;
        } else {
            paymentMethodSelect.disabled = false;
        }
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

    categorySelect.addEventListener('change', () => {
        refreshProviderOptions();
        toggleCategorySections();
    });
    providerSelect.addEventListener('change', refreshOfferOptions);
    paymentHolderToggle.addEventListener('change', togglePaymentHolderFields);
    paymentMethodSelect.addEventListener('change', handlePaymentMethodChange);

    refreshProviderOptions();
    toggleCategorySections();
    togglePaymentHolderFields();
    handlePaymentMethodChange();
    renderFileList();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
