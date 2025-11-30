<?php
require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager', 'Viewer');
$pageTitle = 'Dettaglio movimento';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
	header('Location: index.php');
	exit;
}

$stmt = $pdo->prepare('SELECT p.*, c.nome, c.cognome, c.ragione_sociale FROM entrate_uscite p LEFT JOIN clienti c ON c.id = p.cliente_id WHERE p.id = :id');
$stmt->execute([':id' => $id]);
$pagamento = $stmt->fetch();

if (!$pagamento) {
	header('Location: index.php?notfound=1');
	exit;
}

$puoModificare = current_user_can('Admin', 'Operatore');
$puoEliminare = current_user_can('Admin');

$notaCliente = array_filter([
	$pagamento['ragione_sociale'] ?: null,
	trim(($pagamento['cognome'] ?? '') . ' ' . ($pagamento['nome'] ?? '')) ?: null,
]);
$etichettaCliente = $notaCliente ? implode(' • ', $notaCliente) : 'Movimento interno';

$csrfToken = csrf_token();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
	<?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
	<main class="content-wrapper">
		<div class="d-flex justify-content-between align-items-center mb-4">
			<a class="btn btn-outline-warning" href="index.php"><i class="fa-solid fa-arrow-left"></i> Elenco movimenti</a>
			<div class="d-flex gap-2">
				<?php if ($puoModificare): ?>
					<a class="btn btn-warning text-dark" href="edit.php?id=<?php echo $id; ?>"><i class="fa-solid fa-pen"></i> Modifica</a>
				<?php endif; ?>
				<?php if ($puoEliminare): ?>
					<form method="post" action="delete.php" onsubmit="return confirm('Eliminare definitivamente questo movimento?');">
						<input type="hidden" name="id" value="<?php echo $id; ?>">
						<input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
						<button class="btn btn-outline-danger" type="submit"><i class="fa-solid fa-trash"></i> Elimina</button>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<div class="row g-4">
			<div class="col-lg-8">
				<div class="card ag-card h-100">
					<div class="card-header bg-transparent border-0">
						<h1 class="h4 mb-0">Dettagli movimento</h1>
					</div>
					<div class="card-body">
						<dl class="row mb-0">
							<dt class="col-sm-4 text-muted">Cliente</dt>
							<dd class="col-sm-8"><?php echo sanitize_output($etichettaCliente); ?></dd>
							<dt class="col-sm-4 text-muted">Descrizione</dt>
							<dd class="col-sm-8"><?php echo sanitize_output($pagamento['descrizione']); ?></dd>
							<dt class="col-sm-4 text-muted">Tipo movimento</dt>
							<dd class="col-sm-8">
								<?php
									$tipoMovimento = $pagamento['tipo_movimento'] ?? 'Entrata';
									$badgeClass = $tipoMovimento === 'Uscita' ? 'bg-danger text-white' : 'bg-success text-white';
								?>
								<span class="badge <?php echo $badgeClass; ?>"><?php echo sanitize_output($tipoMovimento); ?></span>
							</dd>
							<dt class="col-sm-4 text-muted">Metodo</dt>
							<dd class="col-sm-8"><?php echo sanitize_output($pagamento['metodo']); ?></dd>
							<dt class="col-sm-4 text-muted">Stato</dt>
							<dd class="col-sm-8"><span class="badge bg-warning text-white"><?php echo sanitize_output($pagamento['stato']); ?></span></dd>
							<?php
								$quantityValue = (int) ($pagamento['quantita'] ?? 1);
								if ($quantityValue <= 0) {
									$quantityValue = 1;
								}
								$unitPriceValue = isset($pagamento['prezzo_unitario']) ? (float) $pagamento['prezzo_unitario'] : 0.0;
							?>
							<dt class="col-sm-4 text-muted">Quantità</dt>
							<dd class="col-sm-8"><?php echo sanitize_output((string) $quantityValue); ?></dd>
							<dt class="col-sm-4 text-muted">Prezzo unitario</dt>
							<dd class="col-sm-8"><?php echo $unitPriceValue > 0 ? sanitize_output(format_currency($unitPriceValue)) : '—'; ?></dd>
							<dt class="col-sm-4 text-muted">Totale</dt>
							<dd class="col-sm-8">
								<?php
									$sign = ($pagamento['tipo_movimento'] ?? 'Entrata') === 'Uscita' ? -1 : 1;
									$amountClass = $sign < 0 ? 'text-danger' : 'text-success';
								?>
								<span class="<?php echo $amountClass; ?>"><?php echo sanitize_output(format_currency((float) $pagamento['importo'] * $sign)); ?></span>
								<?php if ($unitPriceValue > 0): ?>
									<small class="text-muted d-block">Calcolo: <?php echo sanitize_output($quantityValue . ' × ' . format_currency($unitPriceValue)); ?></small>
								<?php endif; ?>
							</dd>
							<dt class="col-sm-4 text-muted">Riferimento</dt>
							<dd class="col-sm-8"><?php echo sanitize_output($pagamento['riferimento'] ?: '—'); ?></dd>
							<dt class="col-sm-4 text-muted">Data scadenza</dt>
							<dd class="col-sm-8"><?php echo $pagamento['data_scadenza'] ? format_date_locale($pagamento['data_scadenza']) : '—'; ?></dd>
							<dt class="col-sm-4 text-muted">Data movimento</dt>
							<dd class="col-sm-8"><?php echo $pagamento['data_pagamento'] ? format_date_locale($pagamento['data_pagamento']) : '—'; ?></dd>
							<dt class="col-sm-4 text-muted">Note</dt>
							<dd class="col-sm-8"><?php echo nl2br(sanitize_output($pagamento['note'] ?: '—')); ?></dd>
							<dt class="col-sm-4 text-muted">Creato il</dt>
							<dd class="col-sm-8"><?php echo format_datetime_locale($pagamento['created_at']); ?></dd>
							<dt class="col-sm-4 text-muted">Ultimo aggiornamento</dt>
							<dd class="col-sm-8"><?php echo format_datetime_locale($pagamento['updated_at']); ?></dd>
						</dl>
					</div>
				</div>
			</div>
			<div class="col-lg-4">
				<div class="card ag-card h-100">
					<div class="card-header bg-transparent border-0">
						<h2 class="h5 mb-0">Allegato</h2>
					</div>
					<div class="card-body">
						<?php if (!empty($pagamento['allegato_path'])): ?>
							<div class="d-flex flex-column gap-2">
								<a class="btn btn-outline-warning" href="../../../<?php echo sanitize_output($pagamento['allegato_path']); ?>" target="_blank"><i class="fa-solid fa-download"></i> Scarica allegato</a>
								<?php if ($pagamento['allegato_hash']): ?>
									<small class="text-muted">SHA-256: <?php echo sanitize_output($pagamento['allegato_hash']); ?></small>
								<?php endif; ?>
							</div>
						<?php else: ?>
							<p class="text-muted mb-0">Nessun allegato disponibile.</p>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
	</main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
