<?php
require_once '../../../includes/auth.php';
require_role('Admin', 'Manager', 'Operatore');
$pageTitle = 'Certi³ - Certificati Camerali';
$csrfToken = csrf_token();

require_once '../../../includes/header.php';
require_once '../../../includes/sidebar.php';

// Carica richieste recenti
$richieste = $pdo->prepare('SELECT cr.*, u.nome, u.cognome FROM certificati_richieste cr JOIN users u ON cr.user_id = u.id WHERE cr.user_id = ? ORDER BY cr.created_at DESC LIMIT 50');
$richieste->execute([$_SESSION['user_id']]);
$richieste = $richieste->fetchAll();

// Gestione richieste POST
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['categoria'])) {
    require_once 'api/' . $_POST['categoria'] . '.php';
    // La logica è gestita nel file API specifico
    exit; // Le API restituiscono JSON direttamente
}
?>

<div class="flex-grow-1 d-flex flex-column min-vh-100 bg-light">
    <?php require_once '../../../includes/topbar.php'; ?>

    <main class="content-wrapper">
        <!-- Header Section -->
        <div class="page-header bg-white border-bottom shadow-sm">
            <div class="container-fluid">
                <div class="row align-items-center">
                    <div class="col-auto">
                        <div class="page-icon bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                            <i class="fa-solid fa-building-shield fa-lg"></i>
                        </div>
                    </div>
                    <div class="col">
                        <h1 class="h2 mb-1 text-dark fw-bold">Certificati Camerali</h1>
                        <p class="text-muted mb-0">Richiesta bilanci, certificati camerali e informazioni societarie dalla Camera di Commercio</p>
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
                                            <div class="step-circle bg-warning text-dark">
                                                <i class="fa-solid fa-file-contract"></i>
                                            </div>
                                            <div class="step-label mt-2 fw-semibold">Tipo Certificato</div>
                                        </div>
                                        <div class="step-connector"></div>
                                        <div class="step">
                                            <div class="step-circle bg-light text-muted">
                                                <i class="fa-solid fa-building"></i>
                                            </div>
                                            <div class="step-label mt-2">Dati Società</div>
                                        </div>
                                        <div class="step-connector"></div>
                                        <div class="step">
                                            <div class="step-circle bg-light text-muted">
                                                <i class="fa-solid fa-chart-line"></i>
                                            </div>
                                            <div class="step-label mt-2">Bilancio</div>
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
                        <div class="card-header bg-warning border-0">
                            <div class="d-flex align-items-center">
                                <i class="fa-solid fa-plus-circle fa-lg me-3"></i>
                                <div>
                                    <h5 class="mb-0 fw-bold">Nuova Richiesta Certificato Camerale</h5>
                                    <small>Compila tutti i campi richiesti per procedere con la richiesta</small>
                                </div>
                            </div>
                        </div>

                        <form method="POST" enctype="multipart/form-data" id="certificatoForm" class="needs-validation" novalidate>
                            <input type="hidden" name="categoria" value="camerali">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                            <div class="card-body p-0">
                                <!-- Step 1: Tipo Certificato -->
                                <div class="form-section p-4 border-bottom">
                                    <div class="section-header mb-4">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="section-number bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                1
                                            </div>
                                            <h6 class="mb-0 fw-bold text-dark">Tipo di Certificato</h6>
                                        </div>
                                        <p class="text-muted small mb-0">Seleziona il tipo di certificato camerale che desideri richiedere</p>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="form-floating">
                                                <select class="form-select form-select-lg" id="camerali_tipo" name="tipo" required style="padding-top: 1.625rem; padding-bottom: 0.625rem;">
                                                    <option value="">Caricamento tipi...</option>
                                                </select>
                                                <label for="camerali_tipo" class="form-label">
                                                    <i class="fa-solid fa-file-contract text-warning me-2"></i>Tipo Certificato *
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                <i class="fa-solid fa-info-circle text-info me-1"></i>
                                                Seleziona il certificato camerale di tuo interesse dall'elenco
                                            </div>
                                        </div>
                                        <div class="col-md-4 d-flex align-items-end">
                                            <div class="certificate-preview w-100">
                                                <div class="preview-placeholder bg-light rounded p-3 text-center">
                                                    <i class="fa-solid fa-building-shield fa-2x text-muted mb-2"></i>
                                                    <div class="small text-muted">Anteprima certificato</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 2: Dati Società -->
                                <div class="form-section p-4 border-bottom">
                                    <div class="section-header mb-4">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="section-number bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                2
                                            </div>
                                            <h6 class="mb-0 fw-bold text-dark">Dati della Società</h6>
                                        </div>
                                        <p class="text-muted small mb-0">Inserisci le informazioni identificative dell'azienda</p>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="camerali_codice_fiscale" name="codice_fiscale" required
                                                       pattern="^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$|^[0-9]{11}$"
                                                       title="Codice fiscale (16 caratteri) o P.IVA (11 numeri)">
                                                <label for="camerali_codice_fiscale">
                                                    <i class="fa-solid fa-id-card text-warning me-2"></i>Codice Fiscale/P.IVA *
                                                </label>
                                            </div>
                                            <div class="invalid-feedback">
                                                Inserisci un codice fiscale (16 caratteri) o P.IVA (11 numeri) valido
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="camerali_ragione_sociale" name="ragione_sociale">
                                                <label for="camerali_ragione_sociale">
                                                    <i class="fa-solid fa-building text-warning me-2"></i>Ragione Sociale
                                                </label>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="camerali_provincia" name="provincia">
                                                <label for="camerali_provincia">
                                                    <i class="fa-solid fa-flag text-warning me-2"></i>Provincia CCIAA
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                <i class="fa-solid fa-info-circle text-info me-1"></i>
                                                Provincia della Camera di Commercio competente
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="camerali_numero_rea" name="numero_rea"
                                                       pattern="[A-Z]{2}[0-9]+" title="Formato: XX123456">
                                                <label for="camerali_numero_rea">
                                                    <i class="fa-solid fa-hashtag text-warning me-2"></i>Numero REA
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                <i class="fa-solid fa-info-circle text-info me-1"></i>
                                                Numero di iscrizione al Registro delle Imprese (es. MI123456)
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 3: Dati Bilancio (condizionale) -->
                                <div class="form-section p-4 bilancio-fields" style="display: none;">
                                    <div class="section-header mb-4">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="section-number bg-warning text-dark rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                3
                                            </div>
                                            <h6 class="mb-0 fw-bold text-dark">Dati del Bilancio</h6>
                                        </div>
                                        <p class="text-muted small mb-0">Specifica l'anno e il tipo di bilancio richiesto</p>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <input type="number" class="form-control form-control-lg" id="camerali_anno_bilancio" name="anno_bilancio"
                                                       min="1900" max="2030" placeholder="2023">
                                                <label for="camerali_anno_bilancio">
                                                    <i class="fa-solid fa-calendar text-warning me-2"></i>Anno Bilancio
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                <i class="fa-solid fa-info-circle text-info me-1"></i>
                                                Anno di riferimento del bilancio societario
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <select class="form-select form-select-lg" id="camerali_tipo_bilancio" name="tipo_bilancio">
                                                    <option value="">Seleziona il tipo di bilancio...</option>
                                                    <option value="ordinario">Ordinario</option>
                                                    <option value="semplificato">Semplificato</option>
                                                    <option value="micro">Micro-imprese</option>
                                                    <option value="consolidato">Consolidato</option>
                                                    <option value="abbreviato">Abbreviato</option>
                                                </select>
                                                <label for="camerali_tipo_bilancio">
                                                    <i class="fa-solid fa-chart-line text-warning me-2"></i>Tipo Bilancio
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                <i class="fa-solid fa-info-circle text-info me-1"></i>
                                                Tipo di bilancio in base alle dimensioni dell'impresa
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
                                        <button type="submit" class="btn btn-warning btn-lg px-4">
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
                                        <i class="fa-solid fa-question-circle text-info me-2"></i>Informazioni Utili
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
                                                    I certificati camerali vengono generalmente rilasciati entro 2-5 giorni lavorativi.
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
                                                    I costi variano in base al tipo di certificato e alla Camera di Commercio di riferimento.
                                                </div>
                                            </div>
                                        </div>
                                        <div class="accordion-item border-0">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed bg-transparent px-0 py-2" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                                    <i class="fa-solid fa-hashtag me-2 text-muted"></i>REA
                                                </button>
                                            </h2>
                                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                                <div class="accordion-body px-0 py-2 text-muted small">
                                                    Il numero REA è reperibile dal certificato camerale precedente o dal sito della Camera di Commercio.
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

<style>
.page-header {
    margin-bottom: 0;
}

.page-icon {
    box-shadow: 0 4px 12px rgba(255, 193, 7, 0.15);
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
    box-shadow: 0 6px 20px rgba(255, 193, 7, 0.3);
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
    border-color: #ffc107;
    box-shadow: 0 0 0 0.25rem rgba(255, 193, 7, 0.1);
}

.form-floating > label {
    color: #6c757d;
    transition: all 0.3s ease;
}

.form-floating > .form-control:focus ~ label,
.form-floating > .form-control:not(:placeholder-shown) ~ label {
    color: #ffc107;
    transform: scale(0.85) translateY(-0.5rem) translateX(0.15rem);
}

.certificate-preview {
    min-height: 120px;
}

.preview-placeholder {
    border: 2px dashed #dee2e6;
    transition: all 0.3s ease;
}

.preview-placeholder:hover {
    border-color: #ffc107;
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
function resetForm() {
    document.getElementById('certificatoForm').reset();
    document.querySelectorAll('.bilancio-fields').forEach(field => field.style.display = 'none');
}

// Gestione cambio tipo certificato camerale per mostrare/nascondere campi bilancio
document.getElementById('camerali_tipo').addEventListener('change', function() {
    const bilancioFields = document.querySelectorAll('.bilancio-fields');
    const selectedValue = this.value;

    if (selectedValue && selectedValue.includes('bilancio')) {
        bilancioFields.forEach(field => {
            field.style.display = 'block';
            field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        });
    } else {
        bilancioFields.forEach(field => field.style.display = 'none');
    }
});

// Carica i tipi di documento disponibili per certificati camerali
function caricaTipiDocumentoCamerali() {
    console.log('Iniziando caricamento tipi documento camerali...');

    fetch('api/camerali.php?action=get_tipi', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        console.log('Risposta ricevuta camerali:', response);
        return response.text();
    })
    .then(text => {
        try {
            const data = JSON.parse(text);
            console.log('JSON parsato camerali:', data);

            const select = document.getElementById('camerali_tipo');
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
                    console.log('API riuscita ma nessun tipo certificato camerale disponibile');
                }
            } else {
                const errorMsg = data.error ? `Errore API: ${data.error}` : 'Errore caricamento tipi';
                select.innerHTML = `<option value="">${errorMsg}</option>`;
                console.error('Errore caricamento tipi:', data.error || 'Nessun dettaglio errore disponibile');
                console.error('Risposta completa:', data);
            }
        } catch (e) {
            console.error('Errore parsing JSON:', e);
            console.error('Testo che ha causato l\'errore:', text);
        }
    })
    .catch(error => {
        console.error('Errore richiesta tipi documento:', error);
        console.error('Tipo errore:', error.constructor.name);
        const select = document.getElementById('camerali_tipo');
        select.innerHTML = '<option value="">Errore connessione</option>';
    });
}

// Validazione form
document.getElementById('certificatoForm').addEventListener('submit', function(e) {
    if (!this.checkValidity()) {
        e.preventDefault();
        e.stopPropagation();
    } else {
        // Mostra spinner
        const submitBtn = this.querySelector('button[type="submit"]');
        const spinner = submitBtn.querySelector('.spinner-border');
        submitBtn.disabled = true;
        spinner.classList.remove('d-none');
    }
    this.classList.add('was-validated');
});

// Carica i tipi al caricamento della pagina
document.addEventListener('DOMContentLoaded', function() {
    caricaTipiDocumentoCamerali();
});
</script>

<?php require_once '../../../includes/footer.php'; ?>
