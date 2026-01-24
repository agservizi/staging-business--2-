<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

$messageId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($messageId <= 0) {
    add_flash('warning', 'Invio non trovato.');
    header('Location: index.php');
    exit;
}

$message = posta_telematica_get_message($pdo, $messageId);
if (!$message) {
    add_flash('warning', 'Invio non trovato.');
    header('Location: index.php');
    exit;
}

$attachments = posta_telematica_get_attachments($pdo, $messageId);
$channel = (string) ($message['channel'] ?? 'email');
$channelLabel = $channel === 'pec' ? 'PEC' : 'Email';
$clienteLabel = posta_telematica_build_cliente_label($message);

$statusKey = (string) ($message['status'] ?? 'pending');
$statusMap = [
    'pending' => 'In attesa',
    'sent' => 'Inviato',
    'failed' => 'Fallito',
];
$statusLabel = $statusMap[$statusKey] ?? ucfirst($statusKey);

$invioDate = $message['pec_receipt_invio_at'] ?? null;
$accettazioneDate = $message['pec_receipt_accettazione_at'] ?? null;
$consegnaDate = $message['pec_receipt_consegna_at'] ?? null;

$invioStatus = $invioDate ? 'Completato (' . format_datetime_locale($invioDate) . ')' : ($statusKey === 'sent' ? 'Completato' : ($statusKey === 'failed' ? 'Fallito' : 'In attesa'));
$accettazioneStatus = $accettazioneDate ? 'Completato (' . format_datetime_locale($accettazioneDate) . ')' : 'Da verificare';
$consegnaStatus = $consegnaDate ? 'Completato (' . format_datetime_locale($consegnaDate) . ')' : 'Da verificare';

$pecReceipts = [];
$receiptBodies = [
    'invio' => null,
    'accettazione' => null,
    'consegna' => null,
];

if ($channel === 'pec' && !empty($message['message_id_header'])) {
    $pecReceipts = posta_telematica_find_receipts($pdo, (string) $message['message_id_header']);
    foreach ($pecReceipts as $receipt) {
        $type = posta_telematica_detect_receipt_type(
            (string) ($receipt['subject'] ?? ''),
            (string) ($receipt['sender'] ?? ''),
            (string) ($receipt['body'] ?? '')
        );
        if ($type && $receiptBodies[$type] === null) {
            $receiptBodies[$type] = (string) ($receipt['body'] ?? '');
        }
    }
}

$pageTitle = 'Ricevuta invio';

require_once __DIR__ . '/../../../includes/header.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">Ricevuta invio Posta Telematica</h1>
            <div class="text-muted">Documento riepilogativo per il cliente</div>
        </div>
        <div class="d-print-none">
            <button class="btn btn-primary" onclick="window.print()"><i class="fa-solid fa-print me-2"></i>Stampa</button>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <div class="text-muted small">Canale</div>
                    <div class="fw-semibold"><?php echo sanitize_output($channelLabel); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Stato invio</div>
                    <div class="fw-semibold"><?php echo sanitize_output($statusLabel); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Cliente</div>
                    <div class="fw-semibold"><?php echo sanitize_output($clienteLabel); ?></div>
                </div>
                <div class="col-md-6">
                    <div class="text-muted small">Destinatario</div>
                    <div class="fw-semibold"><?php echo sanitize_output($message['recipient_email'] ?? ''); ?></div>
                </div>
                <div class="col-md-6">
                    <div class="text-muted small">Oggetto</div>
                    <div class="fw-semibold"><?php echo sanitize_output($message['subject'] ?? ''); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Creato il</div>
                    <div class="fw-semibold"><?php echo sanitize_output(format_datetime_locale($message['created_at'] ?? null)); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Aggiornato il</div>
                    <div class="fw-semibold"><?php echo sanitize_output(format_datetime_locale($message['updated_at'] ?? null)); ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small">Allegati</div>
                    <div class="fw-semibold"><?php echo count($attachments); ?> file</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0">Riepilogo ricevute</h2>
        </div>
        <div class="card-body">
            <?php if ($channel === 'pec'): ?>
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="text-muted small">Ricevuta invio</div>
                        <div class="fw-semibold"><?php echo sanitize_output($invioStatus); ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Ricevuta accettazione</div>
                        <div class="fw-semibold"><?php echo sanitize_output($accettazioneStatus); ?></div>
                    </div>
                    <div class="col-md-4">
                        <div class="text-muted small">Ricevuta consegna</div>
                        <div class="fw-semibold"><?php echo sanitize_output($consegnaStatus); ?></div>
                    </div>
                </div>
                <div class="text-muted small mt-3">Le ricevute di accettazione e consegna verranno aggiornate appena disponibili.</div>
            <?php else: ?>
                <div class="text-muted">Per l'email è disponibile una sola ricevuta di invio.</div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($channel === 'pec'): ?>
        <div class="card mt-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Ricevuta invio</h2>
                <button class="btn btn-sm btn-outline-primary d-print-none" type="button" onclick="printReceipt('invio')">
                    <i class="fa-solid fa-print me-1"></i>Stampa ricevuta
                </button>
            </div>
            <div class="card-body" data-receipt-section="invio">
                <?php if ($receiptBodies['invio']): ?>
                    <div class="border rounded-3 p-3 bg-light">
                        <?php echo nl2br(sanitize_output($receiptBodies['invio'])); ?>
                    </div>
                <?php else: ?>
                    <div class="text-muted">Ricevuta invio non ancora disponibile.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Ricevuta accettazione</h2>
                <button class="btn btn-sm btn-outline-primary d-print-none" type="button" onclick="printReceipt('accettazione')">
                    <i class="fa-solid fa-print me-1"></i>Stampa ricevuta
                </button>
            </div>
            <div class="card-body" data-receipt-section="accettazione">
                <?php if ($receiptBodies['accettazione']): ?>
                    <div class="border rounded-3 p-3 bg-light">
                        <?php echo nl2br(sanitize_output($receiptBodies['accettazione'])); ?>
                    </div>
                <?php else: ?>
                    <div class="text-muted">Ricevuta accettazione non ancora disponibile.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h2 class="h6 mb-0">Ricevuta consegna</h2>
                <button class="btn btn-sm btn-outline-primary d-print-none" type="button" onclick="printReceipt('consegna')">
                    <i class="fa-solid fa-print me-1"></i>Stampa ricevuta
                </button>
            </div>
            <div class="card-body" data-receipt-section="consegna">
                <?php if ($receiptBodies['consegna']): ?>
                    <div class="border rounded-3 p-3 bg-light">
                        <?php echo nl2br(sanitize_output($receiptBodies['consegna'])); ?>
                    </div>
                <?php else: ?>
                    <div class="text-muted">Ricevuta consegna non ancora disponibile.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="card mt-4">
        <div class="card-header bg-white">
            <h2 class="h6 mb-0">Messaggio inviato</h2>
        </div>
        <div class="card-body">
            <div class="border rounded-3 p-3 bg-light">
                <?php echo nl2br(sanitize_output((string) ($message['body'] ?? ''))); ?>
            </div>
        </div>
    </div>
</div>

<style>
@media print {
    body {
        background: #ffffff !important;
    }
    .d-print-none {
        display: none !important;
    }
    .card {
        border: 1px solid #e5e7eb !important;
    }
    [data-receipt-section] {
        display: none;
    }
    [data-receipt-section].print-active {
        display: block !important;
    }
}
</style>

<script>
    function printReceipt(type) {
        const sections = document.querySelectorAll('[data-receipt-section]');
        sections.forEach((section) => section.classList.remove('print-active'));

        const target = document.querySelector('[data-receipt-section="' + type + '"]');
        if (target) {
            target.classList.add('print-active');
        }

        window.print();

        if (target) {
            target.classList.remove('print-active');
        }
    }
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
