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
        $messageIdHeader = null;
        if ($channel === 'pec') {
            $messageIdHeader = posta_telematica_generate_message_id((string) env('PEC_FROM_ADDRESS', ''));
        }

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
            'message_id_header' => $messageIdHeader ? posta_telematica_normalize_message_id($messageIdHeader) : null,
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
        $htmlBody = posta_telematica_render_mail_template($subject, '<p>' . $content . '</p>');

        $sent = send_system_mail($recipient, $subject, $htmlBody, [
            'channel' => $channel,
            'attachments' => $preparedAttachments,
            'message_id' => $messageIdHeader,
        ]);

        if ($sent) {
            posta_telematica_update_message_status($pdo, $messageId, 'sent', null);
            if ($channel === 'pec' && $messageIdHeader) {
                $messageRow = posta_telematica_get_message($pdo, $messageId);
                if ($messageRow) {
                    $receiptBody = posta_telematica_build_invio_receipt_body($messageRow);
                    posta_telematica_update_receipt($pdo, $messageIdHeader, 'invio', date('Y-m-d H:i:s'), $receiptBody, null);
                }
            }
            add_flash('success', 'Invio completato con successo.');
            header('Location: ' . posta_telematica_module_url('view', ['id' => $messageId]));
            exit;
        }

        $lastError = function_exists('get_last_mail_error') ? get_last_mail_error() : null;
        $errorMessage = 'Invio non riuscito. Controlla la configurazione del canale.';
        if ($lastError) {
            $errorMessage .= ' Dettaglio: ' . $lastError;
        }
        posta_telematica_update_message_status($pdo, $messageId, 'failed', $errorMessage);
        $errors['general'] = $errorMessage;
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
                <a class="btn btn-outline-secondary" href="<?php echo sanitize_output(posta_telematica_module_url('index')); ?>"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna</a>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-body">
                <style>
                    .pt-dropzone {
                        border: 2px dashed #cbd5e1;
                        border-radius: 12px;
                        padding: 20px;
                        background: #f8fafc;
                        transition: border-color 0.2s ease, background 0.2s ease;
                        cursor: pointer;
                    }
                    .pt-dropzone.is-dragover {
                        border-color: #0d6efd;
                        background: #eef4ff;
                    }
                    .pt-dropzone .pt-dropzone-title {
                        font-weight: 600;
                    }
                    .pt-dropzone .pt-dropzone-hint {
                        color: #6c757d;
                        font-size: 0.9rem;
                    }
                    .pt-dropzone-list {
                        margin-top: 12px;
                        padding-left: 0;
                        list-style: none;
                    }
                    .pt-dropzone-list li {
                        display: flex;
                        align-items: center;
                        gap: 8px;
                        padding: 6px 10px;
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        border-radius: 8px;
                        margin-bottom: 6px;
                    }
                    .pt-dropzone-list li i {
                        color: #0d6efd;
                    }
                    .pt-dropzone-input {
                        display: none;
                    }
                    .pt-recipient-suggestions {
                        position: absolute;
                        z-index: 1050;
                        width: 100%;
                        max-height: 240px;
                        overflow-y: auto;
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        border-radius: 10px;
                        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
                        padding: 6px;
                        margin-top: 6px;
                        display: none;
                    }
                    .pt-recipient-suggestions .pt-suggestion-item {
                        padding: 8px 10px;
                        border-radius: 8px;
                        cursor: pointer;
                        font-size: 0.95rem;
                    }
                    .pt-recipient-suggestions .pt-suggestion-item:hover {
                        background: #f1f5f9;
                    }
                </style>
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
                            <div class="position-relative">
                                <input type="email" class="form-control <?php echo isset($errors['recipient']) ? 'is-invalid' : ''; ?>" id="recipient" name="recipient" value="<?php echo sanitize_output($formData['recipient']); ?>" required placeholder="cliente@example.com" autocomplete="off">
                                <div class="pt-recipient-suggestions" id="recipientSuggestionsPanel"></div>
                            </div>
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
                            <div class="pt-dropzone" id="attachmentsDropzone" role="button" tabindex="0">
                                <div class="pt-dropzone-title">Trascina qui i file</div>
                                <div class="pt-dropzone-hint">oppure clicca per selezionare più file.</div>
                                <ul class="pt-dropzone-list" id="attachmentsList"></ul>
                            </div>
                            <input class="pt-dropzone-input <?php echo isset($errors['attachments']) ? 'is-invalid' : ''; ?>" type="file" id="attachments" name="attachments[]" multiple>
                            <?php if (isset($errors['attachments'])): ?>
                                <div class="invalid-feedback d-block"><?php echo sanitize_output($errors['attachments']); ?></div>
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

<script>
    (function () {
        const recipientInput = document.getElementById('recipient');
        const recipientSuggestionsPanel = document.getElementById('recipientSuggestionsPanel');
        const fetchSuggestions = async (query) => {
            if (!recipientSuggestionsPanel) {
                return;
            }
            recipientSuggestionsPanel.innerHTML = '';
            if (query.length < 3) {
                recipientSuggestionsPanel.style.display = 'none';
                return;
            }
            try {
                const response = await fetch('recipients.php?q=' + encodeURIComponent(query));
                const data = await response.json();
                const results = Array.isArray(data.results) ? data.results : [];
                if (results.length === 0) {
                    recipientSuggestionsPanel.style.display = 'none';
                    return;
                }
                results.forEach((email) => {
                    const item = document.createElement('div');
                    item.className = 'pt-suggestion-item';
                    item.textContent = email;
                    item.addEventListener('click', () => {
                        recipientInput.value = email;
                        recipientSuggestionsPanel.style.display = 'none';
                    });
                    recipientSuggestionsPanel.appendChild(item);
                });
                recipientSuggestionsPanel.style.display = 'block';
            } catch (error) {
                recipientSuggestionsPanel.innerHTML = '';
                recipientSuggestionsPanel.style.display = 'none';
            }
        };

        if (recipientInput) {
            let debounceId;
            recipientInput.addEventListener('input', () => {
                clearTimeout(debounceId);
                const value = recipientInput.value.trim();
                debounceId = setTimeout(() => fetchSuggestions(value), 200);
            });
            recipientInput.addEventListener('focus', () => {
                const value = recipientInput.value.trim();
                fetchSuggestions(value);
            });
        }

        document.addEventListener('click', (event) => {
            if (!recipientSuggestionsPanel || !recipientInput) {
                return;
            }
            if (recipientSuggestionsPanel.contains(event.target) || recipientInput.contains(event.target)) {
                return;
            }
            recipientSuggestionsPanel.style.display = 'none';
        });

        const dropzone = document.getElementById('attachmentsDropzone');
        const input = document.getElementById('attachments');
        const list = document.getElementById('attachmentsList');

        if (!dropzone || !input || !list) {
            return;
        }

        const renderList = (files) => {
            list.innerHTML = '';
            if (!files || files.length === 0) {
                return;
            }
            Array.from(files).forEach((file) => {
                const li = document.createElement('li');
                li.innerHTML = '<i class="fa-solid fa-paperclip"></i><span></span>';
                li.querySelector('span').textContent = file.name + ' (' + Math.ceil(file.size / 1024) + ' KB)';
                list.appendChild(li);
            });
        };

        const updateFiles = (files) => {
            if (!files) {
                return;
            }
            const dataTransfer = new DataTransfer();
            Array.from(files).forEach((file) => dataTransfer.items.add(file));
            input.files = dataTransfer.files;
            renderList(input.files);
        };

        dropzone.addEventListener('click', () => input.click());
        dropzone.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                input.click();
            }
        });

        input.addEventListener('change', () => {
            renderList(input.files);
        });

        ['dragenter', 'dragover'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (event) => {
                event.preventDefault();
                event.stopPropagation();
                dropzone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'drop'].forEach((eventName) => {
            dropzone.addEventListener(eventName, (event) => {
                event.preventDefault();
                event.stopPropagation();
                dropzone.classList.remove('is-dragover');
            });
        });

        dropzone.addEventListener('drop', (event) => {
            const files = event.dataTransfer ? event.dataTransfer.files : null;
            updateFiles(files);
        });
    })();
</script>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
