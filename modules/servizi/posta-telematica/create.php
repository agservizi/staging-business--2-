<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/mailer.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

$pageTitle = 'Nuovo invio Posta Telematica';
$errors = [];

$clientsStmt = $pdo->query('SELECT id, nome, cognome, ragione_sociale FROM clienti ORDER BY ragione_sociale, cognome, nome');
$clients = $clientsStmt ? $clientsStmt->fetchAll(PDO::FETCH_ASSOC) : [];

$formData = [
    'channel' => 'email',
    'recipient' => '',
    'subject' => '',
    'message' => '',
    'cliente_id' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();

    $channel = strtolower(trim((string) ($_POST['channel'] ?? 'email')));
    $recipient = trim((string) ($_POST['recipient'] ?? ''));
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));
    $clienteId = isset($_POST['cliente_id']) ? (int) $_POST['cliente_id'] : 0;

    $formData = [
        'channel' => $channel,
        'recipient' => $recipient,
        'subject' => $subject,
        'message' => $message,
        'cliente_id' => $clienteId > 0 ? (string) $clienteId : '',
    ];

    if (!in_array($channel, ['email', 'pec'], true)) {
        $errors['channel'] = 'Seleziona un canale valido.';
    }

    if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        $errors['recipient'] = 'Inserisci un indirizzo email valido.';
    }

    if ($subject === '') {
        $errors['subject'] = 'Inserisci un oggetto.';
    }

    if ($message === '') {
        $errors['message'] = 'Inserisci il messaggio.';
    }

    $storedAttachments = [];
    if (empty($errors)) {
        try {
            if (!empty($_FILES['attachments'])) {
                $storedAttachments = posta_telematica_store_attachments($_FILES['attachments']);
            }
        } catch (Throwable $exception) {
            $errors['attachments'] = $exception->getMessage();
        }
    }

    if (empty($errors)) {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        $messageId = posta_telematica_create_message($pdo, [
            'channel' => $channel,
            'recipient_email' => $recipient,
            'subject' => $subject,
            'body' => $message,
            'status' => 'pending',
            'error_message' => null,
            'cliente_id' => $clienteId > 0 ? $clienteId : null,
            'created_by' => $userId,
        ]);

        posta_telematica_insert_attachments($pdo, $messageId, $storedAttachments);

        $preparedAttachments = [];
        foreach ($storedAttachments as $attachment) {
            $content = is_file($attachment['absolute_path']) ? file_get_contents($attachment['absolute_path']) : false;
            if ($content === false) {
                continue;
            }
            $preparedAttachments[] = [
                'name' => $attachment['original_name'],
                'content' => $content,
                'mime' => $attachment['mime'],
            ];
        }

        $content = nl2br(sanitize_output($message));
        $htmlBody = render_mail_template($subject, '<p>' . $content . '</p>');

        $sent = send_system_mail($recipient, $subject, $htmlBody, [
            'channel' => $channel,
            'attachments' => $preparedAttachments,
        ]);

        if ($sent) {
            posta_telematica_update_message_status($pdo, $messageId, 'sent', null);
            add_flash('success', 'Invio completato con successo.');
            header('Location: view.php?id=' . $messageId);
            exit;
        }

        posta_telematica_update_message_status($pdo, $messageId, 'failed', 'Invio non riuscito. Controlla la configurazione del canale.');
        $errors['general'] = 'Invio non riuscito. Controlla la configurazione del canale.';
    }
}

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div>
                <h1 class="h3 mb-1">Nuovo invio</h1>
                <p class="text-muted mb-0">Invia Email o PEC e archivia lo storico delle comunicazioni.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-secondary" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna</a>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-body">
                <?php if (!empty($errors['general'])): ?>
                    <div class="alert alert-danger"><?php echo sanitize_output($errors['general']); ?></div>
                <?php endif; ?>

                <form method="post" enctype="multipart/form-data" novalidate>
                    <input type="hidden" name="_token" value="<?php echo sanitize_output(csrf_token()); ?>">

                    <div class="row g-4">
                        <div class="col-md-4">
                            <label class="form-label" for="channel">Canale</label>
                            <select class="form-select <?php echo isset($errors['channel']) ? 'is-invalid' : ''; ?>" id="channel" name="channel" required>
                                <option value="email" <?php echo $formData['channel'] === 'email' ? 'selected' : ''; ?>>Email (Resend)</option>
                                <option value="pec" <?php echo $formData['channel'] === 'pec' ? 'selected' : ''; ?>>PEC (SMTP Namirial)</option>
                            </select>
                            <?php if (isset($errors['channel'])): ?>
                                <div class="invalid-feedback"><?php echo sanitize_output($errors['channel']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="recipient">Destinatario</label>
                            <input type="email" class="form-control <?php echo isset($errors['recipient']) ? 'is-invalid' : ''; ?>" id="recipient" name="recipient" value="<?php echo sanitize_output($formData['recipient']); ?>" required placeholder="cliente@example.com">
                            <?php if (isset($errors['recipient'])): ?>
                                <div class="invalid-feedback"><?php echo sanitize_output($errors['recipient']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="cliente_id">Cliente (opzionale)</label>
                            <select class="form-select" id="cliente_id" name="cliente_id">
                                <option value="">Seleziona cliente</option>
                                <?php foreach ($clients as $client): ?>
                                    <?php
                                        $label = trim((string) ($client['ragione_sociale'] ?? ''));
                                        $person = trim((string) ($client['cognome'] ?? '') . ' ' . (string) ($client['nome'] ?? ''));
                                        if ($label !== '' && $person !== '') {
                                            $label .= ' · ' . $person;
                                        } elseif ($label === '') {
                                            $label = $person;
                                        }
                                    ?>
                                    <option value="<?php echo (int) $client['id']; ?>" <?php echo (string) $client['id'] === $formData['cliente_id'] ? 'selected' : ''; ?>>
                                        <?php echo sanitize_output($label !== '' ? $label : 'Cliente #' . (int) $client['id']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="subject">Oggetto</label>
                            <input type="text" class="form-control <?php echo isset($errors['subject']) ? 'is-invalid' : ''; ?>" id="subject" name="subject" value="<?php echo sanitize_output($formData['subject']); ?>" required>
                            <?php if (isset($errors['subject'])): ?>
                                <div class="invalid-feedback"><?php echo sanitize_output($errors['subject']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="message">Messaggio</label>
                            <textarea class="form-control <?php echo isset($errors['message']) ? 'is-invalid' : ''; ?>" id="message" name="message" rows="6" required><?php echo sanitize_output($formData['message']); ?></textarea>
                            <?php if (isset($errors['message'])): ?>
                                <div class="invalid-feedback"><?php echo sanitize_output($errors['message']); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="attachments">Allegati (max 10MB)</label>
                            <input class="form-control <?php echo isset($errors['attachments']) ? 'is-invalid' : ''; ?>" type="file" id="attachments" name="attachments[]" multiple>
                            <?php if (isset($errors['attachments'])): ?>
                                <div class="invalid-feedback"><?php echo sanitize_output($errors['attachments']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <button class="btn btn-primary" type="submit">
                            <i class="fa-solid fa-paper-plane me-2"></i>Invia
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
