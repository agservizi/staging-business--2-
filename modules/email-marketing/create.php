<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Nuova campagna email';

if (!function_exists('email_marketing_tables_ready')) {
    function email_marketing_tables_ready(PDO $pdo): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        try {
            $pdo->query('SELECT 1 FROM email_campaigns LIMIT 1');
            $pdo->query('SELECT 1 FROM email_subscribers LIMIT 1');
            $cache = true;
        } catch (PDOException $exception) {
            error_log('Email marketing tables missing: ' . $exception->getMessage());
            $cache = false;
        }

        return $cache;
    }
}

if (!function_exists('parse_manual_recipients')) {

if (!function_exists('email_templates_column_exists')) {
    function email_templates_column_exists(PDO $pdo, string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }

        try {
            $statement = $pdo->query("SHOW COLUMNS FROM email_templates LIKE '" . str_replace("'", "''", $column) . "'");
            $cache[$column] = $statement && $statement->fetch(PDO::FETCH_ASSOC) !== false;
        } catch (PDOException $exception) {
            error_log('Email template column check failed: ' . $exception->getMessage());
            $cache[$column] = false;
        }

        return $cache[$column];
    }
}
    /**
     * @return array<int, array{email:string,first_name:string|null,last_name:string|null}>
     */
    function parse_manual_recipients(string $input, array &$invalidEmails = []): array
    {
        $invalidEmails = [];
        $lines = preg_split("/(\r\n|\n|,|;)+/", $input) ?: [];
        $parsed = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $namePart = '';
            $email = $line;
            if (preg_match('/^(.+?)<([^>]+)>$/', $line, $matches)) {
                $namePart = trim($matches[1], " \"'{}");
                $email = trim($matches[2]);
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $invalidEmails[] = $email;
                continue;
            }

            $firstName = null;
            $lastName = null;
            if ($namePart !== '') {
                $parts = preg_split('/\s+/', $namePart);
                if ($parts) {
                    $firstName = array_shift($parts);
                    $lastName = $parts ? implode(' ', $parts) : null;
                }
            }

            $parsed[] = [
                'email' => strtolower($email),
                'first_name' => $firstName,
                'last_name' => $lastName,
            ];
        }

        return $parsed;
    }
}

$emailTablesReady = email_marketing_tables_ready($pdo);
$csrfToken = csrf_token();

$templates = [];
$lists = [];
if ($emailTablesReady) {
    try {
        $orderColumn = email_templates_column_exists($pdo, 'updated_at') ? 'updated_at' : 'id';
        $templateStmt = $pdo->query('SELECT id, name, subject FROM email_templates ORDER BY ' . $orderColumn . ' DESC');
        $templates = $templateStmt->fetchAll() ?: [];
        $listStmt = $pdo->query('SELECT id, name FROM email_lists ORDER BY name');
        $lists = $listStmt->fetchAll() ?: [];
    } catch (PDOException $exception) {
        error_log('Email marketing init error: ' . $exception->getMessage());
        $emailTablesReady = false;
    }
}

$emailMarketingConfig = get_email_marketing_config($pdo);

$defaults = [
    'name' => trim($_POST['name'] ?? ''),
    'subject' => trim($_POST['subject'] ?? ''),
    'from_name' => trim($_POST['from_name'] ?? ($emailMarketingConfig['sender_name'] ?? 'Coresuite Business')),
    'from_email' => trim($_POST['from_email'] ?? ($emailMarketingConfig['sender_email'] ?? 'marketing@example.com')),
    'reply_to' => trim($_POST['reply_to'] ?? ($emailMarketingConfig['reply_to_email'] ?? '')),
    'audience_type' => $_POST['audience_type'] ?? 'all_clients',
    'list_ids' => isset($_POST['list_ids']) ? array_map('intval', (array) $_POST['list_ids']) : [],
    'manual_emails' => trim($_POST['manual_emails'] ?? ''),
    'content_html' => $_POST['content_html'] ?? '',
    'template_id' => $_POST['template_id'] ?? '',
];

$errors = [];
if ($emailTablesReady && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $defaults['name'];
    $subject = $defaults['subject'];
    $fromName = $defaults['from_name'];
    $fromEmail = $defaults['from_email'];
    $replyTo = $defaults['reply_to'];
    $audienceType = $defaults['audience_type'];
    $templateId = $defaults['template_id'] !== '' ? (int) $defaults['template_id'] : null;
    $contentHtml = trim($defaults['content_html']);

    if ($name === '') {
        $errors[] = 'Inserisci un nome per la campagna.';
    }

    if ($subject === '') {
        $errors[] = 'Imposta l\'oggetto della email.';
    }

    if ($fromName === '') {
        $errors[] = 'Specificare il mittente della campagna.';
    }

    if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Indirizzo mittente non valido.';
    }

    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Indirizzo di risposta non valido.';
    }

    $allowedAudiences = ['all_clients', 'list', 'manual'];
    if (!in_array($audienceType, $allowedAudiences, true)) {
        $audienceType = 'all_clients';
    }

    $manualRecipients = [];
    if ($audienceType === 'list') {
        if (!$defaults['list_ids']) {
            $errors[] = 'Seleziona almeno una lista destinatari.';
        }
    }

    if ($audienceType === 'manual') {
        $invalid = [];
        $manualRecipients = parse_manual_recipients($defaults['manual_emails'], $invalid);
        if ($invalid) {
            $errors[] = 'Email non valide: ' . implode(', ', $invalid);
        }
        if (!$manualRecipients) {
            $errors[] = 'Inserisci almeno un destinatario valido.';
        }
    }

    if ($templateId === null && $contentHtml === '') {
        $errors[] = 'Scrivi il contenuto della campagna o seleziona un modello.';
    }

    $audienceFilters = [];
    if ($audienceType === 'list') {
        $audienceFilters['list_ids'] = array_values(array_unique($defaults['list_ids']));
    }
    if ($audienceType === 'manual') {
        $audienceFilters['manual_emails'] = $manualRecipients;
    }

    try {
        $filtersJson = json_encode($audienceFilters, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $errors[] = 'Impossibile serializzare i destinatari: ' . $exception->getMessage();
        $filtersJson = null;
    }

    if (!$errors) {
        $replyToFinal = $replyTo !== '' ? $replyTo : $fromEmail;
        try {
            $stmt = $pdo->prepare('INSERT INTO email_campaigns (name, subject, from_name, from_email, reply_to, template_id, content_html, audience_type, audience_filters, status, created_by, created_at, updated_at)
                VALUES (:name, :subject, :from_name, :from_email, :reply_to, :template_id, :content_html, :audience_type, :audience_filters, \'draft\', :created_by, NOW(), NOW())');
            $stmt->execute([
                ':name' => $name,
                ':subject' => $subject,
                ':from_name' => $fromName,
                ':from_email' => $fromEmail,
                ':reply_to' => $replyToFinal,
                ':template_id' => $templateId,
                ':content_html' => $contentHtml !== '' ? $contentHtml : null,
                ':audience_type' => $audienceType,
                ':audience_filters' => $filtersJson,
                ':created_by' => (int) $_SESSION['user_id'],
            ]);

            $campaignId = (int) $pdo->lastInsertId();

            $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
                VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
            $logStmt->execute([
                ':user_id' => (int) $_SESSION['user_id'],
                ':modulo' => 'Email marketing',
                ':azione' => 'Creazione campagna',
                ':dettagli' => $name,
            ]);

            add_flash('success', 'Campagna creata. Puoi completarla e inviarla dalla schermata di dettaglio.');
            header('Location: view.php?id=' . $campaignId);
            exit;
        } catch (PDOException $exception) {
            error_log('Email campaign create failed: ' . $exception->getMessage());
            $errors[] = 'Errore durante il salvataggio. Riprova più tardi.';
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Nuova campagna email</h1>
                <p class="text-muted mb-0">Imposta contenuto, mittente e destinatari. Potrai inviare test e programmare l'invio in seguito.</p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna</a>
            </div>
        </div>

        <?php if (!$emailTablesReady): ?>
            <div class="alert alert-warning">
                Per creare una campagna esegui prima la migrazione delle tabelle email marketing.
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo sanitize_output($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($emailTablesReady): ?>
            <div class="card ag-card">
                <div class="card-body">
                    <form method="post" class="row g-4">
                        <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                        <div class="col-12 col-lg-6">
                            <label class="form-label" for="name">Nome campagna *</label>
                            <input class="form-control" id="name" name="name" required value="<?php echo sanitize_output($defaults['name']); ?>">
                        </div>
                        <div class="col-12 col-lg-6">
                            <label class="form-label" for="subject">Oggetto email *</label>
                            <input class="form-control" id="subject" name="subject" required value="<?php echo sanitize_output($defaults['subject']); ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="from_name">Mittente (nome) *</label>
                            <input class="form-control" id="from_name" name="from_name" required value="<?php echo sanitize_output($defaults['from_name']); ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="from_email">Mittente (email) *</label>
                            <input class="form-control" id="from_email" name="from_email" type="email" required value="<?php echo sanitize_output($defaults['from_email']); ?>">
                        </div>
                        <div class="col-12 col-md-4">
                            <label class="form-label" for="reply_to">Rispondi a</label>
                            <input class="form-control" id="reply_to" name="reply_to" type="email" value="<?php echo sanitize_output($defaults['reply_to']); ?>" placeholder="Lascia vuoto per usare il mittente">
                        </div>

                        <div class="col-12 col-xl-6">
                            <label class="form-label d-block">Destinatari *</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="audience_type" id="audience_clients" value="all_clients" <?php echo $defaults['audience_type'] === 'all_clients' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="audience_clients">Tutti i clienti con email</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="audience_type" id="audience_list" value="list" <?php echo $defaults['audience_type'] === 'list' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="audience_list">Una o più liste</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="audience_type" id="audience_manual" value="manual" <?php echo $defaults['audience_type'] === 'manual' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="audience_manual">Inserimento manuale</label>
                            </div>

                            <div class="mt-3" id="audience_lists" <?php echo $defaults['audience_type'] === 'list' ? '' : 'hidden'; ?>>
                                <label class="form-label" for="list_ids">Seleziona liste</label>
                                <select class="form-select" id="list_ids" name="list_ids[]" multiple size="5">
                                    <?php foreach ($lists as $list): ?>
                                        <option value="<?php echo (int) $list['id']; ?>" <?php echo in_array((int) $list['id'], $defaults['list_ids'], true) ? 'selected' : ''; ?>><?php echo sanitize_output($list['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Tieni premuto CTRL (o CMD) per selezione multipla.</small>
                            </div>

                            <div class="mt-3" id="audience_manual_block" <?php echo $defaults['audience_type'] === 'manual' ? '' : 'hidden'; ?>>
                                <label class="form-label" for="manual_emails">Destinatari manuali</label>
                                <textarea class="form-control" id="manual_emails" name="manual_emails" rows="6" placeholder="Nome Cognome <email@example.com>
altro@example.com"><?php echo sanitize_output($defaults['manual_emails']); ?></textarea>
                                <small class="text-muted">Separare con virgola o a capo. Supportati formati "Nome Cognome &lt;email&gt;" o solo email.</small>
                            </div>
                        </div>

                        <div class="col-12 col-xl-6">
                            <label class="form-label" for="template_id">Modello</label>
                            <select class="form-select" id="template_id" name="template_id">
                                <option value="">Nessun modello (usa il contenuto sotto)</option>
                                <?php foreach ($templates as $template): ?>
                                    <option value="<?php echo (int) $template['id']; ?>" <?php echo ((string) $defaults['template_id'] === (string) $template['id']) ? 'selected' : ''; ?>><?php echo sanitize_output($template['name']); ?> (<?php echo sanitize_output($template['subject']); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Puoi creare o modificare i modelli dalla sezione dedicata.</small>
                            <div class="mt-3">
                                <label class="form-label" for="content_html">Contenuto HTML</label>
                                <textarea class="form-control" id="content_html" name="content_html" rows="12" placeholder="<p>Ciao {{first_name}}, ...</p>"><?php echo sanitize_output($defaults['content_html']); ?></textarea>
                                <small class="text-muted">Token disponibili: <code>{{first_name}}</code>, <code>{{last_name}}</code>, <code>{{full_name}}</code>, <code>{{email}}</code>, <code>{{unsubscribe_url}}</code>.</small>
                            </div>
                        </div>

                        <div class="col-12 text-end">
                            <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva campagna</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<script>
    (function() {
        const audienceRadios = document.querySelectorAll('input[name="audience_type"]');
        const listBlock = document.getElementById('audience_lists');
        const manualBlock = document.getElementById('audience_manual_block');
        audienceRadios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                const value = this.value;
                if (value === 'list') {
                    listBlock.removeAttribute('hidden');
                } else {
                    listBlock.setAttribute('hidden', 'hidden');
                }
                if (value === 'manual') {
                    manualBlock.removeAttribute('hidden');
                } else {
                    manualBlock.setAttribute('hidden', 'hidden');
                }
            });
        });
    })();
</script>
