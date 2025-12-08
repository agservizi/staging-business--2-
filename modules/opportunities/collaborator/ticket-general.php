<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';
require_once __DIR__ . '/../../../includes/ticket_functions.php';
require_once __DIR__ . '/../../../includes/mailer.php';

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
if ($collaboratorId <= 0) {
    add_flash('warning', 'Sessione non valida.');
    header('Location: tickets.php');
    exit;
}

// Recupera le opportunity del collaboratore per selezione assistenza
$opportunities = [];
$opportunitiesStmt = $pdo->prepare(
    'SELECT id, code, customer_first_name, customer_last_name, customer_email, customer_phone
     FROM opportunities
     WHERE collaborator_id = :cid
     ORDER BY updated_at DESC
     LIMIT 200'
);
$opportunitiesStmt->execute([':cid' => $collaboratorId]);
$opportunities = $opportunitiesStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$opportunitiesMap = [];
foreach ($opportunities as $op) {
    $opportunitiesMap[(int) ($op['id'] ?? 0)] = $op;
}

$csrfToken = csrf_token();
$priorityOptions = ticket_priority_options();
$typeOptions = [
    'SUPPORT' => 'Supporto opportunity / cliente',
    'TECH' => 'Problemi tecnici al portale',
    'ADMIN' => 'Richieste informative / amministrative',
];

$defaultPriority = 'MEDIUM';
$defaultType = 'SUPPORT';

$authorNameDefault = trim(sprintf('%s %s', (string) ($_SESSION['cognome'] ?? ''), (string) ($_SESSION['nome'] ?? '')));
if ($authorNameDefault === '') {
    $authorNameDefault = (string) ($_SESSION['username'] ?? 'Collaboratore');
}

$errors = [];
$selectedOpportunityId = isset($_POST['opportunity_id']) ? (int) $_POST['opportunity_id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $subject = trim((string) ($_POST['subject'] ?? ''));
    $messageBody = trim((string) ($_POST['message'] ?? ''));
    $priorityInput = strtoupper((string) ($_POST['priority'] ?? $defaultPriority));
    $priority = array_key_exists($priorityInput, $priorityOptions) ? $priorityInput : $defaultPriority;
    $typeInput = strtoupper((string) ($_POST['type'] ?? $defaultType));
    $type = array_key_exists($typeInput, $typeOptions) ? $typeInput : $defaultType;

    $customerName = trim((string) ($_POST['customer_name'] ?? $authorNameDefault));
    $customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
    $customerPhone = trim((string) ($_POST['customer_phone'] ?? ''));

    $selectedOpportunityId = isset($_POST['opportunity_id']) ? (int) $_POST['opportunity_id'] : 0;
    $selectedOpportunity = $selectedOpportunityId > 0 && isset($opportunitiesMap[$selectedOpportunityId])
        ? $opportunitiesMap[$selectedOpportunityId]
        : null;

    if ($type === 'SUPPORT' && $selectedOpportunityId > 0 && $selectedOpportunity) {
        // Precompila dati cliente se presenti nell'opportunity
        $customerNameFromOp = trim(sprintf('%s %s', (string) ($selectedOpportunity['customer_first_name'] ?? ''), (string) ($selectedOpportunity['customer_last_name'] ?? '')));
        if ($customerName === '' && $customerNameFromOp !== '') {
            $customerName = $customerNameFromOp;
        }
        if ($customerEmail === '' && !empty($selectedOpportunity['customer_email'])) {
            $customerEmail = (string) $selectedOpportunity['customer_email'];
        }
        if ($customerPhone === '' && !empty($selectedOpportunity['customer_phone'])) {
            $customerPhone = (string) $selectedOpportunity['customer_phone'];
        }
    }

    if ($subject === '') {
        $errors[] = 'Inserisci un oggetto per il ticket.';
    }

    if ($messageBody === '') {
        $errors[] = 'Descrivi il problema o la richiesta.';
    }

    if ($customerEmail !== '' && !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'L\'email indicata non è valida.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $code = ticket_generate_code();
            $tagsArray = [
                'collaborator',
                'collaborator_id:' . $collaboratorId,
                'channel:portal',
                'type:' . strtolower($type),
            ];
            if ($type === 'SUPPORT' && $selectedOpportunityId > 0 && $selectedOpportunity) {
                $tagsArray[] = 'opportunity';
                $tagsArray[] = 'opportunity_id:' . $selectedOpportunityId;
                if (!empty($selectedOpportunity['code'])) {
                    $tagsArray[] = 'opportunity_code:' . (string) $selectedOpportunity['code'];
                }
            }
            $tags = json_encode($tagsArray, JSON_UNESCAPED_UNICODE);

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
                ':type' => $type,
                ':priority' => $priority,
                ':status' => 'OPEN',
                ':channel' => 'PORTAL',
                ':assigned_to' => null,
                ':tags' => $tags,
                ':sla_due_at' => null,
                ':created_by' => $collaboratorId,
            ]);

            $ticketId = (int) $pdo->lastInsertId();

            $messagePayload = [
                'ticket_id' => $ticketId,
                'author_id' => $collaboratorId,
                'author_name' => $authorNameDefault,
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

            $supportRecipient = (string) env('SUPPORT_TEAM_EMAIL', (string) env('MAIL_FROM_ADDRESS', ''));
            if ($supportRecipient !== '') {
                $baseUrl = rtrim((string) env('APP_URL', sprintf('%s://%s', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http', $_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
                $ticketLink = $baseUrl . '/modules/ticket/view.php?id=' . $ticketId;
                $mailBody = '<p>Nuovo ticket collaboratore #' . sanitize_output($code) . '.</p>'
                    . '<p><strong>Tipo:</strong> ' . sanitize_output(ticket_type_label($type)) . '</p>'
                    . '<p><strong>Oggetto:</strong> ' . sanitize_output($subject) . '</p>'
                    . '<p><a href="' . sanitize_output($ticketLink) . '">Apri il ticket</a></p>';
                send_system_mail($supportRecipient, 'Ticket collaboratore #' . $code, render_mail_template('Nuovo ticket', $mailBody));
            }

            add_flash('success', 'Ticket creato. Ti risponderemo al più presto.');
            header('Location: ticket-view.php?id=' . $ticketId);
            exit;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Impossibile creare il ticket: ' . $exception->getMessage();
        }
    }
} else {
    $subject = '';
    $messageBody = '';
    $priority = $defaultPriority;
    $type = $defaultType;
    $customerName = $authorNameDefault;
    $customerEmail = '';
    $customerPhone = '';
    $selectedOpportunity = null;
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Supporto</p>
                <h1 class="h4 mb-1">Apri un ticket</h1>
                <p class="text-muted mb-0">Segnala problemi tecnici al portale, richieste informative o supporto generale.</p>
            </div>
            <a class="btn btn-outline-secondary" href="<?php echo asset('modules/opportunities/collaborator/tickets.php'); ?>">
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

        <form class="card shadow-sm" method="post" enctype="multipart/form-data" autocomplete="off">
            <input type="hidden" name="_token" value="<?php echo sanitize_output($csrfToken); ?>">
            <div class="card-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label text-uppercase small text-muted" for="subject">Oggetto</label>
                        <input class="form-control" type="text" id="subject" name="subject" value="<?php echo sanitize_output($subject); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-uppercase small text-muted" for="type">Categoria</label>
                        <select class="form-select" id="type" name="type">
                            <?php foreach ($typeOptions as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>" <?php echo $type === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted">Scegli se è problema tecnico, info o supporto OP.</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label text-uppercase small text-muted" for="priority">Priorità</label>
                        <select class="form-select" id="priority" name="priority">
                            <?php foreach ($priorityOptions as $value => $label): ?>
                                <option value="<?php echo sanitize_output($value); ?>" <?php echo $priority === $value ? 'selected' : ''; ?>><?php echo sanitize_output($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6" id="opportunity-wrapper">
                        <label class="form-label text-uppercase small text-muted" for="opportunity_id">Opportunity (solo per supporto)</label>
                        <select class="form-select" id="opportunity_id" name="opportunity_id">
                            <option value="">Seleziona</option>
                            <?php foreach ($opportunities as $op): ?>
                                <?php
                                    $opId = (int) ($op['id'] ?? 0);
                                    $selectedAttr = $selectedOpportunityId === $opId ? 'selected' : '';
                                    $customerLabel = trim(($op['customer_first_name'] ?? '') . ' ' . ($op['customer_last_name'] ?? ''));
                                ?>
                                <option
                                    value="<?php echo $opId; ?>"
                                    <?php echo $selectedAttr; ?>
                                    data-customer-name="<?php echo sanitize_output($customerLabel); ?>"
                                    data-customer-email="<?php echo sanitize_output($op['customer_email'] ?? ''); ?>"
                                    data-customer-phone="<?php echo sanitize_output($op['customer_phone'] ?? ''); ?>"
                                >
                                    <?php echo sanitize_output(($op['code'] ?? 'OP') . ' · ' . ($customerLabel !== '' ? $customerLabel : 'Cliente non indicato')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-muted">Obbligatorio solo per richieste "Supporto opportunity / cliente".</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-uppercase small text-muted" for="customer_name">Nome e cognome / Azienda</label>
                        <input class="form-control" type="text" id="customer_name" name="customer_name" value="<?php echo sanitize_output($customerName); ?>" required>
                        <div class="form-text text-muted">Useremo questo nominativo per contattarti.</div>
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

                <div class="mb-4">
                    <label class="form-label text-uppercase small text-muted" for="message">Descrizione</label>
                    <textarea class="form-control" id="message" name="message" rows="5" required><?php echo sanitize_output($messageBody); ?></textarea>
                    <div class="form-text text-muted">Indica eventuali link o schermate utili.</div>
                </div>

                <div class="mb-4">
                    <label class="form-label text-uppercase small text-muted" for="attachments">Allegati (opzionale)</label>
                    <input class="form-control" type="file" id="attachments" name="attachments[]" multiple accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.doc,.docx,.xls,.xlsx,.zip">
                    <div class="form-text text-muted">Max 10MB per file. Formati ammessi: PDF, immagini, Office, ZIP.</div>
                </div>

                <div class="d-flex justify-content-end gap-2">
                    <a class="btn btn-outline-secondary" href="<?php echo asset('modules/opportunities/collaborator/tickets.php'); ?>">Annulla</a>
                    <button class="btn btn-primary" type="submit">
                        <i class="fa-solid fa-paper-plane me-2"></i>Invia ticket
                    </button>
                </div>
            </div>
        </form>
    </main>
</div>
<link rel="stylesheet" href="<?php echo asset('modules/opportunities/assets/opportunities.css'); ?>">
<script>
    (function() {
        const typeSelect = document.getElementById('type');
        const opportunityWrapper = document.getElementById('opportunity-wrapper');
        const opportunitySelect = document.getElementById('opportunity_id');
        const customerNameInput = document.getElementById('customer_name');
        const customerEmailInput = document.getElementById('customer_email');
        const customerPhoneInput = document.getElementById('customer_phone');

        const toggleOpportunityVisibility = () => {
            if (!typeSelect || !opportunityWrapper) return;
            const isSupport = typeSelect.value === 'SUPPORT';
            opportunityWrapper.classList.toggle('d-none', !isSupport);
        };

        const applyOpportunityData = () => {
            if (!opportunitySelect) return;
            const selected = opportunitySelect.options[opportunitySelect.selectedIndex];
            if (!selected) return;
            const name = selected.getAttribute('data-customer-name') || '';
            const email = selected.getAttribute('data-customer-email') || '';
            const phone = selected.getAttribute('data-customer-phone') || '';
            if (name && customerNameInput && !customerNameInput.value.trim()) {
                customerNameInput.value = name;
            }
            if (email && customerEmailInput && !customerEmailInput.value.trim()) {
                customerEmailInput.value = email;
            }
            if (phone && customerPhoneInput && !customerPhoneInput.value.trim()) {
                customerPhoneInput.value = phone;
            }
        };

        typeSelect?.addEventListener('change', toggleOpportunityVisibility);
        opportunitySelect?.addEventListener('change', applyOpportunityData);

        toggleOpportunityVisibility();
        applyOpportunityData();
    })();
</script>
<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
