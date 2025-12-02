<?php
declare(strict_types=1);

use App\Services\Certi\CameraliCatalog;
use App\Services\Certi\CertiWorkflowService;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Manager', 'Operatore');

$requestedCategory = strtolower((string) ($_GET['categoria'] ?? 'camerale'));
if ($requestedCategory === 'comunale') {
	header('Location: create.php');
	exit;
}

$pageTitle = 'Certificati Camerali Ufficiali';
$moduleColor = '#0a5ed7';
$csrfToken = csrf_token();

$schema = CameraliCatalog::schema();
$categories = $schema['categories'] ?? [];
$fieldsets = $schema['fieldsets'] ?? [];

$formaGiuridicaOptions = [
	'srl' => 'Società a responsabilità limitata (SRL)',
	'spa' => 'Società per azioni (SPA)',
	'snc' => 'Società in nome collettivo (SNC)',
	'sas' => 'Società in accomandita semplice (SAS)',
	'sapa' => 'Società in accomandita per azioni (SAPA)',
	'cooperativa' => 'Società cooperativa',
	'consorzio' => 'Consorzio',
	'holding' => 'Holding / capogruppo',
	'ditta_individuale' => 'Ditta individuale',
	'altro' => 'Altra forma giuridica',
];

$provinceCciaa = ['AG','AL','AN','AO','AP','AQ','AR','AT','AV','BA','BG','BI','BL','BN','BO','BR','BS','BT','BZ','CA','CB','CE','CH','CL','CN','CO','CR','CS','CT','CZ','EN','FC','FE','FG','FI','FM','FR','GE','GO','GR','IM','IS','KR','LC','LE','LI','LO','LT','LU','MB','MC','ME','MI','MN','MO','MS','MT','NA','NO','NU','OR','PA','PC','PD','PE','PG','PI','PN','PO','PR','PT','PU','PV','PZ','RA','RC','RE','RG','RI','RM','RN','RO','SA','SI','SO','SP','SR','SS','SV','TA','TE','TN','TO','TP','TR','TS','TV','UD','VA','VB','VC','VE','VI','VR','VT','VV'];

$categoryKeys = array_keys($categories);
$defaultCategory = $categoryKeys[0] ?? 'visure';
$rawCategory = strtolower((string) ($_POST['categoria_macro'] ?? $defaultCategory));
$selectedCategory = array_key_exists($rawCategory, $categories) ? $rawCategory : $defaultCategory;

$certificates = $categories[$selectedCategory]['certificates'] ?? [];
$certificateKeys = array_keys($certificates);
$defaultCertificate = $certificateKeys[0] ?? '';
$rawCertificate = (string) ($_POST['certificato'] ?? $defaultCertificate);
$selectedCertificate = array_key_exists($rawCertificate, $certificates) ? $rawCertificate : $defaultCertificate;

$allowedUrgency = ['low','standard','alta'];
$rawUrgency = strtolower((string) ($_POST['urgenza'] ?? 'standard'));
$selectedUrgency = in_array($rawUrgency, $allowedUrgency, true) ? $rawUrgency : 'standard';

$generalData = [
	'denominazione' => trim((string) ($_POST['denominazione'] ?? '')),
	'forma_giuridica' => strtolower((string) ($_POST['forma_giuridica'] ?? 'srl')),
	'codice_fiscale' => strtoupper(trim((string) ($_POST['codice_fiscale'] ?? ''))),
	'partita_iva' => preg_replace('/[^0-9]/', '', (string) ($_POST['partita_iva'] ?? '')),
	'rea' => strtoupper(trim((string) ($_POST['rea'] ?? ''))),
	'provincia_cciaa' => strtoupper(trim((string) ($_POST['provincia_cciaa'] ?? ''))),
	'pec' => strtolower(trim((string) ($_POST['pec'] ?? ''))),
	'email_referente' => strtolower(trim((string) ($_POST['email_referente'] ?? ''))),
	'telefono_referente' => trim((string) ($_POST['telefono_referente'] ?? '')),
	'sede_legale' => trim((string) ($_POST['sede_legale'] ?? '')),
];

$noteOperatore = trim((string) ($_POST['note_operatore'] ?? ''));
$trackingCode = trim((string) ($_POST['tracking_code'] ?? ''));

$checkboxFields = [];
foreach ($fieldsets as $fieldset) {
	foreach ($fieldset['fields'] as $field) {
		if (($field['type'] ?? '') === 'checkbox') {
			$checkboxFields[] = $field['name'];
		}
	}
}

$dynamicValues = [];
foreach ($fieldsets as $fieldset) {
	foreach ($fieldset['fields'] as $field) {
		$name = $field['name'];
		if (in_array($name, $checkboxFields, true)) {
			$dynamicValues[$name] = isset($_POST[$name]) ? '1' : '0';
		} else {
			$dynamicValues[$name] = trim((string) ($_POST[$name] ?? ''));
		}
	}
}

$schemaJson = sanitize_output(json_encode($schema, JSON_UNESCAPED_UNICODE));
$dynamicValuesJson = sanitize_output(json_encode($dynamicValues, JSON_UNESCAPED_UNICODE));

$errors = [];
$successMessage = null;
$certificateDefinition = CameraliCatalog::certificate($selectedCategory, $selectedCertificate);
$requiredDynamicFields = CameraliCatalog::requiredFields($selectedCategory, $selectedCertificate);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	try {
		require_valid_csrf();
	} catch (Throwable $exception) {
		$errors[] = 'Sessione non valida. Aggiorna la pagina e riprova.';
	}

	if ($generalData['denominazione'] === '') {
		$errors[] = 'La denominazione dell’impresa è obbligatoria.';
	}

	if (!array_key_exists($generalData['forma_giuridica'], $formaGiuridicaOptions)) {
		$errors[] = 'Seleziona una forma giuridica valida.';
	}

	if ($generalData['codice_fiscale'] === '' || !preg_match('/^[A-Z0-9]{11,16}$/', $generalData['codice_fiscale'])) {
		$errors[] = 'Inserisci un codice fiscale impresa valido (11-16 caratteri).';
	}

	if ($generalData['partita_iva'] === '' || !preg_match('/^[0-9]{11}$/', $generalData['partita_iva'])) {
		$errors[] = 'La partita IVA deve contenere 11 cifre.';
	}

	if ($generalData['rea'] !== '' && !preg_match('/^[A-Z]{0,2}[0-9]{1,7}$/i', $generalData['rea'])) {
		$errors[] = 'Il numero REA deve contenere prefisso provincia e progressivo numerico (es. MI123456).';
	}

	if ($generalData['provincia_cciaa'] === '' || !in_array($generalData['provincia_cciaa'], $provinceCciaa, true)) {
		$errors[] = 'Seleziona una provincia CCIAA italiana valida.';
	}

	if ($generalData['pec'] !== '' && !filter_var($generalData['pec'], FILTER_VALIDATE_EMAIL)) {
		$errors[] = 'La PEC inserita non è valida.';
	}

	if ($generalData['email_referente'] !== '' && !filter_var($generalData['email_referente'], FILTER_VALIDATE_EMAIL)) {
		$errors[] = 'L’email del referente non è valida.';
	}

	if ($generalData['telefono_referente'] !== '' && !preg_match('/^[0-9+()\s-]{6,}$/', $generalData['telefono_referente'])) {
		$errors[] = 'Il numero di telefono non è valido.';
	}

	if ($selectedCertificate === 'elenco_soci' && $generalData['forma_giuridica'] === 'ditta_individuale') {
		$errors[] = 'L’elenco soci non è disponibile per le ditte individuali.';
	}

	foreach ($requiredDynamicFields as $fieldName) {
		$value = $dynamicValues[$fieldName] ?? '';
		$isCheckbox = in_array($fieldName, $checkboxFields, true);
		$isValid = $isCheckbox ? $value === '1' : ($value !== '');
		if (!$isValid) {
			$errors[] = 'Compila il campo obbligatorio: ' . sanitize_output($fieldName) . '.';
		}
	}

	if (!$errors && $certificateDefinition === null) {
		$errors[] = 'Seleziona un certificato camerale valido.';
	}

	if (!$errors) {
		$workflow = new CertiWorkflowService($pdo);
		$payload = [
			'categoria' => 'camerale',
			'tipo_certificato' => $selectedCertificate,
			'urgenza' => $selectedUrgency,
			'note_interne' => $noteOperatore !== '' ? $noteOperatore : null,
			'tracking_code' => $trackingCode !== '' ? $trackingCode : null,
			'dati_intestatario' => [
				'tipo' => 'azienda',
				'denominazione' => $generalData['denominazione'],
				'forma_giuridica' => $generalData['forma_giuridica'],
				'codice_fiscale' => $generalData['codice_fiscale'],
				'partita_iva' => $generalData['partita_iva'],
				'rea' => $generalData['rea'],
				'provincia_cciaa' => $generalData['provincia_cciaa'],
				'pec' => $generalData['pec'],
				'email_referente' => $generalData['email_referente'],
				'telefono_referente' => $generalData['telefono_referente'],
				'sede_legale' => $generalData['sede_legale'],
				'parametri_specifici' => $dynamicValues,
			],
			'parametri_specifici' => $dynamicValues,
			'camerale_payload' => [
				'categoria' => $selectedCategory,
				'certificato' => $selectedCertificate,
				'dati_impresa' => $generalData,
				'parametri_specifici' => $dynamicValues,
				'tracking_code' => $trackingCode,
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
<style>
.cciaa-hero {
	background: linear-gradient(120deg, #0a5ed7, #74b4ff);
	border-radius: 28px;
	padding: 32px;
	color: #fff;
}
.cciaa-step-label {
	font-size: 12px;
	letter-spacing: 0.08em;
	text-transform: uppercase;
	color: #6c757d;
}
.cciaa-card {
	border: 1px solid #e3e7ef;
	border-radius: 20px;
	box-shadow: 0 10px 30px rgba(4, 32, 66, 0.08);
}
.cciaa-category-card {
	border-radius: 18px;
	border: 1px solid transparent;
	transition: border-color 0.2s ease, background 0.2s ease;
}
.cciaa-category-card.active,
.cciaa-category-card:hover {
	border-color: #0a5ed7;
	background: rgba(10, 94, 215, 0.08);
}
.cciaa-dynamic-box {
	border: 1px dashed #94b8ff;
	border-radius: 16px;
	padding: 24px;
	background: #f8fbff;
}
.cciaa-badge {
	background: rgba(10, 94, 215, 0.12);
	color: #0a5ed7;
	border-radius: 999px;
	padding: 4px 12px;
	font-size: 12px;
}
@media (max-width: 991px) {
	.cciaa-hero {
		padding: 24px;
	}
}
</style>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
	<?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
	<main class="content-wrapper">
		<div class="cciaa-hero mb-4">
			<div class="d-flex flex-column flex-lg-row align-items-start align-items-lg-center justify-content-between gap-3">
				<div>
					<span class="badge bg-light text-dark mb-2">Workflow camerale</span>
					<h1 class="h2 mb-2">Richiedi certificati camerali ufficiali</h1>
					<p class="mb-0">Tre livelli guidati (categoria → certificato → parametri) con controlli CCIAA pronti per VisEngine / DocuEngine.</p>
				</div>
				<div class="text-lg-end">
					<p class="mb-1">Colore modulo</p>
					<span class="badge" style="background: rgba(255,255,255,0.2); color:#fff;"><?php echo sanitize_output($moduleColor); ?></span>
				</div>
			</div>
		</div>

		<div class="d-flex flex-wrap gap-2 mb-4">
			<a class="btn btn-outline-light" href="create.php"><i class="fa-solid fa-city me-1"></i>Certificati comunali (ANPR)</a>
			<a class="btn btn-light text-primary" href="create_camerale.php"><i class="fa-solid fa-briefcase me-1"></i>Certificati camerali (CCIAA)</a>
		</div>

		<?php if ($errors): ?>
			<div class="alert alert-danger"> 
				<p class="fw-semibold mb-2">Correggi i seguenti errori:</p>
				<ul class="mb-0">
					<?php foreach ($errors as $error): ?>
						<li><?php echo sanitize_output($error); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<form
			id="cciaa-form"
			class="card cciaa-card"
			method="post"
			autocomplete="off"
			data-camerali-schema="<?php echo $schemaJson; ?>"
			data-camerali-values="<?php echo $dynamicValuesJson; ?>"
			data-selected-category="<?php echo sanitize_output($selectedCategory); ?>"
			data-selected-certificate="<?php echo sanitize_output($selectedCertificate); ?>"
		>
			<input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
			<div class="card-body p-4 p-lg-5">
				<div class="row g-4 mb-4">
					<div class="col-12">
						<p class="cciaa-step-label mb-2">Step 1</p>
						<h2 class="h4 mb-3">Seleziona categoria e certificato</h2>
						<div class="row g-3" data-role="cciaa-category-grid">
							<?php foreach ($categories as $key => $definition): ?>
								<div class="col-12 col-md-4">
									<button
										class="cciaa-category-card w-100 p-3 text-start"
										type="button"
										data-category-option="<?php echo sanitize_output($key); ?>"
										data-active="<?php echo $selectedCategory === $key ? '1' : '0'; ?>"
									>
										<div class="d-flex justify-content-between align-items-start">
											<strong><?php echo sanitize_output($definition['label']); ?></strong>
											<span class="badge bg-light text-dark"><?php echo count($definition['certificates'] ?? []); ?> certificati</span>
										</div>
										<p class="text-muted small mb-0 mt-2"><?php echo sanitize_output($definition['description'] ?? ''); ?></p>
									</button>
								</div>
							<?php endforeach; ?>
						</div>
						<input type="hidden" name="categoria_macro" id="categoria_macro" value="<?php echo sanitize_output($selectedCategory); ?>">
					</div>
					<div class="col-12 col-lg-6">
						<label class="form-label" for="certificato">Certificato camerale</label>
						<select class="form-select" id="certificato" name="certificato" data-role="cciaa-certificate-select">
							<?php foreach ($certificates as $key => $definition): ?>
								<option value="<?php echo sanitize_output($key); ?>" <?php echo $selectedCertificate === $key ? 'selected' : ''; ?>><?php echo sanitize_output($definition['label']); ?></option>
							<?php endforeach; ?>
						</select>
						<small class="text-muted" data-role="cciaa-certificate-tooltip"><?php echo sanitize_output($certificateDefinition['tooltip'] ?? 'Seleziona un certificato per leggere la descrizione.'); ?></small>
						<div class="text-danger small mt-1" data-role="elenco-warning" <?php echo $generalData['forma_giuridica'] === 'ditta_individuale' ? '' : 'hidden'; ?>>
							Il certificato elenco soci non &egrave; disponibile per le ditte individuali.
						</div>
					</div>
					<div class="col-6 col-lg-3">
						<label class="form-label" for="urgenza">Urgenza</label>
						<select class="form-select" id="urgenza" name="urgenza">
							<option value="low" <?php echo $selectedUrgency === 'low' ? 'selected' : ''; ?>>Bassa</option>
							<option value="standard" <?php echo $selectedUrgency === 'standard' ? 'selected' : ''; ?>>Standard</option>
							<option value="alta" <?php echo $selectedUrgency === 'alta' ? 'selected' : ''; ?>>Alta</option>
						</select>
					</div>
					<div class="col-6 col-lg-3">
						<label class="form-label" for="tracking_code">Codice tracking interno</label>
						<input class="form-control" type="text" id="tracking_code" name="tracking_code" placeholder="Es. Commessa/2025" value="<?php echo sanitize_output($trackingCode); ?>">
					</div>
				</div>

				<hr class="my-4">

				<section class="mb-4">
					<p class="cciaa-step-label mb-2">Step 2</p>
					<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
						<div>
							<h2 class="h4 mb-0">Dati impresa</h2>
							<p class="text-muted mb-0">Campi obbligatori per qualunque certificato camerale.</p>
						</div>
						<span class="cciaa-badge">Dati CCIAA</span>
					</div>
					<div class="row g-3">
						<div class="col-lg-6">
							<label class="form-label" for="denominazione">Denominazione / Impresa</label>
							<input class="form-control" type="text" id="denominazione" name="denominazione" value="<?php echo sanitize_output($generalData['denominazione']); ?>" required>
						</div>
						<div class="col-lg-6">
							<label class="form-label" for="forma_giuridica">Forma giuridica</label>
							<select class="form-select" id="forma_giuridica" name="forma_giuridica" data-role="forma-giuridica">
								<?php foreach ($formaGiuridicaOptions as $value => $label): ?>
									<option value="<?php echo sanitize_output($value); ?>" <?php echo $generalData['forma_giuridica'] === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-md-4">
							<label class="form-label" for="codice_fiscale">Codice fiscale impresa</label>
							<input class="form-control" type="text" id="codice_fiscale" name="codice_fiscale" value="<?php echo sanitize_output($generalData['codice_fiscale']); ?>" maxlength="16" required>
						</div>
						<div class="col-md-4">
							<label class="form-label" for="partita_iva">Partita IVA</label>
							<input class="form-control" type="text" id="partita_iva" name="partita_iva" value="<?php echo sanitize_output($generalData['partita_iva']); ?>" maxlength="11" required>
						</div>
						<div class="col-md-4">
							<label class="form-label" for="rea">REA (opzionale)</label>
							<input class="form-control" type="text" id="rea" name="rea" value="<?php echo sanitize_output($generalData['rea']); ?>" placeholder="es. MI123456">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="provincia_cciaa">Provincia CCIAA</label>
							<select class="form-select" id="provincia_cciaa" name="provincia_cciaa" required>
								<option value="">Seleziona provincia</option>
								<?php foreach ($provinceCciaa as $provincia): ?>
									<option value="<?php echo $provincia; ?>" <?php echo $generalData['provincia_cciaa'] === $provincia ? 'selected' : ''; ?>><?php echo $provincia; ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="col-md-8">
							<label class="form-label" for="sede_legale">Sede legale (via, civico, comune)</label>
							<input class="form-control" type="text" id="sede_legale" name="sede_legale" value="<?php echo sanitize_output($generalData['sede_legale']); ?>" placeholder="Via / Piazza, Comune, CAP">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="pec">PEC impresa</label>
							<input class="form-control" type="email" id="pec" name="pec" value="<?php echo sanitize_output($generalData['pec']); ?>" placeholder="pec@impresa.it">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="email_referente">Email referente</label>
							<input class="form-control" type="email" id="email_referente" name="email_referente" value="<?php echo sanitize_output($generalData['email_referente']); ?>">
						</div>
						<div class="col-md-4">
							<label class="form-label" for="telefono_referente">Telefono referente</label>
							<input class="form-control" type="text" id="telefono_referente" name="telefono_referente" value="<?php echo sanitize_output($generalData['telefono_referente']); ?>" placeholder="+39 ...">
						</div>
					</div>
				</section>

				<hr class="my-4">

				<section class="mb-4">
					<p class="cciaa-step-label mb-2">Step 3</p>
					<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
						<div>
							<h2 class="h4 mb-0">Parametri dinamici</h2>
							<p class="text-muted mb-0">Si attivano in base al certificato selezionato.</p>
						</div>
						<span class="cciaa-badge">Config. intelligente</span>
					</div>
					<div class="cciaa-dynamic-box" data-role="cciaa-dynamic-fields">
						<p class="text-muted mb-0">Seleziona un certificato per generare automaticamente i campi richiesti.</p>
					</div>
				</section>

				<section>
					<label class="form-label" for="note_operatore">Note interne / istruzioni</label>
					<textarea class="form-control" id="note_operatore" name="note_operatore" rows="4" placeholder="Indica clausole specifiche, portali di consegna o coordinate cliente."><?php echo sanitize_output($noteOperatore); ?></textarea>
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
window.CCIAAProvinciaCatalog = <?php echo json_encode($provinceCciaa, JSON_UNESCAPED_UNICODE); ?>;
</script>
<script src="<?php echo asset('assets/js/certi-camerale.js'); ?>" defer></script>
