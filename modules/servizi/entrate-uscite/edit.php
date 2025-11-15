<?php
use App\Services\SettingsService;
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Modifica movimento';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
	header('Location: index.php');
	exit;
}

$stmt = $pdo->prepare('SELECT * FROM entrate_uscite WHERE id = :id');
$stmt->execute([':id' => $id]);
$pagamento = $stmt->fetch();

if (!$pagamento) {
	header('Location: index.php?notfound=1');
	exit;
}

$stati = ['In lavorazione', 'In attesa', 'Completato', 'Annullato'];
$metodi = ['Bonifico', 'Carta di credito', 'Carta di debito', 'Contanti', 'RID', 'Altro'];
$tipiMovimento = ['Entrata', 'Uscita'];

$clientsStmt = $pdo->query('SELECT id, nome, cognome, ragione_sociale FROM clienti ORDER BY ragione_sociale, cognome, nome');
$clients = $clientsStmt->fetchAll();

$projectRoot = realpath(__DIR__ . '/../../../') ?: __DIR__ . '/../../../';
$settingsService = new SettingsService($pdo, $projectRoot);
$storedDescriptions = $settingsService->getMovementDescriptions();
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

$data = $pagamento;
$data['cliente_id'] = isset($data['cliente_id']) ? (string) $data['cliente_id'] : '';
$data['tipo_movimento'] = $data['tipo_movimento'] ?? 'Entrata';
$data['quantita'] = (string) max((int) ($data['quantita'] ?? 1), 1);
$unitPriceFromDb = isset($data['prezzo_unitario']) ? (float) $data['prezzo_unitario'] : (float) $data['importo'];
$data['prezzo_unitario'] = number_format($unitPriceFromDb, 2, '.', '');
$data['importo'] = number_format((float) $data['importo'], 2, '.', '');
$data['data_scadenza'] = !empty($data['data_scadenza']) ? date('d/m/Y', strtotime($data['data_scadenza'])) : '';
$data['data_pagamento'] = !empty($data['data_pagamento']) ? date('d/m/Y', strtotime($data['data_pagamento'])) : '';
$data['descrizione'] = trim((string) ($data['descrizione'] ?? ''));
$currentOptions = $movementPresets[$data['tipo_movimento']] ?? [];
$data['descrizione_option'] = in_array($data['descrizione'], $currentOptions, true) ? $data['descrizione'] : '__custom__';
$data['descrizione_custom'] = $data['descrizione_option'] === '__custom__' ? $data['descrizione'] : '';
$errors = [];
$clienteId = $data['cliente_id'] !== '' ? (int) $data['cliente_id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	require_valid_csrf();

	$fields = ['cliente_id', 'riferimento', 'metodo', 'stato', 'tipo_movimento', 'data_scadenza', 'data_pagamento', 'note'];
	foreach ($fields as $field) {
		$data[$field] = trim($_POST[$field] ?? '');
	}

	$data['quantita'] = trim($_POST['quantita'] ?? '1');
	$data['prezzo_unitario'] = trim($_POST['prezzo_unitario'] ?? '');

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

	$data['descrizione'] = trim($data['descrizione'] ?? '');

	$clienteId = $data['cliente_id'] === '' ? null : (int) $data['cliente_id'];
	if ($clienteId !== null && $clienteId <= 0) {
		$errors[] = 'Se selezioni un cliente deve essere valido.';
	}

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

	if (!$errors && $quantityValue !== null && $unitPriceValue !== null) {
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
		$errors[] = 'Specifica la data in cui stai registrando o aggiornando il movimento.';
	} else {
		$pagamentoDate = DateTimeImmutable::createFromFormat('d/m/Y', $data['data_pagamento']);
		if (!$pagamentoDate || $pagamentoDate->format('d/m/Y') !== $data['data_pagamento']) {
			$errors[] = 'La data del movimento non è valida (usa il formato gg/mm/aaaa).';
		} else {
			$pagamentoForDb = $pagamentoDate->format('Y-m-d');
		}
	}

	$newPath = $pagamento['allegato_path'] ?? null;
	$newHash = $pagamento['allegato_hash'] ?? null;
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
				if (!empty($pagamento['allegato_path'])) {
					$oldAbsolute = public_path($pagamento['allegato_path']);
					if (is_file($oldAbsolute)) {
						@unlink($oldAbsolute);
					}
				}
				$newPath = 'assets/uploads/entrate-uscite/' . $fileName;
				$newHash = hash_file('sha256', $destination);
			}
		}
	}

	if (!$errors) {
		$stmt = $pdo->prepare('UPDATE entrate_uscite SET
			cliente_id = :cliente_id,
			descrizione = :descrizione,
			riferimento = :riferimento,
			metodo = :metodo,
			stato = :stato,
			tipo_movimento = :tipo_movimento,
			quantita = :quantita,
			prezzo_unitario = :prezzo_unitario,
			importo = :importo,
			data_scadenza = :data_scadenza,
			data_pagamento = :data_pagamento,
			note = :note,
			allegato_path = :allegato_path,
			allegato_hash = :allegato_hash,
			updated_at = NOW()
		WHERE id = :id');
		$stmt->execute([
			':cliente_id' => $clienteId,
			':descrizione' => $data['descrizione'],
			':riferimento' => $data['riferimento'] ?: null,
			':metodo' => $data['metodo'],
			':stato' => $data['stato'],
			':tipo_movimento' => $data['tipo_movimento'],
			':quantita' => (int) $data['quantita'],
			':prezzo_unitario' => $data['prezzo_unitario'],
			':importo' => $data['importo'],
			':data_scadenza' => $scadenzaForDb,
			':data_pagamento' => $pagamentoForDb,
			':note' => $data['note'] ?: null,
			':allegato_path' => $newPath,
			':allegato_hash' => $newHash,
			':id' => $id,
		]);

		add_flash('success', 'Movimento aggiornato correttamente.');
		header('Location: view.php?id=' . $id);
		exit;
	}
}

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
	<?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
	<main class="content-wrapper">
		<div class="mb-4">
			<a class="btn btn-outline-warning" href="view.php?id=<?php echo $id; ?>"><i class="fa-solid fa-arrow-left"></i> Dettaglio movimento</a>
		</div>
		<div class="card ag-card">
			<div class="card-header bg-transparent border-0">
				<h1 class="h4 mb-0">Modifica movimento</h1>
			</div>
			<div class="card-body">
				<?php if ($errors): ?>
					<div class="alert alert-warning">
						<?php echo implode('<br>', array_map('sanitize_output', $errors)); ?>
					</div>
				<?php endif; ?>
				<form method="post" enctype="multipart/form-data" class="row g-4" novalidate>
					<input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
					<div class="col-12">
						<div class="alert alert-secondary py-2">
							Lascia vuoto per movimento interno (nessun cliente associato).
						</div>
					</div>
					<div class="col-md-6">
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
										echo sanitize_output($labelPieces ? implode(' • ', $labelPieces) : ('#' . $client['id']));
								?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php
						$currentOptions = $movementPresets[$data['tipo_movimento']] ?? [];
						$selectedOption = $data['descrizione_option'] ?: (in_array($data['descrizione'], $currentOptions, true) ? $data['descrizione'] : ($data['descrizione'] !== '' ? '__custom__' : ''));
						$showCustomInput = $selectedOption === '__custom__' || (!in_array($data['descrizione'], $currentOptions, true) && $data['descrizione'] !== '');
					?>
					<div class="col-md-6">
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
					<div class="col-md-4">
						<label class="form-label" for="tipo_movimento">Tipo movimento</label>
						<select class="form-select" id="tipo_movimento" name="tipo_movimento">
							<?php foreach ($tipiMovimento as $tipo): ?>
								<option value="<?php echo $tipo; ?>" <?php echo $data['tipo_movimento'] === $tipo ? 'selected' : ''; ?>><?php echo $tipo; ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-4">
						<label class="form-label" for="riferimento">Riferimento</label>
						<input class="form-control" id="riferimento" name="riferimento" value="<?php echo sanitize_output($data['riferimento'] ?? ''); ?>" maxlength="80">
					</div>
					<div class="col-md-4">
						<label class="form-label" for="metodo">Metodo</label>
						<select class="form-select" id="metodo" name="metodo">
							<?php foreach ($metodi as $metodo): ?>
								<option value="<?php echo $metodo; ?>" <?php echo $data['metodo'] === $metodo ? 'selected' : ''; ?>><?php echo $metodo; ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-4">
						<label class="form-label" for="stato">Stato</label>
						<select class="form-select" id="stato" name="stato">
							<?php foreach ($stati as $stato): ?>
								<option value="<?php echo $stato; ?>" <?php echo $data['stato'] === $stato ? 'selected' : ''; ?>><?php echo $stato; ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="col-md-4">
						<label class="form-label" for="quantita">Quantità</label>
						<input class="form-control" id="quantita" name="quantita" type="number" min="1" step="1" value="<?php echo sanitize_output((string) $data['quantita']); ?>" required>
					</div>
					<div class="col-md-4">
						<label class="form-label" for="prezzo_unitario">Prezzo unitario</label>
						<div class="input-group">
							<span class="input-group-text">€</span>
							<input class="form-control" id="prezzo_unitario" name="prezzo_unitario" type="number" step="0.01" min="0.01" value="<?php echo sanitize_output((string) $data['prezzo_unitario']); ?>" required>
						</div>
					</div>
					<div class="col-md-4">
						<label class="form-label" for="totale_calcolato">Totale</label>
						<div class="input-group">
							<span class="input-group-text">€</span>
							<input class="form-control" id="totale_calcolato" type="text" value="<?php echo sanitize_output((string) $data['importo']); ?>" readonly>
						</div>
						<small class="text-muted">Calcolato automaticamente in base a quantità e prezzo.</small>
					</div>
					<div class="col-md-4">
						<label class="form-label" for="data_scadenza">Data scadenza</label>
						<input class="form-control" id="data_scadenza" name="data_scadenza" type="text" inputmode="numeric" pattern="\d{2}/\d{2}/\d{4}" placeholder="gg/mm/aaaa" value="<?php echo sanitize_output((string) $data['data_scadenza']); ?>">
					</div>
					<div class="col-md-4">
						<label class="form-label" for="data_pagamento">Data movimento</label>
						<input class="form-control" id="data_pagamento" name="data_pagamento" type="text" inputmode="numeric" pattern="\d{2}/\d{2}/\d{4}" placeholder="gg/mm/aaaa" value="<?php echo sanitize_output((string) $data['data_pagamento']); ?>">
						<small class="text-muted">Formato richiesto: gg/mm/aaaa.</small>
					</div>
					<div class="col-12">
						<label class="form-label" for="note">Note</label>
						<textarea class="form-control" id="note" name="note" rows="4"><?php echo sanitize_output($data['note'] ?? ''); ?></textarea>
					</div>
					<div class="col-md-6">
						<label class="form-label" for="allegato">Allegato (opzionale)</label>
						<input class="form-control" id="allegato" name="allegato" type="file" accept="application/pdf,image/*">
						<?php if (!empty($pagamento['allegato_path'])): ?>
							<small class="d-block mt-2">Allegato attuale: <a class="link-warning" href="../../../<?php echo sanitize_output($pagamento['allegato_path']); ?>" target="_blank">Scarica</a></small>
						<?php else: ?>
							<small class="text-muted">Nessun file caricato.</small>
						<?php endif; ?>
					</div>
					<div class="col-12 d-flex justify-content-end gap-2">
						<a class="btn btn-secondary" href="view.php?id=<?php echo $id; ?>">Annulla</a>
						<button class="btn btn-warning text-dark" type="submit">Salva modifiche</button>
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
		});

		descrSelect.addEventListener('change', () => {
			applyCustomVisibility();
		});

		const quantityInput = document.getElementById('quantita');
		const unitPriceInput = document.getElementById('prezzo_unitario');
		const totalInput = document.getElementById('totale_calcolato');

		const formatTotal = (value) => value.toLocaleString('it-IT', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

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

		recalcTotal();
		populateOptions(tipoSelect.value);
	});
	</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
