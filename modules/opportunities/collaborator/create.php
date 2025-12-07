<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/mailer.php';

use App\Services\Opportunities\OpportunityUploadStorage;
use App\Services\Payments\StripeBankValidator;

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';

$editOpportunityId = isset($_GET['edit_id']) ? (int) $_GET['edit_id'] : (isset($_POST['edit_id']) ? (int) $_POST['edit_id'] : 0);
$isEditingOpportunity = $editOpportunityId > 0;
$pageTitle = $isEditingOpportunity ? 'Modifica Opportunity' : 'Nuova Opportunity';
$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$catalog = $opportunityService->getProviderCatalog();
$errors = [];
$formData = $_POST;
$hasSubmitted = $_SERVER['REQUEST_METHOD'] === 'POST';
$telefoniaContractTypeValue = isset($formData['telefonia_contract_type']) ? (string) $formData['telefonia_contract_type'] : 'migrazione';
$draftStorageKey = 'opportunity_collaborator_draft_' . $collaboratorId;
$existingUploadTokens = [];
$existingUploadTokensValue = '';
$extractUploadTokens = static function ($rawValue): array {
    if ($rawValue === null) {
        return [];
    }
    if (is_array($rawValue)) {
        $tokens = $rawValue;
    } else {
        $rawString = trim((string) $rawValue);
        if ($rawString === '') {
            return [];
        }
        $tokens = null;
        if ($rawString[0] === '[') {
            $decoded = json_decode($rawString, true);
            if (is_array($decoded)) {
                $tokens = $decoded;
            }
        }
        if ($tokens === null) {
            $tokens = explode(',', $rawString);
        }
    }
    $tokens = array_map(static fn ($token): string => trim((string) $token), $tokens);
    $tokens = array_filter($tokens, static fn (string $token): bool => $token !== '');

    return array_values(array_unique($tokens));
};

$requestedUploadTokens = $extractUploadTokens($_POST['upload_tokens_payload'] ?? null);
$existingUploadTokensValue = $requestedUploadTokens !== [] ? implode(',', $requestedUploadTokens) : '';
$missingTempUploads = [];
if ($requestedUploadTokens !== []) {
    try {
        $availableUploads = OpportunityUploadStorage::listTokens($collaboratorId);
    } catch (RuntimeException $exception) {
        $availableUploads = [];
    }
    $uploadsByToken = [];
    foreach ($availableUploads as $uploadMeta) {
        $tokenValue = (string) ($uploadMeta['token'] ?? '');
        if ($tokenValue === '') {
            continue;
        }
        $uploadsByToken[$tokenValue] = $uploadMeta;
    }
    foreach ($requestedUploadTokens as $token) {
        if ($token !== '' && isset($uploadsByToken[$token])) {
            $existingUploadTokens[] = $uploadsByToken[$token];
        }
    }
    $foundTokens = array_filter(array_map(static function (array $upload): string {
        return (string) ($upload['token'] ?? '');
    }, $existingUploadTokens), static fn (string $token): bool => $token !== '');
    $missingTempUploads = array_values(array_diff($requestedUploadTokens, $foundTokens));
}

if ($missingTempUploads && $hasSubmitted) {
    add_flash('warning', 'Alcuni allegati temporanei sono scaduti e devono essere ricaricati.');
}

$existingOpportunity = null;
if ($isEditingOpportunity) {
    $existingOpportunity = $opportunityService->findById($editOpportunityId);
    if ($existingOpportunity === null || (int) ($existingOpportunity['collaborator_id'] ?? 0) !== $collaboratorId) {
        add_flash('warning', 'Opportunity non trovata o non di tua proprietà.');
        header('Location: list.php');
        exit;
    }
    if (($existingOpportunity['status_code'] ?? '') !== 'in_verifica') {
        add_flash('warning', 'La opportunity non è modificabile in questo stato.');
        header('Location: view.php?id=' . $editOpportunityId);
        exit;
    }
    $existingMetadata = [];
    if (!empty($existingOpportunity['metadata'])) {
        $decodedMeta = json_decode((string) $existingOpportunity['metadata'], true);
        if (is_array($decodedMeta)) {
            $existingMetadata = $decodedMeta;
        }
    }
    $telefoniaContractTypeValue = isset($formData['telefonia_contract_type'])
        ? (string) $formData['telefonia_contract_type']
        : (string) ($existingMetadata['telefonia_contract_type'] ?? $telefoniaContractTypeValue);
    if (!$hasSubmitted) {
        $formData = [
            'category' => $existingOpportunity['category'] ?? '',
            'provider_id' => $existingOpportunity['provider_id'] ?? '',
            'offer_id' => $existingOpportunity['offer_id'] ?? '',
            'customer_first_name' => $existingOpportunity['customer_first_name'] ?? '',
            'customer_last_name' => $existingOpportunity['customer_last_name'] ?? '',
            'customer_tax_code' => $existingOpportunity['customer_tax_code'] ?? '',
            'customer_birth_date' => $existingOpportunity['customer_birth_date'] ?? '',
            'customer_birth_place' => $existingOpportunity['customer_birth_place'] ?? '',
            'customer_phone' => $existingOpportunity['customer_phone'] ?? '',
            'customer_email' => $existingOpportunity['customer_email'] ?? '',
            'customer_address' => $existingOpportunity['customer_address'] ?? '',
            'customer_city' => $existingOpportunity['customer_city'] ?? '',
            'customer_postal_code' => $existingOpportunity['customer_postal_code'] ?? '',
            'customer_province' => $existingOpportunity['customer_province'] ?? '',
            'document_type' => $existingOpportunity['document_type'] ?? "Carta d'identità",
            'document_number' => $existingOpportunity['document_number'] ?? '',
            'document_issued_by' => $existingOpportunity['document_issued_by'] ?? '',
            'document_issued_at' => $existingOpportunity['document_issued_at'] ?? '',
            'document_expires_at' => $existingOpportunity['document_expires_at'] ?? '',
            'telefonia_current_operator' => $existingOpportunity['telefonia_current_operator'] ?? '',
            'telefonia_line_number' => $existingOpportunity['telefonia_line_number'] ?? '',
            'telefonia_contract_type' => $telefoniaContractTypeValue,
            'telefonia_migration_code' => $existingMetadata['telefonia_migration_code'] ?? '',
            'luce_pod' => $existingOpportunity['luce_pod'] ?? '',
            'gas_pdr' => $existingOpportunity['gas_pdr'] ?? '',
            'payment_method' => $existingOpportunity['payment_method'] ?? 'iban',
            'payment_iban' => $existingOpportunity['payment_iban'] ?? '',
            'payment_holder_is_customer' => ($existingOpportunity['payment_holder_is_customer'] ?? 1) ? '1' : '0',
            'payment_holder_first_name' => $existingOpportunity['payment_holder_first_name'] ?? '',
            'payment_holder_last_name' => $existingOpportunity['payment_holder_last_name'] ?? '',
            'payment_holder_tax_code' => $existingOpportunity['payment_holder_tax_code'] ?? '',
            'additional_notes' => $existingOpportunity['additional_notes'] ?? '',
        ];
    }
}

$telefoniaContractTypeValue = $telefoniaContractTypeValue ?: 'migrazione';

$documentTypePresets = [
    "Carta d'identità" => 'Comune',
    'Passaporto' => 'Ministero Affari Esteri',
    'Patente' => 'MIT UCO Motorizzazione',
];
$isCloningOpportunity = false;
$clonedOpportunityMeta = null;
if (!$hasSubmitted) {
    $cloneId = isset($_GET['clone_id']) ? (int) $_GET['clone_id'] : 0;
    if ($cloneId > 0) {
        $clonePayload = $opportunityService->getCollaboratorClonePayload($cloneId, $collaboratorId);
        if ($clonePayload) {
            $formData = array_merge($clonePayload['form'] ?? [], $formData);
            $clonedOpportunityMeta = $clonePayload['meta'] ?? null;
            $isCloningOpportunity = true;
        } else {
            add_flash('warning', 'Non è stato possibile duplicare la opportunity selezionata oppure non ti appartiene.');
        }
    }
}
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
        if (isset($_POST['customer_tax_code'])) {
            $_POST['customer_tax_code'] = strtoupper((string) $_POST['customer_tax_code']);
        }
        if (isset($_POST['payment_iban'])) {
            $_POST['payment_iban'] = strtoupper(str_replace(' ', '', (string) $_POST['payment_iban']));
        }

        $stripeIbanValidation = null;
        $paymentMethod = $_POST['payment_method'] ?? 'iban';
        $paymentIban = $_POST['payment_iban'] ?? '';
        if ($paymentMethod === 'iban' && $paymentIban !== '') {
            $holderIsCustomer = ($_POST['payment_holder_is_customer'] ?? '1') === '1';
            $holderFirst = $holderIsCustomer ? ($_POST['customer_first_name'] ?? '') : ($_POST['payment_holder_first_name'] ?? '');
            $holderLast = $holderIsCustomer ? ($_POST['customer_last_name'] ?? '') : ($_POST['payment_holder_last_name'] ?? '');
            $holderName = trim(trim((string) $holderFirst) . ' ' . trim((string) $holderLast));
            $holderEmail = (string) ($_POST['customer_email'] ?? '');

            $ibanValidator = new StripeBankValidator();
            $stripeIbanValidation = $ibanValidator->validateIban((string) $paymentIban, $holderName, $holderEmail !== '' ? $holderEmail : null);
            // Facoltativo: disponibile per logging o audit
            $_POST['payment_iban_stripe_pm_id'] = $stripeIbanValidation['payment_method_id'];
            add_flash('success', 'IBAN validato con Stripe: banca ' . sanitize_output($stripeIbanValidation['bank_code'] ?? 'n/d') . ', codice ' . sanitize_output($stripeIbanValidation['last4'] ?? 'xxxx'));
        }
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
        header('Location: list.php');
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
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <h1 class="h4 mb-0"><?php echo $isEditingOpportunity ? 'Modifica opportunity' : 'Nuova opportunity'; ?></h1>
                    <?php if ($isEditingOpportunity): ?>
                        <span class="badge bg-warning text-dark">Riaperta per rettifica</span>
                    <?php endif; ?>
                </div>
                <p class="text-muted mb-0"><?php echo $isEditingOpportunity ? 'Aggiorna i dati richiesti dall\'admin prima della nuova verifica.' : 'Registra contratti telefonici, luce e gas per l\'approvazione dell\'admin.'; ?></p>

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
            <a class="btn btn-outline-secondary" href="<?php echo $isEditingOpportunity ? asset('modules/opportunities/collaborator/view.php?id=' . $editOpportunityId) : asset('modules/opportunities/collaborator/index.php'); ?>">
                <i class="fa-solid fa-arrow-left me-2"></i>Torna alla lista
            </a>
        </div>

        <?php if ($isEditingOpportunity): ?>
            <div class="alert alert-info d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3" role="status">
                        $_POST['payment_iban_stripe_bank_code'] = $stripeIbanValidation['bank_code'] ?? null;
                        $_POST['payment_iban_stripe_last4'] = $stripeIbanValidation['last4'] ?? null;
                <div>
                    <strong>Rettifica richiesta</strong>

                    if ($isEditingOpportunity) {
                        $opportunity = $opportunityService->updateOpportunityByCollaborator($editOpportunityId, $collaboratorId, $_POST, $_FILES['documents'] ?? []);
                        add_flash('success', 'Opportunity aggiornata e rimessa in verifica.');
                        header('Location: view.php?id=' . $editOpportunityId);
                        exit;
                    }
                    <p class="mb-0">Aggiorna i dati segnalati e salva: l'admin riesaminerà la pratica dopo l'invio.</p>
                </div>
                <div class="text-muted small">Opportunity #<?php echo (int) $editOpportunityId; ?></div>
            </div>
        <?php endif; ?>

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

        <?php if ($isCloningOpportunity && $clonedOpportunityMeta): ?>
            <div class="alert alert-warning d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3" role="status">
                <div>
                    <strong>Duplicazione attiva</strong>
                    <p class="mb-0 small">
                        Origine: <?php echo sanitize_output($clonedOpportunityMeta['code'] ?? 'N/D'); ?>
                        <?php if (!empty($clonedOpportunityMeta['provider_label'])): ?> · Gestore: <?php echo sanitize_output($clonedOpportunityMeta['provider_label']); ?><?php endif; ?>
                        <?php $cloneCreatedLabel = format_datetime_locale($clonedOpportunityMeta['created_at'] ?? null); ?>
                        <?php if ($cloneCreatedLabel): ?> · Inviata il <?php echo sanitize_output($cloneCreatedLabel); ?><?php endif; ?>.
                        Ricorda di caricare nuovamente gli allegati.
                    </p>
                </div>
                <a class="btn btn-outline-secondary btn-sm" href="<?php echo asset('modules/opportunities/collaborator/create.php'); ?>">
                    <i class="fa-solid fa-arrow-rotate-left me-1"></i>Annulla duplicazione
                </a>
            </div>
        <?php endif; ?>

        <div class="alert alert-secondary d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3" role="status">
            <div>
                <strong>Salvataggio automatico bozza</strong>
                <p class="mb-1 small" id="draft-status-label">Ultimo salvataggio: —</p>
                <p class="mb-0 small text-muted" id="remote-draft-status-label">Bozza cloud non ancora salvata.</p>
            </div>
            <div class="d-flex flex-column flex-md-row gap-2">
                <button class="btn btn-outline-secondary btn-sm" type="button" id="clear-draft-button">
                    <i class="fa-solid fa-eraser me-1"></i>Svuota bozza locale
                </button>
                <button class="btn btn-outline-primary btn-sm d-none" type="button" id="restore-remote-draft-button">
                    <i class="fa-solid fa-cloud-arrow-down me-1"></i>Ripristina bozza cloud
                </button>
                <button class="btn btn-outline-danger btn-sm d-none" type="button" id="clear-remote-draft-button">
                    <i class="fa-solid fa-cloud-xmark me-1"></i>Elimina bozza cloud
                </button>
            </div>
        </div>

        <form class="row g-4" method="post" enctype="multipart/form-data" id="opportunity-form">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
            <input type="hidden" name="edit_id" value="<?php echo $isEditingOpportunity ? (int) $editOpportunityId : 0; ?>">
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
                                    <input class="form-control" type="text" name="customer_tax_code" id="customer-tax-code" required value="<?php echo sanitize_output($formData['customer_tax_code'] ?? ''); ?>" placeholder="RSSMRA90A01H501U" style="text-transform: uppercase;">
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
                        <div class="alert alert-warning d-none" role="alert" aria-live="polite" id="telefonia-section-warning">
                            <i class="fa-solid fa-circle-exclamation me-1"></i>
                            <span class="warning-text">Compila i campi obbligatori della sezione telefonia.</span>
                        </div>
                        <div class="row g-3 align-items-end mb-2">
                            <div class="col-md-4">
                                <label class="form-label">Tipologia contratto</label>
                                <select class="form-select" name="telefonia_contract_type" id="telefonia-contract-type">
                                    <option value="migrazione" <?php echo ($formData['telefonia_contract_type'] ?? 'migrazione') === 'migrazione' ? 'selected' : ''; ?>>Migrazione (con numero e operatore attuale)</option>
                                    <option value="nuova_attivazione" <?php echo ($formData['telefonia_contract_type'] ?? 'migrazione') === 'nuova_attivazione' ? 'selected' : ''; ?>>Nuova attivazione</option>
                                </select>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4" id="telefonia-current-operator-wrapper">
                                <label class="form-label">Operatore attuale</label>
                                <input class="form-control" type="text" name="telefonia_current_operator" id="telefonia-current-operator" value="<?php echo sanitize_output($formData['telefonia_current_operator'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4" id="telefonia-line-number-wrapper">
                                <label class="form-label">Numero linea</label>
                                <input class="form-control" type="text" name="telefonia_line_number" id="telefonia-line-number" value="<?php echo sanitize_output($formData['telefonia_line_number'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4" id="telefonia-migration-code-wrapper">
                                <label class="form-label">Codice di migrazione</label>
                                <input class="form-control" type="text" name="telefonia_migration_code" id="telefonia-migration-code" value="<?php echo sanitize_output($formData['telefonia_migration_code'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12" id="luce-section" hidden>
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Dettagli luce</h2>
                        <div class="alert alert-warning d-none" role="alert" aria-live="polite" id="luce-section-warning">
                            <i class="fa-solid fa-circle-exclamation me-1"></i>
                            <span class="warning-text">Compila i campi obbligatori della sezione luce.</span>
                        </div>
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
                        <div class="alert alert-warning d-none" role="alert" aria-live="polite" id="gas-section-warning">
                            <i class="fa-solid fa-circle-exclamation me-1"></i>
                            <span class="warning-text">Compila i campi obbligatori della sezione gas.</span>
                        </div>
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
                                <input class="form-control" type="text" name="payment_iban" id="payment-iban-field" value="<?php echo sanitize_output($formData['payment_iban'] ?? ''); ?>" maxlength="34" pattern="[A-Za-z0-9]{15,34}" title="Inserisci un IBAN valido senza spazi (15-34 caratteri)." style="text-transform: uppercase;">
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
                            <p class="text-muted small mb-0">Documenti accettati: PDF, JPG, PNG, ZIP (max 10MB ciascuno). Mostriamo progresso e puoi riprovare in caso di errore.</p>
                            <input class="d-none" type="file" name="documents[]" id="documents-input" multiple>
                        </div>
                        <input type="hidden" name="upload_tokens_payload" id="upload-tokens-field" value="<?php echo sanitize_output($existingUploadTokensValue); ?>">
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
    const isCloning = <?php echo $isCloningOpportunity ? 'true' : 'false'; ?>;
    const draftStorageKey = '<?php echo sanitize_output($draftStorageKey); ?>';
    const categorySelect = document.getElementById('category-select');
    const providerSelect = document.getElementById('provider-select');
    const offerSelect = document.getElementById('offer-select');
    const telefoniaSection = document.getElementById('telefonia-section');
    const telefoniaContractTypeSelect = document.getElementById('telefonia-contract-type');
    const telefoniaCurrentOperatorInput = document.getElementById('telefonia-current-operator');
    const telefoniaLineNumberInput = document.getElementById('telefonia-line-number');
    const telefoniaMigrationCodeInput = document.getElementById('telefonia-migration-code');
    const telefoniaCurrentOperatorWrapper = document.getElementById('telefonia-current-operator-wrapper');
    const telefoniaLineNumberWrapper = document.getElementById('telefonia-line-number-wrapper');
    const telefoniaMigrationCodeWrapper = document.getElementById('telefonia-migration-code-wrapper');
    const luceSection = document.getElementById('luce-section');
    const gasSection = document.getElementById('gas-section');
    const paymentMethodSelect = document.getElementById('payment-method-select');
    const paymentIbanField = document.getElementById('payment-iban-field');
    const paymentHolderToggle = document.getElementById('payment-holder-toggle');
    const paymentHolderFields = document.getElementById('payment-holder-fields');
    const dropzoneArea = document.getElementById('dropzone-area');
    const documentsInput = document.getElementById('documents-input');
    const dropzoneFiles = document.getElementById('dropzone-files');
    const uploadTokensField = document.getElementById('upload-tokens-field');
    const uploadEndpoint = "<?php echo sanitize_output(asset('api/opportunities/uploads.php')); ?>";
    const existingUploads = <?php echo json_encode($existingUploadTokens, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
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
    const remoteDraftStatusLabel = document.getElementById('remote-draft-status-label');
    const restoreRemoteDraftButton = document.getElementById('restore-remote-draft-button');
    const clearRemoteDraftButton = document.getElementById('clear-remote-draft-button');
    const remoteDraftEndpoint = "<?php echo sanitize_output(asset('api/opportunities/drafts.php')); ?>";
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
    const canUseServerDrafts = Boolean(remoteDraftEndpoint);
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
    let lastDraftSavedAt = null;
    let remoteDraftAvailable = false;
    let remoteDraftPayload = null;
    let remoteDraftSavedAt = null;
    let remoteDraftSaving = false;

    if (paymentIbanField) {
        paymentIbanField.addEventListener('input', () => {
            const { selectionStart, selectionEnd, value } = paymentIbanField;
            const upper = value.toUpperCase();
            if (value !== upper) {
                paymentIbanField.value = upper;
                if (selectionStart !== null && selectionEnd !== null) {
                    paymentIbanField.setSelectionRange(selectionStart, selectionEnd);
                }
            }
        });
    }

    if (taxCodeInput) {
        taxCodeInput.addEventListener('input', () => {
            const { selectionStart, selectionEnd, value } = taxCodeInput;
            const upper = value.toUpperCase();
            if (value !== upper) {
                taxCodeInput.value = upper;
                if (selectionStart !== null && selectionEnd !== null) {
                    taxCodeInput.setSelectionRange(selectionStart, selectionEnd);
                }
            }
        });
    }

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

    const formatDraftTimestamp = (timestamp) => {
        if (!timestamp) {
            return '';
        }
        const date = new Date(Number(timestamp));
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        try {
            return new Intl.DateTimeFormat('it-IT', {
                hour: '2-digit',
                minute: '2-digit',
            }).format(date);
        } catch (error) {
            const hours = String(date.getHours()).padStart(2, '0');
            const minutes = String(date.getMinutes()).padStart(2, '0');
            return `${hours}:${minutes}`;
        }
    };

    const buildLastSavedMessage = (timestamp = lastDraftSavedAt) => {
        const formatted = formatDraftTimestamp(timestamp);
        return formatted ? `Ultimo salvataggio: ${formatted}` : 'Ultimo salvataggio: —';
    };

    const updateRemoteDraftStatus = (message, tone = 'muted') => {
        if (!remoteDraftStatusLabel) {
            return;
        }
        remoteDraftStatusLabel.classList.remove('text-muted', 'text-success', 'text-warning', 'text-danger');
        const toneClass = {
            muted: 'text-muted',
            success: 'text-success',
            warning: 'text-warning',
            danger: 'text-danger',
        };
        remoteDraftStatusLabel.classList.add(toneClass[tone] || 'text-muted');
        remoteDraftStatusLabel.textContent = message;
    };

    const parseServerDraftTimestamp = (value) => {
        if (!value) {
            return null;
        }
        const normalized = value.replace(' ', 'T');
        const parsed = Date.parse(normalized);
        return Number.isNaN(parsed) ? null : parsed;
    };

    const refreshRemoteDraftButtons = () => {
        const shouldShowActions = remoteDraftAvailable;
        if (restoreRemoteDraftButton) {
            restoreRemoteDraftButton.classList.toggle('d-none', !shouldShowActions);
            restoreRemoteDraftButton.disabled = !shouldShowActions;
        }
        if (clearRemoteDraftButton) {
            clearRemoteDraftButton.classList.toggle('d-none', !shouldShowActions);
            clearRemoteDraftButton.disabled = !shouldShowActions;
        }
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
            if (field.name === 'csrf_token' || field.name === 'documents[]' || field.name === 'upload_tokens_payload') {
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
            const serialized = {
                data: payload,
                savedAt: Date.now(),
            };
            window.localStorage.setItem(draftStorageKey, JSON.stringify(serialized));
            lastDraftSavedAt = serialized.savedAt;
            updateDraftStatus(buildLastSavedMessage(), 'success');
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
        let fields = payload;
        let savedAt = null;
        if (payload && typeof payload === 'object' && payload.data) {
            fields = payload.data;
            savedAt = payload.savedAt ?? payload.meta?.savedAt ?? null;
        }
        if (!fields || typeof fields !== 'object') {
            return;
        }
        applyDraftPayload(fields);
        lastDraftSavedAt = savedAt || Date.now();
        updateDraftStatus(buildLastSavedMessage(lastDraftSavedAt), 'success');
    };

    const clearDraft = (resetForm = false, statusOverride = null) => {
        const savedAtBeforeClear = lastDraftSavedAt;
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
        let message = 'Bozza eliminata.';
        if (hasOverride && typeof statusOverride.message === 'function') {
            message = statusOverride.message(savedAtBeforeClear);
        } else if (hasOverride && typeof statusOverride.message === 'string') {
            message = statusOverride.message;
        }
        const tone = hasOverride && statusOverride.tone ? statusOverride.tone : 'muted';
        updateDraftStatus(message, tone);
        lastDraftSavedAt = null;
    };

    if (!canUseDrafts) {
        updateDraftStatus('Il salvataggio automatico non è disponibile su questo browser. Completa il modulo senza chiudere la pagina.', 'warning');
        clearDraftButton?.setAttribute('disabled', 'disabled');
        clearDraftButton?.setAttribute('aria-disabled', 'true');
    }

    // Stato iniziale visivo del salvataggio
    updateDraftStatus(buildLastSavedMessage(), 'muted');

    const describeRemoteDraftStatus = () => {
        if (!canUseServerDrafts) {
            return 'Bozza cloud non disponibile su questo account.';
        }
        if (!remoteDraftAvailable) {
            return 'Bozza cloud non ancora salvata.';
        }
        const formatted = formatDraftTimestamp(remoteDraftSavedAt);
        return formatted ? `Bozza cloud aggiornata alle ${formatted}.` : 'Bozza cloud aggiornata di recente.';
    };

    const saveRemoteDraft = async () => {
        if (!canUseServerDrafts || remoteDraftSaving) {
            return;
        }
        const payload = collectDraftPayload();
        if (!payload) {
            return;
        }
        remoteDraftSaving = true;
        try {
            const response = await fetch(remoteDraftEndpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({ data: payload }),
            });
            if (!response.ok) {
                throw new Error('Remote draft save failed');
            }
            const body = await response.json();
            const draft = body.draft ?? null;
            if (draft && typeof draft === 'object' && draft.data && typeof draft.data === 'object') {
                remoteDraftPayload = draft.data;
                remoteDraftSavedAt = parseServerDraftTimestamp(draft.saved_at ?? null) ?? Date.now();
            } else {
                remoteDraftPayload = payload;
                remoteDraftSavedAt = Date.now();
            }
            remoteDraftAvailable = true;
            updateRemoteDraftStatus(describeRemoteDraftStatus(), 'success');
            refreshRemoteDraftButtons();
        } catch (error) {
            updateRemoteDraftStatus('Non riesco a salvare la bozza cloud. Ritenterò automaticamente.', 'warning');
        } finally {
            remoteDraftSaving = false;
        }
    };

    const fetchRemoteDraft = async () => {
        if (!canUseServerDrafts) {
            return;
        }
        try {
            const response = await fetch(remoteDraftEndpoint, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!response.ok) {
                throw new Error('Remote draft fetch failed');
            }
            const body = await response.json();
            const draft = body.draft ?? null;
            if (draft && typeof draft === 'object' && draft.data && typeof draft.data === 'object') {
                remoteDraftPayload = draft.data;
                remoteDraftSavedAt = parseServerDraftTimestamp(draft.saved_at ?? null) ?? Date.now();
                remoteDraftAvailable = true;
                updateRemoteDraftStatus(describeRemoteDraftStatus(), 'success');
            } else {
                remoteDraftPayload = null;
                remoteDraftSavedAt = null;
                remoteDraftAvailable = false;
                updateRemoteDraftStatus('Bozza cloud non ancora salvata.', 'muted');
            }
            refreshRemoteDraftButtons();
        } catch (error) {
            remoteDraftAvailable = false;
            updateRemoteDraftStatus('Servizio bozza cloud non disponibile al momento.', 'warning');
            refreshRemoteDraftButtons();
        }
    };

    const clearRemoteDraft = async (showFeedback = true) => {
        if (!canUseServerDrafts) {
            return;
        }
        if (!remoteDraftAvailable) {
            if (showFeedback) {
                updateRemoteDraftStatus('Nessuna bozza cloud da eliminare.', 'muted');
            }
            return;
        }
        try {
            const response = await fetch(remoteDraftEndpoint, {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken,
                },
            });
            if (!response.ok) {
                throw new Error('Remote draft delete failed');
            }
            remoteDraftAvailable = false;
            remoteDraftPayload = null;
            remoteDraftSavedAt = null;
            if (showFeedback) {
                updateRemoteDraftStatus('Bozza cloud eliminata.', 'muted');
            } else {
                updateRemoteDraftStatus('Bozza cloud liberata dopo l\'invio.', 'muted');
            }
            refreshRemoteDraftButtons();
        } catch (error) {
            updateRemoteDraftStatus('Errore durante l\'eliminazione della bozza cloud.', 'danger');
        }
    };

    const applyRemoteDraft = () => {
        if (!remoteDraftPayload) {
            return;
        }
        applyDraftPayload(remoteDraftPayload);
        lastDraftSavedAt = remoteDraftSavedAt ?? Date.now();
        updateDraftStatus('Bozza cloud applicata al modulo.', 'success');
        updateRemoteDraftStatus(describeRemoteDraftStatus(), 'success');
    };

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

    const syncTelefoniaContractFields = () => {
        if (!telefoniaContractTypeSelect) {
            return;
        }
        const isTelefonia = categorySelect?.value === 'telefonia';
        const isMigration = isTelefonia && telefoniaContractTypeSelect.value === 'migrazione';
        [telefoniaCurrentOperatorInput, telefoniaLineNumberInput, telefoniaMigrationCodeInput].forEach((input) => {
            if (!input) {
                return;
            }
            const shouldRequire = isMigration;
            if (shouldRequire) {
                input.removeAttribute('disabled');
                input.setAttribute('required', 'required');
            } else {
                input.removeAttribute('required');
            }
        });
        if (telefoniaCurrentOperatorWrapper) {
            telefoniaCurrentOperatorWrapper.hidden = !isMigration;
        }
        if (telefoniaLineNumberWrapper) {
            telefoniaLineNumberWrapper.hidden = !isMigration;
        }
        if (telefoniaMigrationCodeWrapper) {
            telefoniaMigrationCodeWrapper.hidden = !isMigration;
        }
    };

    const getTelefoniaRequiredFields = () => {
        const isMigration = telefoniaContractTypeSelect?.value === 'migrazione';
        if (!isMigration) {
            return [];
        }
        return [
            { selector: 'input[name="telefonia_current_operator"]', label: 'Operatore attuale' },
            { selector: 'input[name="telefonia_line_number"]', label: 'Numero linea' },
            { selector: 'input[name="telefonia_migration_code"]', label: 'Codice di migrazione' },
        ];
    };

    const sectionValidationConfig = {
        telefonia: {
            section: telefoniaSection,
            warning: document.getElementById('telefonia-section-warning'),
            fields: [],
        },
        luce: {
            section: luceSection,
            warning: document.getElementById('luce-section-warning'),
            fields: [
                { selector: 'input[name="luce_pod"]', label: 'Codice POD' },
            ],
        },
        gas: {
            section: gasSection,
            warning: document.getElementById('gas-section-warning'),
            fields: [
                { selector: 'input[name="gas_pdr"]', label: 'Codice PDR' },
            ],
        },
    };

    const evaluateSectionValidation = () => {
        const category = categorySelect.value;
        Object.entries(sectionValidationConfig).forEach(([key, config]) => {
            if (!config.section || !config.warning) {
                return;
            }
            const shouldValidate = category === key;
            if (!shouldValidate) {
                config.warning.classList.add('d-none');
                config.section.classList.remove('border-danger');
                return;
            }
            const fields = key === 'telefonia' ? getTelefoniaRequiredFields() : config.fields;
            const missingFields = fields.filter(({ selector }) => {
                const node = opportunityForm.querySelector(selector);
                return node ? node.value.trim() === '' : false;
            });
            if (missingFields.length === 0) {
                config.warning.classList.add('d-none');
                config.section.classList.remove('border-danger');
            } else {
                const label = missingFields.map(({ label }) => label).join(', ');
                const warningText = config.warning.querySelector('.warning-text');
                if (warningText) {
                    warningText.textContent = `Completa: ${label}.`;
                }
                config.warning.classList.remove('d-none');
                config.section.classList.add('border-danger');
            }
        });
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

    const uploadConfig = {
        allowedExtensions: ['pdf', 'jpg', 'jpeg', 'png', 'zip'],
        maxFileSize: 10 * 1024 * 1024,
    };
    let uploadEntries = [];

    const formatFileSize = (bytes) => {
        if (!Number.isFinite(bytes) || bytes <= 0) {
            return '0 KB';
        }
        if (bytes >= 1024 * 1024) {
            return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
        }
        return `${(bytes / 1024).toFixed(1)} KB`;
    };

    const getCompletedTokens = () => uploadEntries
        .filter((entry) => entry.status === 'completed' && entry.token)
        .map((entry) => entry.token);

    const syncUploadTokensField = () => {
        if (!uploadTokensField) {
            return;
        }
        uploadTokensField.value = getCompletedTokens().join(',');
    };

    const renderUploadEntries = () => {
        if (!dropzoneFiles) {
            return;
        }
        dropzoneFiles.innerHTML = '';
        if (uploadEntries.length === 0) {
            const emptyState = document.createElement('p');
            emptyState.className = 'text-muted small mb-0';
            emptyState.textContent = 'Nessun allegato caricato.';
            dropzoneFiles.appendChild(emptyState);
            return;
        }
        uploadEntries.forEach((entry) => {
            const wrapper = document.createElement('div');
            wrapper.className = 'dropzone-file-entry';

            const meta = document.createElement('div');
            meta.className = 'file-meta';
            const nameNode = document.createElement('strong');
            nameNode.textContent = entry.name;
            const sizeNode = document.createElement('small');
            sizeNode.textContent = formatFileSize(entry.size);
            const statusNode = document.createElement('small');
            const statusClass = entry.status === 'completed'
                ? 'text-success'
                : entry.status === 'error'
                    ? 'text-danger'
                    : entry.status === 'uploading'
                        ? 'text-primary'
                        : 'text-muted';
            statusNode.className = `upload-status ${statusClass}`;
            statusNode.textContent = entry.status === 'completed'
                ? 'Caricato'
                : entry.status === 'uploading'
                    ? `Caricamento ${entry.progress}%`
                    : entry.status === 'error'
                        ? (entry.error || 'Upload non riuscito.')
                        : entry.status === 'canceled'
                            ? 'Upload annullato'
                            : 'In coda';
            meta.append(nameNode, sizeNode, statusNode);

            if (entry.status === 'uploading') {
                const progressBar = document.createElement('div');
                progressBar.className = 'upload-progress';
                const progressValue = document.createElement('div');
                progressValue.className = 'upload-progress-value';
                progressValue.style.width = `${entry.progress}%`;
                progressBar.appendChild(progressValue);
                meta.appendChild(progressBar);
            }

            const actions = document.createElement('div');
            actions.className = 'file-actions';
            if (entry.status === 'error' && entry.file) {
                const retryButton = document.createElement('button');
                retryButton.type = 'button';
                retryButton.classList.add('text-warning');
                retryButton.setAttribute('aria-label', 'Riprova upload');
                retryButton.innerHTML = '<i class="fa-solid fa-rotate-right"></i>';
                retryButton.addEventListener('click', () => retryUploadEntry(entry.id));
                actions.appendChild(retryButton);
            }
            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.classList.add('text-danger');
            removeButton.setAttribute('aria-label', entry.status === 'uploading' ? 'Annulla caricamento' : 'Rimuovi');
            removeButton.innerHTML = '<i class="fa-solid fa-times"></i>';
            removeButton.addEventListener('click', () => removeUploadEntry(entry.id));
            actions.appendChild(removeButton);

            wrapper.append(meta, actions);
            dropzoneFiles.appendChild(wrapper);
        });
    };

    const validateFileForUpload = (file) => {
        if (!file) {
            return 'File non valido.';
        }
        if (file.size <= 0) {
            return 'File vuoto o danneggiato.';
        }
        if (file.size > uploadConfig.maxFileSize) {
            return 'Il file supera il limite di 10MB.';
        }
        const extension = file.name.includes('.')
            ? file.name.split('.').pop().toLowerCase()
            : '';
        if (extension && !uploadConfig.allowedExtensions.includes(extension)) {
            return 'Formato non supportato.';
        }
        return null;
    };

    const deleteUploadToken = async (token) => {
        if (!token) {
            return;
        }
        try {
            await fetch(`${uploadEndpoint}?token=${encodeURIComponent(token)}` , {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({ token, _token: csrfToken }),
            });
        } catch (error) {
            console.warn('Impossibile eliminare l\'upload temporaneo', error);
        }
    };

    const removeUploadEntry = (entryId) => {
        const index = uploadEntries.findIndex((entry) => entry.id === entryId);
        if (index === -1) {
            return;
        }
        const entry = uploadEntries[index];
        if (entry.xhr && entry.status === 'uploading') {
            entry.xhr.abort();
        }
        const token = entry.token;
        uploadEntries.splice(index, 1);
        renderUploadEntries();
        syncUploadTokensField();
        if (token) {
            deleteUploadToken(token);
        }
    };

    const retryUploadEntry = (entryId) => {
        const entry = uploadEntries.find((upload) => upload.id === entryId);
        if (!entry || !entry.file) {
            return;
        }
        entry.error = null;
        entry.progress = 0;
        startUpload(entry);
    };

    const startUpload = (entry) => {
        if (!uploadEndpoint || !entry.file) {
            entry.status = 'error';
            entry.error = 'Upload non disponibile su questa pagina.';
            renderUploadEntries();
            return;
        }
        const xhr = new XMLHttpRequest();
        entry.xhr = xhr;
        entry.status = 'uploading';
        entry.progress = 0;
        renderUploadEntries();

        xhr.upload.addEventListener('progress', (event) => {
            if (!event.lengthComputable) {
                return;
            }
            entry.progress = Math.round((event.loaded / event.total) * 100);
            renderUploadEntries();
        });

        xhr.addEventListener('load', () => {
            entry.xhr = null;
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const payload = JSON.parse(xhr.responseText || '{}');
                    const upload = payload.upload ?? null;
                    if (!upload || !upload.token) {
                        throw new Error('Risposta server non valida.');
                    }
                    entry.status = 'completed';
                    entry.progress = 100;
                    entry.token = upload.token;
                    entry.file = null;
                    entry.error = null;
                    syncUploadTokensField();
                    renderUploadEntries();
                    return;
                } catch (error) {
                    entry.status = 'error';
                    entry.error = error.message || 'Upload non riuscito.';
                    renderUploadEntries();
                    return;
                }
            }
            let message = 'Upload non riuscito.';
            try {
                const errorPayload = JSON.parse(xhr.responseText || '{}');
                if (errorPayload.error) {
                    message = errorPayload.error;
                }
            } catch (error) {
                // ignore JSON parse issues
            }
            entry.status = 'error';
            entry.error = message;
            renderUploadEntries();
        });

        xhr.addEventListener('error', () => {
            entry.xhr = null;
            entry.status = 'error';
            entry.error = 'Connessione interrotta. Riprova.';
            renderUploadEntries();
        });

        xhr.addEventListener('abort', () => {
            entry.xhr = null;
            entry.status = 'canceled';
            entry.error = 'Upload annullato.';
            renderUploadEntries();
        });

        const formData = new FormData();
        formData.append('_token', csrfToken);
        formData.append('file', entry.file);
        xhr.open('POST', uploadEndpoint);
        xhr.withCredentials = true;
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('X-CSRF-Token', csrfToken);
        xhr.send(formData);
    };

    const queueFileForUpload = (file) => {
        if (!file) {
            return;
        }
        const entry = {
            id: `upload-${Date.now()}-${Math.random().toString(16).slice(2)}`,
            file,
            name: file.name,
            size: file.size,
            status: 'queued',
            progress: 0,
            token: null,
            error: null,
            xhr: null,
        };
        uploadEntries.push(entry);
        renderUploadEntries();
        const validationError = validateFileForUpload(file);
        if (validationError) {
            entry.status = 'error';
            entry.error = validationError;
            renderUploadEntries();
            return;
        }
        startUpload(entry);
    };

    const handleSelectedFiles = (files) => {
        if (!files || files.length === 0) {
            return;
        }
        Array.from(files).forEach((file) => queueFileForUpload(file));
        if (documentsInput) {
            documentsInput.value = '';
        }
    };

    const mountExistingUploads = () => {
        if (!Array.isArray(existingUploads) || existingUploads.length === 0) {
            renderUploadEntries();
            syncUploadTokensField();
            return;
        }
        uploadEntries = existingUploads.map((upload) => ({
            id: `existing-${upload.token}`,
            file: null,
            name: upload.original_name || 'Documento',
            size: Number(upload.size || 0),
            status: 'completed',
            progress: 100,
            token: upload.token,
            error: null,
            xhr: null,
        }));
        syncUploadTokensField();
        renderUploadEntries();
    };

    const clearAllUploads = (deleteRemote = false) => {
        const tokensToDelete = deleteRemote ? getCompletedTokens() : [];
        uploadEntries.forEach((entry) => {
            if (entry.xhr && entry.status === 'uploading') {
                entry.xhr.abort();
            }
        });
        uploadEntries = [];
        syncUploadTokensField();
        renderUploadEntries();
        if (deleteRemote && tokensToDelete.length) {
            tokensToDelete.forEach((token) => deleteUploadToken(token));
        }
    };

    mountExistingUploads();

    const debouncedSaveDraft = debounce(() => {
        saveDraft();
    }, 450);

    const debouncedRemoteSave = debounce(() => {
        saveRemoteDraft();
    }, 1200);

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
        if (canUseServerDrafts) {
            debouncedRemoteSave();
        }
    };

    if (opportunityForm) {
        ['input', 'change'].forEach((eventName) => {
            opportunityForm.addEventListener(eventName, handleFormMutationForDraft);
        });
        opportunityForm.addEventListener('submit', (event) => {
            evaluateSectionValidation();
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
                    message: (savedAt) => {
                        const timeLabel = formatDraftTimestamp(savedAt);
                        return timeLabel
                            ? `Invio in corso, ultimo salvataggio: ${timeLabel}. Bozza rimossa.`
                            : 'Invio in corso, la bozza salvata è stata rimossa.';
                    },
                    tone: 'success',
                });
            }
            if (canUseServerDrafts) {
                clearRemoteDraft(false);
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
            telefonia_contract_type: 'telefonia_contract_type',
            telefonia_migration_code: 'telefonia_migration_code',
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
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': csrfToken,
                },
                body: new URLSearchParams({
                    tax_code: rawValue,
                    _token: csrfToken,
                }),
            });
            const responseText = await response.text();
            let data = null;
            try {
                data = responseText ? JSON.parse(responseText) : null;
            } catch (parseError) {
                throw new Error('Risposta non valida dal server.');
            }
            if (!response.ok || !data?.success) {
                const message = data?.message || 'Nessun cliente trovato.';
                showLookupMessage(message, 'danger');
                lastLookupTaxCode = '';
                return;
            }
            pendingPrefillData = data.customer || null;
            if (pendingPrefillData) {
                renderPrefillDetails(pendingPrefillData);
                applyPrefillData();
                if (taxCodePrefillModal) {
                    taxCodePrefillModal.show();
                }
                showLookupMessage('Dati trovati e precompilati dal riepilogo.', 'success');
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
        handleSelectedFiles(event.dataTransfer?.files || null);
    });
    documentsInput.addEventListener('change', () => handleSelectedFiles(documentsInput.files));
    bindCapLookup();

    if (!hasSubmitted && !isCloning) {
        loadDraftFromStorage();
    } else if (isCloning) {
        updateDraftStatus('Stai duplicando una opportunity: il salvataggio parte dopo le prime modifiche.', 'info');
    }

    if (canUseServerDrafts) {
        fetchRemoteDraft();
    } else {
        updateRemoteDraftStatus('Bozza cloud non disponibile su questo dispositivo.', 'warning');
    }

    clearDraftButton?.addEventListener('click', () => {
        clearDraft(true);
        clearAllUploads(true);
        refreshProviderOptions();
        refreshOfferOptions();
        toggleCategorySections();
        evaluateSectionValidation();
        updatePaymentMethodOptions();
        handlePaymentMethodChange();
    });

    restoreRemoteDraftButton?.addEventListener('click', () => {
        applyRemoteDraft();
    });

    clearRemoteDraftButton?.addEventListener('click', () => {
        clearRemoteDraft(true);
    });

    categorySelect.addEventListener('change', () => {
        refreshProviderOptions();
        refreshOfferOptions();
        toggleCategorySections();
        syncTelefoniaContractFields();
        evaluateSectionValidation();
        updatePaymentMethodOptions();
        handlePaymentMethodChange();
    });
    providerSelect.addEventListener('change', () => {
        refreshOfferOptions();
        updatePaymentMethodOptions();
        handlePaymentMethodChange();
    });
    telefoniaContractTypeSelect?.addEventListener('change', () => {
        syncTelefoniaContractFields();
        evaluateSectionValidation();
    });
    paymentHolderToggle.addEventListener('change', togglePaymentHolderFields);
    paymentMethodSelect.addEventListener('change', handlePaymentMethodChange);

    refreshProviderOptions();
    refreshOfferOptions();
    toggleCategorySections();
    syncTelefoniaContractFields();
    evaluateSectionValidation();
    updatePaymentMethodOptions();
    togglePaymentHolderFields();
    handlePaymentMethodChange();
    initializeDocumentAuthoritySync();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
