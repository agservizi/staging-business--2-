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

$pageTitle = 'Dettaglio invio';
$channelLabel = ($message['channel'] ?? '') === 'pec' ? 'PEC' : 'Email';
$statusLabels = [
    'pending' => 'In attesa',
    'sent' => 'Inviato',
    'failed' => 'Fallito',
];
$statusBadge = [
    'pending' => 'bg-warning text-dark',
    'sent' => 'bg-success',
    'failed' => 'bg-danger',
];
$statusKey = $message['status'] ?? 'pending';
$statusLabel = $statusLabels[$statusKey] ?? ucfirst((string) $statusKey);
$statusClass = $statusBadge[$statusKey] ?? 'bg-secondary';

$clienteLabel = posta_telematica_build_cliente_label($message);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Dettaglio invio #<?php echo (int) $message['id']; ?></h1>
                <p class="text-muted mb-0">Storico invio posta telematica.</p>
            </div>
            <div class="toolbar-actions d-flex gap-2">
                <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna</a>
                <a class="btn btn-primary" href="create.php"><i class="fa-solid fa-paper-plane me-2"></i>Nuovo invio</a>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <div class="text-muted small">Canale</div>
                        <div class="fw-semibold"><?php echo sanitize_output($channelLabel); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Stato</div>
                        <span class="badge <?php echo $statusClass; ?>"><?php echo sanitize_output($statusLabel); ?></span>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Destinatario</div>
                        <div class="fw-semibold"><?php echo sanitize_output($message['recipient_email'] ?? ''); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Cliente</div>
                        <div class="fw-semibold"><?php echo sanitize_output($clienteLabel); ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Oggetto</div>
                        <div class="fw-semibold"><?php echo sanitize_output($message['subject'] ?? ''); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Creato il</div>
                        <div class="fw-semibold"><?php echo sanitize_output(format_datetime_locale($message['created_at'] ?? null)); ?></div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-muted small">Aggiornato il</div>
                        <div class="fw-semibold"><?php echo sanitize_output(format_datetime_locale($message['updated_at'] ?? null)); ?></div>
                    </div>
                </div>

                <?php if (!empty($message['error_message'])): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        <?php echo sanitize_output($message['error_message']); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card ag-card mb-4">
            <div class="card-header bg-transparent border-0">
                <h2 class="h5 mb-0">Messaggio</h2>
            </div>
            <div class="card-body">
                <div class="border rounded-3 p-3 bg-body-tertiary">
                    <?php echo nl2br(sanitize_output((string) ($message['body'] ?? ''))); ?>
                </div>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Allegati</h2>
                <span class="badge ag-badge"><?php echo count($attachments); ?></span>
            </div>
            <div class="card-body">
                <?php if (!$attachments): ?>
                    <p class="text-muted mb-0">Nessun allegato.</p>
                <?php else: ?>
                    <div class="list-group">
                        <?php foreach ($attachments as $attachment): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold"><?php echo sanitize_output($attachment['file_name'] ?? 'Allegato'); ?></div>
                                    <small class="text-muted"><?php echo sanitize_output($attachment['mime_type'] ?? ''); ?> · <?php echo number_format((int) ($attachment['file_size'] ?? 0) / 1024, 1); ?> KB</small>
                                </div>
                                <a class="btn btn-sm btn-outline-primary" href="download.php?id=<?php echo (int) $attachment['id']; ?>">
                                    <i class="fa-solid fa-download me-1"></i>Scarica
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
