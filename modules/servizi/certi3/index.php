<?php
require_once '../../../includes/auth.php';
require_role('Admin', 'Manager', 'Operatore');
$pageTitle = 'Certi³ - Gestione Certificati';
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
                        <small class="text-warning d-block mb-2">
                            <i class="fa-solid fa-info-circle me-1"></i>
                            Temporaneamente non disponibili
                        </small>
                        <div class="mt-auto">
                            <button class="btn btn-primary btn-lg w-100" type="button" disabled title="Temporaneamente non disponibile">
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
                                    <option value="">Caricamento tipi...</option>
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

        <!-- Script per gestire le richieste AJAX -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Gestione form comunali
            const comunaliForm = document.querySelector('#comunaliModal form');
            if (comunaliForm) {
                comunaliForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    submitRequest(this, 'comunali');
                });
            }

            // Gestione form catastali
            const catastaliForm = document.querySelector('#catastaliModal form');
            if (catastaliForm) {
                catastaliForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    submitRequest(this, 'catastali');
                });
            }

            // Gestione form camerali
            const cameraliForm = document.querySelector('#cameraliModal form');
            if (cameraliForm) {
                cameraliForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    submitRequest(this, 'camerali');
                });
            }
        });

        function submitRequest(form, categoria) {
            const formData = new FormData(form);
            formData.append('categoria', categoria);

            // Determina l'endpoint API corretto
            let apiEndpoint;
            switch (categoria) {
                case 'comunali':
                    apiEndpoint = 'api/comunali.php';
                    break;
                case 'catastali':
                    apiEndpoint = 'api/catasto.php';
                    break;
                case 'camerali':
                    apiEndpoint = 'api/camerali.php'; // Per ora, da implementare
                    break;
                default:
                    showAlert('danger', 'Categoria non supportata');
                    return;
            }

            // Mostra loading
            const submitBtn = form.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Elaborazione...';
            submitBtn.disabled = true;

            fetch(apiEndpoint, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showAlert('success', `Richiesta inviata con successo! ID: ${data.request_id || 'N/A'}`);
                    form.reset();
                    // Chiudi modal
                    const modal = bootstrap.Modal.getInstance(form.closest('.modal'));
                    if (modal) modal.hide();
                    // Ricarica la pagina per aggiornare la tabella
                    setTimeout(() => location.reload(), 2000);
                } else {
                    showAlert('danger', `Errore: ${data.error || 'Errore sconosciuto'}`);
                }
            })
            .catch(error => {
                showAlert('danger', 'Errore di connessione');
                console.error('Error:', error);
            })
            .finally(() => {
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        }

        function showAlert(type, message) {
            const alertDiv = document.createElement('div');
            alertDiv.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
            alertDiv.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            alertDiv.innerHTML = `
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            document.body.appendChild(alertDiv);

            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }
        </script>

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
                                    <?php if (empty($richieste)): ?>
                                    <tr>
                                        <td colspan="6" class="text-center text-muted py-4">
                                            <i class="fa-solid fa-inbox fa-2x mb-2"></i>
                                            <br>Nessuna richiesta effettuata
                                        </td>
                                    </tr>
                                    <?php else: ?>
                                        <?php foreach ($richieste as $richiesta): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y H:i', strtotime($richiesta['created_at'])); ?></td>
                                            <td>
                                                <span class="badge bg-<?php
                                                    echo match($richiesta['categoria']) {
                                                        'comunali' => 'primary',
                                                        'catastali' => 'success',
                                                        'camerali' => 'warning',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <?php echo ucfirst($richiesta['categoria']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($richiesta['tipo']); ?></td>
                                            <td>
                                                <?php
                                                $dati = json_decode($richiesta['dati_richiesta'], true);
                                                $dettagli = [];
                                                if (!empty($dati['codice_fiscale'])) $dettagli[] = 'CF: ' . substr($dati['codice_fiscale'], 0, 6) . '...';
                                                if (!empty($dati['comune'])) $dettagli[] = 'Comune: ' . $dati['comune'];
                                                if (!empty($dati['piva'])) $dettagli[] = 'PIVA: ' . substr($dati['piva'], 0, 6) . '...';
                                                echo htmlspecialchars(implode(', ', $dettagli));
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php
                                                    echo match($richiesta['stato']) {
                                                        'pending' => 'warning',
                                                        'processing' => 'info',
                                                        'done' => 'success',
                                                        'error' => 'danger',
                                                        default => 'secondary'
                                                    };
                                                ?>">
                                                    <?php echo ucfirst($richiesta['stato']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($richiesta['stato'] === 'done' && !empty($richiesta['documenti'])): ?>
                                                    <a href="api/<?php echo $richiesta['categoria']; ?>.php?action=download&request_id=<?php echo $richiesta['request_id']; ?>"
                                                       class="btn btn-sm btn-outline-success" title="Scarica" target="_blank">
                                                        <i class="fa-solid fa-download"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <button class="btn btn-sm btn-outline-info" title="Dettagli" onclick="mostraDettagli(<?php echo $richiesta['id']; ?>)">
                                                    <i class="fa-solid fa-eye"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
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

<script>
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
        console.log('Status:', response.status);
        console.log('Content-Type:', response.headers.get('content-type'));
        
        return response.text(); // Prima leggo come testo per debug
    })
    .then(text => {
        console.log('Testo risposta:', text.substring(0, 200) + '...');
        
        // Ora provo a fare il parse JSON
        try {
            const data = JSON.parse(text);
            console.log('JSON parsato:', data);
            
            const select = document.getElementById('comunale_tipo');
            select.innerHTML = '<option value="">Seleziona...</option>';

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
        const select = document.getElementById('comunale_tipo');
        select.innerHTML = '<option value="">Errore connessione</option>';
    });
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
            select.innerHTML = '<option value="">Seleziona...</option>';

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
                console.error('Errore caricamento tipi catastali:', data.error || 'Nessun dettaglio errore disponibile');
                console.error('Risposta completa catastali:', data);
            }
        } catch (e) {
            console.error('Errore parsing JSON catastali:', e);
            console.error('Testo che ha causato l\'errore:', text);
        }
    })
    .catch(error => {
        console.error('Errore richiesta tipi documento catastali:', error);
        console.error('Tipo errore:', error.constructor.name);
        const select = document.getElementById('catastale_tipo');
        select.innerHTML = '<option value="">Errore connessione</option>';
    });
}

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
            select.innerHTML = '<option value="">Seleziona...</option>';

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
                    console.log('API riuscita ma nessun tipo certificato camerali disponibile');
                }
            } else {
                const errorMsg = data.error ? `Errore API: ${data.error}` : 'Errore caricamento tipi';
                select.innerHTML = `<option value="">${errorMsg}</option>`;
                console.error('Errore caricamento tipi camerali:', data.error || 'Nessun dettaglio errore disponibile');
                console.error('Risposta completa camerali:', data);
            }
        } catch (e) {
            console.error('Errore parsing JSON camerali:', e);
            console.error('Testo che ha causato l\'errore:', text);
        }
    })
    .catch(error => {
        console.error('Errore richiesta tipi documento camerali:', error);
        console.error('Tipo errore:', error.constructor.name);
        const select = document.getElementById('camerali_tipo');
        select.innerHTML = '<option value="">Errore connessione</option>';
    });
}

// Carica i tipi quando la pagina è pronta
document.addEventListener('DOMContentLoaded', function() {
    caricaTipiDocumento();
    caricaTipiDocumentoCatastali();
    caricaTipiDocumentoCamerali();
});

// Gestione invio form certificati comunali
const comunaliForm = document.querySelector('#comunaliModal form');
if (comunaliForm) {
    comunaliForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Invio richiesta...';

        fetch('api/comunali.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Chiudi modal e mostra messaggio successo
                const modal = bootstrap.Modal.getInstance(document.getElementById('comunaliModal'));
                modal.hide();

                // Ricarica la pagina per aggiornare la tabella richieste
                location.reload();
            } else {
                alert('Errore: ' + (data.error || 'Errore sconosciuto'));
            }
        })
        .catch(error => {
            console.error('Errore:', error);
            alert('Errore di connessione');
        })
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        });
    });
}

// Gestione invio form certificati camerali
const cameraliForm = document.querySelector('#cameraliModal form');
if (cameraliForm) {
    cameraliForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;

        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin me-2"></i>Invio richiesta...';

        fetch('api/camerali.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Chiudi modal e mostra messaggio successo
                const modal = bootstrap.Modal.getInstance(document.getElementById('cameraliModal'));
                modal.hide();

                // Ricarica la pagina per aggiornare la tabella richieste
                location.reload();
            } else {
                alert('Errore: ' + (data.error || 'Errore sconosciuto'));
            }
        })
        .catch(error => {
            console.error('Errore:', error);
            alert('Errore di connessione');
        })
        .finally(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        });
    });
}

// Funzione per mostrare i dettagli della richiesta
function mostraDettagli(richiestaId) {
    // Per ora mostriamo un alert semplice, in futuro possiamo implementare un modal
    alert('Funzionalità dettagli richiesta in sviluppo. ID richiesta: ' + richiestaId);
}
</script>