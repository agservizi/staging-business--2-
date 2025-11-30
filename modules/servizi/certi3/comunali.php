<?php
require_once '../../../includes/auth.php';
require_role('Admin', 'Manager', 'Operatore');
$pageTitle = 'Certi³ - Certificati Comunali';
$csrfToken = csrf_token();

require_once '../../../includes/header.php';
require_once '../../../includes/sidebar.php';

// Carica richieste recenti
$richieste = $pdo->prepare('SELECT cr.*, u.nome, u.cognome FROM certificati_richieste cr JOIN users u ON cr.user_id = u.id WHERE cr.user_id = ? ORDER BY cr.created_at DESC LIMIT 50');
$richieste->execute([$_SESSION['user_id']]);
$richieste = $richieste->fetchAll();

// Gestione richieste POST rimossa - ora usa AJAX
?>

<div class="flex-grow-1 d-flex flex-column min-vh-100 bg-light">
    <?php require_once '../../../includes/topbar.php'; ?>

    <main class="content-wrapper">
        <!-- Header Section -->
        <div class="page-header bg-white border-bottom shadow-sm">
            <div class="container-fluid">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <div class="page-icon bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                            <i class="fa-solid fa-building fa-lg"></i>
                        </div>
                    </div>
                    <div class="col">
                        <h1 class="h2 mb-1 text-dark fw-bold">Certificati Comunali</h1>
                        <p class="text-muted mb-0">Richiesta certificati anagrafici, residenza, stato civile e altri documenti comunali</p>
                    </div>
                    <div class="col-auto">
                        <a href="index.php" class="btn btn-outline-secondary btn-lg">
                            <i class="fa-solid fa-arrow-left me-2"></i>Torna alla Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <div class="container-fluid py-4">
            <div class="row justify-content-center">
                <div class="col-xl-10 col-lg-12">
                    <!-- Progress Steps -->
                    <div class="card border-0 shadow-sm mb-4">
                        <div class="card-body p-4">
                            <div class="row text-center">
                                <div class="col-12">
                                    <div class="progress-steps d-flex justify-content-center">
                                        <div class="step active">
                                            <div class="step-circle bg-primary text-white">
                                                <i class="fa-solid fa-file-signature"></i>
                                            </div>
                                            <div class="step-label mt-2 fw-semibold">Tipo Certificato</div>
                                        </div>
                                        <div class="step-connector"></div>
                                        <div class="step">
                                            <div class="step-circle bg-light text-muted">
                                                <i class="fa-solid fa-user"></i>
                                            </div>
                                            <div class="step-label mt-2">Dati Anagrafici</div>
                                        </div>
                                        <div class="step-connector"></div>
                                        <div class="step">
                                            <div class="step-circle bg-light text-muted">
                                                <i class="fa-solid fa-map-marker-alt"></i>
                                            </div>
                                            <div class="step-label mt-2">Residenza</div>
                                        </div>
                                        <div class="step-connector"></div>
                                        <div class="step">
                                            <div class="step-circle bg-light text-muted">
                                                <i class="fa-solid fa-paper-plane"></i>
                                            </div>
                                            <div class="step-label mt-2">Invio</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Main Form Card -->
                    <div class="card border-0 shadow-lg">
                        <div class="card-header bg-primary text-white border-0">
                            <div class="d-flex align-items-center">
                                <i class="fa-solid fa-plus-circle fa-lg me-3"></i>
                                <div>
                                    <h5 class="mb-0 fw-bold">Nuova Richiesta Certificato Comunale</h5>
                                    <small>Compila tutti i campi richiesti per procedere con la richiesta</small>
                                </div>
                            </div>
                        </div>

                        <form method="POST" enctype="multipart/form-data" id="certificatoForm" class="needs-validation" novalidate>
                            <input type="hidden" name="categoria" value="comunali">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                            <div class="card-body p-0">
                                <!-- Step 1: Tipo Certificato -->
                                <div class="form-section p-4 border-bottom">
                                    <div class="section-header mb-4">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="section-number bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                1
                                            </div>
                                            <h6 class="mb-0 fw-bold text-dark">Tipo di Certificato</h6>
                                        </div>
                                        <p class="text-muted small mb-0">Seleziona il tipo di certificato che desideri richiedere</p>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="form-floating">
                                                <select class="form-select form-select-lg" id="comunale_tipo" name="tipo" required style="padding-top: 1.625rem; padding-bottom: 0.625rem;">
                                                    <option value="">Caricamento tipi...</option>
                                                </select>
                                                <label for="comunale_tipo" class="form-label">
                                                    <i class="fa-solid fa-certificate text-primary me-2"></i>Tipo Certificato *
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                <i class="fa-solid fa-info-circle text-info me-1"></i>
                                                Seleziona il certificato di tuo interesse dall'elenco
                                            </div>
                                        </div>
                                        <div class="col-md-4 d-flex align-items-end">
                                            <div class="certificate-preview w-100">
                                                <div class="preview-placeholder bg-light rounded p-3 text-center">
                                                    <i class="fa-solid fa-file-contract fa-2x text-muted mb-2"></i>
                                                    <div class="small text-muted">Anteprima certificato</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 2: Dati Anagrafici -->
                                <div class="form-section p-4 border-bottom">
                                    <div class="section-header mb-4">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="section-number bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                2
                                            </div>
                                            <h6 class="mb-0 fw-bold text-dark">Dati Anagrafici</h6>
                                        </div>
                                        <p class="text-muted small mb-0">Inserisci i dati della persona per cui richiedi il certificato</p>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="comunale_codice_fiscale" name="codice_fiscale" required
                                                       pattern="^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$"
                                                       style="text-transform: uppercase;">
                                                <label for="comunale_codice_fiscale">
                                                    <i class="fa-solid fa-id-card text-primary me-2"></i>Codice Fiscale *
                                                </label>
                                            </div>
                                            <div class="invalid-feedback">
                                                Inserisci un codice fiscale valido (es. RSSMRA85T10A562S)
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="comunale_nome" name="nome">
                                                <label for="comunale_nome">
                                                    <i class="fa-solid fa-user text-primary me-2"></i>Nome
                                                </label>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="comunale_cognome" name="cognome">
                                                <label for="comunale_cognome">
                                                    <i class="fa-solid fa-user text-primary me-2"></i>Cognome
                                                </label>
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <div class="form-floating">
                                                <select class="form-select form-select-lg" id="comunale_sesso" name="sesso">
                                                    <option value="">Seleziona...</option>
                                                    <option value="M">Maschio</option>
                                                    <option value="F">Femmina</option>
                                                </select>
                                                <label for="comunale_sesso">
                                                    <i class="fa-solid fa-venus-mars text-primary me-2"></i>Sesso
                                                </label>
                                            </div>
                                        </div>

                                        <div class="col-md-3">
                                            <div class="form-floating">
                                                <input type="date" class="form-control form-control-lg" id="comunale_data_nascita" name="data_nascita">
                                                <label for="comunale_data_nascita">
                                                    <i class="fa-solid fa-calendar text-primary me-2"></i>Data Nascita
                                                </label>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="comunale_luogo_nascita" name="luogo_nascita">
                                                <label for="comunale_luogo_nascita">
                                                    <i class="fa-solid fa-map-pin text-primary me-2"></i>Luogo di Nascita
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 3: Residenza -->
                                <div class="form-section p-4 border-bottom">
                                    <div class="section-header mb-4">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="section-number bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                3
                                            </div>
                                            <h6 class="mb-0 fw-bold text-dark">Dati di Residenza</h6>
                                        </div>
                                        <p class="text-muted small mb-0">Specifica il comune e l'indirizzo per cui richiedi il certificato</p>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="comunale_comune" name="comune" required
                                                       data-istat-comune="true" data-istat-min-chars="3" data-istat-province-target="#comunale_provincia">
                                                <label for="comunale_comune">
                                                    <i class="fa-solid fa-city text-primary me-2"></i>Comune *
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                <i class="fa-solid fa-search text-info me-1"></i>
                                                Digita almeno 3 caratteri per vedere i suggerimenti ISTAT
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="comunale_provincia" name="provincia" readonly>
                                                <label for="comunale_provincia">
                                                    <i class="fa-solid fa-flag text-primary me-2"></i>Provincia
                                                </label>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="comunale_indirizzo" name="indirizzo"
                                                       placeholder="Via/Piazza e numero civico">
                                                <label for="comunale_indirizzo">
                                                    <i class="fa-solid fa-home text-primary me-2"></i>Indirizzo Completo
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                <i class="fa-solid fa-info-circle text-info me-1"></i>
                                                Specifica via, piazza, numero civico e eventuali altre informazioni
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 4: Esenzione (condizionale) -->
                                <div class="form-section p-4 exemption-fields" style="display: none;">
                                    <div class="section-header mb-4">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="section-number bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                <i class="fa-solid fa-exclamation-triangle"></i>
                                            </div>
                                            <h6 class="mb-0 fw-bold text-dark">Esenzione da Marca da Bollo</h6>
                                        </div>
                                        <p class="text-muted small mb-0">Per il certificato di stato di famiglia è richiesta documentazione per l'esenzione</p>
                                    </div>

                                    <div class="alert alert-warning border-warning">
                                        <div class="d-flex">
                                            <i class="fa-solid fa-exclamation-triangle fa-lg me-3 text-warning"></i>
                                            <div>
                                                <h6 class="alert-heading mb-2">Documentazione Richiesta</h6>
                                                <p class="mb-0">Per ottenere l'esenzione dalla marca da bollo, devi fornire un documento che attesti il motivo dell'esenzione.</p>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-12">
                                            <div class="form-floating">
                                                <select class="form-select form-select-lg" id="comunale_exemption_reason" name="exemption_reason">
                                                    <option value="">Seleziona il motivo dell'esenzione...</option>
                                                    <option value="MINORI">Adozione, affido, tutela minori</option>
                                                    <option value="CTU">CTU (Consulente Tecnico d'Ufficio); Curatore fallimentare</option>
                                                    <option value="INTERDIZIONE">Interdizione, inabilitazione, amministrazione di sostegno</option>
                                                    <option value="ONLUS">Organizzazioni non lucrative (ONLUS)</option>
                                                    <option value="PENSIONE">Pensione estera - richiesti da Enti previdenziali esteri</option>
                                                    <option value="PROCESSUALE">Processuale - Notifica atti giudiziari richiesti da Avvocati</option>
                                                    <option value="PA">Scambio di atti e documenti fra Pubbliche Amministrazioni</option>
                                                    <option value="DIVORZIO">Separazione, Divorzio</option>
                                                    <option value="SPORTIVO">Società sportive</option>
                                                    <option value="VARIAZIONE">Variazione toponomastica stradale e numerazione civica</option>
                                                </select>
                                                <label for="comunale_exemption_reason">
                                                    <i class="fa-solid fa-file-signature text-warning me-2"></i>Motivo Esenzione *
                                                </label>
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="file-upload-area border-2 border-dashed border-primary rounded p-4 text-center bg-light">
                                                <input type="file" class="form-control d-none" id="comunale_exemption_document" name="exemption_document" accept=".pdf">
                                                <div class="upload-content">
                                                    <i class="fa-solid fa-cloud-upload-alt fa-3x text-primary mb-3"></i>
                                                    <h6 class="fw-bold text-dark mb-2">Carica Documento Esenzione</h6>
                                                    <p class="text-muted mb-3">Trascina qui il file PDF o clicca per selezionare</p>
                                                    <button type="button" class="btn btn-primary btn-lg" onclick="document.getElementById('comunale_exemption_document').click()">
                                                        <i class="fa-solid fa-folder-open me-2"></i>Seleziona File
                                                    </button>
                                                </div>
                                                <div class="upload-preview d-none">
                                                    <i class="fa-solid fa-file-pdf fa-3x text-danger mb-3"></i>
                                                    <div class="file-info">
                                                        <h6 class="fw-bold text-dark mb-1" id="file-name"></h6>
                                                        <small class="text-muted" id="file-size"></small>
                                                    </div>
                                                    <button type="button" class="btn btn-outline-danger btn-sm mt-2" onclick="clearFile()">
                                                        <i class="fa-solid fa-times me-1"></i>Rimuovi
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="form-text">
                                                <i class="fa-solid fa-info-circle text-info me-1"></i>
                                                Caricare esclusivamente documenti in formato PDF che attestino il motivo dell'esenzione
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Form Actions -->
                            <div class="card-footer bg-light border-0 p-4">
                                <div class="row align-items-center">
                                    <div class="col-md-6">
                                        <div class="d-flex align-items-center text-muted">
                                            <i class="fa-solid fa-shield-alt me-2"></i>
                                            <small>I tuoi dati sono protetti e trattati in conformità al GDPR</small>
                                        </div>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <button type="button" class="btn btn-outline-secondary btn-lg me-3" onclick="resetForm()">
                                            <i class="fa-solid fa-undo me-2"></i>Ripristina
                                        </button>
                                        <button type="submit" class="btn btn-primary btn-lg px-4">
                                            <i class="fa-solid fa-paper-plane me-2"></i>Invia Richiesta
                                            <span class="spinner-border spinner-border-sm ms-2 d-none" role="status"></span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>

                    <!-- Help Section -->
                    <div class="card border-0 shadow-sm mt-4">
                        <div class="card-body p-4">
                            <div class="row">
                                <div class="col-md-6">
                                    <h6 class="fw-bold text-dark mb-3">
                                        <i class="fa-solid fa-question-circle text-info me-2"></i>Domande Frequenti
                                    </h6>
                                    <div class="accordion accordion-flush" id="faqAccordion">
                                        <div class="accordion-item border-0">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed bg-transparent px-0 py-2" type="button" data-bs-toggle="collapse" data-bs-target="#faq1">
                                                    <i class="fa-solid fa-clock me-2 text-muted"></i>Tempi di consegna
                                                </button>
                                            </h2>
                                            <div id="faq1" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                                <div class="accordion-body px-0 py-2 text-muted small">
                                                    I certificati comunali vengono generalmente rilasciati entro 24-48 ore lavorative.
                                                </div>
                                            </div>
                                        </div>
                                        <div class="accordion-item border-0">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed bg-transparent px-0 py-2" type="button" data-bs-toggle="collapse" data-bs-target="#faq2">
                                                    <i class="fa-solid fa-euro-sign me-2 text-muted"></i>Costi
                                                </button>
                                            </h2>
                                            <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                                <div class="accordion-body px-0 py-2 text-muted small">
                                                    I costi variano in base al tipo di certificato e al comune di residenza.
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <h6 class="fw-bold text-dark mb-3">
                                        <i class="fa-solid fa-headset text-success me-2"></i>Supporto
                                    </h6>
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="fa-solid fa-envelope text-primary me-3"></i>
                                        <div>
                                            <div class="fw-semibold small">Email Supporto</div>
                                            <div class="text-muted small">supporto@coresuite.it</div>
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center">
                                        <i class="fa-solid fa-phone text-primary me-3"></i>
                                        <div>
                                            <div class="fw-semibold small">Telefono</div>
                                            <div class="text-muted small">+39 02 1234 5678</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<?php
// Configurazione ISTAT per il lookup dei comuni
$istatDatasetUrl = asset('customer-portal/assets/data/comuni.json');
?>

<script>
    // Configurazione globale per il lookup ISTAT
    window.CIEIstatLookupConfig = {
        datasetUrl: '<?php echo sanitize_output($istatDatasetUrl); ?>',
        maxResults: 12,
        minChars: 3,
        debounceMs: 160
    };
</script>

<script src="<?php echo asset('assets/js/cie-istat-lookup.js'); ?>"></script>

<style>
.page-header {
    margin-bottom: 0;
}

.page-icon {
    box-shadow: 0 4px 12px rgba(0, 123, 255, 0.15);
}

.progress-steps {
    position: relative;
}

.step {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    z-index: 2;
}

.step-circle {
    width: 50px;
    height: 50px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    transition: all 0.3s ease;
}

.step.active .step-circle {
    box-shadow: 0 6px 20px rgba(0, 123, 255, 0.3);
}

.step-connector {
    width: 80px;
    height: 2px;
    background: #e9ecef;
    margin: 0 10px;
    align-self: center;
}

.step-label {
    font-size: 12px;
    color: #6c757d;
    text-align: center;
    max-width: 80px;
}

.step.active .step-label {
    color: #495057;
    font-weight: 600;
}

.form-section {
    transition: all 0.3s ease;
}

.section-number {
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
}

.form-floating > .form-control {
    border: 2px solid #e9ecef;
    transition: all 0.3s ease;
}

.form-floating > .form-control:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.1);
}

.form-floating > label {
    color: #6c757d;
    transition: all 0.3s ease;
}

.form-floating > .form-control:focus ~ label,
.form-floating > .form-control:not(:placeholder-shown) ~ label {
    color: #0d6efd;
    transform: scale(0.85) translateY(-0.5rem) translateX(0.15rem);
}

.file-upload-area {
    transition: all 0.3s ease;
    cursor: pointer;
}

.file-upload-area:hover {
    background-color: #f8f9fa;
    border-color: #0d6efd !important;
}

.upload-preview {
    animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.certificate-preview {
    min-height: 120px;
}

.preview-placeholder {
    border: 2px dashed #dee2e6;
    transition: all 0.3s ease;
}

.preview-placeholder:hover {
    border-color: #0d6efd;
    background-color: #f8f9fa;
}

.btn-lg {
    padding: 0.75rem 1.5rem;
    font-size: 1rem;
}

.card {
    border-radius: 12px;
    overflow: hidden;
}

.card-header {
    border-radius: 12px 12px 0 0 !important;
}

@media (max-width: 768px) {
    .progress-steps {
        flex-direction: column;
        gap: 1rem;
    }

    .step-connector {
        width: 2px;
        height: 40px;
        margin: 10px 0;
    }

    .step-label {
        max-width: none;
    }

    .page-header .row {
        text-align: center;
    }

    .page-header .col-auto:last-child {
        margin-top: 1rem;
    }
}
</style>

<!-- Script per gestire le richieste AJAX -->
<script>
// Gestione caricamento file
document.getElementById('comunale_exemption_document').addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const fileName = file.name;
        const fileSize = (file.size / 1024 / 1024).toFixed(2) + ' MB';

        document.getElementById('file-name').textContent = fileName;
        document.getElementById('file-size').textContent = fileSize;

        document.querySelector('.upload-content').classList.add('d-none');
        document.querySelector('.upload-preview').classList.remove('d-none');
    }
});

function clearFile() {
    document.getElementById('comunale_exemption_document').value = '';
    document.querySelector('.upload-content').classList.remove('d-none');
    document.querySelector('.upload-preview').classList.add('d-none');
}

function resetForm() {
    document.getElementById('certificatoForm').reset();
    clearFile();
    document.querySelectorAll('.exemption-fields').forEach(field => field.style.display = 'none');
}

// Gestione cambio tipo certificato comunale per mostrare/nascondere campi esenzione
document.getElementById('comunale_tipo').addEventListener('change', function() {
    const exemptionFields = document.querySelectorAll('.exemption-fields');
    const selectedValue = this.value;

    if (selectedValue === 'famiglia') {
        exemptionFields.forEach(field => {
            field.style.display = 'block';
            field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
        // Rendi obbligatori i campi esenzione
        document.getElementById('comunale_exemption_reason').setAttribute('required', 'required');
        document.getElementById('comunale_exemption_document').setAttribute('required', 'required');
    } else {
        exemptionFields.forEach(field => field.style.display = 'none');
        // Rimuovi obbligatorietà
        document.getElementById('comunale_exemption_reason').removeAttribute('required');
        document.getElementById('comunale_exemption_document').removeAttribute('required');
    }
});

// Carica i tipi di documento disponibili per certificati comunali
function caricaTipiDocumento() {
    console.log('Iniziando caricamento tipi documento comunali...');

    fetch('api/comunali.php?action=get_tipi', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        console.log('Risposta ricevuta:', response);
        return response.text();
    })
    .then(text => {
        console.log('Testo risposta:', text.substring(0, 200) + '...');

        try {
            const data = JSON.parse(text);
            console.log('JSON parsato:', data);

            const select = document.getElementById('comunale_tipo');
            select.innerHTML = '<option value="">Seleziona il tipo di certificato...</option>';

            if (data.success) {
                if (data.tipi.length > 0) {
                    data.tipi.forEach(tipo => {
                        const option = document.createElement('option');
                        option.value = tipo.categoria;
                        option.textContent = tipo.nome;
                        option.setAttribute('data-id', tipo.id);
                        select.appendChild(option);
                    });
                } else {
                    select.innerHTML = '<option value="">Nessun tipo certificato disponibile</option>';
                    console.log('API riuscita ma nessun tipo certificato comunale disponibile');
                }
            } else {
                const errorMsg = data.error ? `Errore API: ${data.error}` : 'Errore caricamento tipi';
                select.innerHTML = `<option value="">${errorMsg}</option>`;
                console.error('Errore caricamento tipi:', data.error || 'Nessun dettaglio errore disponibile');
            }
        } catch (e) {
            console.error('Errore parsing JSON:', e);
            console.error('Testo che ha causato l\'errore:', text);
        }
    })
    .catch(error => {
        console.error('Errore richiesta tipi documento:', error);
        const select = document.getElementById('comunale_tipo');
        select.innerHTML = '<option value="">Errore connessione</option>';
    });
}

// Validazione form e invio AJAX
document.getElementById('certificatoForm').addEventListener('submit', function(e) {
    e.preventDefault(); // Previene il submit normale

    if (!this.checkValidity()) {
        this.classList.add('was-validated');
        return;
    }

    // Mostra spinner
    const submitBtn = this.querySelector('button[type="submit"]');
    const spinner = submitBtn.querySelector('.spinner-border');
    submitBtn.disabled = true;
    spinner.classList.remove('d-none');

    // Prepara i dati del form
    const formData = new FormData(this);

    // Invia richiesta AJAX
    fetch('api/comunali.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Successo
            showMessage('Richiesta inviata con successo! ID richiesta: ' + data.request_id, 'success');
            resetForm();
        } else {
            // Errore
            showMessage('Errore: ' + (data.error || 'Errore sconosciuto'), 'danger');
        }
    })
    .catch(error => {
        console.error('Errore:', error);
        showMessage('Errore di connessione', 'danger');
    })
    .finally(() => {
        // Nasconde spinner
        submitBtn.disabled = false;
        spinner.classList.add('d-none');
    });

    this.classList.add('was-validated');
});

// Funzione per mostrare messaggi
function showMessage(message, type) {
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
    alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    document.body.appendChild(alertDiv);

    // Rimuovi automaticamente dopo 5 secondi
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 5000);
}

// Carica i tipi al caricamento della pagina
document.addEventListener('DOMContentLoaded', function() {
    caricaTipiDocumento();
});
</script>

<?php require_once '../../../includes/footer.php'; ?>