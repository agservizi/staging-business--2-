<?php
declare(strict_types=1);

use App\Services\Certi\CertiWorkflowService;
use App\Services\Certi\CertificateCatalog;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Manager', 'Operatore');

$pageTitle = 'Nuova richiesta Certi³';
$moduleColor = '#0061ff';
$csrfToken = csrf_token();

$catalogDefinitions = CertificateCatalog::definitions();
$catalogSchema = CertificateCatalog::schema();
$categoryKeys = array_keys($catalogDefinitions);
$defaultCategory = $categoryKeys[0] ?? 'comunale';
$catalogJson = sanitize_output(json_encode($catalogSchema, JSON_UNESCAPED_UNICODE));

$rawCategoria = strtolower((string) ($_POST['categoria'] ?? $defaultCategory));
$selectedCategory = array_key_exists($rawCategoria, $catalogDefinitions) ? $rawCategoria : $defaultCategory;

$data = [
    'categoria' => $selectedCategory,
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
    'data_nascita' => trim((string) ($_POST['data_nascita'] ?? '')),
    'comune_nascita' => trim((string) ($_POST['comune_nascita'] ?? '')),
    'provincia_nascita' => strtoupper(trim((string) ($_POST['provincia_nascita'] ?? ''))),
    'data_matrimonio' => trim((string) ($_POST['data_matrimonio'] ?? '')),
    'comune_matrimonio' => trim((string) ($_POST['comune_matrimonio'] ?? '')),
    'azienda_rea' => strtoupper(trim((string) ($_POST['azienda_rea'] ?? ''))),
    'azienda_camera' => strtoupper(trim((string) ($_POST['azienda_camera'] ?? ''))),
    'azienda_pec' => trim((string) ($_POST['azienda_pec'] ?? '')),
    'immobile_comune' => trim((string) ($_POST['immobile_comune'] ?? '')),
    'immobile_indirizzo' => trim((string) ($_POST['immobile_indirizzo'] ?? '')),
];

$errors = [];
$successMessage = null;
$certificateLabels = CertificateCatalog::labels($data['categoria']);
if ($data['tipo_certificato'] !== '' && !array_key_exists($data['tipo_certificato'], $certificateLabels)) {
    $data['tipo_certificato'] = '';
}

$currentDefinition = $data['tipo_certificato'] !== '' ? CertificateCatalog::certificate($data['categoria'], $data['tipo_certificato']) : null;
$requirementsDefaults = [
    'birth_data' => false,
    'marriage_data' => false,
    'company_data' => false,
    'property_data' => false,
];
$requirements = array_merge($requirementsDefaults, $currentDefinition['requirements'] ?? []);
$requiresBirthData = (bool) $requirements['birth_data'];
$requiresMarriageData = (bool) $requirements['marriage_data'];
$requiresCompanyData = (bool) $requirements['company_data'];
$requiresPropertyData = (bool) $requirements['property_data'];
$allowedIntestatari = $currentDefinition['allowed_intestatario'] ?? ['persona','azienda'];
if (!in_array($data['intestatario_tipo'], $allowedIntestatari, true)) {
    $data['intestatario_tipo'] = $allowedIntestatari[0] ?? 'persona';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
    } catch (Throwable $exception) {
        $errors[] = 'Sessione scaduta. Aggiorna la pagina e riprova.';
    }

    if ($data['tipo_certificato'] === '' || $currentDefinition === null) {
        $errors[] = 'Seleziona un tipo di certificato valido per la categoria scelta.';
    }

    if ($currentDefinition !== null && !in_array($data['intestatario_tipo'], $allowedIntestatari, true)) {
        $errors[] = 'La tipologia di intestatario selezionata non è consentita per questo certificato.';
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

    if ($requiresCompanyData) {
        if ($data['denominazione'] === '') {
            $errors[] = 'Per i documenti camerali è obbligatoria la ragione sociale.';
        }
        if ($data['azienda_rea'] === '' && $data['azienda_camera'] === '') {
            $errors[] = 'Indica almeno il numero REA o il numero di iscrizione alla Camera di Commercio.';
        }
        if ($data['azienda_pec'] !== '' && !filter_var($data['azienda_pec'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'PEC aziendale non valida.';
        }
    }

    if ($requiresBirthData) {
        if ($data['data_nascita'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['data_nascita'])) {
            $errors[] = 'Inserisci una data di nascita nel formato YYYY-MM-DD.';
        }
        if ($data['comune_nascita'] === '' || $data['provincia_nascita'] === '') {
            $errors[] = 'Comune e provincia di nascita sono obbligatori.';
        }
    }

    if ($requiresMarriageData) {
        if ($data['data_matrimonio'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['data_matrimonio'])) {
            $errors[] = 'Inserisci la data dell\'atto matrimoniale nel formato YYYY-MM-DD.';
        }
        if ($data['comune_matrimonio'] === '') {
            $errors[] = 'Il comune dell\'atto matrimoniale è obbligatorio.';
        }
    }

    if ($requiresPropertyData) {
        if ($data['catasto_foglio'] === '' || $data['catasto_particella'] === '') {
            $errors[] = 'Per le richieste catastali è necessario indicare Foglio e Particella.';
        }
        if ($data['immobile_comune'] === '') {
            $errors[] = 'Specificare il comune dell\'immobile è obbligatorio.';
        }
    }

    if (!$errors) {
        $workflow = new CertiWorkflowService($pdo);
        $specifiche = [];

        if ($requiresBirthData) {
            $specifiche['nascita'] = [
                'data' => $data['data_nascita'],
                'comune' => $data['comune_nascita'],
                'provincia' => $data['provincia_nascita'],
            ];
        }

        if ($requiresMarriageData) {
            $specifiche['matrimonio'] = [
                'data' => $data['data_matrimonio'],
                'comune' => $data['comune_matrimonio'],
            ];
        }

        if ($requiresCompanyData) {
            $specifiche['azienda'] = [
                'rea' => $data['azienda_rea'],
                'camera' => $data['azienda_camera'],
                'pec' => $data['azienda_pec'],
            ];
        }

        if ($requiresPropertyData) {
            $specifiche['immobile'] = [
                'comune' => $data['immobile_comune'],
                'indirizzo' => $data['immobile_indirizzo'],
                'foglio' => $data['catasto_foglio'],
                'particella' => $data['catasto_particella'],
                'subalterno' => $data['catasto_sub'],
            ];
        }

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
                    'comune' => $data['immobile_comune'],
                    'indirizzo' => $data['immobile_indirizzo'],
                ],
            ],
        ];

        if ($specifiche) {
            $payload['dati_intestatario']['specifiche'] = $specifiche;
        }

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
                            <?php foreach (array_keys($catalogDefinitions) as $cat): ?>
                            <input type="radio" class="btn-check" name="categoria" id="cat-<?php echo sanitize_output($cat); ?>" value="<?php echo sanitize_output($cat); ?>" <?php echo $data['categoria'] === $cat ? 'checked' : ''; ?>>
                            <label class="btn btn-outline-primary" for="cat-<?php echo sanitize_output($cat); ?>"><?php echo sanitize_output($catalogSchema[$cat]['label'] ?? ucfirst($cat)); ?></label>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted">Colore principale modulo: <span style="color: <?php echo sanitize_output($moduleColor); ?>;"><?php echo sanitize_output($moduleColor); ?></span></small>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label" for="tipo_certificato">Tipo certificato</label>
                        <select class="form-select" id="tipo_certificato" name="tipo_certificato" required data-certi-schema='<?php echo $catalogJson; ?>'>
                            <option value="">Seleziona</option>
                            <?php foreach ($certificateLabels as $value => $label): ?>
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

                <div class="row g-4 mt-1" data-section="birth" <?php echo $requiresBirthData ? '' : 'hidden'; ?>>
                    <div class="col-md-4">
                        <label class="form-label" for="data_nascita">Data di nascita</label>
                        <input class="form-control" type="date" id="data_nascita" name="data_nascita" value="<?php echo sanitize_output($data['data_nascita']); ?>">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="comune_nascita">Comune di nascita</label>
                        <input class="form-control" type="text" id="comune_nascita" name="comune_nascita" value="<?php echo sanitize_output($data['comune_nascita']); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="provincia_nascita">Provincia</label>
                        <input class="form-control" type="text" id="provincia_nascita" name="provincia_nascita" value="<?php echo sanitize_output($data['provincia_nascita']); ?>" maxlength="2">
                    </div>
                </div>

                <div class="row g-4 mt-1" data-section="marriage" <?php echo $requiresMarriageData ? '' : 'hidden'; ?>>
                    <div class="col-md-4">
                        <label class="form-label" for="data_matrimonio">Data atto</label>
                        <input class="form-control" type="date" id="data_matrimonio" name="data_matrimonio" value="<?php echo sanitize_output($data['data_matrimonio']); ?>">
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="comune_matrimonio">Comune dell'atto</label>
                        <input class="form-control" type="text" id="comune_matrimonio" name="comune_matrimonio" value="<?php echo sanitize_output($data['comune_matrimonio']); ?>">
                    </div>
                </div>

                <div class="row g-4 mt-1" data-section="company" <?php echo $requiresCompanyData ? '' : 'hidden'; ?>>
                    <div class="col-md-4">
                        <label class="form-label" for="azienda_rea">Numero REA</label>
                        <input class="form-control" type="text" id="azienda_rea" name="azienda_rea" value="<?php echo sanitize_output($data['azienda_rea']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="azienda_camera">Numero iscrizione CCIAA</label>
                        <input class="form-control" type="text" id="azienda_camera" name="azienda_camera" value="<?php echo sanitize_output($data['azienda_camera']); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="azienda_pec">PEC aziendale</label>
                        <input class="form-control" type="email" id="azienda_pec" name="azienda_pec" value="<?php echo sanitize_output($data['azienda_pec']); ?>">
                    </div>
                </div>

                <div class="row g-4 mt-1" data-section="property" <?php echo $requiresPropertyData ? '' : 'hidden'; ?>>
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
                    <div class="col-md-6">
                        <label class="form-label" for="immobile_comune">Comune immobile</label>
                        <input class="form-control" type="text" id="immobile_comune" name="immobile_comune" value="<?php echo sanitize_output($data['immobile_comune']); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="immobile_indirizzo">Indirizzo immobile</label>
                        <input class="form-control" type="text" id="immobile_indirizzo" name="immobile_indirizzo" value="<?php echo sanitize_output($data['immobile_indirizzo']); ?>">
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
