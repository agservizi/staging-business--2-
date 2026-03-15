<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';
require_once __DIR__ . '/../../../includes/ticket_functions.php';
require_once __DIR__ . '/../../../includes/mailer.php';

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$ticketId = (int) ($_GET['id'] ?? 0);

if ($ticketId <= 0) {
    add_flash('warning', 'Ticket non trovato.');
    header('Location: ' . opportunities_collaborator_url('tickets'));
    exit;
}

$ticket = ticket_find($pdo, $ticketId);
if (!$ticket || (int) ($ticket['created_by'] ?? 0) !== $collaboratorId) {
    add_flash('warning', 'Non hai accesso a questo ticket.');
    header('Location: ' . opportunities_collaborator_url('tickets'));
    exit;
}

$messages = array_filter(
    ticket_messages($pdo, $ticketId),
    static function (array $message): bool {
        return ($message['visibility'] ?? 'customer') !== 'internal';
    }
);

$statusBadge = ticket_status_badge((string) ($ticket['status'] ?? 'OPEN'));
$priorityBadge = ticket_priority_badge((string) ($ticket['priority'] ?? 'MEDIUM'));
$csrfToken = csrf_token();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $messageBody = trim((string) ($_POST['message'] ?? ''));
    if ($messageBody === '') {
        $errors[] = 'Scrivi un messaggio per il team di supporto.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $authorName = trim(sprintf('%s %s', (string) ($_SESSION['cognome'] ?? ''), (string) ($_SESSION['nome'] ?? '')));
            if ($authorName === '') {
                $authorName = (string) ($_SESSION['username'] ?? 'Collaboratore');
            }

            $messagePayload = [
                'ticket_id' => $ticketId,
                'author_id' => $collaboratorId,
                'author_name' => $authorName,
                'body' => $messageBody,
                'attachments' => json_encode([], JSON_UNESCAPED_UNICODE),
                'is_internal' => 0,
                'visibility' => 'customer',
                'status_snapshot' => (string) ($ticket['status'] ?? 'OPEN'),
                'notified_client' => 0,
                'notified_admin' => 1,
            ];

            $messageId = ticket_insert_message($pdo, $messagePayload);

            $attachments = ticket_store_attachments($_FILES['attachments'] ?? [], $ticketId, $messageId);
            if ($attachments) {
                $updateMessage = $pdo->prepare('UPDATE ticket_messages SET attachments = :attachments WHERE id = :id');
                $updateMessage->execute([
                    ':attachments' => json_encode($attachments, JSON_UNESCAPED_UNICODE),
                    ':id' => $messageId,
                ]);
            }

            $updateTicket = $pdo->prepare('UPDATE tickets SET last_message_at = NOW() WHERE id = :id');
            $updateTicket->execute([':id' => $ticketId]);

            $pdo->commit();

            $supportRecipient = (string) env('SUPPORT_TEAM_EMAIL', (string) env('MAIL_FROM_ADDRESS', ''));
            if ($supportRecipient !== '') {
                $baseUrl = rtrim((string) env('APP_URL', sprintf('%s://%s', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http', $_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
                $ticketLink = $baseUrl . '/modules/ticket/view.php?id=' . $ticketId;
                $mailBody = '<p>Nuovo aggiornamento dal collaboratore sul ticket #' . sanitize_output((string) ($ticket['codice'] ?? $ticketId)) . '.</p>'
                    . '<blockquote>' . nl2br(sanitize_output($messageBody)) . '</blockquote>'
                    . '<p><a href="' . sanitize_output($ticketLink) . '">Apri il ticket</a>.</p>';
                send_system_mail($supportRecipient, 'Aggiornamento ticket #' . ($ticket['codice'] ?? $ticketId), render_mail_template('Aggiornamento ticket', $mailBody));
            }

            add_flash('success', 'Messaggio inviato correttamente.');
            header('Location: ' . opportunities_collaborator_url('ticket-view', ['id' => $ticketId]));
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Impossibile inviare il messaggio: ' . $exception->getMessage();
        }
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small text-muted mb-1">Ticket #<?php echo sanitize_output($ticket['codice'] ?? $ticketId); ?></p>
                <h1 class="h4 mb-1"><?php echo sanitize_output($ticket['subject'] ?? 'Richiesta di assistenza'); ?></h1>
                <p class="text-muted mb-0">Creato il <?php echo sanitize_output(format_datetime_locale($ticket['created_at'] ?? null)); ?> · Ultimo aggiornamento <?php echo sanitize_output(format_datetime_locale($ticket['updated_at'] ?? null)); ?></p>
            </div>
            <a class="btn btn-outline-secondary" href="<?php echo opportunities_collaborator_url('tickets'); ?>">
                <i class="fa-solid fa-arrow-left me-2"></i>Indietro
            </a>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger" role="alert">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo sanitize_output($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-lg-4 d-flex flex-column gap-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="badge <?php echo $statusBadge; ?> text-uppercase"><?php echo sanitize_output($ticket['status'] ?? 'OPEN'); ?></span>
                            <span class="badge <?php echo $priorityBadge; ?> text-uppercase"><?php echo sanitize_output($ticket['priority'] ?? 'MEDIUM'); ?></span>
                        </div>
                        <dl class="row small mb-0">
                            <dt class="col-5 text-muted">Cliente</dt>
                            <dd class="col-7 mb-2"><?php echo sanitize_output($ticket['customer_name'] ?? 'Non indicato'); ?></dd>
                            <dt class="col-5 text-muted">Email</dt>
                            <dd class="col-7 mb-2"><?php echo sanitize_output($ticket['customer_email'] ?? '—'); ?></dd>
                            <dt class="col-5 text-muted">Telefono</dt>
                            <dd class="col-7 mb-2"><?php echo sanitize_output($ticket['customer_phone'] ?? '—'); ?></dd>
                            <dt class="col-5 text-muted">Opportunity</dt>
                            <dd class="col-7 mb-2">
                                <?php
                                    $opportunityCodeTag = null;
                                    if (!empty($ticket['tags'])) {
                                        $decodedTags = json_decode((string) $ticket['tags'], true);
                                        if (is_array($decodedTags)) {
                                            foreach ($decodedTags as $tag) {
                                                if (str_starts_with((string) $tag, 'opportunity_code:')) {
                                                    $opportunityCodeTag = substr((string) $tag, strlen('opportunity_code:'));
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                    echo sanitize_output($opportunityCodeTag ?? '—');
                                ?>
                            </dd>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-lg-8 d-flex flex-column gap-4">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h6 text-uppercase text-muted mb-0">Conversazione</h2>
                            <span class="badge bg-light text-muted border"><?php echo count($messages); ?> messaggi</span>
                        </div>
                        <?php if (!$messages): ?>
                            <p class="text-muted mb-0">Ancora nessuno ha risposto a questo ticket.</p>
                        <?php endif; ?>
                        <?php foreach ($messages as $message): ?>
                            <?php
                                $attachments = json_decode((string) ($message['attachments'] ?? '[]'), true);
                                $attachments = is_array($attachments) ? $attachments : [];
                                $isOperator = (int) ($message['author_id'] ?? 0) !== $collaboratorId;
                            ?>
                            <article class="border rounded-3 p-3 mb-3 <?php echo $isOperator ? 'bg-light-subtle' : ''; ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <strong><?php echo sanitize_output($message['author_name'] ?? ($isOperator ? 'Team supporto' : 'Tu')); ?></strong>
                                        <span class="text-muted small ms-2"><?php echo sanitize_output(format_datetime_locale($message['created_at'] ?? null)); ?></span>
                                    </div>
                                    <span class="badge bg-light text-muted border">Stato: <?php echo sanitize_output($message['status_snapshot'] ?? 'OPEN'); ?></span>
                                </div>
                                <p class="mb-2">
                                    <?php echo nl2br(sanitize_output($message['body'] ?? ''), false); ?>
                                </p>
                                <?php if ($attachments): ?>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($attachments as $attachment): ?>
                                            <a class="btn btn-sm btn-outline-primary" href="/<?php echo sanitize_output(ltrim((string) $attachment, '/')); ?>" target="_blank">
                                                <i class="fa-solid fa-paperclip me-1"></i>Allegato
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Invia un aggiornamento</h2>
                        <form method="post" enctype="multipart/form-data" autocomplete="off">
                            <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <div class="mb-3">
                                <label class="form-label text-uppercase small text-muted" for="message">Messaggio</label>
                                <textarea class="form-control" id="message" name="message" rows="5" required><?php echo sanitize_output($_POST['message'] ?? ''); ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-uppercase small text-muted" for="attachments">Allegati (opzionale)</label>
                                <input class="form-control" type="file" id="attachments" name="attachments[]" multiple>
                                <small class="text-muted">PDF, immagini o documenti fino a 10MB.</small>
                            </div>
                            <p class="text-muted small mb-3">Il team di supporto riceverà subito la tua risposta e potrà risponderti via ticket o telefono.</p>
                            <div class="d-flex justify-content-end">
                                <button class="btn btn-primary" type="submit">
                                    <i class="fa-solid fa-paper-plane me-2"></i>Invia
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
