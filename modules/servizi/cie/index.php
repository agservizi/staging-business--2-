<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/mailer.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Prenotazione CIE';

$csrfToken = csrf_token();

$filters = [
    'search' => trim((string) ($_GET['search'] ?? '')),
    'stato' => (string) ($_GET['stato'] ?? ''),
    'cliente_id' => (int) ($_GET['cliente_id'] ?? 0),
    'created_from' => (string) ($_GET['created_from'] ?? ''),
    'created_to' => (string) ($_GET['created_to'] ?? ''),
];

if ($filters['cliente_id'] <= 0) {
    unset($filters['cliente_id']);
}

$bookings = cie_fetch_bookings($pdo, $filters);
$stats = cie_fetch_stats($pdo);
$clients = cie_fetch_clients($pdo);
$statuses = cie_status_map();

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Prenotazione Carta d'Identità Elettronica</h1>
                <p class="text-muted mb-0">Gestisci le richieste dei cittadini, monitora gli appuntamenti e conserva la documentazione allegata.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-warning" href="https://www.prenotazionicie.interno.gov.it/cittadino/n/sc/wizardAppuntamentoCittadino/sceltaComune" target="_blank" rel="noopener"><i class="fa-solid fa-id-card me-2"></i>Portale CIE</a>
                <a class="btn btn-warning text-dark" href="create.php"><i class="fa-solid fa-circle-plus me-2"></i>Nuova richiesta</a>
            </div>
        </div>
        <section class="mb-4">
            <div class="row g-3">
                <div class="col-sm-6 col-xl-3">
                    <div class="card ag-card h-100">
                        <div class="card-body">
                            <p class="text-muted mb-1">Richieste totali</p>
                            <h3 class="fw-bold mb-0"><?php echo (int) ($stats['total'] ?? 0); ?></h3>
                        </div>
                    </div>
                </div>
                <?php foreach ($statuses as $key => $config): ?>
                    <div class="col-sm-6 col-xl-2">
                        <div class="card ag-card h-100">
                            <div class="card-body">
                                <p class="text-muted mb-1"><?php echo sanitize_output($config['label']); ?></p>
                                <h4 class="fw-bold mb-0"><?php echo (int) ($stats['by_status'][$key] ?? 0); ?></h4>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card ag-card mb-4">
            <div class="card-body">
                <form class="row g-3 align-items-end" method="get">
                    <div class="col-md-3">
                        <label class="form-label" for="search">Ricerca</label>
                        <input class="form-control" id="search" name="search" placeholder="Codice, cittadino, comune" value="<?php echo sanitize_output($filters['search']); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="stato">Stato</label>
                        <select class="form-select" id="stato" name="stato">
                            <option value="">Tutti</option>
                            <?php foreach ($statuses as $key => $config): ?>
                                <option value="<?php echo sanitize_output($key); ?>" <?php echo $filters['stato'] === $key ? 'selected' : ''; ?>><?php echo sanitize_output($config['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="cliente_id">Cliente</label>
                        <select class="form-select" id="cliente_id" name="cliente_id">
                            <option value="">Tutti</option>
                            <?php foreach ($clients as $client): ?>
                                <?php $clientId = (int) ($client['id'] ?? 0); ?>
                                <option value="<?php echo $clientId; ?>" <?php echo ($filters['cliente_id'] ?? 0) === $clientId ? 'selected' : ''; ?>>
                                    <?php echo sanitize_output(trim(($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? ''))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="created_from">Dal</label>
                        <input class="form-control" type="date" id="created_from" name="created_from" value="<?php echo sanitize_output($filters['created_from']); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="created_to">Al</label>
                        <input class="form-control" type="date" id="created_to" name="created_to" value="<?php echo sanitize_output($filters['created_to']); ?>">
                    </div>
                    <div class="col-md-12 d-flex gap-2">
                        <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-filter me-2"></i>Filtra</button>
                        <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-eraser me-2"></i>Pulisci</a>
                    </div>
                </form>
            </div>
        </section>

        <section class="card ag-card">
            <div class="card-body">
                <?php if (!$bookings): ?>
                    <p class="text-muted mb-0">Nessuna prenotazione presente. Crea una nuova richiesta per iniziare.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-dark table-hover align-middle">
                            <thead>
                                <tr>
                                    <th>Codice</th>
                                    <th>Cittadino</th>
                                    <th>Comune</th>
                                    <th>Disponibilità</th>
                                    <th>Appuntamento</th>
                                    <th>Stato</th>
                                    <th>Operatore</th>
                                    <th class="text-end">Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $booking): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo sanitize_output((string) ($booking['booking_code'] ?? cie_booking_code($booking))); ?></strong><br>
                                            <small class="text-muted">Creato il <?php echo sanitize_output(format_datetime_locale((string) ($booking['created_at'] ?? ''))); ?></small>
                                        </td>
                                        <td>
                                            <span class="fw-semibold"><?php echo sanitize_output(trim(($booking['cittadino_cognome'] ?? '') . ' ' . ($booking['cittadino_nome'] ?? ''))); ?></span><br>
                                            <?php if (!empty($booking['cittadino_cf'])): ?>
                                                <small class="text-muted">CF: <?php echo sanitize_output($booking['cittadino_cf']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo sanitize_output((string) ($booking['comune_richiesta'] ?? '')); ?><br>
                                            <?php if (!empty($booking['disponibilita_data'])): ?>
                                                <small class="text-muted">Preferenza: <?php echo sanitize_output(format_date_locale($booking['disponibilita_data'])); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($booking['disponibilita_data'])): ?>
                                                <?php echo sanitize_output(format_date_locale($booking['disponibilita_data'])); ?><br>
                                                <small class="text-muted"><?php echo sanitize_output((string) ($booking['disponibilita_fascia'] ?? '')); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($booking['appuntamento_data'])): ?>
                                                <span class="text-warning fw-semibold"><?php echo sanitize_output(format_date_locale($booking['appuntamento_data'])); ?></span><br>
                                                <small class="text-muted"><?php echo sanitize_output((string) ($booking['appuntamento_orario'] ?? '')); ?></small>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">In attesa</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo sanitize_output(cie_status_badge((string) $booking['stato'])); ?>"><?php echo sanitize_output(cie_status_label((string) $booking['stato'])); ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($booking['created_by_username'])): ?>
                                                <small class="text-muted">Creato da <?php echo sanitize_output((string) $booking['created_by_username']); ?></small><br>
                                            <?php endif; ?>
                                            <?php if (!empty($booking['updated_by_username'])): ?>
                                                <small class="text-muted">Ultimo aggiornamento di <?php echo sanitize_output((string) $booking['updated_by_username']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-inline-flex align-items-center justify-content-end gap-2 flex-wrap" role="group">
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="view.php?id=<?php echo (int) $booking['id']; ?>" title="Dettagli">
                                                    <i class="fa-solid fa-eye"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="edit.php?id=<?php echo (int) $booking['id']; ?>" title="Modifica">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-accent btn-sm" href="open_portal.php?id=<?php echo (int) $booking['id']; ?>" title="Apri portale" target="_blank">
                                                    <i class="fa-solid fa-up-right-from-square"></i>
                                                </a>
                                                <a class="btn btn-icon btn-soft-danger btn-sm" href="delete.php?id=<?php echo (int) $booking['id']; ?>" title="Elimina">
                                                    <i class="fa-solid fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
