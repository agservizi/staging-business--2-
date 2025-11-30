<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');
$pageTitle = 'Entrate/Uscite';

$stati = ['In lavorazione', 'In attesa', 'Completato', 'Annullato'];
$metodi = ['Bonifico', 'Carta di credito', 'Carta di debito', 'Contanti', 'RID', 'Altro'];
$tipiMovimento = ['Entrata', 'Uscita'];

$clientsStmt = $pdo->query('SELECT id, nome, cognome, ragione_sociale FROM clienti ORDER BY ragione_sociale, cognome, nome');
$clients = $clientsStmt ? $clientsStmt->fetchAll() : [];

$puoCreare = current_user_can('Admin', 'Operatore');
$puoModificare = current_user_can('Admin', 'Operatore');
$puoEliminare = current_user_can('Admin');

$filters = [
	'stato' => isset($_GET['stato']) && in_array($_GET['stato'], $stati, true) ? $_GET['stato'] : null,
	'tipo_movimento' => isset($_GET['tipo_movimento']) && in_array($_GET['tipo_movimento'], $tipiMovimento, true) ? $_GET['tipo_movimento'] : null,
	'cliente_id' => isset($_GET['cliente_id'])
		? ($_GET['cliente_id'] === 'none'
			? 'none'
			: (ctype_digit($_GET['cliente_id']) ? (int) $_GET['cliente_id'] : null))
		: null,
	'search' => trim($_GET['search'] ?? ''),
];

$params = [];
$sql = "SELECT p.*, c.nome, c.cognome, c.ragione_sociale
	FROM entrate_uscite p
	LEFT JOIN clienti c ON p.cliente_id = c.id
	WHERE 1 = 1";

if ($filters['stato']) {
	$sql .= ' AND p.stato = :stato';
	$params[':stato'] = $filters['stato'];
}

if ($filters['tipo_movimento']) {
	$sql .= ' AND p.tipo_movimento = :tipo_movimento';
	$params[':tipo_movimento'] = $filters['tipo_movimento'];
}

if ($filters['cliente_id'] !== null) {
	if ($filters['cliente_id'] === 'none') {
		$sql .= ' AND p.cliente_id IS NULL';
	} else {
		$sql .= ' AND p.cliente_id = :cliente_id';
		$params[':cliente_id'] = $filters['cliente_id'];
	}
}

if ($filters['search'] !== '') {
	$sql .= ' AND (p.descrizione LIKE :search OR p.riferimento LIKE :search OR c.ragione_sociale LIKE :search OR c.nome LIKE :search OR c.cognome LIKE :search)';
	$params[':search'] = '%' . $filters['search'] . '%';
}

$sql .= ' ORDER BY COALESCE(p.data_pagamento, p.data_scadenza, p.updated_at) DESC, p.id DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pagamenti = $stmt->fetchAll();

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
	<?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
	<main class="content-wrapper">
		<?php if (isset($_GET['notfound'])): ?>
			<div class="alert alert-warning alert-dismissible fade show" role="alert">
				Il movimento richiesto non è stato trovato o è già stato rimosso.
				<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
			</div>
		<?php endif; ?>
		<div class="page-toolbar mb-4">
			<div>
				<h1 class="h3 mb-1">Entrate/Uscite</h1>
				<p class="text-muted mb-0">Registra e monitora movimenti economici interni all&rsquo;azienda.</p>
			</div>
			<div class="toolbar-actions">
				<a class="btn btn-outline-warning" href="../../../dashboard.php"><i class="fa-solid fa-gauge-high me-2"></i>Dashboard</a>
				<?php if ($puoCreare): ?>
					<a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo movimento</a>
				<?php endif; ?>
			</div>
		</div>

		<div class="card ag-card mb-4">
			<div class="card-header bg-transparent border-0">
				<h2 class="h5 mb-0">Filtri</h2>
			</div>
			<div class="card-body">
				<form class="toolbar-search" method="get" role="search">
					<div class="input-group flex-wrap flex-xl-nowrap">
						<select class="form-select" id="stato" name="stato" aria-label="Filtra per stato">
							<option value="">Stato: tutti</option>
							<?php foreach ($stati as $stato): ?>
								<option value="<?php echo sanitize_output($stato); ?>" <?php echo $filters['stato'] === $stato ? 'selected' : ''; ?>><?php echo sanitize_output($stato); ?></option>
							<?php endforeach; ?>
						</select>
						<select class="form-select" id="tipo_movimento" name="tipo_movimento" aria-label="Filtra per tipo movimento">
							<option value="">Tipo: entrate e uscite</option>
							<?php foreach ($tipiMovimento as $tipo): ?>
								<option value="<?php echo sanitize_output($tipo); ?>" <?php echo $filters['tipo_movimento'] === $tipo ? 'selected' : ''; ?>><?php echo sanitize_output($tipo); ?></option>
							<?php endforeach; ?>
						</select>
						<select class="form-select" id="cliente_id" name="cliente_id" aria-label="Filtra per cliente">
							<option value="">Cliente: tutti</option>
							<option value="none" <?php echo $filters['cliente_id'] === 'none' ? 'selected' : ''; ?>>Solo movimenti interni</option>
							<?php foreach ($clients as $client): ?>
								<?php
									$clientLabelParts = array_filter([
										$client['ragione_sociale'] ?: null,
										trim(($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? '')) ?: null,
									]);
									$clientLabel = $clientLabelParts ? implode(' - ', $clientLabelParts) : ('#' . $client['id']);
								?>
								<option value="<?php echo (int) $client['id']; ?>" <?php echo $filters['cliente_id'] === (int) $client['id'] ? 'selected' : ''; ?>><?php echo sanitize_output($clientLabel); ?></option>
							<?php endforeach; ?>
						</select>
						<input class="form-control" id="search" type="search" name="search" value="<?php echo sanitize_output($filters['search']); ?>" placeholder="Cerca descrizione o riferimento">
						<button class="btn btn-warning" type="submit" title="Applica filtri"><i class="fa-solid fa-filter"></i></button>
						<a class="btn btn-outline-warning" href="index.php" title="Reimposta filtri"><i class="fa-solid fa-rotate-left"></i></a>
					</div>
				</form>
			</div>
		</div>

		<div class="card ag-card">
			<div class="card-header bg-transparent border-0">
				<h2 class="h5 mb-0">Movimenti registrati</h2>
			</div>
			<div class="card-body">
				<?php if ($pagamenti): ?>
					<div class="table-responsive">
						<table class="table table-dark table-hover align-middle" data-datatable="true">
							<thead>
								<tr>
									<th>ID</th>
									<th>Descrizione</th>
									<th>Cliente</th>
									<th>Tipo</th>
									<th>Totale</th>
									<th>Stato</th>
									<th>Metodo</th>
									<th>Scadenza</th>
									<th>Data movimento</th>
									<th class="text-end">Azioni</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($pagamenti as $pagamento): ?>
									<tr>
										<td>#<?php echo (int) $pagamento['id']; ?></td>
										<td>
											<strong><?php echo sanitize_output($pagamento['descrizione']); ?></strong><br>
											<small class="text-muted"><?php echo $pagamento['riferimento'] ? sanitize_output($pagamento['riferimento']) : '—'; ?></small>
										</td>
										<td>
											<?php
												$clientLabelParts = array_filter([
													$pagamento['ragione_sociale'] ?? null,
													trim(($pagamento['cognome'] ?? '') . ' ' . ($pagamento['nome'] ?? '')) ?: null,
												]);
												$clientLabel = $clientLabelParts ? implode(' - ', $clientLabelParts) : null;
											?>
											<?php if ($pagamento['cliente_id']): ?>
												<?php if ($clientLabel): ?>
													<?php echo sanitize_output($clientLabel); ?>
												<?php else: ?>
													<span class="text-muted">Cliente #<?php echo (int) $pagamento['cliente_id']; ?></span>
												<?php endif; ?>
											<?php else: ?>
												<span class="text-muted">Movimento interno</span>
											<?php endif; ?>
										</td>
										<td><?php echo sanitize_output($pagamento['tipo_movimento'] ?? 'Entrata'); ?></td>
										<td>
											<?php
												$sign = (($pagamento['tipo_movimento'] ?? 'Entrata') === 'Uscita') ? -1 : 1;
												$amountClass = $sign < 0 ? 'text-danger' : 'text-success';
												$quantityDisplay = (int) ($pagamento['quantita'] ?? 1);
												if ($quantityDisplay <= 0) {
													$quantityDisplay = 1;
												}
												$unitPriceDisplay = isset($pagamento['prezzo_unitario']) ? (float) $pagamento['prezzo_unitario'] : 0.0;
												$showBreakdown = $quantityDisplay > 1 || ($unitPriceDisplay > 0 && abs($unitPriceDisplay - (float) $pagamento['importo']) > 0.01);
											?>
											<span class="<?php echo $amountClass; ?>"><?php echo sanitize_output(format_currency((float) $pagamento['importo'] * $sign)); ?></span>
											<?php if ($showBreakdown): ?>
												<small class="text-muted d-block"><?php echo sanitize_output($quantityDisplay . ' × ' . format_currency($unitPriceDisplay)); ?></small>
											<?php endif; ?>
										</td>
										<td><span class="badge ag-badge text-uppercase"><?php echo sanitize_output($pagamento['stato']); ?></span></td>
										<td><?php echo sanitize_output($pagamento['metodo']); ?></td>
										<td><?php echo $pagamento['data_scadenza'] ? sanitize_output(date('d/m/Y', strtotime($pagamento['data_scadenza']))) : '<span class="text-muted">—</span>'; ?></td>
										<td><?php echo $pagamento['data_pagamento'] ? sanitize_output(date('d/m/Y', strtotime($pagamento['data_pagamento']))) : '<span class="text-muted">—</span>'; ?></td>
										<td class="text-end">
											<div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap">
												<a class="btn btn-icon btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $pagamento['id']; ?>" title="Dettagli">
													<i class="fa-solid fa-eye"></i>
												</a>
												<?php if ($puoModificare): ?>
													<a class="btn btn-icon btn-soft-accent btn-sm" href="edit.php?id=<?php echo (int) $pagamento['id']; ?>" title="Modifica">
														<i class="fa-solid fa-pen"></i>
													</a>
												<?php endif; ?>
												<?php if ($puoEliminare): ?>
													<form method="post" action="delete.php" class="d-inline" onsubmit="return confirm('Confermi l\'eliminazione di questo movimento?');">
														<input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
														<input type="hidden" name="id" value="<?php echo (int) $pagamento['id']; ?>">
														<button class="btn btn-icon btn-soft-danger btn-sm" type="submit" title="Elimina">
															<i class="fa-solid fa-trash"></i>
														</button>
													</form>
												<?php endif; ?>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php else: ?>
					<div class="text-center text-muted py-5">
						<i class="fa-solid fa-money-bill-wave fa-2x mb-3"></i>
						<p class="mb-1">Nessun movimento corrisponde ai filtri selezionati.</p>
						<a class="btn btn-outline-warning" href="index.php">Reimposta filtri</a>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
