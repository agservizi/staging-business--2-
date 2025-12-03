<?php
use App\Services\SettingsService;
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Nuovo movimento';

$stati = ['In lavorazione', 'In attesa', 'Completato', 'Annullato'];
$metodi = ['Bonifico', 'Carta di credito', 'Carta di debito', 'Contanti', 'RID', 'Altro'];
$tipiMovimento = ['Entrata', 'Uscita'];

$projectRoot = realpath(__DIR__ . '/../../../') ?: __DIR__ . '/../../../';
$settingsService = new SettingsService($pdo, $projectRoot);
$storedDescriptions = $settingsService->getMovementDescriptions();
$servicePricing = $settingsService->getServicePricing();
$clientsStmt = $pdo->query('SELECT id, nome, cognome, ragione_sociale FROM clienti ORDER BY ragione_sociale, cognome, nome');
$clients = $clientsStmt ? $clientsStmt->fetchAll() : [];
$fallbackDescriptions = [
	'Entrata' => ['Incasso giornaliero', 'Vendita servizi', 'Rimborso spese'],
	'Uscita' => ['Pagamento fornitori', 'Spese operative', 'Stipendi e compensi'],
];

$movementPresets = [
	'Entrata' => !empty($storedDescriptions['entrate']) ? $storedDescriptions['entrate'] : $fallbackDescriptions['Entrata'],
	'Uscita' => !empty($storedDescriptions['uscite']) ? $storedDescriptions['uscite'] : $fallbackDescriptions['Uscita'],
];

foreach ($movementPresets as $key => $values) {
	$movementPresets[$key] = array_values(array_unique(array_map('trim', $values)));
}

$errors = [];
$data = [
	'cliente_id' => '',
	'descrizione' => '',
	'descrizione_option' => '',
	'descrizione_custom' => '',
	'riferimento' => '',
	'metodo' => 'Contanti',
	'stato' => 'In lavorazione',
	'tipo_movimento' => 'Entrata',
	'quantita' => '1',
	'prezzo_unitario' => '0.01',
	'importo' => '0.01',
	'data_scadenza' => '',
	'data_pagamento' => date('d/m/Y'),
	'note' => '',
	'service_pricing_id' => '',
	'listino_voce' => '',
	'listino_costo_rivenditore' => '',
	'listino_costo_cliente' => '',
	'listino_margine' => '',
];

$clienteId = null;

$initialClientId = isset($_GET['cliente_id']) ? (int) $_GET['cliente_id'] : 0;
if ($initialClientId > 0) {
	$data['cliente_id'] = (string) $initialClientId;
	$clienteId = $initialClientId;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	$initialType = $data['tipo_movimento'];
	$defaultDescription = $movementPresets[$initialType][0] ?? '';
	if ($defaultDescription !== '') {
		$data['descrizione'] = $defaultDescription;
		$data['descrizione_option'] = $defaultDescription;
	}
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	require_valid_csrf();

	$fields = ['cliente_id', 'riferimento', 'metodo', 'stato', 'tipo_movimento', 'data_scadenza', 'data_pagamento', 'note'];
	foreach ($fields as $field) {
		$data[$field] = trim($_POST[$field] ?? '');
	}

		$clienteId = null;
		if ($data['cliente_id'] === '') {
			$clienteId = null;
		} elseif (!ctype_digit($data['cliente_id'])) {
			$errors[] = 'Il cliente selezionato non è valido.';
		} else {
			$clienteId = (int) $data['cliente_id'];
			if ($clienteId <= 0) {
				$errors[] = 'Il cliente selezionato non è valido.';
			} else {
				$clientExistsStmt = $pdo->prepare('SELECT 1 FROM clienti WHERE id = :id LIMIT 1');
				$clientExistsStmt->execute([':id' => $clienteId]);
				if (!$clientExistsStmt->fetchColumn()) {
					$errors[] = 'Il cliente selezionato non esiste più.';
				}
			}
		}

	$data['quantita'] = trim($_POST['quantita'] ?? '1');
	$data['prezzo_unitario'] = trim($_POST['prezzo_unitario'] ?? '');
	$data['service_pricing_id'] = trim($_POST['service_pricing_id'] ?? '');
	$selectedPricing = null;
	if ($data['service_pricing_id'] !== '') {
		$pricingIndex = (int) $data['service_pricing_id'];
		if (!array_key_exists($pricingIndex, $servicePricing)) {
			$errors[] = 'Il listino selezionato non è valido.';
		} else {
			$selectedPricing = $servicePricing[$pricingIndex];
			$data['listino_voce'] = (string) ($selectedPricing['name'] ?? '');
			$data['listino_costo_rivenditore'] = number_format((float) ($selectedPricing['cost_reseller'] ?? 0), 2, '.', '');
			$data['listino_costo_cliente'] = number_format((float) ($selectedPricing['cost_customer'] ?? 0), 2, '.', '');
		}
	} else {
		$data['listino_voce'] = '';
		$data['listino_costo_rivenditore'] = '';
		$data['listino_costo_cliente'] = '';
	}

	$selectedDescription = trim($_POST['descrizione_select'] ?? '');
	$customDescription = trim($_POST['descrizione_custom'] ?? '');
	$data['descrizione_option'] = $selectedDescription;
	$data['descrizione_custom'] = $customDescription;

	if ($selectedDescription === '__custom__') {
		$data['descrizione'] = $customDescription;
	} elseif ($selectedDescription !== '') {
		$data['descrizione'] = $selectedDescription;
	} else {
		$data['descrizione'] = $customDescription !== '' ? $customDescription : '';
		if ($customDescription !== '') {
			$data['descrizione_option'] = '__custom__';
		}
	}

	$data['descrizione'] = trim($data['descrizione']);

	if (!in_array($data['tipo_movimento'], $tipiMovimento, true)) {
		$data['tipo_movimento'] = 'Entrata';
	}

	$currentOptions = $movementPresets[$data['tipo_movimento']] ?? [];
	if ($data['descrizione'] !== '' && in_array($data['descrizione'], $currentOptions, true) && $data['descrizione_option'] === '__custom__') {
		$data['descrizione_option'] = $data['descrizione'];
		$data['descrizione_custom'] = '';
	}

	if ($data['descrizione'] === '') {
		$errors[] = 'Seleziona o inserisci una descrizione del movimento.';
	} elseif (mb_strlen($data['descrizione']) > 180) {
		$errors[] = 'La descrizione del movimento non può superare 180 caratteri.';
	}

	if (!in_array($data['metodo'], $metodi, true)) {
		$data['metodo'] = 'Altro';
	}

	if (!in_array($data['stato'], $stati, true)) {
		$data['stato'] = 'In lavorazione';
	}

	$quantityValue = null;
	if ($data['quantita'] === '' || !ctype_digit($data['quantita'])) {
		$errors[] = 'Inserisci una quantità valida (numero intero maggiore di zero).';
	} else {
		$quantityValue = (int) $data['quantita'];
		if ($quantityValue <= 0) {
			$errors[] = 'La quantità deve essere almeno 1.';
		}
	}

	$unitPriceValue = null;
	if ($data['prezzo_unitario'] === '' || !is_numeric($data['prezzo_unitario'])) {
		$errors[] = 'Inserisci un prezzo unitario numerico valido.';
	} else {
		$unitPriceValue = round(abs((float) $data['prezzo_unitario']), 2);
		if ($unitPriceValue <= 0) {
			$errors[] = 'Il prezzo unitario deve essere maggiore di zero.';
		}
	}

	$listinoMargineValue = null;
	if ($selectedPricing && $quantityValue !== null && $unitPriceValue !== null) {
		$listinoCostReseller = (float) ($selectedPricing['cost_reseller'] ?? 0);
		if ($data['tipo_movimento'] === 'Entrata') {
			$listinoMargineValue = ($unitPriceValue - $listinoCostReseller) * $quantityValue;
		}
	}
	$data['listino_margine'] = $listinoMargineValue !== null ? number_format($listinoMargineValue, 2, '.', '') : '';

	if (!$errors) {
		$data['quantita'] = (string) $quantityValue;
		$data['prezzo_unitario'] = number_format($unitPriceValue, 2, '.', '');
		$data['importo'] = number_format($quantityValue * $unitPriceValue, 2, '.', '');
	}

	$quantityForTotal = ($data['quantita'] !== '' && ctype_digit($data['quantita'])) ? (int) $data['quantita'] : 0;
	$unitPriceForTotal = is_numeric($data['prezzo_unitario']) ? (float) $data['prezzo_unitario'] : 0.0;
	$data['importo'] = number_format(max($quantityForTotal, 0) * max($unitPriceForTotal, 0), 2, '.', '');

	$data['data_scadenza'] = $data['data_scadenza'] ?: '';
	$scadenzaForDb = null;
	if ($data['data_scadenza'] !== '') {
		$scadenzaDate = DateTimeImmutable::createFromFormat('d/m/Y', $data['data_scadenza']);
		if (!$scadenzaDate || $scadenzaDate->format('d/m/Y') !== $data['data_scadenza']) {
			$errors[] = 'La data di scadenza non è valida (usa il formato gg/mm/aaaa).';
		} else {
			$scadenzaForDb = $scadenzaDate->format('Y-m-d');
		}
	}

	$pagamentoForDb = null;
	if ($data['data_pagamento'] === '') {
		$errors[] = 'Specifica la data in cui stai registrando il movimento.';
	} else {
		$pagamentoDate = DateTimeImmutable::createFromFormat('d/m/Y', $data['data_pagamento']);
		if (!$pagamentoDate || $pagamentoDate->format('d/m/Y') !== $data['data_pagamento']) {
			$errors[] = 'La data del movimento non è valida (usa il formato gg/mm/aaaa).';
		} else {
			$pagamentoForDb = $pagamentoDate->format('Y-m-d');
		}
	}

	$uploadPath = null;
	$uploadHash = null;
	$uploadedFile = $_FILES['allegato'] ?? null;

	if ($uploadedFile && !empty($uploadedFile['name']) && $uploadedFile['error'] !== UPLOAD_ERR_OK) {
		$errors[] = 'Errore nel caricamento del file allegato.';
	}

	if (!$errors && $uploadedFile && !empty($uploadedFile['name']) && $uploadedFile['error'] === UPLOAD_ERR_OK) {
		$storageDir = public_path('assets/uploads/entrate-uscite');
		if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
			$errors[] = 'Impossibile creare la cartella di archiviazione allegati.';
		} else {
			$original = sanitize_filename($uploadedFile['name']);
			$prefix = strtolower($data['tipo_movimento'] ?: 'movimento');
			$fileName = sprintf('%s_%s_%s', $prefix, date('YmdHis'), $original);
			$destination = $storageDir . DIRECTORY_SEPARATOR . $fileName;
			if (!move_uploaded_file($uploadedFile['tmp_name'], $destination)) {
				$errors[] = 'Impossibile salvare il file allegato.';
			} else {
				$uploadPath = 'assets/uploads/entrate-uscite/' . $fileName;
				$uploadHash = hash_file('sha256', $destination);
			}
		}
	}

	if (!$errors) {
		$stmt = $pdo->prepare('INSERT INTO entrate_uscite (
			cliente_id,
			descrizione,
			listino_voce,
			listino_costo_rivenditore,
			listino_costo_cliente,
			listino_margine,
			riferimento,
			metodo,
			stato,
			tipo_movimento,
			importo,
			quantita,
			prezzo_unitario,
			data_scadenza,
			data_pagamento,
			note,
			allegato_path,
			allegato_hash,
			created_at,
			updated_at
		) VALUES (
			:cliente_id,
			:descrizione,
			:listino_voce,
			:listino_costo_rivenditore,
			:listino_costo_cliente,
			:listino_margine,
			:riferimento,
			:metodo,
			:stato,
			:tipo_movimento,
			:importo,
			:quantita,
			:prezzo_unitario,
			:data_scadenza,
			:data_pagamento,
			:note,
			:allegato_path,
			:allegato_hash,
			NOW(),
			NOW()
		)');
		$stmt->execute([
			':cliente_id' => $clienteId,
			':descrizione' => $data['descrizione'],
			':listino_voce' => $data['listino_voce'] ?: null,
			':listino_costo_rivenditore' => $data['listino_costo_rivenditore'] !== '' ? $data['listino_costo_rivenditore'] : null,
			':listino_costo_cliente' => $data['listino_costo_cliente'] !== '' ? $data['listino_costo_cliente'] : null,
			':listino_margine' => $data['listino_margine'] !== '' ? $data['listino_margine'] : null,
			':riferimento' => $data['riferimento'] ?: null,
			':metodo' => $data['metodo'],
			':stato' => $data['stato'],
			':tipo_movimento' => $data['tipo_movimento'],
			':importo' => $data['importo'],
			':quantita' => (int) $data['quantita'],
			':prezzo_unitario' => $data['prezzo_unitario'],
			':data_scadenza' => $scadenzaForDb,
			':data_pagamento' => $pagamentoForDb,
			':note' => $data['note'] ?: null,
			':allegato_path' => $uploadPath,
			':allegato_hash' => $uploadHash,
		]);

		$paymentId = (int) $pdo->lastInsertId();
		add_flash('success', 'Movimento registrato correttamente.');
		header('Location: view.php?id=' . $paymentId);
		exit;
	}
}

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<style>
	.attachment-dropzone {
		border: 2px dashed var(--bs-border-color, #ced4da);
		border-radius: 0.75rem;
		padding: 1.25rem;
		text-align: center;
		cursor: pointer;
		transition: border-color 0.2s ease, background-color 0.2s ease;
	}
	.attachment-dropzone .attachment-dropzone-icon {
		font-size: 1.75rem;
		color: var(--bs-warning, #ffc107);
	}
	.attachment-dropzone.is-dragover {
		border-color: var(--bs-warning, #ffc107);
		background-color: rgba(255, 193, 7, 0.1);
	}
	.attachment-dropzone.is-uploading {
		border-color: var(--bs-warning, #ffc107);
		background-color: rgba(255, 193, 7, 0.08);
	}
	.attachment-dropzone-status {
		min-height: 1.25rem;
		margin-top: 0.5rem;
		font-size: 0.85rem;
	}
</style>
</style>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
	<?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
	<main class="content-wrapper">
		<div class="mb-4">
			<a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left"></i> Tutti i movimenti</a>
		</div>
		<div class="card ag-card">
			<div class="card-header bg-transparent border-0">
				<h1 class="h4 mb-0">Nuovo movimento</h1>
			</div>
			<div class="card-body">
				<?php if ($errors): ?>
					<div class="alert alert-warning">
						<?php echo implode('<br>', array_map('sanitize_output', $errors)); ?>
					</div>
				<?php endif; ?>
				<form method="post" enctype="multipart/form-data" class="row g-4 align-items-start" novalidate>
					<input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
					<div class="col-12">
						<label class="form-label" for="cliente_id">Cliente</label>
						<select class="form-select" id="cliente_id" name="cliente_id">
							<option value="" <?php echo $data['cliente_id'] === '' ? 'selected' : ''; ?>>Nessun cliente (movimento interno)</option>
							<?php foreach ($clients as $client): ?>
								<option value="<?php echo (int) $client['id']; ?>" <?php echo (string) $client['id'] === (string) $data['cliente_id'] ? 'selected' : ''; ?>>
									<?php
										$labelPieces = array_filter([
											$client['ragione_sociale'] ?: null,
											trim(($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? '')) ?: null,
										]);
										echo sanitize_output($labelPieces ? implode(' - ', $labelPieces) : ('#' . $client['id']));
									?>
								</option>
							<?php endforeach; ?>
						</select>
						<small class="text-muted">Lascia vuoto per registrare un movimento interno senza collegare un cliente.</small>
					</div>
					<?php
						$currentOptions = $movementPresets[$data['tipo_movimento']] ?? [];
						$selectedOption = $data['descrizione_option'] ?: (in_array($data['descrizione'], $currentOptions, true) ? $data['descrizione'] : ($data['descrizione'] !== '' ? '__custom__' : ''));
						$showCustomInput = $selectedOption === '__custom__' || (!in_array($data['descrizione'], $currentOptions, true) && $data['descrizione'] !== '');
					?>
					<div class="col-12 col-xl-8">
						<div class="card border-0 shadow-sm h-100">
							<div class="card-header bg-transparent border-0 pb-0">
								<span class="text-uppercase text-body-secondary small fw-semibold">Dettagli operazione</span>
								<h2 class="h5 mb-0">Informazioni principali</h2>
							</div>
							<div class="card-body pt-3">
								<div class="row g-3">
									<div class="col-12">
										<label class="form-label" for="descrizione_select">Descrizione</label>
										<select class="form-select" id="descrizione_select" name="descrizione_select" required>
											<option value="">Seleziona descrizione</option>
											<?php foreach ($currentOptions as $option): ?>
												<option value="<?php echo sanitize_output($option); ?>" <?php echo $selectedOption === $option ? 'selected' : ''; ?>><?php echo sanitize_output($option); ?></option>
											<?php endforeach; ?>
											<option value="__custom__" <?php echo $selectedOption === '__custom__' ? 'selected' : ''; ?>>Descrizione personalizzata…</option>
										</select>
										<input class="form-control mt-2<?php echo $showCustomInput ? '' : ' d-none'; ?>" id="descrizione_custom" name="descrizione_custom" value="<?php echo sanitize_output($data['descrizione_custom'] ?: ($showCustomInput ? $data['descrizione'] : '')); ?>" maxlength="180" placeholder="Inserisci descrizione personalizzata" <?php echo $showCustomInput ? 'required' : ''; ?>>
										<small class="text-muted">Configura le opzioni in Impostazioni &gt; Descrizioni movimenti.</small>
									</div>
									<div class="col-sm-6">
										<label class="form-label" for="tipo_movimento">Tipo movimento</label>
										<select class="form-select" id="tipo_movimento" name="tipo_movimento">
											<?php foreach ($tipiMovimento as $tipo): ?>
												<option value="<?php echo $tipo; ?>" <?php echo $data['tipo_movimento'] === $tipo ? 'selected' : ''; ?>><?php echo $tipo; ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="col-sm-6">
										<label class="form-label" for="riferimento">Riferimento</label>
										<input class="form-control" id="riferimento" name="riferimento" value="<?php echo sanitize_output($data['riferimento']); ?>" maxlength="80" placeholder="Es. FATT-2025-001">
									</div>
									<div class="col-12">
										<label class="form-label" for="service_pricing_id">Servizio/Prodotto dal listino (opzionale)</label>
										<select class="form-select" id="service_pricing_id" name="service_pricing_id">
											<option value="">Seleziona dal listino</option>
											<?php foreach ($servicePricing as $index => $item): ?>
												<?php
													$resellerValue = number_format((float) ($item['cost_reseller'] ?? 0), 2, '.', '');
													$customerValue = number_format((float) ($item['cost_customer'] ?? 0), 2, '.', '');
												?>
												<option value="<?php echo (int) $index; ?>"
													data-name="<?php echo sanitize_output($item['name'] ?? ''); ?>"
													data-cost-reseller="<?php echo sanitize_output($resellerValue); ?>"
													data-cost-customer="<?php echo sanitize_output($customerValue); ?>"
													<?php echo (string) $index === $data['service_pricing_id'] ? 'selected' : ''; ?>>
													<?php echo sanitize_output($item['name'] ?? ''); ?> (Rivenditore: €<?php echo number_format((float) ($item['cost_reseller'] ?? 0), 2); ?>, Cliente: €<?php echo number_format((float) ($item['cost_customer'] ?? 0), 2); ?>)
												</option>
											<?php endforeach; ?>
										</select>
										<small class="text-muted">Selezionando, popola automaticamente descrizione e prezzo. Configura i listini in Impostazioni &gt; Listini.</small>
										<div class="row g-3 mt-1" id="listinoSummary">
											<div class="col-md-4">
												<label class="form-label" for="listino_cost_reseller_display">Costo al rivenditore</label>
												<div class="input-group">
													<span class="input-group-text">€</span>
													<input class="form-control" id="listino_cost_reseller_display" type="text" value="<?php echo $data['listino_costo_rivenditore'] !== '' ? sanitize_output($data['listino_costo_rivenditore']) : '0.00'; ?>" readonly>
												</div>
											</div>
											<div class="col-md-4">
												<label class="form-label" for="listino_cost_customer_display">Costo al cliente</label>
												<div class="input-group">
													<span class="input-group-text">€</span>
													<input class="form-control" id="listino_cost_customer_display" type="text" value="<?php echo $data['listino_costo_cliente'] !== '' ? sanitize_output($data['listino_costo_cliente']) : '0.00'; ?>" readonly>
												</div>
											</div>
											<div class="col-md-4">
												<label class="form-label" for="listino_margin_display">Margine stimato</label>
												<div class="input-group">
													<span class="input-group-text">€</span>
													<input class="form-control" id="listino_margin_display" type="text" value="<?php echo $data['listino_margine'] !== '' ? sanitize_output($data['listino_margine']) : '0.00'; ?>" readonly>
												</div>
											</div>
										</div>
									</div>
									<div class="col-sm-6">
										<label class="form-label" for="metodo">Metodo</label>
										<select class="form-select" id="metodo" name="metodo">
											<?php foreach ($metodi as $metodo): ?>
												<option value="<?php echo $metodo; ?>" <?php echo $data['metodo'] === $metodo ? 'selected' : ''; ?>><?php echo $metodo; ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="col-sm-6">
										<label class="form-label" for="stato">Stato</label>
										<select class="form-select" id="stato" name="stato">
											<?php foreach ($stati as $stato): ?>
												<option value="<?php echo $stato; ?>" <?php echo $data['stato'] === $stato ? 'selected' : ''; ?>><?php echo $stato; ?></option>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="col-sm-4">
										<label class="form-label" for="quantita">Quantità</label>
										<input class="form-control" id="quantita" name="quantita" type="number" min="1" step="1" value="<?php echo sanitize_output($data['quantita']); ?>" required>
									</div>
									<div class="col-sm-4">
										<label class="form-label" for="prezzo_unitario">Prezzo unitario</label>
										<div class="input-group">
											<span class="input-group-text">€</span>
											<input class="form-control" id="prezzo_unitario" name="prezzo_unitario" type="number" step="0.01" min="0.01" value="<?php echo sanitize_output($data['prezzo_unitario']); ?>" required>
										</div>
									</div>
									<div class="col-sm-4">
										<label class="form-label" for="totale_calcolato">Totale</label>
										<div class="input-group">
											<span class="input-group-text">€</span>
											<input class="form-control" id="totale_calcolato" type="text" value="<?php echo sanitize_output($data['importo']); ?>" readonly>
										</div>
										<small class="text-muted">Calcolato automaticamente in base a quantità e prezzo.</small>
									</div>
									<div class="col-12">
										<label class="form-label" for="note">Note</label>
										<textarea class="form-control" id="note" name="note" rows="4" placeholder="Note interne o condizioni"><?php echo sanitize_output($data['note']); ?></textarea>
									</div>
								</div>
							</div>
						</div>
					</div>
					<div class="col-12 col-xl-4">
						<div class="card border-0 shadow-sm h-100">
							<div class="card-header bg-transparent border-0 pb-0">
								<span class="text-uppercase text-body-secondary small fw-semibold">Pianificazione</span>
								<h2 class="h5 mb-0">Scadenze e allegati</h2>
							</div>
							<div class="card-body pt-3">
								<div class="row g-3">
									<div class="col-12">
										<label class="form-label" for="data_pagamento">Data movimento</label>
										<input class="form-control" id="data_pagamento" name="data_pagamento" type="text" inputmode="numeric" pattern="\d{2}/\d{2}/\d{4}" placeholder="gg/mm/aaaa" value="<?php echo sanitize_output((string) $data['data_pagamento']); ?>" required>
										<small class="text-muted">Imposta la giornata di registrazione nel formato gg/mm/aaaa.</small>
									</div>
									<div class="col-12">
										<label class="form-label" for="data_scadenza">Data scadenza</label>
										<input class="form-control" id="data_scadenza" name="data_scadenza" type="text" inputmode="numeric" pattern="\d{2}/\d{2}/\d{4}" placeholder="gg/mm/aaaa" value="<?php echo sanitize_output((string) $data['data_scadenza']); ?>">
										<small class="text-muted">Lascia vuoto se non è prevista una scadenza.</small>
									</div>
									<div class="col-12">
										<label class="form-label" for="allegato">Allegato (opzionale)</label>
										<div class="attachment-dropzone" id="attachmentDropzoneCreate">
											<div class="attachment-dropzone-icon mb-2"><i class="fa-solid fa-cloud-arrow-up"></i></div>
											<p class="mb-1">Trascina qui il file oppure</p>
											<button class="btn btn-sm btn-outline-warning" type="button" data-action="browse">Scegli file</button>
											<p class="text-muted small mb-0" data-role="filename">Nessun file selezionato</p>
											<div class="attachment-dropzone-status text-muted" data-role="status" aria-live="polite"></div>
										</div>
										<input class="form-control mt-2" id="allegato" name="allegato" type="file" accept="application/pdf,image/*">
										<small class="text-muted">Allega un PDF o un'immagine (es. distinta d'incasso o giustificativo).</small>
									</div>
								</div>
							</div>
							<div class="card-footer bg-transparent border-0 pt-0">
								<div class="d-flex align-items-center gap-2 small text-body-secondary">
									<i class="fa-regular fa-lightbulb text-warning"></i>
									<span>Monitora le scadenze dal cruscotto entrate/uscite per evitare ritardi.</span>
								</div>
							</div>
						</div>
					</div>
					<div class="col-12 d-flex justify-content-end gap-2">
						<a class="btn btn-secondary" href="index.php">Annulla</a>
						<button class="btn btn-warning text-dark" type="submit">Salva movimento</button>
					</div>
				</form>
			</div>
		</div>
	</main>
</div>
<?php $movementPresetsJson = json_encode($movementPresets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
	const movementDescriptions = <?php echo $movementPresetsJson ?: '{}'; ?>;
	const tipoSelect = document.getElementById('tipo_movimento');
	const descrSelect = document.getElementById('descrizione_select');
	const descrCustom = document.getElementById('descrizione_custom');
	const initAttachmentDropzone = (dropzoneId, inputId) => {
		const dropzone = document.getElementById(dropzoneId);
		const fileInput = document.getElementById(inputId);
		if (!dropzone || !fileInput) {
			return;
		}

		const browseButton = dropzone.querySelector('[data-action="browse"]');
		const filenameLabel = dropzone.querySelector('[data-role="filename"]');
		const statusIndicator = dropzone.querySelector('[data-role="status"]');
		let statusTimer = null;
		const setStatus = (state) => {
			if (!statusIndicator) {
				return;
			}
			if (statusTimer) {
				clearTimeout(statusTimer);
				statusTimer = null;
			}
			if (state === 'loading') {
				dropzone.classList.add('is-uploading');
				statusIndicator.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin me-1"></i>Caricamento…';
				statusIndicator.classList.remove('text-success');
			} else if (state === 'success') {
				dropzone.classList.remove('is-uploading');
				statusIndicator.innerHTML = '<i class="fa-solid fa-check me-1 text-success"></i>File pronto';
				statusIndicator.classList.add('text-success');
				statusTimer = setTimeout(() => {
					statusIndicator.textContent = '';
					statusIndicator.classList.remove('text-success');
				}, 2000);
			} else {
				dropzone.classList.remove('is-uploading');
				statusIndicator.textContent = '';
				statusIndicator.classList.remove('text-success');
			}
		};
		setStatus(null);
		const updateFileName = (file) => {
			if (!filenameLabel) {
				return;
			}
			filenameLabel.textContent = file ? file.name : 'Nessun file selezionato';
			if (file) {
				setStatus('success');
			} else {
				setStatus(null);
			}
		};
		const openPicker = () => {
			fileInput.click();
		};

		const preventDefaults = (event) => {
			event.preventDefault();
			event.stopPropagation();
		};

		['dragenter', 'dragover'].forEach((eventName) => {
			dropzone.addEventListener(eventName, (event) => {
				preventDefaults(event);
				dropzone.classList.add('is-dragover');
			});
		});

		['dragleave', 'dragend'].forEach((eventName) => {
			dropzone.addEventListener(eventName, (event) => {
				preventDefaults(event);
				dropzone.classList.remove('is-dragover');
			});
		});

		dropzone.addEventListener('drop', (event) => {
			preventDefaults(event);
			dropzone.classList.remove('is-dragover');
			setStatus('loading');
			const files = event.dataTransfer ? event.dataTransfer.files : null;
			if (files && files.length) {
				const file = files[0];
				if (typeof DataTransfer !== 'undefined') {
					const dataTransfer = new DataTransfer();
					dataTransfer.items.add(file);
					fileInput.files = dataTransfer.files;
				} else {
					fileInput.files = files;
				}
				fileInput.dispatchEvent(new Event('change', { bubbles: true }));
				updateFileName(file);
					if (fileInput.files && fileInput.files.length) {
						setStatus('loading');
					}
			}
		});

		dropzone.addEventListener('click', (event) => {
			if (browseButton && browseButton.contains(event.target)) {
				event.preventDefault();
			}
			openPicker();
		});

		if (browseButton) {
			browseButton.addEventListener('click', (event) => {
				event.preventDefault();
				openPicker();
			});
		}

		fileInput.addEventListener('change', () => {
			const file = fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
			updateFileName(file);
		});

		updateFileName(fileInput.files && fileInput.files[0] ? fileInput.files[0] : null);
		fileInput.classList.add('visually-hidden');
		fileInput.classList.remove('form-control');
		fileInput.classList.remove('mt-2');
	};

	if (!tipoSelect || !descrSelect || !descrCustom) {
		return;
	}

	let lastCustomValue = descrCustom.value;

	const applyCustomVisibility = () => {
		const isCustom = descrSelect.value === '__custom__';
		if (isCustom) {
			descrCustom.classList.remove('d-none');
			descrCustom.required = true;
			if (!descrCustom.value) {
				descrCustom.value = lastCustomValue;
			}
		} else {
			lastCustomValue = descrCustom.value;
			descrCustom.required = false;
			descrCustom.classList.add('d-none');
			descrCustom.value = '';
		}
	};

	const populateOptions = (type) => {
		const preservedValue = descrSelect.value;
		const options = movementDescriptions[type] || [];

		descrSelect.innerHTML = '';

		const placeholder = document.createElement('option');
		placeholder.value = '';
		placeholder.textContent = 'Seleziona descrizione';
		descrSelect.appendChild(placeholder);

		options.forEach((label) => {
			const option = document.createElement('option');
			option.value = label;
			option.textContent = label;
			descrSelect.appendChild(option);
		});

		const customOption = document.createElement('option');
		customOption.value = '__custom__';
		customOption.textContent = 'Descrizione personalizzata…';
		descrSelect.appendChild(customOption);

		let valueToSelect = preservedValue;
		if (valueToSelect && valueToSelect !== '__custom__' && !options.includes(valueToSelect)) {
			valueToSelect = options[0] || (lastCustomValue ? '__custom__' : '');
		}

		if (!valueToSelect) {
			valueToSelect = options[0] || '';
		}

		descrSelect.value = valueToSelect;

		if (descrSelect.value === '__custom__') {
			descrCustom.value = lastCustomValue;
		}

		applyCustomVisibility();
	};

	tipoSelect.addEventListener('change', () => {
		populateOptions(tipoSelect.value);
		applyServicePricingSelection();
	});

	descrSelect.addEventListener('change', () => {
		applyCustomVisibility();
	});

	const quantityInput = document.getElementById('quantita');
	const unitPriceInput = document.getElementById('prezzo_unitario');
	const totalInput = document.getElementById('totale_calcolato');
	const listinoResellerField = document.getElementById('listino_cost_reseller_display');
	const listinoCustomerField = document.getElementById('listino_cost_customer_display');
	const listinoMarginField = document.getElementById('listino_margin_display');

	const formatTotal = (value) => value.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

	const updateMarginDisplay = () => {
		if (!listinoMarginField || !quantityInput || !unitPriceInput || !tipoSelect) {
			return;
		}
		const resellerValue = listinoResellerField ? parseFloat(listinoResellerField.value.replace(',', '.')) || 0 : 0;
		const quantity = parseInt(quantityInput.value, 10);
		const unitPrice = parseFloat(unitPriceInput.value);
		const safeQuantity = Number.isFinite(quantity) && quantity > 0 ? quantity : 0;
		const safeUnitPrice = Number.isFinite(unitPrice) && unitPrice >= 0 ? unitPrice : 0;
		let margin = 0;
		if (tipoSelect.value === 'Entrata' && resellerValue > 0 && safeQuantity > 0) {
			margin = (safeUnitPrice - resellerValue) * safeQuantity;
		}
		listinoMarginField.value = margin.toFixed(2);
	};

	const recalcTotal = () => {
		if (!quantityInput || !unitPriceInput || !totalInput) {
			return;
		}
		const quantity = parseInt(quantityInput.value, 10);
		const unitPrice = parseFloat(unitPriceInput.value);
		const safeQuantity = Number.isFinite(quantity) && quantity > 0 ? quantity : 0;
		const safeUnitPrice = Number.isFinite(unitPrice) && unitPrice >= 0 ? unitPrice : 0;
		const total = safeQuantity * safeUnitPrice;
		totalInput.value = formatTotal(total);
		updateMarginDisplay();
	};

	if (quantityInput) {
		quantityInput.addEventListener('input', recalcTotal);
	}
	if (unitPriceInput) {
		unitPriceInput.addEventListener('input', recalcTotal);
	}

	descrCustom.addEventListener('input', () => {
		lastCustomValue = descrCustom.value;
	});

	// Service Pricing Integration
	const servicePricingSelect = document.getElementById('service_pricing_id');
	function applyServicePricingSelection() {
		if (!servicePricingSelect) {
			return;
		}
		const selectedIndex = servicePricingSelect.selectedIndex;
		if (selectedIndex < 0) {
			return;
		}
		const option = servicePricingSelect.options[selectedIndex];
		if (!option || option.value === '') {
			if (listinoResellerField) {
				listinoResellerField.value = '0.00';
			}
			if (listinoCustomerField) {
				listinoCustomerField.value = '0.00';
			}
			if (listinoMarginField) {
				listinoMarginField.value = '0.00';
			}
			return;
		}

		const name = option.getAttribute('data-name') || '';
		const costReseller = parseFloat(option.getAttribute('data-cost-reseller') || '0') || 0;
		const costCustomer = parseFloat(option.getAttribute('data-cost-customer') || '0') || 0;

		if (name && descrSelect) {
			descrSelect.value = '__custom__';
			descrCustom.value = name;
			lastCustomValue = name;
			applyCustomVisibility();
		}

		if (listinoResellerField) {
			listinoResellerField.value = costReseller.toFixed(2);
		}
		if (listinoCustomerField) {
			listinoCustomerField.value = costCustomer.toFixed(2);
		}

		if (unitPriceInput) {
			const isEntrata = tipoSelect ? tipoSelect.value === 'Entrata' : true;
			const price = isEntrata ? costCustomer : costReseller;
			const normalizedPrice = Number.isFinite(price) ? price : 0;
			unitPriceInput.value = normalizedPrice.toFixed(2);
			recalcTotal();
		}

		updateMarginDisplay();
	}

	if (servicePricingSelect) {
		servicePricingSelect.addEventListener('change', applyServicePricingSelection);
	}

	recalcTotal();
	populateOptions(tipoSelect.value);
	applyServicePricingSelection();
	initAttachmentDropzone('attachmentDropzoneCreate', 'allegato');
});
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
