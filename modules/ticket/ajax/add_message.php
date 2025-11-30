<?php

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/ticket_functions.php';
require_once __DIR__ . '/../../../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo non consentito']);
    exit;
}

$ticketId = (int) ($_POST['ticket_id'] ?? 0);
$body = trim((string) ($_POST['body'] ?? ''));

if ($ticketId <= 0 || $body === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Dati mancanti per creare il messaggio']);
    exit;
}

$ticket = ticket_find($pdo, $ticketId);
if (!$ticket) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Ticket non trovato']);
    exit;
}

$isInternal = isset($_POST['is_internal']);
$notifyClient = isset($_POST['notify_client']);
$notifyAdmin = isset($_POST['notify_admin']);
$authorName = trim(((string) ($_SESSION['cognome'] ?? '')) . ' ' . ((string) ($_SESSION['nome'] ?? '')));
if ($authorName === '') {
    $authorName = (string) ($_SESSION['username'] ?? 'Operatore');
}

try {
    $pdo->beginTransaction();

    $insertId = ticket_insert_message($pdo, [
        'ticket_id' => $ticketId,
        'author_id' => (int) ($_SESSION['user_id'] ?? 0),
        'author_name' => $authorName,
        'body' => $body,
        'attachments' => json_encode([], JSON_UNESCAPED_UNICODE),
        'is_internal' => $isInternal ? 1 : 0,
        'visibility' => $isInternal ? 'internal' : 'customer',
        'status_snapshot' => (string) $ticket['status'],
        'notified_client' => $notifyClient ? 1 : 0,
        'notified_admin' => $notifyAdmin ? 1 : 0,
    ]);

    $attachments = ticket_store_attachments($_FILES['attachments'] ?? [], $ticketId, $insertId);
    if ($attachments) {
        $updateAttachment = $pdo->prepare('UPDATE ticket_messages SET attachments = :attachments WHERE id = :id');
        $updateAttachment->execute([
            ':attachments' => json_encode($attachments, JSON_UNESCAPED_UNICODE),
            ':id' => $insertId,
        ]);
    }

    $pdo->prepare('UPDATE tickets SET last_message_at = NOW(), updated_at = NOW() WHERE id = :id')->execute([':id' => $ticketId]);

    $pdo->commit();

    if ($notifyClient && !$isInternal && !empty($ticket['customer_email'])) {
        $baseUrl = rtrim((string) env('APP_URL', sprintf('%s://%s', isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http', $_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
        $ticketUrl = $baseUrl . '/modules/ticket/view.php?id=' . $ticketId;
        $mailContent = '<p>Ciao,</p>' .
            '<p>È stato aggiunto un nuovo aggiornamento al ticket <strong>#' . sanitize_output($ticket['codice'] ?? $ticket['id']) . '</strong>.</p>' .
            '<blockquote>' . nl2br(sanitize_output($body)) . '</blockquote>' .
            '<p>Consulta il dettaglio <a href="' . sanitize_output($ticketUrl) . '">da qui</a>.</p>';
        send_system_mail(
            (string) $ticket['customer_email'],
            'Aggiornamento ticket #' . ($ticket['codice'] ?? $ticket['id']),
            render_mail_template('Aggiornamento ticket', $mailContent)
        );
    }

    if ($notifyAdmin) {
        // Facoltativo: broadcast interno via email o webhook.
    }

    $attachmentsLinks = [];
    foreach ($attachments as $attachment) {
        $attachmentsLinks[] = sprintf(
            '<a class="btn btn-outline-warning btn-sm" href="/%s" target="_blank"><i class="fa-solid fa-paperclip me-1"></i>Allegato</a>',
            ltrim($attachment, '/')
        );
    }

    ob_start();
    ?>
    <article class="border rounded-3 p-3 mb-3" data-ticket-message-id="<?php echo (int) $insertId; ?>">
        <header class="d-flex justify-content-between align-items-start mb-2">
            <div>
                <strong><?php echo sanitize_output($authorName); ?></strong>
                <span class="badge <?php echo $isInternal ? 'bg-secondary' : 'bg-primary'; ?> ms-2"><?php echo $isInternal ? 'Interno' : 'Cliente'; ?></span>
                <span class="text-muted small ms-2"><?php echo date('d/m/Y H:i'); ?></span>
            </div>
            <div class="text-end small text-uppercase text-muted">
                Stato: <?php echo sanitize_output((string) $ticket['status']); ?>
            </div>
        </header>
        <p class="mb-2"><?php echo nl2br(sanitize_output($body)); ?></p>
        <?php if ($attachmentsLinks): ?>
            <div class="d-flex flex-wrap gap-2">
                <?php echo implode('', $attachmentsLinks); ?>
            </div>
        <?php endif; ?>
    </article>
    <?php
    $messageHtml = ob_get_clean();

    echo json_encode([
        'success' => true,
        'html' => $messageHtml,
    ]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $exception->getMessage()]);
}
