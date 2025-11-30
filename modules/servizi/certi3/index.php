<?php
require_once '../../../includes/auth.php';
require_role('Admin', 'Manager', 'Operatore');
$pageTitle = 'Certi³ - Gestione Certificati';
$csrfToken = csrf_token();

require_once '../../../includes/header.php';
require_once '../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once '../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Certi³</h1>
                <p class="text-muted mb-0">Gestione certificati comunali, catastali e camerali</p>
            </div>
            <div class="toolbar-actions">
                <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#nuovaRichiestaModal">
                    <i class="fa-solid fa-plus me-2"></i>Nuova Richiesta
                </button>
            </div>
        </div>
        <div class="row g-4">
            <!-- Certificati Comunali -->
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card ag-card h-100 border-primary">
                    <div class="card-body text-center d-flex flex-column">
                        <div class="mb-3">
                            <i class="fa-solid fa-building fa-3x text-primary"></i>
                        </div>
                        <h5 class="card-title">Certificati Comunali</h5>
                        <p class="card-text text-muted small">Anagrafici, residenza, stato civile e altri documenti comunali</p>
                        <div class="mt-auto">
                            <button class="btn btn-primary btn-lg w-100" type="button" data-bs-toggle="modal" data-bs-target="#comunaliModal">
                                <i class="fa-solid fa-plus me-2"></i>Richiedi Certificato
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Certificati Catastali -->
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card ag-card h-100 border-success">
                    <div class="card-body text-center d-flex flex-column">
                        <div class="mb-3">
                            <i class="fa-solid fa-map fa-3x text-success"></i>
                        </div>
                        <h5 class="card-title">Certificati Catastali</h5>
                        <p class="card-text text-muted small">Visure catastali, planimetrie e certificati di proprietà</p>
                        <div class="mt-auto">
                            <button class="btn btn-success btn-lg w-100" type="button" data-bs-toggle="modal" data-bs-target="#catastaliModal">
                                <i class="fa-solid fa-plus me-2"></i>Richiedi Certificato
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Certificati Camerali -->
            <div class="col-12 col-md-6 col-lg-4">
                <div class="card ag-card h-100 border-warning">
                    <div class="card-body text-center d-flex flex-column">
                        <div class="mb-3">
                            <i class="fa-solid fa-industry fa-3x text-warning"></i>
                        </div>
                        <h5 class="card-title">Certificati Camerali</h5>
                        <p class="card-text text-muted small">Bilanci, certificati camerali e informazioni societarie</p>
                        <div class="mt-auto">
                            <button class="btn btn-warning btn-lg w-100" type="button" data-bs-toggle="modal" data-bs-target="#cameraliModal">
                                <i class="fa-solid fa-plus me-2"></i>Richiedi Certificato
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modals per i form -->
        <!-- Modal Comunali -->
        <div class="modal fade" id="comunaliModal" tabindex="-1" aria-labelledby="comunaliModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="comunaliModalLabel">
                            <i class="fa-solid fa-building me-2 text-primary"></i>Richiedi Certificato Comunale
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <form method="post" novalidate>
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="categoria" value="comunali">
                            <div class="mb-3">
                                <label class="form-label" for="comunale_tipo">Tipo Certificato *</label>
                                <select class="form-select" id="comunale_tipo" name="tipo" required>
                                    <option value="">Seleziona...</option>
                                    <option value="anagrafico">Anagrafico</option>
                                    <option value="residenza">Residenza</option>
                                    <option value="stato_civile">Stato Civile</option>
                                    <option value="nascita">Nascita</option>
                                    <option value="morte">Morte</option>
                                    <option value="altro">Altro</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comunale_codice_fiscale">Codice Fiscale *</label>
                                <input class="form-control" id="comunale_codice_fiscale" name="codice_fiscale" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comunale_nome">Nome</label>
                                <input class="form-control" id="comunale_nome" name="nome">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comunale_cognome">Cognome</label>
                                <input class="form-control" id="comunale_cognome" name="cognome">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comunale_comune">Comune *</label>
                                <input class="form-control" id="comunale_comune" name="comune" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comunale_provincia">Provincia</label>
                                <input class="form-control" id="comunale_provincia" name="provincia">
                            </div>
                            <div class="text-end">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fa-solid fa-paper-plane me-2"></i>Invia Richiesta
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Catastali -->
        <div class="modal fade" id="catastaliModal" tabindex="-1" aria-labelledby="catastaliModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="catastaliModalLabel">
                            <i class="fa-solid fa-map me-2 text-success"></i>Richiedi Certificato Catastale
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <form method="post" novalidate>
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="categoria" value="catastali">
                            <div class="mb-3">
                                <label class="form-label" for="catastale_tipo">Tipo Certificato *</label>
                                <select class="form-select" id="catastale_tipo" name="tipo" required>
                                    <option value="">Seleziona...</option>
                                    <option value="visura">Visura Catastale</option>
                                    <option value="planimetria">Planimetria</option>
                                    <option value="proprieta">Certificato Proprietà</option>
                                    <option value="docfa">Docfa</option>
                                    <option value="altro">Altro</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="catastale_provincia">Provincia *</label>
                                <input class="form-control" id="catastale_provincia" name="provincia" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="catastale_comune">Comune *</label>
                                <input class="form-control" id="catastale_comune" name="comune" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="catastale_foglio">Foglio *</label>
                                <input class="form-control" id="catastale_foglio" name="foglio" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="catastale_particella">Particella *</label>
                                <input class="form-control" id="catastale_particella" name="particella" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="catastale_subalterno">Subalterno</label>
                                <input class="form-control" id="catastale_subalterno" name="subalterno">
                            </div>
                            <div class="text-end">
                                <button class="btn btn-success" type="submit">
                                    <i class="fa-solid fa-paper-plane me-2"></i>Invia Richiesta
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal Camerali -->
        <div class="modal fade" id="cameraliModal" tabindex="-1" aria-labelledby="cameraliModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="cameraliModalLabel">
                            <i class="fa-solid fa-industry me-2 text-warning"></i>Richiedi Certificato Camerale
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body">
                        <form method="post" novalidate>
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="categoria" value="camerali">
                            <div class="mb-3">
                                <label class="form-label" for="camerali_tipo">Tipo Certificato *</label>
                                <select class="form-select" id="camerali_tipo" name="tipo" required>
                                    <option value="">Seleziona...</option>
                                    <option value="bilancio">Bilancio</option>
                                    <option value="certificato">Certificato Camerale</option>
                                    <option value="visura">Visura Camerale</option>
                                    <option value="carica">Carica Sociale</option>
                                    <option value="altro">Altro</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="camerali_piva">Partita IVA *</label>
                                <input class="form-control" id="camerali_piva" name="piva" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="camerali_ragione_sociale">Ragione Sociale</label>
                                <input class="form-control" id="camerali_ragione_sociale" name="ragione_sociale">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="camerali_codice_fiscale">Codice Fiscale</label>
                                <input class="form-control" id="camerali_codice_fiscale" name="codice_fiscale">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="camerali_provincia">Provincia</label>
                                <input class="form-control" id="camerali_provincia" name="provincia">
                            </div>
                            <div class="text-end">
                                <button class="btn btn-warning" type="submit">
                                    <i class="fa-solid fa-paper-plane me-2"></i>Invia Richiesta
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Statistiche Rapide -->
        <div class="row g-4 mt-4">
            <div class="col-12">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">
                            <i class="fa-solid fa-chart-line me-2"></i>Statistiche Richieste
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-4">
                                <div class="p-3">
                                    <h3 class="text-primary mb-1">0</h3>
                                    <small class="text-muted">Comunali</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-3">
                                    <h3 class="text-success mb-1">0</h3>
                                    <small class="text-muted">Catastali</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-3">
                                    <h3 class="text-warning mb-1">0</h3>
                                    <small class="text-muted">Camerali</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sezione Risultati -->
        <div class="row g-4 mt-4">
            <div class="col-12">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="fa-solid fa-list me-2"></i>Storico Richieste
                        </h5>
                        <button class="btn btn-outline-primary btn-sm" type="button">
                            <i class="fa-solid fa-download me-2"></i>Esporta
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Categoria</th>
                                        <th>Tipo</th>
                                        <th>Dettagli</th>
                                        <th>Stato</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Qui verranno mostrati i risultati delle richieste -->
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            <i class="fa-solid fa-inbox fa-2x mb-2"></i>
                                            <br>Nessuna richiesta effettuata
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once '../../../includes/footer.php'; ?>