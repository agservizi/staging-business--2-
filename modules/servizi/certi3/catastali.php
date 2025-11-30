<?php
require_once '../../../includes/auth.php';
require_role('Admin', 'Manager', 'Operatore');
$pageTitle = 'Certi³ - Certificati Catastali';
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
                <h1 class="h3 mb-0">Certificati Catastali</h1>
                <p class="text-muted mb-0">Richiesta visure catastali, planimetrie e certificati di proprietà</p>
            </div>
            <div class="toolbar-actions">
                <a href="index.php" class="btn btn-outline-secondary">
                    <i class="fa-solid fa-arrow-left me-2"></i>Torna alla Dashboard
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8 mx-auto">
                <div class="card ag-card">
                    <div class="card-header">
                        <h5 class="mb-0">Nuova Richiesta Certificato Catastale</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="categoria" value="catasto">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                            <div class="mb-3">
                                <label class="form-label" for="catastale_tipo">Tipo Certificato *</label>
                                <select class="form-select" id="catastale_tipo" name="tipo" required>
                                    <option value="">Caricamento tipi...</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="catastale_provincia">Provincia *</label>
                                <input class="form-control" id="catastale_provincia" name="provincia" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="catastale_comune">Comune *</label>
                                <input class="form-control" id="catastale_comune" name="comune" required data-istat-comune="true" data-istat-min-chars="3">
                                <small class="text-muted">Digita almeno 3 caratteri per vedere i suggerimenti ISTAT</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="catastale_foglio">Foglio Catastale *</label>
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
                            <div class="mb-3">
                                <label class="form-label" for="catastale_indirizzo">Indirizzo Immobile</label>
                                <input class="form-control" id="catastale_indirizzo" name="indirizzo" placeholder="Via/Piazza e numero civico">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="catastale_intestatario">Intestatario</label>
                                <input class="form-control" id="catastale_intestatario" name="intestatario">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="catastale_codice_fiscale">Codice Fiscale/P.IVA</label>
                                <input class="form-control" id="catastale_codice_fiscale" name="codice_fiscale">
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
    </main>
</div>

<!-- Script per gestire le richieste AJAX -->
<script>
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

// Carica i tipi al caricamento della pagina
document.addEventListener('DOMContentLoaded', function() {
    caricaTipiDocumentoCatastali();
});
</script>

<?php require_once '../../../includes/footer.php'; ?>