<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

$bookingId = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
if ($bookingId <= 0) {
    add_flash('warning', 'Prenotazione CIE non valida.');
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    try {
        if (cie_delete($pdo, $bookingId)) {
            add_flash('success', 'Prenotazione CIE eliminata correttamente.');
        } else {
            add_flash('warning', 'Prenotazione CIE non trovata o già rimossa.');
        }
    } catch (Throwable $exception) {
        error_log('Errore eliminazione prenotazione CIE #' . $bookingId . ': ' . $exception->getMessage());
        add_flash('warning', 'Impossibile eliminare la prenotazione selezionata.');
    }

    header('Location: index.php');
    exit;
}

$booking = cie_fetch_booking($pdo, $bookingId);
if ($booking === null) {
    add_flash('warning', 'Prenotazione CIE non trovata o già rimossa.');
    header('Location: index.php');
    exit;
}

$pageTitle = 'Elimina prenotazione CIE';
$csrfToken = csrf_token();
$bookingCode = cie_booking_code($booking);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Elimina prenotazione CIE</h1>
                <p class="text-muted mb-0">Prenotazione #<?php echo (int) $booking['id']; ?> · Codice <strong><?php echo sanitize_output($bookingCode); ?></strong></p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-light" href="view.php?id=<?php echo (int) $booking['id']; ?>"><i class="fa-solid fa-arrow-left me-2"></i>Dettaglio</a>
                <a class="btn btn-warning text-dark" href="index.php"><i class="fa-solid fa-table-list me-2"></i>Dashboard CIE</a>
            </div>
        </div>

        <div class="card ag-card border-danger">
            <div class="card-header bg-transparent border-0 d-flex align-items-center gap-2">
                <i class="fa-solid fa-triangle-exclamation text-danger"></i>
                <h2 class="h5 mb-0 text-danger">Conferma eliminazione</h2>
            </div>
            <div class="card-body">
                <p class="text-muted">Questa operazione rimuoverà definitivamente la prenotazione e tutti i documenti allegati.</p>
                <dl class="row mb-4">
                    <dt class="col-sm-3">Cittadino</dt>
                    <dd class="col-sm-9"><?php echo sanitize_output(trim((string) ($booking['cittadino_cognome'] ?? '') . ' ' . ($booking['cittadino_nome'] ?? ''))); ?></dd>

                    <dt class="col-sm-3">Comune</dt>
                    <dd class="col-sm-9"><?php echo sanitize_output((string) ($booking['comune_richiesta'] ?? '')); ?></dd>

                    <dt class="col-sm-3">Stato attuale</dt>
                    <dd class="col-sm-9"><span class="<?php echo sanitize_output(cie_status_badge((string) ($booking['stato'] ?? 'nuova'))); ?>"><?php echo sanitize_output(cie_status_label((string) ($booking['stato'] ?? 'nuova'))); ?></span></dd>
                </dl>
                <form method="post" class="d-flex justify-content-end gap-2">
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <input type="hidden" name="id" value="<?php echo (int) $booking['id']; ?>">
                    <a class="btn btn-outline-light" href="view.php?id=<?php echo (int) $booking['id']; ?>">Annulla</a>
                    <button class="btn btn-danger" type="submit"><i class="fa-solid fa-trash me-2"></i>Conferma eliminazione</button>
                </form>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
