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
                <h1 class="h3 mb-0">Certificati Comunali</h1>
                <p class="text-muted mb-0">Richiesta certificati anagrafici, residenza, stato civile e altri documenti comunali</p>
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
                        <h5 class="mb-0">Nuova Richiesta Certificato Comunale</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="categoria" value="comunali">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">

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
                                <input class="form-control" id="comunale_comune" name="comune" required data-istat-comune="true" data-istat-min-chars="3">
                                <small class="text-muted">Digita almeno 3 caratteri per vedere i suggerimenti ISTAT</small>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comunale_provincia">Provincia</label>
                                <input class="form-control" id="comunale_provincia" name="provincia">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comunale_data_nascita">Data di Nascita</label>
                                <input type="date" class="form-control" id="comunale_data_nascita" name="data_nascita">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comunale_luogo_nascita">Luogo di Nascita</label>
                                <input class="form-control" id="comunale_luogo_nascita" name="luogo_nascita">
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comunale_sesso">Sesso</label>
                                <select class="form-select" id="comunale_sesso" name="sesso">
                                    <option value="">Seleziona...</option>
                                    <option value="M">Maschio</option>
                                    <option value="F">Femmina</option>
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="comunale_indirizzo">Indirizzo</label>
                                <input class="form-control" id="comunale_indirizzo" name="indirizzo" placeholder="Via/Piazza e numero civico">
                            </div>
                            <!-- Campi specifici per Certificato Stato di Famiglia -->
                            <div class="mb-3 exemption-fields" style="display: none;">
                                <label class="form-label" for="comunale_exemption_reason">Motivo Esenzione da Marca da Bollo *</label>
                                <select class="form-select" id="comunale_exemption_reason" name="exemption_reason">
                                    <option value="">Seleziona motivo...</option>
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
                            </div>
                            <div class="mb-3 exemption-fields" style="display: none;">
                                <label class="form-label" for="comunale_exemption_document">Documento Esenzione *</label>
                                <input type="file" class="form-control" id="comunale_exemption_document" name="exemption_document" accept=".pdf">
                                <small class="text-muted">Caricare il documento che attesta il motivo dell'esenzione (PDF)</small>
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
    </main>
</div>

<!-- Script per gestire le richieste AJAX -->
<script>
// Gestione cambio tipo certificato comunale per mostrare/nascondere campi esenzione
document.getElementById('comunale_tipo').addEventListener('change', function() {
    const exemptionFields = document.querySelectorAll('.exemption-fields');
    const selectedValue = this.value;

    if (selectedValue === 'famiglia') {
        exemptionFields.forEach(field => field.style.display = 'block');
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

// Carica i tipi al caricamento della pagina
document.addEventListener('DOMContentLoaded', function() {
    caricaTipiDocumento();
});
</script>

<?php require_once '../../../includes/footer.php'; ?>