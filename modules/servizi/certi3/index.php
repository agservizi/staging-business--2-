<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('Admin', 'Manager', 'Operatore');
$pageTitle = 'Certi³ - Gestione Certificati';
$csrfToken = csrf_token();

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Certi³</h1>
                <p class="text-muted mb-0">Gestione certificati comunali, catastali e camerali</p>
            </div>
        </div>
        <div class="row g-4">
            <!-- Certificati Comunali -->
            <div class="col-12 col-lg-4">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">
                            <i class="fa-solid fa-building me-2 text-primary"></i>Certificati Comunali
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Richiedi certificati anagrafici, di residenza, stato civile e altri documenti comunali.</p>
                        <form method="post" novalidate>
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="mb-3">
                                <label class="form-label" for="comunale_tipo">Tipo Certificato</label>
                                <select class="form-select" id="comunale_tipo" name="tipo" required>
                                    <option value="">Seleziona...</option>
                                    <option value="anagrafico">Anagrafico</option>
                                    <option value="residenza">Residenza</option>
                                    <option value="stato_civile">Stato Civile</option>
                                    <option value="altro">Altro</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comunale_codice_fiscale">Codice Fiscale</label>
                                <input class="form-control" id="comunale_codice_fiscale" name="codice_fiscale" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comunale_comune">Comune</label>
                                <input class="form-control" id="comunale_comune" name="comune" required>
                            </div>
                            <button class="btn btn-primary w-100" type="submit">
                                <i class="fa-solid fa-search me-2"></i>Richiedi Certificato
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Certificati Catastali -->
            <div class="col-12 col-lg-4">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">
                            <i class="fa-solid fa-map me-2 text-success"></i>Certificati Catastali
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Ottieni visure catastali, planimetrie e certificati di proprietà.</p>
                        <form method="post" novalidate>
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="mb-3">
                                <label class="form-label" for="catastale_tipo">Tipo Certificato</label>
                                <select class="form-select" id="catastale_tipo" name="tipo" required>
                                    <option value="">Seleziona...</option>
                                    <option value="visura">Visura Catastale</option>
                                    <option value="planimetria">Planimetria</option>
                                    <option value="proprieta">Certificato Proprietà</option>
                                    <option value="altro">Altro</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="catastale_foglio">Foglio</label>
                                <input class="form-control" id="catastale_foglio" name="foglio" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="catastale_particella">Particella</label>
                                <input class="form-control" id="catastale_particella" name="particella" required>
                            </div>
                            <button class="btn btn-success w-100" type="submit">
                                <i class="fa-solid fa-search me-2"></i>Richiedi Certificato
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Certificati Camerali -->
            <div class="col-12 col-lg-4">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">
                            <i class="fa-solid fa-industry me-2 text-warning"></i>Certificati Camerali
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted small mb-3">Richiedi certificati camerali, bilanci e informazioni societarie.</p>
                        <form method="post" novalidate>
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <div class="mb-3">
                                <label class="form-label" for="camerali_tipo">Tipo Certificato</label>
                                <select class="form-select" id="camerali_tipo" name="tipo" required>
                                    <option value="">Seleziona...</option>
                                    <option value="bilancio">Bilancio</option>
                                    <option value="certificato">Certificato Camerale</option>
                                    <option value="visura">Visura Camerale</option>
                                    <option value="altro">Altro</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="camerali_piva">Partita IVA</label>
                                <input class="form-control" id="camerali_piva" name="piva" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="camerali_ragione_sociale">Ragione Sociale</label>
                                <input class="form-control" id="camerali_ragione_sociale" name="ragione_sociale">
                            </div>
                            <button class="btn btn-warning w-100" type="submit">
                                <i class="fa-solid fa-search me-2"></i>Richiedi Certificato
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sezione Risultati -->
        <div class="row g-4 mt-4">
            <div class="col-12">
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Risultati Richieste</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Data</th>
                                        <th>Tipo</th>
                                        <th>Categoria</th>
                                        <th>Stato</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- Qui verranno mostrati i risultati delle richieste -->
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">Nessuna richiesta effettuata</td>
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
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>