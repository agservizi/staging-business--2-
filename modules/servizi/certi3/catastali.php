<?php
require_once '../../../includes/auth.php';
require_role('Admin', 'Manager', 'Operatore');
$pageTitle = 'Certi³ - Certificati Catastali';
$csrfToken = csrf_token();

require_once '../../../includes/header.php';
require_once '../../../includes/sidebar.php';
?>

<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once '../../../includes/topbar.php'; ?>

    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Certificati Catastali</h1>
                <p class="text-muted mb-0">Richiesta visure catastali e planimetrie</p>
            </div>
            <div class="toolbar-actions">
                <a href="./" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-arrow-left me-2"></i>Torna ai Servizi
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">
                            <i class="fa-solid fa-plus-circle text-success me-2"></i>Nuova Richiesta
                        </h2>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data" id="certificatoForm" class="needs-validation" novalidate>
                            <input type="hidden" name="categoria" value="catastali">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="tipo" class="form-label fw-semibold">
                                        <i class="fa-solid fa-certificate text-success me-1"></i>Tipo Certificato *
                                    </label>
                                    <select class="form-select" id="tipo" name="tipo" required>
                                        <option value="">Seleziona tipo...</option>
                                        <option value="visura_catastale">Visura Catastale</option>
                                        <option value="planimetria">Planimetria</option>
                                        <option value="certificato_proprieta">Certificato di Proprietà</option>
                                        <option value="estratto_mappa">Estratto di Mappa</option>
                                    </select>
                                </div>

                                <div class="col-md-6">
                                    <label for="codice_fiscale" class="form-label fw-semibold">
                                        <i class="fa-solid fa-id-card text-success me-1"></i>Codice Fiscale *
                                    </label>
                                    <input type="text" class="form-control" id="codice_fiscale" name="codice_fiscale" required
                                           pattern="[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]"
                                           placeholder="RSSMRA85T10A562S">
                                </div>

                                <div class="col-md-6">
                                    <label for="nome" class="form-label fw-semibold">
                                        <i class="fa-solid fa-user text-success me-1"></i>Nome *
                                    </label>
                                    <input type="text" class="form-control" id="nome" name="nome" required placeholder="Mario">
                                </div>

                                <div class="col-md-6">
                                    <label for="cognome" class="form-label fw-semibold">
                                        <i class="fa-solid fa-user text-success me-1"></i>Cognome *
                                    </label>
                                    <input type="text" class="form-control" id="cognome" name="cognome" required placeholder="Rossi">
                                </div>

                                <div class="col-md-6">
                                    <label for="comune" class="form-label fw-semibold">
                                        <i class="fa-solid fa-home text-success me-1"></i>Comune *
                                    </label>
                                    <input type="text" class="form-control" id="comune" name="comune" required placeholder="Milano">
                                </div>

                                <div class="col-md-6">
                                    <label for="provincia" class="form-label fw-semibold">
                                        <i class="fa-solid fa-map text-success me-1"></i>Provincia *
                                    </label>
                                    <input type="text" class="form-control" id="provincia" name="provincia" required placeholder="MI">
                                </div>

                                <div class="col-md-6">
                                    <label for="foglio" class="form-label fw-semibold">
                                        <i class="fa-solid fa-hashtag text-success me-1"></i>Foglio (opzionale)
                                    </label>
                                    <input type="text" class="form-control" id="foglio" name="foglio" placeholder="123">
                                </div>

                                <div class="col-md-6">
                                    <label for="particella" class="form-label fw-semibold">
                                        <i class="fa-solid fa-hashtag text-success me-1"></i>Particella (opzionale)
                                    </label>
                                    <input type="text" class="form-control" id="particella" name="particella" placeholder="456">
                                </div>

                                <div class="col-12">
                                    <label for="note" class="form-label fw-semibold">
                                        <i class="fa-solid fa-sticky-note text-success me-1"></i>Note aggiuntive (opzionale)
                                    </label>
                                    <textarea class="form-control" id="note" name="note" rows="3" placeholder="Eventuali note o richieste specifiche"></textarea>
                                </div>

                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="urgente" name="urgente">
                                        <label class="form-check-label fw-semibold" for="urgente">
                                            <i class="fa-solid fa-exclamation-triangle text-warning me-1"></i>Richiesta urgente
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" class="btn btn-success" id="submitBtn">
                                    <i class="fa-solid fa-paper-plane me-2"></i>Invia Richiesta
                                </button>
                                <button type="reset" class="btn btn-outline-secondary">
                                    <i class="fa-solid fa-rotate-left me-2"></i>Reimposta
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">
                            <i class="fa-solid fa-info-circle text-info me-2"></i>Informazioni
                        </h2>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <h6><i class="fa-solid fa-clock me-2"></i>Tempi di consegna</h6>
                            <p class="mb-0 small">I certificati catastali vengono solitamente consegnati entro 5-10 giorni lavorativi.</p>
                        </div>

                        <div class="alert alert-warning">
                            <h6><i class="fa-solid fa-user-check me-2"></i>Approvazione richiesta</h6>
                            <p class="mb-0 small">Questa richiesta richiede l'approvazione dell'amministratore prima dell'elaborazione.</p>
                        </div>

                        <div class="alert alert-success">
                            <h6><i class="fa-solid fa-check-circle me-2"></i>Documenti disponibili</h6>
                            <ul class="mb-0 small">
                                <li>Visura Catastale</li>
                                <li>Planimetria</li>
                                <li>Certificato di Proprietà</li>
                                <li>Estratto di Mappa</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Modal per messaggi -->
<div class="modal fade" id="messageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="messageModalTitle">Notifica</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="messageModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
            </div>
        </div>
    </div>
</div>

<script>
// Gestione form AJAX
document.getElementById('certificatoForm').addEventListener('submit', function(e) {
    e.preventDefault();

    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Invio in corso...';

    const formData = new FormData(this);

    fetch('api/catastali.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        const modal = new bootstrap.Modal(document.getElementById('messageModal'));
        const modalTitle = document.getElementById('messageModalTitle');
        const modalBody = document.getElementById('messageModalBody');

        if (data.success) {
            modalTitle.textContent = 'Richiesta Inviata';
            modalBody.innerHTML = '<div class="alert alert-success"><i class="fa-solid fa-check-circle me-2"></i>Richiesta certificato catastale inviata con successo! Sarà revisionata dall\'amministratore.</div>';
            this.reset();
        } else {
            modalTitle.textContent = 'Errore';
            modalBody.innerHTML = '<div class="alert alert-danger"><i class="fa-solid fa-exclamation-triangle me-2"></i>' + (data.error || 'Errore sconosciuto') + '</div>';
        }

        modal.show();
    })
    .catch(error => {
        console.error('Errore:', error);
        const modal = new bootstrap.Modal(document.getElementById('messageModal'));
        document.getElementById('messageModalTitle').textContent = 'Errore';
        document.getElementById('messageModalBody').innerHTML = '<div class="alert alert-danger"><i class="fa-solid fa-exclamation-triangle me-2"></i>Errore di connessione. Riprova più tardi.</div>';
        modal.show();
    })
    .finally(() => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalText;
    });
});
</script>

<?php require_once '../../../includes/footer.php'; ?>

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
                                            <div class="step-circle bg-success text-white">
                                                <i class="fa-solid fa-file-contract"></i>
                                            </div>
                                            <div class="step-label mt-2 fw-semibold">Tipo Certificato</div>
                                        </div>
                                        <div class="step-connector"></div>
                                        <div class="step">
                                            <div class="step-circle bg-light text-muted">
                                                <i class="fa-solid fa-map-marker-alt"></i>
                                            </div>
                                            <div class="step-label mt-2">Dati Catastali</div>
                                        </div>
                                        <div class="step-connector"></div>
                                        <div class="step">
                                            <div class="step-circle bg-light text-muted">
                                                <i class="fa-solid fa-user"></i>
                                            </div>
                                            <div class="step-label mt-2">Intestatario</div>
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
                        <div class="card-header bg-success text-white border-0">
                            <div class="d-flex align-items-center">
                                <i class="fa-solid fa-plus-circle fa-lg me-3"></i>
                                <div>
                                    <h5 class="mb-0 fw-bold">Nuova Richiesta Certificato Catastale</h5>
                                    <small>Compila tutti i campi richiesti per procedere con la richiesta</small>
                                </div>
                            </div>
                        </div>

                        <form method="POST" enctype="multipart/form-data" id="certificatoForm" class="needs-validation" novalidate>
                            <input type="hidden" name="categoria" value="catasto">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                            <div class="card-body p-0">
                                <!-- Step 1: Tipo Certificato -->
                                <div class="form-section p-4 border-bottom">
                                    <div class="section-header mb-4">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="section-number bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                1
                                            </div>
                                            <h6 class="mb-0 fw-bold text-dark">Tipo di Certificato</h6>
                                        </div>
                                        <p class="text-muted small mb-0">Seleziona il tipo di certificato catastale che desideri richiedere</p>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-8">
                                            <div class="form-floating">
                                                <select class="form-select form-select-lg" id="catastale_tipo" name="tipo" required style="padding-top: 1.625rem; padding-bottom: 0.625rem;">
                                                    <option value="">Caricamento tipi...</option>
                                                </select>
                                                <label for="catastale_tipo" class="form-label">
                                                    <i class="fa-solid fa-file-contract text-success me-2"></i>Tipo Certificato *
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                <i class="fa-solid fa-info-circle text-info me-1"></i>
                                                Seleziona il certificato catastale di tuo interesse dall'elenco
                                            </div>
                                        </div>
                                        <div class="col-md-4 d-flex align-items-end">
                                            <div class="certificate-preview w-100">
                                                <div class="preview-placeholder bg-light rounded p-3 text-center">
                                                    <i class="fa-solid fa-map-marked-alt fa-2x text-muted mb-2"></i>
                                                    <div class="small text-muted">Anteprima certificato</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 2: Dati Catastali -->
                                <div class="form-section p-4 border-bottom">
                                    <div class="section-header mb-4">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="section-number bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                2
                                            </div>
                                            <h6 class="mb-0 fw-bold text-dark">Dati Catastali</h6>
                                        </div>
                                        <p class="text-muted small mb-0">Inserisci i dati identificativi dell'immobile catastale</p>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="catastale_provincia" name="provincia" required>
                                                <label for="catastale_provincia">
                                                    <i class="fa-solid fa-flag text-success me-2"></i>Provincia *
                                                </label>
                                            </div>
                                        </div>

                                        <div class="col-md-6">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="catastale_comune" name="comune" required
                                                       data-istat-comune="true" data-istat-min-chars="3">
                                                <label for="catastale_comune">
                                                    <i class="fa-solid fa-city text-success me-2"></i>Comune *
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                <i class="fa-solid fa-search text-info me-1"></i>
                                                Digita almeno 3 caratteri per vedere i suggerimenti ISTAT
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="catastale_foglio" name="foglio" required
                                                       pattern="[0-9]+" title="Solo numeri">
                                                <label for="catastale_foglio">
                                                    <i class="fa-solid fa-hashtag text-success me-2"></i>Foglio *
                                                </label>
                                            </div>
                                            <div class="invalid-feedback">
                                                Inserisci solo numeri per il foglio catastale
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="catastale_particella" name="particella" required
                                                       pattern="[0-9]+" title="Solo numeri">
                                                <label for="catastale_particella">
                                                    <i class="fa-solid fa-hashtag text-success me-2"></i>Particella *
                                                </label>
                                            </div>
                                            <div class="invalid-feedback">
                                                Inserisci solo numeri per la particella
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="catastale_subalterno" name="subalterno"
                                                       pattern="[0-9]*" title="Solo numeri">
                                                <label for="catastale_subalterno">
                                                    <i class="fa-solid fa-hashtag text-success me-2"></i>Subalterno
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                <i class="fa-solid fa-info-circle text-info me-1"></i>
                                                Opzionale per ulteriori specificazioni
                                            </div>
                                        </div>

                                        <div class="col-12">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="catastale_indirizzo" name="indirizzo"
                                                       placeholder="Via/Piazza e numero civico">
                                                <label for="catastale_indirizzo">
                                                    <i class="fa-solid fa-home text-success me-2"></i>Indirizzo Immobile
                                                </label>
                                            </div>
                                            <div class="form-text">
                                                <i class="fa-solid fa-info-circle text-info me-1"></i>
                                                Specifica l'indirizzo completo dell'immobile catastale
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Step 3: Dati Intestatario -->
                                <div class="form-section p-4">
                                    <div class="section-header mb-4">
                                        <div class="d-flex align-items-center mb-3">
                                            <div class="section-number bg-success text-white rounded-circle d-flex align-items-center justify-content-center me-3" style="width: 32px; height: 32px; font-size: 14px; font-weight: bold;">
                                                3
                                            </div>
                                            <h6 class="mb-0 fw-bold text-dark">Dati Intestatario</h6>
                                        </div>
                                        <p class="text-muted small mb-0">Informazioni sull'intestatario catastale dell'immobile</p>
                                    </div>

                                    <div class="row g-3">
                                        <div class="col-md-8">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="catastale_intestatario" name="intestatario">
                                                <label for="catastale_intestatario">
                                                    <i class="fa-solid fa-user text-success me-2"></i>Nome Intestatario
                                                </label>
                                            </div>
                                        </div>

                                        <div class="col-md-4">
                                            <div class="form-floating">
                                                <input type="text" class="form-control form-control-lg" id="catastale_codice_fiscale" name="codice_fiscale"
                                                       pattern="^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$|^[0-9]{11}$"
                                                       title="Codice fiscale o P.IVA valido">
                                                <label for="catastale_codice_fiscale">
                                                    <i class="fa-solid fa-id-card text-success me-2"></i>Codice Fiscale/P.IVA
                                                </label>
                                            </div>
                                            <div class="invalid-feedback">
                                                Inserisci un codice fiscale (16 caratteri) o P.IVA (11 numeri) valido
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
                                        <button type="submit" class="btn btn-success btn-lg px-4">
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
                                                    I certificati catastali vengono generalmente rilasciati entro 5-10 giorni lavorativi.
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
                                                    I costi variano in base al tipo di certificato e alla complessità della ricerca catastale.
                                                </div>
                                            </div>
                                        </div>
                                        <div class="accordion-item border-0">
                                            <h2 class="accordion-header">
                                                <button class="accordion-button collapsed bg-transparent px-0 py-2" type="button" data-bs-toggle="collapse" data-bs-target="#faq3">
                                                    <i class="fa-solid fa-hashtag me-2 text-muted"></i>Dati catastali
                                                </button>
                                            </h2>
                                            <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">
                                                <div class="accordion-body px-0 py-2 text-muted small">
                                                    Foglio, particella e subalterno sono reperibili sulla visura catastale precedente o presso l'Agenzia delle Entrate.
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
    box-shadow: 0 4px 12px rgba(25, 135, 84, 0.15);
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
    box-shadow: 0 6px 20px rgba(25, 135, 84, 0.3);
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
    border-color: #198754;
    box-shadow: 0 0 0 0.25rem rgba(25, 135, 84, 0.1);
}

.form-floating > label {
    color: #6c757d;
    transition: all 0.3s ease;
}

.form-floating > .form-control:focus ~ label,
.form-floating > .form-control:not(:placeholder-shown) ~ label {
    color: #198754;
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
    border-color: #198754;
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
}

// Carica i tipi di documento disponibili per certificati catastali
function caricaTipiDocumentoCatastali() {
    console.log('Iniziando caricamento tipi documento catastali...');

    fetch('api/catasto.php?action=get_tipi', {
        method: 'GET',
        headers: {
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(response => {
        console.log('Risposta ricevuta catastali:', response);
        return response.text();
    })
    .then(text => {
        try {
            const data = JSON.parse(text);
            console.log('JSON parsato catastali:', data);

            const select = document.getElementById('catastale_tipo');
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
                    console.log('API riuscita ma nessun tipo certificato catastale disponibile');
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
        const select = document.getElementById('catastale_tipo');
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
    caricaTipiDocumentoCatastali();
});
</script>

<?php require_once '../../../includes/footer.php'; ?>