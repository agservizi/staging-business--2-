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

$movementSummary = [
	'total_movements' => count($pagamenti),
	'entrate_count' => 0,
	'uscite_count' => 0,
	'pending_count' => 0,
	'internal_count' => 0,
	'net_total' => 0.0,
];

foreach ($pagamenti as $movement) {
	$type = (string) ($movement['tipo_movimento'] ?? 'Entrata');
	$amount = (float) ($movement['importo'] ?? 0);
	$status = (string) ($movement['stato'] ?? '');
	if ($type === 'Uscita') {
		$movementSummary['uscite_count']++;
		$movementSummary['net_total'] -= $amount;
	} else {
		$movementSummary['entrate_count']++;
		$movementSummary['net_total'] += $amount;
	}
	if (in_array($status, ['In lavorazione', 'In attesa'], true)) {
		$movementSummary['pending_count']++;
	}
	if (empty($movement['cliente_id'])) {
		$movementSummary['internal_count']++;
	}
}

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
	<?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
	<main class="content-wrapper">
		<style>
			.movements-shell {
				display: grid;
				gap: 1.5rem;
			}

			.movements-hero {
				position: relative;
				overflow: hidden;
				border: 1px solid rgba(58, 123, 213, 0.14);
				background:
					radial-gradient(circle at top left, rgba(58, 123, 213, 0.16), transparent 34%),
					radial-gradient(circle at top right, rgba(16, 185, 129, 0.12), transparent 26%),
					#fff;
			}

			.movements-pill {
				display: inline-flex;
				align-items: center;
				gap: 0.5rem;
				padding: 0.45rem 0.85rem;
				border-radius: 999px;
				background: rgba(58, 123, 213, 0.10);
				color: #2154d7;
				font-size: 0.72rem;
				font-weight: 700;
				letter-spacing: 0.08em;
				text-transform: uppercase;
			}

			.movements-kpis {
				display: grid;
				grid-template-columns: repeat(4, minmax(0, 1fr));
				gap: 1rem;
			}

			.movements-kpi {
				border: 1px solid rgba(15, 23, 42, 0.08);
				border-radius: 1.15rem;
				padding: 1rem 1.1rem;
				background: rgba(255, 255, 255, 0.88);
				box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
			}

			.movements-kpi-label {
				display: block;
				margin-bottom: 0.4rem;
				color: #64748b;
				font-size: 0.76rem;
				font-weight: 700;
				letter-spacing: 0.08em;
				text-transform: uppercase;
			}

			.movements-kpi-value {
				display: block;
				color: #0f172a;
				font-size: 1.85rem;
				font-weight: 800;
				line-height: 1;
			}

			.movements-kpi-note {
				display: block;
				margin-top: 0.45rem;
				color: #64748b;
				font-size: 0.86rem;
			}

			.movements-panel {
				border: 1px solid rgba(15, 23, 42, 0.08);
				border-radius: 1.3rem;
				background: #fff;
				box-shadow: 0 18px 44px rgba(15, 23, 42, 0.05);
			}

			.movements-table-card-body {
				padding: 1.25rem 1.25rem 1.4rem;
			}

			.movements-table-card-body .table-responsive {
				border: 1px solid rgba(15, 23, 42, 0.06);
				border-radius: 1rem;
				overflow: hidden;
			}

			.movements-table-card-body .dt-container .dt-layout-row:not(.dt-layout-table),
			.movements-table-card-body .dataTables_wrapper .row:not(.dt-layout-table) {
				margin: 0;
				padding-inline: 0.15rem;
			}

			.movements-table-card-body .dt-container .dt-layout-row:first-child,
			.movements-table-card-body .dataTables_wrapper .row:first-child {
				padding-bottom: 1rem;
			}

			.movements-table-card-body .dt-container .dt-layout-row:last-child,
			.movements-table-card-body .dataTables_wrapper .row:last-child {
				padding-top: 1rem;
			}

			.movements-table-card-body .dt-container .dt-layout-table {
				margin-top: 0;
			}

			.movements-table-card-body .dt-search,
			.movements-table-card-body .dataTables_filter,
			.movements-table-card-body .dt-paging,
			.movements-table-card-body .dataTables_paginate {
				margin-inline: 0.15rem;
			}

			.movements-toolbar-grid {
				display: grid;
				grid-template-columns: repeat(4, minmax(0, 1fr)) minmax(0, 1.4fr) auto;
				gap: 0.85rem;
				align-items: end;
			}

			.movements-table {
				--bs-table-bg: transparent;
				--bs-table-hover-bg: rgba(37, 99, 235, 0.04);
				margin-bottom: 0;
			}

			.movements-table thead th {
				border-bottom: 1px solid rgba(15, 23, 42, 0.08);
				color: #64748b;
				font-size: 0.76rem;
				font-weight: 700;
				letter-spacing: 0.08em;
				text-transform: uppercase;
				white-space: nowrap;
			}

			.movements-table td {
				padding-top: 1rem;
				padding-bottom: 1rem;
				border-color: rgba(15, 23, 42, 0.06);
				vertical-align: middle;
			}

			.movements-id {
				display: inline-flex;
				padding: 0.42rem 0.68rem;
				border-radius: 0.8rem;
				background: #f8fafc;
				color: #0f172a;
				font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
				font-size: 0.8rem;
				font-weight: 700;
			}

			.movements-empty {
				padding: 2.5rem 1rem;
				text-align: center;
				color: #64748b;
			}

			@media (max-width: 1199.98px) {
				.movements-kpis {
					grid-template-columns: repeat(2, minmax(0, 1fr));
				}

				.movements-toolbar-grid {
					grid-template-columns: repeat(2, minmax(0, 1fr));
				}
			}

			@media (max-width: 767.98px) {
				.movements-kpis {
					grid-template-columns: 1fr;
				}

				.movements-toolbar-grid {
					grid-template-columns: 1fr;
				}
			}
		</style>
		<?php if (isset($_GET['notfound'])): ?>
			<div class="alert alert-warning alert-dismissible fade show" role="alert">
				Il movimento richiesto non è stato trovato o è già stato rimosso.
				<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
			</div>
		<?php endif; ?>
		<div class="movements-shell">
			<section class="card movements-hero">
				<div class="card-body p-4 p-xl-5">
					<div class="row g-4 align-items-start">
						<div class="col-12 col-xl-7">
							<span class="movements-pill"><i class="fa-solid fa-coins"></i>Finanza operativa</span>
							<h1 class="mt-3 mb-2 fw-bold" style="max-width: 13ch;">Movimenti più chiari per incassi, uscite e controllo operativo.</h1>
							<p class="text-muted mb-0" style="max-width: 70ch;">
								Controlla il flusso economico aziendale, filtra rapidamente per stato, tipo o cliente e raggiungi ogni movimento con una lettura più ordinata.
							</p>
						</div>
						<div class="col-12 col-xl-5">
							<div class="movements-kpis">
								<div class="movements-kpi">
									<span class="movements-kpi-label">Movimenti</span>
									<span class="movements-kpi-value"><?php echo (int) $movementSummary['total_movements']; ?></span>
									<span class="movements-kpi-note">Risultati del filtro attivo</span>
								</div>
								<div class="movements-kpi">
									<span class="movements-kpi-label">Entrate / uscite</span>
									<span class="movements-kpi-value"><?php echo (int) $movementSummary['entrate_count']; ?> / <?php echo (int) $movementSummary['uscite_count']; ?></span>
									<span class="movements-kpi-note">Composizione del registro</span>
								</div>
								<div class="movements-kpi">
									<span class="movements-kpi-label">Da completare</span>
									<span class="movements-kpi-value"><?php echo (int) $movementSummary['pending_count']; ?></span>
									<span class="movements-kpi-note">In lavorazione o in attesa</span>
								</div>
								<div class="movements-kpi">
									<span class="movements-kpi-label">Saldo netto</span>
									<span class="movements-kpi-value"><?php echo sanitize_output(format_currency((float) $movementSummary['net_total'])); ?></span>
									<span class="movements-kpi-note"><?php echo (int) $movementSummary['internal_count']; ?> movimenti interni nel periodo filtrato</span>
								</div>
							</div>
						</div>
					</div>
				</div>
			</section>

			<section class="card movements-panel">
				<div class="card-body p-4">
					<div class="d-flex justify-content-between align-items-start flex-wrap gap-3 mb-4">
						<div>
							<h2 class="h5 mb-1">Filtri movimenti</h2>
							<p class="text-muted small mb-0">Raffina il registro economico per stato, tipo, cliente o riferimento.</p>
						</div>
						<div class="d-flex gap-2 flex-wrap">
							<a class="btn btn-outline-warning" href="<?php echo dashboard_url(); ?>"><i class="fa-solid fa-gauge-high me-2"></i>Dashboard</a>
							<?php if ($puoCreare): ?>
								<a class="btn btn-warning text-dark" href="<?php echo entrate_uscite_module_url('create'); ?>"><i class="fa-solid fa-circle-plus me-2"></i>Nuovo movimento</a>
							<?php endif; ?>
						</div>
					</div>
					<form method="get" role="search">
						<div class="movements-toolbar-grid">
							<div>
								<label class="form-label small text-uppercase text-muted fw-semibold" for="stato">Stato</label>
								<select class="form-select" id="stato" name="stato" aria-label="Filtra per stato">
									<option value="">Tutti gli stati</option>
									<?php foreach ($stati as $stato): ?>
										<option value="<?php echo sanitize_output($stato); ?>" <?php echo $filters['stato'] === $stato ? 'selected' : ''; ?>><?php echo sanitize_output($stato); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div>
								<label class="form-label small text-uppercase text-muted fw-semibold" for="tipo_movimento">Tipo</label>
								<select class="form-select" id="tipo_movimento" name="tipo_movimento" aria-label="Filtra per tipo movimento">
									<option value="">Entrate e uscite</option>
									<?php foreach ($tipiMovimento as $tipo): ?>
										<option value="<?php echo sanitize_output($tipo); ?>" <?php echo $filters['tipo_movimento'] === $tipo ? 'selected' : ''; ?>><?php echo sanitize_output($tipo); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<div>
								<label class="form-label small text-uppercase text-muted fw-semibold" for="cliente_id">Cliente</label>
								<select class="form-select" id="cliente_id" name="cliente_id" aria-label="Filtra per cliente">
									<option value="">Tutti i clienti</option>
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
							</div>
							<div></div>
							<div>
								<label class="form-label small text-uppercase text-muted fw-semibold" for="search">Ricerca</label>
								<input class="form-control" id="search" type="search" name="search" value="<?php echo sanitize_output($filters['search']); ?>" placeholder="Descrizione, riferimento o cliente">
							</div>
							<div class="d-flex gap-2">
								<button class="btn btn-warning w-100" type="submit"><i class="fa-solid fa-filter me-2"></i>Filtra</button>
								<a class="btn btn-outline-warning" href="<?php echo entrate_uscite_module_url('index'); ?>" title="Reimposta filtri"><i class="fa-solid fa-rotate-left"></i></a>
							</div>
						</div>
					</form>
				</div>
			</section>

			<section class="card movements-panel">
				<div class="card-header bg-transparent border-0 px-4 pt-4 pb-0">
					<h2 class="h5 mb-1">Movimenti registrati</h2>
					<p class="text-muted small mb-0">Elenco operativo di incassi e uscite con dettaglio cliente, stato e scadenze.</p>
				</div>
				<div class="card-body movements-table-card-body">
				<?php if ($pagamenti): ?>
					<div class="table-responsive">
						<table class="table movements-table table-hover align-middle" data-datatable="true">
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
										<td><span class="movements-id">#<?php echo (int) $pagamento['id']; ?></span></td>
										<td>
											<strong><?php echo sanitize_output($pagamento['descrizione']); ?></strong><br>
											<small class="text-muted"><?php echo $pagamento['riferimento'] ? sanitize_output($pagamento['riferimento']) : '—'; ?></small>
											<?php if (!empty($pagamento['listino_voce'])): ?>
												<small class="d-block text-info">Listino: <?php echo sanitize_output($pagamento['listino_voce']); ?><?php if ($pagamento['listino_margine'] !== null && $pagamento['tipo_movimento'] === 'Entrata'): ?> • Margine: <?php echo sanitize_output(format_currency((float) $pagamento['listino_margine'])); ?><?php endif; ?></small>
											<?php endif; ?>
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
												<a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo entrate_uscite_module_url('view', ['id' => (int) $pagamento['id']]); ?>" title="Dettagli">
													<i class="fa-solid fa-eye"></i>
												</a>
												<?php if ($puoModificare): ?>
													<a class="btn btn-icon btn-soft-accent btn-sm" href="<?php echo entrate_uscite_module_url('edit', ['id' => (int) $pagamento['id']]); ?>" title="Modifica">
														<i class="fa-solid fa-pen"></i>
													</a>
												<?php endif; ?>
												<?php if ($puoEliminare): ?>
													<form method="post" action="<?php echo entrate_uscite_module_url('delete'); ?>" class="d-inline" onsubmit="return confirm('Confermi l\'eliminazione di questo movimento?');">
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
					<div class="movements-empty">
						<i class="fa-solid fa-money-bill-wave fa-2x mb-3"></i>
						<p class="mb-1">Nessun movimento corrisponde ai filtri selezionati.</p>
						<a class="btn btn-outline-warning" href="<?php echo entrate_uscite_module_url('index'); ?>">Reimposta filtri</a>
					</div>
				<?php endif; ?>
				</div>
			</section>
		</div>
	</main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
