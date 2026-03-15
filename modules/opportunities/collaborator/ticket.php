<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';
require_once __DIR__ . '/../../../includes/ticket_functions.php';
require_once __DIR__ . '/../../../includes/mailer.php';

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$opportunityId = isset($_POST['opportunity_id']) ? (int) $_POST['opportunity_id'] : (int) ($_GET['id'] ?? 0);

if ($opportunityId <= 0 || $collaboratorId <= 0) {
    add_flash('warning', 'Opportunity non trovata.');
    header('Location: ' . opportunities_collaborator_url('list'));
    exit;
}

$opportunity = $opportunityService->findById($opportunityId);
if ($opportunity === null || (int) ($opportunity['collaborator_id'] ?? 0) !== $collaboratorId) {
    add_flash('warning', 'Non hai accesso a questa opportunity.');
    header('Location: ' . opportunities_collaborator_url('list'));
    exit;
}

$csrfToken = csrf_token();
$priorityOptions = ticket_priority_options();
$defaultPriority = 'MEDIUM';
$subjectDefault = sprintf('Ticket opportunity %s', (string) ($opportunity['code'] ?? '#' . $opportunityId));

$customerNameDefault = trim(sprintf('%s %s', (string) ($opportunity['customer_first_name'] ?? ''), (string) ($opportunity['customer_last_name'] ?? '')));
$customerEmailDefault = (string) ($opportunity['customer_email'] ?? '');
$customerPhoneDefault = (string) ($opportunity['customer_phone'] ?? '');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $subject = trim((string) ($_POST['subject'] ?? $subjectDefault));
    if ($subject === '') {
        $subject = $subjectDefault;
    }

    $messageBody = trim((string) ($_POST['message'] ?? ''));
    $priorityInput = strtoupper((string) ($_POST['priority'] ?? $defaultPriority));
    $priority = array_key_exists($priorityInput, $priorityOptions) ? $priorityInput : $defaultPriority;

    $customerName = trim((string) ($_POST['customer_name'] ?? $customerNameDefault));
    $customerEmail = trim((string) ($_POST['customer_email'] ?? $customerEmailDefault));
    $customerPhone = trim((string) ($_POST['customer_phone'] ?? $customerPhoneDefault));

    if ($messageBody === '') {
        $errors[] = 'Inserisci una descrizione del problema.';
    }

    if ($customerName === '') {
        $errors[] = 'Indica il nominativo o la ragione sociale del cliente.';
    }

    if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'L\'indirizzo email indicato non è valido.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $code = ticket_generate_code();
            $tags = json_encode(
                [
                    'opportunity',
                    'opportunity_id:' . $opportunityId,
                    'opportunity_code:' . (string) ($opportunity['code'] ?? ''),
                    'collaborator_id:' . $collaboratorId,
                ],
                JSON_UNESCAPED_UNICODE
            );

            $insertTicket = $pdo->prepare(
                'INSERT INTO tickets (
                    codice, customer_id, customer_name, customer_email, customer_phone,
                    subject, type, priority, status, channel, assigned_to, tags,
                    sla_due_at, created_by, last_message_at
                ) VALUES (
                    :codice, :customer_id, :customer_name, :customer_email, :customer_phone,
                    :subject, :type, :priority, :status, :channel, :assigned_to, :tags,
                    :sla_due_at, :created_by, NOW()
                )'
            );
            $insertTicket->execute([
                ':codice' => $code,
                ':customer_id' => null,
                ':customer_name' => $customerName !== '' ? $customerName : null,
                ':customer_email' => $customerEmail !== '' ? $customerEmail : null,
                ':customer_phone' => $customerPhone !== '' ? $customerPhone : null,
                ':subject' => $subject,
                ':type' => 'SUPPORT',
                ':priority' => $priority,
                ':status' => 'OPEN',
                ':channel' => 'PORTAL',
                ':assigned_to' => null,
                ':tags' => $tags,
                ':sla_due_at' => null,
                ':created_by' => $collaboratorId,
            ]);

            $ticketId = (int) $pdo->lastInsertId();

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
                'status_snapshot' => 'OPEN',
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

            $pdo->commit();

            $baseUrl = rtrim((string) env('APP_URL', sprintf('%s://%s', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http', $_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
            $ticketLink = $baseUrl . '/modules/ticket/view.php?id=' . $ticketId;

            $supportRecipient = (string) env('SUPPORT_TEAM_EMAIL', (string) env('MAIL_FROM_ADDRESS', ''));
            if ($supportRecipient !== '') {
                $mailBody = '<p>Nuovo ticket creato da un collaboratore.</p>'
                    . '<p><strong>Opportunity:</strong> ' . sanitize_output((string) ($opportunity['code'] ?? '#' . $opportunityId)) . '</p>'
                    . '<p><strong>Oggetto:</strong> ' . sanitize_output($subject) . '</p>'
                    . '<p><a href="' . sanitize_output($ticketLink) . '">Apri il ticket</a></p>';
                send_system_mail($supportRecipient, 'Ticket collaboratore #' . $code, render_mail_template('Nuovo ticket', $mailBody));
            }

            add_flash('success', 'Ticket inviato al team di supporto. Ti contatteremo al più presto.');
            header('Location: ' . opportunities_collaborator_url('view', ['id' => $opportunityId]));
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Impossibile creare il ticket: ' . $exception->getMessage();
        }
    }
} else {
    $subject = $subjectDefault;
    $messageBody = '';
    $priority = $defaultPriority;
    $customerName = $customerNameDefault;
    $customerEmail = $customerEmailDefault;
    $customerPhone = $customerPhoneDefault;
}

$subject = $subject ?? $subjectDefault;
$messageBody = $messageBody ?? '';
$priority = $priority ?? $defaultPriority;
$customerName = $customerName ?? $customerNameDefault;
$customerEmail = $customerEmail ?? $customerEmailDefault;
$customerPhone = $customerPhone ?? $customerPhoneDefault;

$opportunityListUrl = opportunities_collaborator_url('list');
$opportunityViewUrl = opportunities_collaborator_url('view', ['id' => $opportunityId]);

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Supporto opportunity</p>
                <h1 class="h4 mb-1">Apri un ticket</h1>
                <p class="text-muted mb-0">Invia una richiesta di assistenza legata alla opportunity <?php echo sanitize_output($opportunity['code'] ?? '#' . $opportunityId); ?>.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo sanitize_output($opportunityViewUrl); ?>">
                    <i class="fa-solid fa-arrow-left me-2"></i>Torna al dettaglio
                </a>
                <a class="btn btn-outline-secondary" href="<?php echo sanitize_output($opportunityListUrl); ?>">
                    <i class="fa-solid fa-table-list me-2"></i>Elenco opportunity
                </a>
            </div>
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
            <div class="col-lg-4">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <p class="text-uppercase small text-muted mb-1">Opportunity</p>
                        <h2 class="h5 mb-1"><?php echo sanitize_output($opportunity['code'] ?? ''); ?></h2>
                        <p class="text-muted mb-3">Categoria <?php echo sanitize_output(strtoupper((string) ($opportunity['category'] ?? ''))); ?> · Stato <?php echo sanitize_output($opportunity['status_label'] ?? $opportunity['status_code'] ?? ''); ?></p>
                        <dl class="row mb-0 small">
                            <dt class="col-5 text-muted">Gestore</dt>
                            <dd class="col-7 mb-2"><?php echo sanitize_output($opportunity['provider_label'] ?? '—'); ?></dd>
                            <dt class="col-5 text-muted">Cliente</dt>
                            <dd class="col-7 mb-2"><?php echo sanitize_output($customerNameDefault ?: '—'); ?></dd>
                            <dt class="col-5 text-muted">Email</dt>
                            <dd class="col-7 mb-2"><?php echo sanitize_output($customerEmailDefault ?: '—'); ?></dd>
                            <dt class="col-5 text-muted">Telefono</dt>
                            <dd class="col-7 mb-0"><?php echo sanitize_output($customerPhoneDefault ?: '—'); ?></dd>
                        </dl>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <form class="card shadow-sm" method="post" enctype="multipart/form-data" autocomplete="off">
                    <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
                    <input type="hidden" name="opportunity_id" value="<?php echo (int) $opportunityId; ?>">
                    <div class="card-body">
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label text-uppercase small text-muted" for="customer_name">Cliente / Azienda</label>
                                <input class="form-control" type="text" id="customer_name" name="customer_name" value="<?php echo sanitize_output($customerName); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-uppercase small text-muted" for="customer_email">Email</label>
                                <input class="form-control" type="email" id="customer_email" name="customer_email" value="<?php echo sanitize_output($customerEmail); ?>" placeholder="email@example.com">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label text-uppercase small text-muted" for="customer_phone">Telefono</label>
                                <input class="form-control" type="text" id="customer_phone" name="customer_phone" value="<?php echo sanitize_output($customerPhone); ?>" placeholder="Telefono">
                            </div>
                        </div>
                        <div class="row g-3 mb-4">
                            <div class="col-md-8">
                                <label class="form-label text-uppercase small text-muted" for="subject">Oggetto</label>
                                <input class="form-control" type="text" id="subject" name="subject" value="<?php echo sanitize_output($subject); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label text-uppercase small text-muted" for="priority">Priorità</label>
                                <select class="form-select" id="priority" name="priority">
                                    <?php foreach ($priorityOptions as $value => $label): ?>
                                        <option value="<?php echo sanitize_output($value); ?>" <?php echo $priority === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-uppercase small text-muted" for="message">Descrizione dettagliata</label>
                            <textarea class="form-control" id="message" name="message" rows="6" required><?php echo sanitize_output($messageBody); ?></textarea>
                            <small class="text-muted">Spiega il problema o l\'attività richiesta. Il team di supporto riceverà automaticamente tutti i riferimenti della opportunity.</small>
                        </div>
                        <div class="mb-4">
                            <label class="form-label text-uppercase small text-muted" for="attachments">Allegati (opzionale)</label>
                            <input class="form-control" type="file" id="attachments" name="attachments[]" multiple>
                            <small class="text-muted">PDF, immagini o documenti fino a 10MB ciascuno.</small>
                        </div>
                        <div class="alert alert-info" role="note">
                            <div class="d-flex gap-2 align-items-start">
                                <i class="fa-solid fa-circle-info mt-1"></i>
                                <div>
                                    <strong class="d-block">Cosa succede dopo?</strong>
                                    Il ticket viene registrato nel centro assistenza interno e verrà preso in carico dal team amministrativo. Riceverai eventuali aggiornamenti via email o telefono.
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent d-flex justify-content-end gap-2">
                        <a class="btn btn-outline-secondary" href="<?php echo sanitize_output($opportunityViewUrl); ?>">Annulla</a>
                        <button class="btn btn-primary" type="submit">
                            <i class="fa-solid fa-ticket me-2"></i>Invia ticket
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
