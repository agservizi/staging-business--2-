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
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once '../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Certificati Camerali</h1>
                <p class="text-muted mb-0">Richiesta bilanci, certificati camerali e informazioni societarie</p>
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
                        <h5 class="mb-0">Nuova Richiesta Certificato Camerale</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="categoria" value="camerali">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

                            <div class="mb-3">
                                <label class="form-label" for="camerali_tipo">Tipo Certificato *</label>
                                <select class="form-select" id="camerali_tipo" name="tipo" required>
                                    <option value="">Caricamento tipi...</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="camerali_codice_fiscale">Codice Fiscale/P.IVA *</label>
                                <input class="form-control" id="camerali_codice_fiscale" name="codice_fiscale" required>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="camerali_ragione_sociale">Ragione Sociale</label>
                                <input class="form-control" id="camerali_ragione_sociale" name="ragione_sociale">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="camerali_provincia">Provincia CCIAA</label>
                                <input class="form-control" id="camerali_provincia" name="provincia">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="camerali_numero_rea">Numero REA</label>
                                <input class="form-control" id="camerali_numero_rea" name="numero_rea">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="camerali_anno_bilancio">Anno Bilancio</label>
                                <input type="number" class="form-control" id="camerali_anno_bilancio" name="anno_bilancio" min="1900" max="2030">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="camerali_tipo_bilancio">Tipo Bilancio</label>
                                <select class="form-select" id="camerali_tipo_bilancio" name="tipo_bilancio">
                                    <option value="">Seleziona...</option>
                                    <option value="ordinario">Ordinario</option>
                                    <option value="semplificato">Semplificato</option>
                                    <option value="micro">Micro</option>
                                    <option value="consolidato">Consolidato</option>
                                </select>
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
    </main>
</div>

<!-- Script per gestire le richieste AJAX -->
<script>
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

// Carica i tipi al caricamento della pagina
document.addEventListener('DOMContentLoaded', function() {
    caricaTipiDocumentoCamerali();
});
</script>

<?php require_once '../../../includes/footer.php'; ?>