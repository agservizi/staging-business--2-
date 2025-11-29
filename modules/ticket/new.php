<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/ticket_functions.php';
require_once __DIR__ . '/../../includes/mailer.php';

require_role('Admin', 'Operatore', 'Manager', 'Support');

$pageTitle = 'Nuovo ticket';
$csrfToken = csrf_token();

$statusOptions = ticket_status_options();
$priorityOptions = ticket_priority_options();
$channelOptions = ticket_channel_options();
$typeOptions = ticket_type_options();
$clients = ticket_clients($pdo);
$agents = ticket_assignments($pdo);

$errors = [];
$selectedClient = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $customerName = trim((string) ($_POST['customer_name'] ?? ''));
    $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
    $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $channel = strtoupper((string) ($_POST['channel'] ?? 'PORTAL'));
    $type = strtoupper((string) ($_POST['type'] ?? 'SUPPORT'));
    $priority = strtoupper((string) ($_POST['priority'] ?? 'MEDIUM'));
    $status = strtoupper((string) ($_POST['status'] ?? 'OPEN'));
    $assignedTo = (int) ($_POST['assigned_to'] ?? 0) ?: null;
    $notifyClient = isset($_POST['notify_client']);
    $notifyAdmin = isset($_POST['notify_admin']);
    $slaDueAt = trim((string) ($_POST['sla_due_at'] ?? ''));
    $messageBody = trim((string) ($_POST['message'] ?? ''));
    $tags = array_filter(array_map('trim', explode(',', (string) ($_POST['tags'] ?? ''))));

    if ($customerId > 0) {
        $clientStmt = $pdo->prepare('SELECT id, ragione_sociale, nome, cognome, email, telefono FROM clienti WHERE id = :id LIMIT 1');
        $clientStmt->execute([':id' => $customerId]);
        $selectedClient = $clientStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($selectedClient === null) {
            $errors[] = 'Il cliente selezionato non esiste più.';
        } else {
            if ($customerName === '') {
                $customerName = trim((string) (($selectedClient['ragione_sociale'] ?? '') ?: (($selectedClient['cognome'] ?? '') . ' ' . ($selectedClient['nome'] ?? ''))));
            }
            if ($customerEmail === '') {
                $customerEmail = (string) ($selectedClient['email'] ?? '');
            }
            if ($customerPhone === '') {
                $customerPhone = (string) ($selectedClient['telefono'] ?? '');
            }
        }
    }

    $_POST['customer_name'] = $customerName;
    $_POST['customer_email'] = $customerEmail;
    $_POST['customer_phone'] = $customerPhone;

    if ($subject === '') {
        $errors[] = 'L\'oggetto è obbligatorio.';
    }

    if ($messageBody === '') {
        $errors[] = 'Inserisci un messaggio iniziale.';
    }

    if ($customerId === 0 && $customerName === '') {
        $errors[] = 'Seleziona un cliente oppure compila i dati manualmente.';
    }

    if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'L\'indirizzo email del cliente non è valido.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $code = ticket_generate_code();
            $insertTicket = $pdo->prepare('INSERT INTO tickets (codice, customer_id, customer_name, customer_email, customer_phone, subject, type, priority, status, channel, assigned_to, tags, sla_due_at, created_by, last_message_at) VALUES (:codice, :customer_id, :customer_name, :customer_email, :customer_phone, :subject, :type, :priority, :status, :channel, :assigned_to, :tags, :sla_due_at, :created_by, NOW())');
            $insertTicket->execute([
                ':codice' => $code,
                ':customer_id' => $customerId > 0 ? $customerId : null,
                ':customer_name' => $customerName !== '' ? $customerName : null,
                ':customer_email' => $customerEmail !== '' ? $customerEmail : null,
                ':customer_phone' => $customerPhone !== '' ? $customerPhone : null,
                ':subject' => $subject,
                ':type' => $type,
                ':priority' => $priority,
                ':status' => $status,
                ':channel' => $channel,
                ':assigned_to' => $assignedTo,
                ':tags' => $tags ? json_encode($tags, JSON_UNESCAPED_UNICODE) : null,
                ':sla_due_at' => $slaDueAt !== '' ? $slaDueAt . ' 23:59:59' : null,
                ':created_by' => (int) ($_SESSION['user_id'] ?? 0),
            ]);

            $ticketId = (int) $pdo->lastInsertId();

            $authorName = trim(((string) ($_SESSION['cognome'] ?? '')) . ' ' . ((string) ($_SESSION['nome'] ?? '')));
            if ($authorName === '') {
                $authorName = (string) ($_SESSION['username'] ?? 'Operatore');
            }

            $messagePayload = [
                'ticket_id' => $ticketId,
                'author_id' => (int) ($_SESSION['user_id'] ?? 0),
                'author_name' => $authorName,
                'body' => $messageBody,
                'attachments' => json_encode([], JSON_UNESCAPED_UNICODE),
                'is_internal' => isset($_POST['internal_note']) ? 1 : 0,
                'visibility' => isset($_POST['internal_note']) ? 'internal' : 'customer',
                'status_snapshot' => $status,
                'notified_client' => $notifyClient ? 1 : 0,
                'notified_admin' => $notifyAdmin ? 1 : 0,
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

            if ($notifyClient && $customerEmail !== '') {
                $mailBody = '<p>Ciao,</p>'
                    . '<p>Abbiamo aperto il ticket <strong>#' . sanitize_output($code) . '</strong>.</p>'
                    . '<blockquote>' . nl2br(sanitize_output($messageBody)) . '</blockquote>'
                    . '<p>Segui gli aggiornamenti <a href="' . sanitize_output($ticketLink) . '">da qui</a>.</p>';
                send_system_mail($customerEmail, 'Conferma apertura ticket #' . $code, render_mail_template('Ticket aperto', $mailBody));
            }

            if ($notifyAdmin) {
                $adminRecipient = (string) env('SUPPORT_TEAM_EMAIL', (string) env('MAIL_FROM_ADDRESS', ''));
                if ($adminRecipient !== '') {
                    $mailBody = '<p>Nuovo ticket #' . sanitize_output($code) . ' creato da ' . sanitize_output($authorName) . '.</p>'
                        . '<p><strong>Oggetto:</strong> ' . sanitize_output($subject) . '</p>'
                        . '<p><a href="' . sanitize_output($ticketLink) . '">Apri il dettaglio</a>.</p>';
                    send_system_mail($adminRecipient, 'Nuovo ticket #' . $code, render_mail_template('Nuovo ticket', $mailBody));
                }
            }

            add_flash('success', 'Ticket creato correttamente.');
            header('Location: view.php?id=' . $ticketId);
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Impossibile creare il ticket: ' . $exception->getMessage();
        }
    }
}

$oldChannel = $_POST['channel'] ?? 'PORTAL';
$oldType = $_POST['type'] ?? 'SUPPORT';
$oldPriority = $_POST['priority'] ?? 'MEDIUM';
$oldStatus = $_POST['status'] ?? 'OPEN';

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Crea nuovo ticket</h1>
                <p class="text-muted mb-0">Registra rapidamente richieste di assistenza e comunicazioni interne.</p>
            </div>
            <a class="btn btn-outline-secondary" href="index.php">
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

        <form class="row g-4" method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
            <div class="col-12 col-xxl-4">
                <div class="card ag-card h-100">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Dati cliente</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="customer_id">Cliente registrato</label>
                            <select class="form-select" id="customer_id" name="customer_id">
                                <option value="">Seleziona dall'anagrafica</option>
                                <?php foreach ($clients as $client): ?>
                                    <?php $clientLabel = trim(($client['ragione_sociale'] ?? '') . ' ' . ($client['cognome'] ?? '') . ' ' . ($client['nome'] ?? '')); ?>
                                    <option value="<?php echo (int) $client['id']; ?>" <?php echo (int) ($_POST['customer_id'] ?? 0) === (int) $client['id'] ? 'selected' : ''; ?>><?php echo sanitize_output($clientLabel !== '' ? $clientLabel : 'Cliente #' . $client['id']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="customer_name">Nome / Ragione sociale</label>
                            <input type="text" class="form-control" id="customer_name" name="customer_name" placeholder="Nome completo o azienda" value="<?php echo sanitize_output($_POST['customer_name'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="customer_email">Email</label>
                            <input type="email" class="form-control" id="customer_email" name="customer_email" placeholder="email@example.com" value="<?php echo sanitize_output($_POST['customer_email'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="customer_phone">Telefono</label>
                            <input type="text" class="form-control" id="customer_phone" name="customer_phone" placeholder="Telefono" value="<?php echo sanitize_output($_POST['customer_phone'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xxl-8">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Dettagli ticket</h2>
                    </div>
                    <div class="card-body row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="subject">Oggetto</label>
                            <input type="text" class="form-control" id="subject" name="subject" required value="<?php echo sanitize_output($_POST['subject'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="channel">Canale</label>
                            <select class="form-select" id="channel" name="channel">
                                <?php foreach ($channelOptions as $value => $label): ?>
                                    <option value="<?php echo sanitize_output($value); ?>" <?php echo $oldChannel === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="type">Tipologia</label>
                            <select class="form-select" id="type" name="type">
                                <?php foreach ($typeOptions as $value => $label): ?>
                                    <option value="<?php echo sanitize_output($value); ?>" <?php echo $oldType === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="priority">Priorità</label>
                            <select class="form-select" id="priority" name="priority">
                                <?php foreach ($priorityOptions as $value => $label): ?>
                                    <option value="<?php echo sanitize_output($value); ?>" <?php echo $oldPriority === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="status">Stato iniziale</label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach ($statusOptions as $value => $label): ?>
                                    <option value="<?php echo sanitize_output($value); ?>" <?php echo $oldStatus === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="assigned_to">Assegnato a</label>
                            <select class="form-select" id="assigned_to" name="assigned_to">
                                <option value="">Non assegnato</option>
                                <?php foreach ($agents as $agent): ?>
                                    <?php $label = trim(($agent['cognome'] ?? '') . ' ' . ($agent['nome'] ?? '') . ' (' . ($agent['username'] ?? '') . ')'); ?>
                                    <option value="<?php echo (int) $agent['id']; ?>" <?php echo (int) ($_POST['assigned_to'] ?? 0) === (int) $agent['id'] ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="sla_due_at">Scadenza SLA</label>
                            <input type="date" class="form-control" id="sla_due_at" name="sla_due_at" value="<?php echo sanitize_output($_POST['sla_due_at'] ?? ''); ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="tags">Tag (separa con virgola)</label>
                            <input type="text" class="form-control" id="tags" name="tags" value="<?php echo sanitize_output($_POST['tags'] ?? ''); ?>" placeholder="es. onboarding, priorità" />
                        </div>
                    </div>
                </div>
                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h2 class="h5 mb-0">Messaggio iniziale</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="message">Descrizione dettagliata</label>
                            <textarea class="form-control" id="message" name="message" rows="6" required><?php echo sanitize_output($_POST['message'] ?? ''); ?></textarea>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="attachments">Allegati</label>
                            <input type="file" class="form-control" id="attachments" name="attachments[]" multiple>
                            <small class="text-muted">Sono accettati PDF, immagini e documenti fino a 10MB.</small>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="internal_note" name="internal_note" <?php echo isset($_POST['internal_note']) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="internal_note">Nota interna</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <?php $notifyClientDefault = $_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['notify_client']); ?>
                                    <input class="form-check-input" type="checkbox" id="notify_client" name="notify_client" <?php echo $notifyClientDefault ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="notify_client">Notifica cliente via email</label>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check form-switch">
                                    <?php $notifyAdminDefault = $_SERVER['REQUEST_METHOD'] !== 'POST' || isset($_POST['notify_admin']); ?>
                                    <input class="form-check-input" type="checkbox" id="notify_admin" name="notify_admin" <?php echo $notifyAdminDefault ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="notify_admin">Notifica team interno</label>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card-footer bg-transparent border-0 d-flex justify-content-end gap-2">
                        <a class="btn btn-outline-secondary" href="index.php">Annulla</a>
                        <button class="btn btn-primary" type="submit">Registra ticket</button>
                    </div>
                </div>
            </div>
        </form>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
