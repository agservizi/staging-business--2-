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

$anprSchema = CertificateCatalog::anprSchema();
$catalogDefinitions = $anprSchema['categories'];
$fieldsetsDefinition = $anprSchema['fieldsets'];
$fieldLabelMap = [];
$fieldTypeMap = [];
$fieldOptionsMap = [];
$checkboxFields = [];
foreach ($fieldsetsDefinition as $fieldset) {
    foreach ($fieldset['fields'] as $field) {
        $name = $field['name'];
        $fieldLabelMap[$name] = $field['label'];
        $fieldTypeMap[$name] = $field['type'] ?? 'text';
        $fieldOptionsMap[$name] = $field['options'] ?? [];
        if (($field['type'] ?? '') === 'checkbox') {
            $checkboxFields[] = $name;
        }
    }
}

$categoryKeys = array_keys($catalogDefinitions);
$defaultCategory = $categoryKeys[0] ?? 'comunale';
$rawMacroCategoria = strtolower((string) ($_POST['macro_categoria'] ?? $defaultCategory));
$macroCategoria = array_key_exists($rawMacroCategoria, $catalogDefinitions) ? $rawMacroCategoria : $defaultCategory;

$subcategories = $catalogDefinitions[$macroCategoria]['subcategories'] ?? [];
$subKeys = array_keys($subcategories);
$defaultSottocategoria = $subKeys[0] ?? '';
$rawSottocategoria = strtolower((string) ($_POST['sottocategoria'] ?? $defaultSottocategoria));
$sottocategoria = array_key_exists($rawSottocategoria, $subcategories) ? $rawSottocategoria : $defaultSottocategoria;

$availableCertificates = $subcategories[$sottocategoria]['certificates'] ?? [];
$certificateKeys = array_keys($availableCertificates);
$defaultCertificate = $certificateKeys[0] ?? '';
$rawCertificate = (string) ($_POST['tipo_certificato'] ?? $defaultCertificate);
$tipoCertificato = array_key_exists($rawCertificate, $availableCertificates) ? $rawCertificate : $defaultCertificate;

$allowedUrgencyLevels = ['low','standard','alta'];
$rawUrgenza = isset($_POST['urgenza']) ? (string) $_POST['urgenza'] : '';
$urgenza = in_array($rawUrgenza, $allowedUrgencyLevels, true) ? $rawUrgenza : 'standard';

$intestatarioTipo = 'persona';
$schemaJson = sanitize_output(json_encode($anprSchema, JSON_UNESCAPED_UNICODE));

$dynamicValues = [];
foreach ($fieldsetsDefinition as $fieldset) {
    foreach ($fieldset['fields'] as $field) {
        $name = $field['name'];
        if (in_array($name, $checkboxFields, true)) {
            $dynamicValues[$name] = isset($_POST[$name]) ? '1' : '0';
        } else {
            $dynamicValues[$name] = trim((string) ($_POST[$name] ?? ''));
        }
    }
}
$dynamicValuesJson = sanitize_output(json_encode($dynamicValues, JSON_UNESCAPED_UNICODE));

$data = [
    'categoria' => 'comunale',
    'macro_categoria' => $macroCategoria,
    'sottocategoria' => $sottocategoria,
    'tipo_certificato' => $tipoCertificato,
    'intestatario_tipo' => $intestatarioTipo,
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
    'urgenza' => $urgenza,
    'data_nascita' => trim((string) ($_POST['data_nascita'] ?? '')),
    'comune_nascita' => trim((string) ($_POST['comune_nascita'] ?? '')),
    'provincia_nascita' => strtoupper(trim((string) ($_POST['provincia_nascita'] ?? ''))),
];

foreach ($dynamicValues as $fieldName => $fieldValue) {
    $data[$fieldName] = $fieldValue;
}

$errors = [];
$successMessage = null;
$currentCertificate = $data['tipo_certificato'] !== '' ? CertificateCatalog::certificateProfile($data['macro_categoria'], $data['sottocategoria'], $data['tipo_certificato']) : null;
$certificateFieldsets = $currentCertificate ? CertificateCatalog::certificateFieldsets($data['macro_categoria'], $data['sottocategoria'], $data['tipo_certificato']) : [];
$dynamicRequiredFields = $currentCertificate ? CertificateCatalog::requiredFields($data['macro_categoria'], $data['sottocategoria'], $data['tipo_certificato']) : [];
$activeFieldNames = [];
foreach ($certificateFieldsets as $fieldsetEntry) {
    $fieldsetKey = $fieldsetEntry['key'] ?? '';
    if ($fieldsetKey === '' || !isset($fieldsetsDefinition[$fieldsetKey]['fields'])) {
        continue;
    }
    foreach ($fieldsetsDefinition[$fieldsetKey]['fields'] as $fieldDefinition) {
        $activeFieldNames[] = $fieldDefinition['name'];
    }
}
$activeFieldNames = array_values(array_unique($activeFieldNames));
$allowedIntestatari = $currentCertificate['allowed_intestatario'] ?? ['persona'];

if (!in_array($data['intestatario_tipo'], $allowedIntestatari, true)) {
    $data['intestatario_tipo'] = $allowedIntestatari[0] ?? 'persona';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        require_valid_csrf();
    } catch (Throwable $exception) {
        $errors[] = 'Sessione scaduta. Aggiorna la pagina e riprova.';
    }

    if ($data['tipo_certificato'] === '' || $currentCertificate === null) {
        $errors[] = 'Seleziona un tipo di certificato valido per la categoria scelta.';
    }

    if ($data['nome'] === '' || $data['cognome'] === '') {
        $errors[] = 'Nome e cognome sono obbligatori per l\'intestatario.';
    }

    if ($data['cf_piva'] === '' || !preg_match('/^[A-Z0-9]{11,16}$/', $data['cf_piva'])) {
        $errors[] = 'Inserisci un codice fiscale valido (11-16 caratteri alfanumerici).';
    }

    if ($data['data_nascita'] === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['data_nascita'])) {
        $errors[] = 'La data di nascita è obbligatoria e deve avere formato YYYY-MM-DD.';
    }

    if ($data['comune'] === '') {
        $errors[] = 'Il comune di riferimento è obbligatorio.';
    }

    if ($data['provincia'] !== '' && !preg_match('/^[A-Z]{2}$/', $data['provincia'])) {
        $errors[] = 'La provincia deve contenere due lettere.';
    }

    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Indirizzo email non valido.';
    }

    if ($data['telefono'] !== '' && !preg_match('/^[0-9+()\s-]{6,}$/', $data['telefono'])) {
        $errors[] = 'Numero di telefono non valido.';
    }

    if ($data['cap'] !== '' && !preg_match('/^[0-9]{5}$/', $data['cap'])) {
        $errors[] = 'CAP non valido. Usa un formato a 5 cifre.';
    }

    if ($data['istat'] !== '' && !preg_match('/^[0-9]{6}$/', $data['istat'])) {
        $errors[] = 'Il codice ISTAT deve contenere 6 cifre.';
    }

    foreach ($dynamicRequiredFields as $requiredField) {
        if (!in_array($requiredField, $activeFieldNames, true)) {
            continue;
        }
        $value = $data[$requiredField] ?? '';
        $type = $fieldTypeMap[$requiredField] ?? 'text';
        $label = $fieldLabelMap[$requiredField] ?? ucfirst(str_replace('_', ' ', $requiredField));
        $isEmpty = $type === 'checkbox' ? ($value !== '1') : ($value === '');
        if ($isEmpty) {
            $errors[] = 'Il campo "' . $label . '" è obbligatorio per questo certificato.';
        }
    }

    foreach ($activeFieldNames as $fieldName) {
        $value = $data[$fieldName] ?? '';
        $type = $fieldTypeMap[$fieldName] ?? 'text';
        $label = $fieldLabelMap[$fieldName] ?? ucfirst(str_replace('_', ' ', $fieldName));

        if ($type === 'date' && $value !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $errors[] = 'Il campo "' . $label . '" deve avere formato YYYY-MM-DD.';
        }

        if ($type === 'select' && $value !== '') {
            $options = array_column($fieldOptionsMap[$fieldName] ?? [], 'value');
            if ($options && !in_array($value, $options, true)) {
                $errors[] = 'Valore non valido per "' . $label . '".';
            }
        }

        if ($type === 'checkbox') {
            $data[$fieldName] = $value === '1' ? '1' : '0';
        }
    }

    if (($data['periodo_dal'] ?? '') !== '' && ($data['periodo_al'] ?? '') !== '' && strcmp($data['periodo_dal'], $data['periodo_al']) > 0) {
        $errors[] = 'L\'intervallo temporale selezionato non è valido: la data "Al" deve essere successiva a "Dal".';
    }

    if (($data['recapito_email'] ?? '') !== '' && !filter_var($data['recapito_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Il recapito email inserito non è valido.';
    }

    if (($data['recapito_telefono'] ?? '') !== '' && !preg_match('/^[0-9+()\s-]{6,}$/', (string) $data['recapito_telefono'])) {
        $errors[] = 'Il recapito telefonico inserito non è valido.';
    }

    if (($data['cf_convivente'] ?? '') !== '' && !preg_match('/^[A-Z0-9]{11,16}$/', (string) $data['cf_convivente'])) {
        $errors[] = 'Il codice fiscale del convivente non è valido.';
    }

    if (!$errors) {
        $workflow = new CertiWorkflowService($pdo);
        $dynamicPayload = [];
        foreach ($activeFieldNames as $fieldName) {
            $type = $fieldTypeMap[$fieldName] ?? 'text';
            $value = $data[$fieldName] ?? '';
            if ($type === 'checkbox') {
                $dynamicPayload[$fieldName] = $value === '1';
            } else {
                $dynamicPayload[$fieldName] = $value === '' ? null : $value;
            }
        }

        $payload = [
            'categoria' => $data['categoria'],
            'macro_categoria' => $data['macro_categoria'],
            'sottocategoria' => $data['sottocategoria'],
            'tipo_certificato' => $data['tipo_certificato'],
            'urgenza' => $data['urgenza'],
            'note_interne' => $data['note_interne'] ?: null,
            'stato' => 'nuova',
            'dati_intestatario' => [
                'tipo' => $data['intestatario_tipo'],
                'denominazione' => $data['denominazione'],
                'nome' => $data['nome'],
                'cognome' => $data['cognome'],
                'cf_piva' => $data['cf_piva'],
                'data_nascita' => $data['data_nascita'],
                'comune_nascita' => $data['comune_nascita'],
                'provincia_nascita' => $data['provincia_nascita'],
                'email' => $data['email'],
                'telefono' => $data['telefono'],
                'indirizzo' => $data['indirizzo'],
                'comune' => $data['comune'],
                'provincia' => $data['provincia'],
                'cap' => $data['cap'],
                'istat' => $data['istat'],
            ],
        ];

        $payload['dati_intestatario']['anpr'] = [
            'macro_categoria' => $data['macro_categoria'],
            'sottocategoria' => $data['sottocategoria'],
            'certificate' => [
                'id' => $data['tipo_certificato'],
                'label' => $currentCertificate['label'] ?? null,
                'tooltip' => $currentCertificate['tooltip'] ?? null,
                'subcategory_label' => $currentCertificate['subcategory_label'] ?? null,
                'category_label' => $currentCertificate['category_label'] ?? null,
                'provider' => $currentCertificate['provider'] ?? null,
            ],
            'fieldsets' => $certificateFieldsets,
            'values' => $dynamicPayload,
            'required_fields' => $dynamicRequiredFields,
            'schema_version' => 'anpr_v1',
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

        <form
            id="certi-create-form"
            class="card ag-card"
            method="post"
            autocomplete="off"
            data-anpr-schema="<?php echo $schemaJson; ?>"
            data-anpr-values="<?php echo $dynamicValuesJson; ?>"
            data-selected-category="<?php echo sanitize_output($data['macro_categoria']); ?>"
            data-selected-subcategory="<?php echo sanitize_output($data['sottocategoria']); ?>"
            data-selected-certificate="<?php echo sanitize_output($data['tipo_certificato']); ?>"
        >
            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
            <input type="hidden" name="categoria" value="<?php echo sanitize_output($data['categoria']); ?>">
            <input type="hidden" name="intestatario_tipo" value="<?php echo sanitize_output($data['intestatario_tipo']); ?>">
            <div class="card-body">
                <div class="row g-4 align-items-end">
                    <div class="col-lg-4">
                        <label class="form-label" for="macro_categoria">Categoria</label>
                        <select class="form-select" id="macro_categoria" name="macro_categoria" data-role="anpr-category">
                            <?php foreach ($catalogDefinitions as $key => $definition): ?>
                                <option value="<?php echo sanitize_output($key); ?>" <?php echo $data['macro_categoria'] === $key ? 'selected' : ''; ?>><?php echo sanitize_output($definition['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Le categorie riproducono la tassonomia ANPR.</small>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="sottocategoria">Sottocategoria</label>
                        <select class="form-select" id="sottocategoria" name="sottocategoria" data-role="anpr-subcategory">
                            <?php foreach ($subcategories as $key => $definition): ?>
                                <option value="<?php echo sanitize_output($key); ?>" <?php echo $data['sottocategoria'] === $key ? 'selected' : ''; ?>><?php echo sanitize_output($definition['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="tipo_certificato">Certificato</label>
                        <select class="form-select" id="tipo_certificato" name="tipo_certificato" required data-role="anpr-certificate">
                            <?php foreach ($availableCertificates as $key => $definition): ?>
                                <option value="<?php echo sanitize_output($key); ?>" <?php echo $data['tipo_certificato'] === $key ? 'selected' : ''; ?>><?php echo sanitize_output($definition['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mt-2 align-items-center">
                    <div class="col-lg-8">
                        <div class="alert alert-info mb-0" id="certificato-tooltip" role="alert">
                            <i class="fa-solid fa-circle-info me-2"></i>
                            <span data-role="anpr-tooltip-text"><?php echo sanitize_output($currentCertificate['tooltip'] ?? 'Seleziona un certificato per visualizzare la descrizione ufficiale.'); ?></span>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <label class="form-label" for="urgenza">Livello urgenza</label>
                        <select class="form-select" id="urgenza" name="urgenza">
                            <option value="low" <?php echo $data['urgenza'] === 'low' ? 'selected' : ''; ?>>Bassa</option>
                            <option value="standard" <?php echo $data['urgenza'] === 'standard' ? 'selected' : ''; ?>>Normale</option>
                            <option value="alta" <?php echo $data['urgenza'] === 'alta' ? 'selected' : ''; ?>>Alta</option>
                        </select>
                    </div>
                </div>

                <hr class="my-4">

                <section>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h2 class="h5 mb-0">Dati intestatario</h2>
                            <p class="text-muted small mb-0">Campi obbligatori per tutte le richieste ANPR.</p>
                        </div>
                        <span class="badge bg-secondary-subtle text-secondary">Obbligatori</span>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="nome">Nome</label>
                            <input class="form-control" type="text" id="nome" name="nome" value="<?php echo sanitize_output($data['nome']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="cognome">Cognome</label>
                            <input class="form-control" type="text" id="cognome" name="cognome" value="<?php echo sanitize_output($data['cognome']); ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="cf_piva">Codice fiscale</label>
                            <input class="form-control" type="text" id="cf_piva" name="cf_piva" value="<?php echo sanitize_output($data['cf_piva']); ?>" maxlength="16" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="data_nascita">Data di nascita</label>
                            <input class="form-control" type="date" id="data_nascita" name="data_nascita" value="<?php echo sanitize_output($data['data_nascita']); ?>" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="comune">Comune di riferimento</label>
                            <input
                                class="form-control"
                                type="text"
                                id="comune"
                                name="comune"
                                value="<?php echo sanitize_output($data['comune']); ?>"
                                data-istat-comune="true"
                                data-istat-province-target="#provincia"
                                data-istat-cap-target="#cap"
                                data-istat-code-target="#istat"
                                placeholder="Digita per cercare nel dataset ISTAT"
                                required
                            >
                            <small class="text-muted">Utilizza il motore di ricerca ISTAT per evitare errori ortografici.</small>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-4">
                            <label class="form-label" for="provincia">Provincia</label>
                            <input class="form-control" type="text" id="provincia" name="provincia" value="<?php echo sanitize_output($data['provincia']); ?>" maxlength="2">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="cap">CAP</label>
                            <input class="form-control" type="text" id="cap" name="cap" value="<?php echo sanitize_output($data['cap']); ?>" maxlength="5">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="istat">Codice ISTAT</label>
                            <input class="form-control" type="text" id="istat" name="istat" value="<?php echo sanitize_output($data['istat']); ?>" maxlength="6" readonly>
                        </div>
                    </div>

                    <div class="row g-3 mt-1">
                        <div class="col-md-3">
                            <label class="form-label" for="email">Email</label>
                            <input class="form-control" type="email" id="email" name="email" value="<?php echo sanitize_output($data['email']); ?>" placeholder="nome@dominio.it">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="telefono">Telefono</label>
                            <input class="form-control" type="text" id="telefono" name="telefono" value="<?php echo sanitize_output($data['telefono']); ?>" placeholder="+39 ...">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="comune_nascita">Comune di nascita (facoltativo)</label>
                            <input class="form-control" type="text" id="comune_nascita" name="comune_nascita" value="<?php echo sanitize_output($data['comune_nascita']); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="provincia_nascita">Provincia nascita</label>
                            <input class="form-control" type="text" id="provincia_nascita" name="provincia_nascita" value="<?php echo sanitize_output($data['provincia_nascita']); ?>" maxlength="2">
                        </div>
                    </div>
                </section>

                <hr class="my-4">

                <section>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h2 class="h5 mb-0">Campi dinamici certificato</h2>
                            <p class="text-muted small mb-0">Il contenitore si popola in base al workflow selezionato.</p>
                        </div>
                        <span class="badge bg-info-subtle text-info">ANPR schema</span>
                    </div>
                    <div id="anpr-dynamic-fields" class="border border-dashed rounded-3 p-4 bg-light-subtle" data-role="anpr-fieldsets">
                        <p class="text-muted mb-0">Seleziona un certificato per generare automaticamente i campi richiesti.</p>
                    </div>
                </section>

                <section class="mt-4">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h2 class="h5 mb-0">Utility operatore</h2>
                            <p class="text-muted small mb-0">Genera rapidamente i payload per controlli o invio manuale.</p>
                        </div>
                        <div class="btn-group" role="group">
                            <button class="btn btn-outline-primary" type="button" id="generate-payload">
                                <i class="fa-solid fa-code me-2"></i>Genera e copia payload JSON
                            </button>
                            <button class="btn btn-outline-secondary" type="button" id="download-summary">
                                <i class="fa-solid fa-file-arrow-down me-2"></i>Scarica riepilogo
                            </button>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <label class="form-label">Anteprima payload</label>
                            <pre id="payload-preview" class="bg-dark text-light rounded px-3 py-3 small overflow-auto" style="min-height: 160px;">{}</pre>
                        </div>
                        <div class="col-lg-6">
                            <label class="form-label">Riepilogo certificato</label>
                            <pre id="summary-preview" class="bg-dark text-light rounded px-3 py-3 small overflow-auto" style="min-height: 160px;"></pre>
                        </div>
                    </div>
                </section>

                <section class="mt-4">
                    <label class="form-label" for="note_interne">Note interne / istruzioni</label>
                    <textarea class="form-control" id="note_interne" name="note_interne" rows="4" placeholder="Inserisci ulteriori specifiche utili per l'operatore."><?php echo sanitize_output($data['note_interne']); ?></textarea>
                </section>
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
