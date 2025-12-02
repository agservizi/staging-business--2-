<?php
declare(strict_types=1);

use App\Services\Certi\CertiWorkflowService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Manager', 'Operatore');

$pageTitle = 'Nuova richiesta Certi³';
$moduleColor = '#0061ff';
$csrfToken = csrf_token();

$certificateCatalog = [
    'comunale' => [
        'certificato_residenza' => 'Certificato di residenza',
        'certificato_stato_famiglia' => 'Stato di famiglia',
        'certificato_stato_civile' => 'Stato civile',
        'estratto_nascita' => 'Estratto atto di nascita',
        'estratto_matrimonio' => 'Estratto atto di matrimonio',
    ],
    'camerale' => [
        'visura_ordinaria' => 'Visura camerale ordinaria',
        'visura_storica' => 'Visura camerale storica',
        'assetti_societari' => 'Assetti societari',
        'certificato_cciaa' => 'Certificato CCIAA',
        'atti_ufficiali' => 'Atti depositati',
    ],
    'catastale' => [
        'visura_catastale' => 'Visura catastale attuale',
        'visura_catastale_storica' => 'Visura catastale storica',
        'planimetria' => 'Planimetria',
        'rendita' => 'Rendita catastale',
        'titolarita' => 'Titolarità immobili',
    ],
];
$catalogJson = sanitize_output(json_encode($certificateCatalog, JSON_UNESCAPED_UNICODE));

$data = [
    'categoria' => in_array(strtolower((string) ($_POST['categoria'] ?? 'comunale')), array_keys($certificateCatalog), true) ? strtolower((string) ($_POST['categoria'] ?? 'comunale')) : 'comunale',
    'tipo_certificato' => trim((string) ($_POST['tipo_certificato'] ?? '')),
    'intestatario_tipo' => in_array($_POST['intestatario_tipo'] ?? 'persona', ['persona','azienda'], true) ? (string) $_POST['intestatario_tipo'] : 'persona',
    'denominazione' => trim((string) ($_POST['denominazione'] ?? '')),
    'nome' => trim((string) ($_POST['nome'] ?? '')),
    'cognome' => trim((string) ($_POST['cognome'] ?? '')),
    'cf_piva' => strtoupper(trim((string) ($_POST['cf_piva'] ?? ''))),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'telefono' => trim((string) ($_POST['telefono'] ?? '')),
    'indirizzo' => trim((string) ($_POST['indirizzo'] ?? '')),
    'comune' => trim((string) ($_POST['comune'] ?? '')),
    'provincia' => strtoupper(trim((string) ($_POST['provincia'] ?? ''))),
    'cap' => trim((string) ($_POST['cap'] ?? '')),
    'istat' => trim((string) ($_POST['istat'] ?? '')),
    'note_interne' => trim((string) ($_POST['note_interne'] ?? '')),
    'urgenza' => in_array($_POST['urgenza'] ?? 'standard', ['low','standard','alta'], true) ? (string) $_POST['urgenza'] : 'standard',
    'catasto_foglio' => trim((string) ($_POST['catasto_foglio'] ?? '')),
    'catasto_particella' => trim((string) ($_POST['catasto_particella'] ?? '')),
    'catasto_sub' => trim((string) ($_POST['catasto_sub'] ?? '')),
];

$errors = [];
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
    } catch (Throwable $exception) {
        $errors[] = 'Sessione scaduta. Aggiorna la pagina e riprova.';
    }

    if ($data['tipo_certificato'] === '' || !isset($certificateCatalog[$data['categoria']][$data['tipo_certificato']])) {
        $errors[] = 'Seleziona un tipo di certificato valido per la categoria scelta.';
    }

    if ($data['intestatario_tipo'] === 'azienda' && $data['denominazione'] === '') {
        $errors[] = 'La ragione sociale è obbligatoria per le aziende.';
    }

    if ($data['intestatario_tipo'] === 'persona' && ($data['nome'] === '' || $data['cognome'] === '')) {
        $errors[] = 'Nome e cognome sono obbligatori per le persone fisiche.';
    }

    if ($data['cf_piva'] === '' || !preg_match('/^[A-Z0-9]{11,16}$/', $data['cf_piva'])) {
        $errors[] = 'Inserisci un codice fiscale o partita IVA valido (11-16 caratteri alfanumerici).';
    }

    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Indirizzo email non valido.';
    }

    if ($data['telefono'] !== '' && !preg_match('/^[0-9+()\s-]{6,}$/', $data['telefono'])) {
        $errors[] = 'Numero di telefono non valido.';
    }

    if ($data['comune'] === '' || $data['provincia'] === '') {
        $errors[] = 'Comune e provincia sono obbligatori.';
    }

    if ($data['cap'] !== '' && !preg_match('/^[0-9]{5}$/', $data['cap'])) {
        $errors[] = 'CAP non valido. Usa un formato a 5 cifre.';
    }

    if ($data['categoria'] === 'catastale') {
        if ($data['catasto_foglio'] === '' || $data['catasto_particella'] === '') {
            $errors[] = 'Per le richieste catastali è necessario indicare Foglio e Particella.';
        }
    }

    if (!$errors) {
        $workflow = new CertiWorkflowService($pdo);
        $payload = [
            'categoria' => $data['categoria'],
            'tipo_certificato' => $data['tipo_certificato'],
            'urgenza' => $data['urgenza'],
            'note_interne' => $data['note_interne'] ?: null,
            'dati_intestatario' => [
                'tipo' => $data['intestatario_tipo'],
                'denominazione' => $data['denominazione'],
                'nome' => $data['nome'],
                'cognome' => $data['cognome'],
                'cf_piva' => $data['cf_piva'],
                'email' => $data['email'],
                'telefono' => $data['telefono'],
                'indirizzo' => $data['indirizzo'],
                'comune' => $data['comune'],
                'provincia' => $data['provincia'],
                'cap' => $data['cap'],
                'istat' => $data['istat'],
                'catasto' => [
                    'foglio' => $data['catasto_foglio'],
                    'particella' => $data['catasto_particella'],
                    'subalterno' => $data['catasto_sub'],
                ],
            ],
        ];

        try {
            $request = $workflow->createRequest($payload, (int) ($_SESSION['user_id'] ?? 0));
            header('Location: view.php?id=' . (int) $request['id'] . '&created=1');
            exit;
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Nuova richiesta Certi³</h1>
                <p class="text-muted mb-0">Compila i dati obbligatori e scegli la categoria di certificato da evadere.</p>
            </div>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Torna alla dashboard</a>
                <button class="btn btn-primary" form="certi-create-form" type="submit" style="background-color: <?php echo sanitize_output($moduleColor); ?>; border-color: <?php echo sanitize_output($moduleColor); ?>;">
                    <i class="fa-solid fa-floppy-disk me-2"></i>Salva richiesta
                </button>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger" role="alert">
                <p class="fw-semibold mb-2">Correggi i seguenti errori:</p>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo sanitize_output($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form id="certi-create-form" class="card ag-card" method="post" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-12">
                        <label class="form-label d-block">Categoria certificato</label>
                        <div class="btn-group" role="group">
                            <?php foreach (array_keys($certificateCatalog) as $cat): ?>
                            <input type="radio" class="btn-check" name="categoria" id="cat-<?php echo sanitize_output($cat); ?>" value="<?php echo sanitize_output($cat); ?>" <?php echo $data['categoria'] === $cat ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-primary" for="cat-<?php echo sanitize_output($cat); ?>"><?php echo ucfirst($cat); ?></label>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted">Colore principale modulo: <span style="color: <?php echo sanitize_output($moduleColor); ?>;"><?php echo sanitize_output($moduleColor); ?></span></small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="tipo_certificato">Tipo certificato</label>
                        <select class="form-select" id="tipo_certificato" name="tipo_certificato" required data-certi-options='<?php echo $catalogJson; ?>'>
                            <option value="">Seleziona</option>
                            <?php foreach ($certificateCatalog[$data['categoria']] as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>" <?php echo $data['tipo_certificato'] === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="urgenza">Livello urgenza</label>
                        <select class="form-select" id="urgenza" name="urgenza">
                            <option value="low" <?php echo $data['urgenza'] === 'low' ? 'selected' : ''; ?>>Bassa</option>
                            <option value="standard" <?php echo $data['urgenza'] === 'standard' ? 'selected' : ''; ?>>Normale</option>
                            <option value="alta" <?php echo $data['urgenza'] === 'alta' ? 'selected' : ''; ?>>Alta</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label d-block">Intestatario</label>
                        <div class="btn-group" role="group">
                            <input type="radio" class="btn-check" name="intestatario_tipo" id="intestatario-persona" value="persona" <?php echo $data['intestatario_tipo'] === 'persona' ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-light" for="intestatario-persona">Persona</label>
                            <input type="radio" class="btn-check" name="intestatario_tipo" id="intestatario-azienda" value="azienda" <?php echo $data['intestatario_tipo'] === 'azienda' ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-light" for="intestatario-azienda">Azienda</label>
                        </div>
                    </div>
                </div>

                <hr class="my-4">

                <div class="row g-4">
                    <div class="col-md-6" data-intestatario="azienda" <?php echo $data['intestatario_tipo'] === 'azienda' ? '' : 'hidden'; ?>>
                        <label class="form-label" for="denominazione">Ragione sociale</label>
                        <input class="form-control" type="text" id="denominazione" name="denominazione" value="<?php echo sanitize_output($data['denominazione']); ?>" placeholder="Denominazione azienda">
                    </div>
                    <div class="col-md-3" data-intestatario="persona" <?php echo $data['intestatario_tipo'] === 'persona' ? '' : 'hidden'; ?>>
                        <label class="form-label" for="nome">Nome</label>
                        <input class="form-control" type="text" id="nome" name="nome" value="<?php echo sanitize_output($data['nome']); ?>">
                    </div>
                    <div class="col-md-3" data-intestatario="persona" <?php echo $data['intestatario_tipo'] === 'persona' ? '' : 'hidden'; ?>>
                        <label class="form-label" for="cognome">Cognome</label>
                        <input class="form-control" type="text" id="cognome" name="cognome" value="<?php echo sanitize_output($data['cognome']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="cf_piva">Codice fiscale o P.IVA</label>
                        <input class="form-control" type="text" id="cf_piva" name="cf_piva" value="<?php echo sanitize_output($data['cf_piva']); ?>" maxlength="16">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="email">Email intestatario</label>
                        <input class="form-control" type="email" id="email" name="email" value="<?php echo sanitize_output($data['email']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="telefono">Telefono</label>
                        <input class="form-control" type="text" id="telefono" name="telefono" value="<?php echo sanitize_output($data['telefono']); ?>">
                    </div>
                </div>

                <div class="row g-4 mt-1">
                    <div class="col-md-6">
                        <label class="form-label" for="indirizzo">Indirizzo</label>
                        <input class="form-control" type="text" id="indirizzo" name="indirizzo" value="<?php echo sanitize_output($data['indirizzo']); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="comune">Comune</label>
                        <input class="form-control" type="text" id="comune" name="comune" value="<?php echo sanitize_output($data['comune']); ?>" data-istat-comune="true" data-istat-province-target="#provincia" data-istat-cap-target="#cap" data-istat-code-target="#istat" placeholder="Comune di riferimento">
                        <small class="text-muted">Ricerca con dataset ISTAT.</small>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label" for="provincia">Prov.</label>
                        <input class="form-control" type="text" id="provincia" name="provincia" value="<?php echo sanitize_output($data['provincia']); ?>" maxlength="2">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="cap">CAP</label>
                        <input class="form-control" type="text" id="cap" name="cap" value="<?php echo sanitize_output($data['cap']); ?>" maxlength="5">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="istat">Codice ISTAT</label>
                        <input class="form-control" type="text" id="istat" name="istat" value="<?php echo sanitize_output($data['istat']); ?>" maxlength="10" readonly>
                    </div>
                </div>

                <div class="row g-4 mt-1">
                    <div class="col-12">
                        <label class="form-label" for="note_interne">Note interne / requisiti specifici</label>
                        <textarea class="form-control" id="note_interne" name="note_interne" rows="4" placeholder="Indica dettagli utili per l'operatore (es. consegna digitale, portale cliente)"><?php echo sanitize_output($data['note_interne']); ?></textarea>
                    </div>
                </div>

                <div class="row g-4 mt-1" data-cat-only="catastale" <?php echo $data['categoria'] === 'catastale' ? '' : 'hidden'; ?>>
                    <div class="col-md-4">
                        <label class="form-label" for="catasto_foglio">Foglio</label>
                        <input class="form-control" type="text" id="catasto_foglio" name="catasto_foglio" value="<?php echo sanitize_output($data['catasto_foglio']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="catasto_particella">Particella</label>
                        <input class="form-control" type="text" id="catasto_particella" name="catasto_particella" value="<?php echo sanitize_output($data['catasto_particella']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="catasto_sub">Subalterno</label>
                        <input class="form-control" type="text" id="catasto_sub" name="catasto_sub" value="<?php echo sanitize_output($data['catasto_sub']); ?>">
                    </div>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-end gap-2">
                <a class="btn btn-outline-light" href="index.php">Annulla</a>
                <button class="btn btn-primary" type="submit" style="background-color: <?php echo sanitize_output($moduleColor); ?>; border-color: <?php echo sanitize_output($moduleColor); ?>;">
                    <i class="fa-solid fa-floppy-disk me-2"></i>Salva richiesta
                </button>
            </div>
        </form>
    </main>
</div>
<?php
$istatDatasetUrl = asset('customer-portal/assets/data/comuni.json');
?>
<script>
window.CIEIstatLookupConfig = {
    datasetUrl: '<?php echo sanitize_output($istatDatasetUrl); ?>',
    fallbackUrl: 'https://raw.githubusercontent.com/matteocontrini/comuni-json/master/comuni.json',
    maxResults: 15,
    minChars: 2
};
</script>
<script src="<?php echo asset('assets/js/cie-istat-lookup.js'); ?>"></script>
<script src="<?php echo asset('assets/js/certi-module.js'); ?>" defer></script>
