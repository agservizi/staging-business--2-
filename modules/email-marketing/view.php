<?php
use App\Services\EmailMarketing\CampaignDispatcher;
use DateTimeImmutable;
use Exception;
use JsonException;
use PDOException;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/mailer.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Campagna email';

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

/**
 * @return array<string, mixed>
 */
function load_campaign(PDO $pdo, int $campaignId): array
{
    $stmt = $pdo->prepare('SELECT * FROM email_campaigns WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $campaignId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

/**
 * @return array<string, mixed>
 */
function load_template(PDO $pdo, ?int $templateId): array
{
    if ($templateId === null || $templateId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('SELECT * FROM email_templates WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $templateId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: [];
}

/**
 * @return array<string, mixed>
 */
function decode_campaign_filters(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    try {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        error_log('Campaign audience decode failed: ' . $exception->getMessage());
        return [];
    }
    return is_array($decoded) ? $decoded : [];
}

function flatten_manual_recipients(array $manual): string
{
    if (!$manual) {
        return '';
    }
    $lines = [];
    foreach ($manual as $row) {
        $email = $row['email'] ?? '';
        $first = trim((string) ($row['first_name'] ?? ''));
        $last = trim((string) ($row['last_name'] ?? ''));
        $name = trim($first . ' ' . $last);
        if ($name !== '') {
            $lines[] = $name . ' <' . $email . '>';
        } else {
            $lines[] = $email;
        }
    }
    return implode(PHP_EOL, $lines);
}

function estimate_audience_count(PDO $pdo, string $audienceType, array $filters): int
{
    if ($audienceType === 'manual') {
        return isset($filters['manual_emails']) && is_array($filters['manual_emails']) ? count($filters['manual_emails']) : 0;
    }

    if ($audienceType === 'list') {
        if (empty($filters['list_ids']) || !is_array($filters['list_ids'])) {
            return 0;
        }
        $ids = array_map('intval', $filters['list_ids']);
        if (!$ids) {
            return 0;
        }
        $placeholder = implode(', ', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare('SELECT COUNT(DISTINCT subscriber_id) FROM email_list_subscribers WHERE list_id IN (' . $placeholder . ') AND status = \'active\'');
        $stmt->execute($ids);
        return (int) $stmt->fetchColumn();
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM clienti WHERE email IS NOT NULL AND email <> ''");
    return (int) $stmt->fetchColumn();
}

if (!function_exists('campaign_status_badge')) {
    function campaign_status_badge(string $status): string
    {
        $map = [
            'draft' => 'bg-secondary',
            'scheduled' => 'bg-info',
            'sending' => 'bg-warning text-dark',
            'sent' => 'bg-success',
            'cancelled' => 'bg-dark',
            'failed' => 'bg-danger',
        ];
        $class = $map[$status] ?? 'bg-secondary';
        return '<span class="badge ' . $class . ' text-uppercase">' . sanitize_output($status) . '</span>';
    }
}

function build_preview_subject(array $campaign, array $recipient): string
{
    $fullName = trim(sprintf('%s %s', $recipient['first_name'] ?? '', $recipient['last_name'] ?? ''));
    $replacements = [
        '{{email}}' => $recipient['email'] ?? '',
        '{{first_name}}' => $recipient['first_name'] ?? '',
        '{{last_name}}' => $recipient['last_name'] ?? '',
        '{{full_name}}' => $fullName,
    ];
    return strtr((string) ($campaign['subject'] ?? ''), $replacements);
}

function build_preview_html(array $campaign, array $recipient, array $template = [], ?string $unsubscribeBaseUrl = null): string
{
    $content = (string) ($campaign['content_html'] ?? '');
    if ($content === '' && $template) {
        $content = (string) ($template['html'] ?? '');
    }

    $base = $unsubscribeBaseUrl ?? '';
    if ($base === '') {
        $base = base_url();
    }
    $base = rtrim($base, '/');
    $unsubscribeUrl = $base . '/email-unsubscribe.php?token=anteprima';
    $hasPlaceholder = strpos($content, '{{unsubscribe_url}}') !== false;
    $fullName = trim(sprintf('%s %s', $recipient['first_name'] ?? '', $recipient['last_name'] ?? ''));

    $replacements = [
        '{{email}}' => $recipient['email'] ?? '',
        '{{first_name}}' => $recipient['first_name'] ?? '',
        '{{last_name}}' => $recipient['last_name'] ?? '',
        '{{full_name}}' => $fullName,
        '{{unsubscribe_url}}' => $unsubscribeUrl,
    ];

    $personalized = strtr($content, $replacements);
    if (!preg_match('/<html[\s>]/i', $personalized)) {
        $personalized .= '<p style="margin-top:24px; font-size:12px; color:#6c7d93">Se non vuoi più ricevere queste comunicazioni <a href="' . $unsubscribeUrl . '">clicca qui per disiscriverti</a>.</p>';
        return render_mail_template((string) ($campaign['name'] ?? 'Campagna'), $personalized);
    }

    if (!$hasPlaceholder) {
        $personalized .= '<p style="margin:24px 0; font-size:12px; color:#6c7d93">Se non vuoi più ricevere queste comunicazioni <a href="' . $unsubscribeUrl . '">clicca qui</a>.</p>';
    }

    return $personalized;
}

$campaignId = (int) ($_GET['id'] ?? 0);
if ($campaignId <= 0) {
    header('Location: index.php');
    exit;
}

$emailTablesReady = email_marketing_tables_ready($pdo);
if (!$emailTablesReady) {
    add_flash('warning', 'Completa la migrazione email marketing prima di accedere al dettaglio campagna.');
    header('Location: index.php');
    exit;
}

$emailMarketingConfig = get_email_marketing_config($pdo);
$unsubscribeBaseUrl = rtrim((string) ($emailMarketingConfig['unsubscribe_base_url'] ?? base_url()), '/');

$campaign = load_campaign($pdo, $campaignId);
if (!$campaign) {
    add_flash('warning', 'Campagna non trovata.');
    header('Location: index.php');
    exit;
}

$filters = decode_campaign_filters($campaign['audience_filters'] ?? null);
$manualEmailsText = flatten_manual_recipients($filters['manual_emails'] ?? []);

$csrfToken = csrf_token();
$errors = [];

try {
    $templateStmt = $pdo->query('SELECT id, name FROM email_templates ORDER BY name');
    $templates = $templateStmt->fetchAll() ?: [];
    $listStmt = $pdo->query('SELECT id, name FROM email_lists ORDER BY name');
    $lists = $listStmt->fetchAll() ?: [];
} catch (PDOException $exception) {
    error_log('Email marketing supporting data error: ' . $exception->getMessage());
    $templates = [];
    $lists = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'meta') {
        $name = trim($_POST['name'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $fromName = trim($_POST['from_name'] ?? '');
        $fromEmail = trim($_POST['from_email'] ?? '');
        $replyTo = trim($_POST['reply_to'] ?? '');
        $scheduledAtInput = trim($_POST['scheduled_at'] ?? '');
        $scheduledAt = null;

        if ($name === '') {
            $errors[] = 'Il nome campagna è obbligatorio.';
        }
        if ($subject === '') {
            $errors[] = 'L\'oggetto è obbligatorio.';
        }
        if ($fromName === '') {
            $errors[] = 'Il mittente è obbligatorio.';
        }
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email mittente non valida.';
        }
        if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email di risposta non valida.';
        }

        if ($scheduledAtInput !== '') {
            try {
                $scheduleDate = new DateTimeImmutable($scheduledAtInput);
                $scheduledAt = $scheduleDate->format('Y-m-d H:i:s');
            } catch (Exception $exception) {
                $errors[] = 'Formato data/ora non valido per la pianificazione.';
            }
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare('UPDATE email_campaigns SET name = :name, subject = :subject, from_name = :from_name, from_email = :from_email, reply_to = :reply_to, scheduled_at = :scheduled_at, updated_at = NOW() WHERE id = :id');
                $stmt->execute([
                    ':name' => $name,
                    ':subject' => $subject,
                    ':from_name' => $fromName,
                    ':from_email' => $fromEmail,
                    ':reply_to' => $replyTo !== '' ? $replyTo : $fromEmail,
                    ':scheduled_at' => $scheduledAt,
                    ':id' => $campaignId,
                ]);
                add_flash('success', 'Metadati aggiornati.');
                $campaign = load_campaign($pdo, $campaignId);
            } catch (PDOException $exception) {
                error_log('Campaign meta update failed: ' . $exception->getMessage());
                $errors[] = 'Impossibile aggiornare i dati della campagna.';
            }
        }
    }

    if ($action === 'audience') {
        $audienceType = $_POST['audience_type'] ?? 'all_clients';
        $allowedAudiences = ['all_clients', 'list', 'manual'];
        if (!in_array($audienceType, $allowedAudiences, true)) {
            $audienceType = 'all_clients';
        }

        $listIds = isset($_POST['list_ids']) ? array_map('intval', (array) $_POST['list_ids']) : [];
        $manualText = trim($_POST['manual_emails'] ?? '');
        $manualRecipients = [];

        if ($audienceType === 'list' && !$listIds) {
            $errors[] = 'Seleziona almeno una lista.';
        }

        if ($audienceType === 'manual') {
            $invalid = [];
            $manualRecipients = parse_manual_recipients($manualText, $invalid);
            if ($invalid) {
                $errors[] = 'Email non valide: ' . implode(', ', $invalid);
            }
            if (!$manualRecipients) {
                $errors[] = 'Inserisci almeno un destinatario valido.';
            }
        }

        if (!$errors) {
            $filtersPayload = [];
            if ($audienceType === 'list') {
                $filtersPayload['list_ids'] = array_values(array_unique($listIds));
            }
            if ($audienceType === 'manual') {
                $filtersPayload['manual_emails'] = $manualRecipients;
            }

            try {
                $filtersJson = json_encode($filtersPayload, JSON_THROW_ON_ERROR);
                $stmt = $pdo->prepare('UPDATE email_campaigns SET audience_type = :audience_type, audience_filters = :filters, updated_at = NOW() WHERE id = :id');
                $stmt->execute([
                    ':audience_type' => $audienceType,
                    ':filters' => $filtersJson,
                    ':id' => $campaignId,
                ]);
                $pdo->prepare('DELETE FROM email_campaign_recipients WHERE campaign_id = :id')->execute([':id' => $campaignId]);
                add_flash('success', 'Destinatari aggiornati. I destinatari generati verranno ricostruiti al prossimo invio.');
                $campaign = load_campaign($pdo, $campaignId);
                $filters = decode_campaign_filters($campaign['audience_filters'] ?? null);
                $manualEmailsText = flatten_manual_recipients($filters['manual_emails'] ?? []);
            } catch (JsonException|PDOException $exception) {
                error_log('Campaign audience update failed: ' . $exception->getMessage());
                $errors[] = 'Impossibile aggiornare il pubblico destinato.';
            }
        }
    }

    if ($action === 'content') {
        $templateId = $_POST['template_id'] !== '' ? (int) $_POST['template_id'] : null;
        $contentHtml = $_POST['content_html'] ?? '';

        $validTemplate = null;
        if ($templateId !== null) {
            foreach ($templates as $tpl) {
                if ((int) $tpl['id'] === $templateId) {
                    $validTemplate = $templateId;
                    break;
                }
            }
            if ($validTemplate === null) {
                $errors[] = 'Modello selezionato non valido.';
            }
        }

        if ($validTemplate === null && trim($contentHtml) === '') {
            $errors[] = 'Scrivi il contenuto della campagna o scegli un modello.';
        }

        if (!$errors) {
            try {
                $stmt = $pdo->prepare('UPDATE email_campaigns SET template_id = :template_id, content_html = :content_html, updated_at = NOW() WHERE id = :id');
                $stmt->execute([
                    ':template_id' => $validTemplate,
                    ':content_html' => trim($contentHtml) !== '' ? $contentHtml : null,
                    ':id' => $campaignId,
                ]);
                add_flash('success', 'Contenuto aggiornato.');
                $campaign = load_campaign($pdo, $campaignId);
            } catch (PDOException $exception) {
                error_log('Campaign content update failed: ' . $exception->getMessage());
                $errors[] = 'Impossibile salvare il contenuto.';
            }
        }
    }

    if ($action === 'test') {
        $testEmail = trim($_POST['test_email'] ?? '');
        if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Inserisci un indirizzo email di test valido.';
        } else {
            $previewRecipient = [
                'email' => $testEmail,
                'first_name' => trim($_POST['test_first_name'] ?? 'Test'),
                'last_name' => trim($_POST['test_last_name'] ?? 'User'),
            ];
            $templateRow = load_template($pdo, $campaign['template_id'] !== null ? (int) $campaign['template_id'] : null);
            $previewHtml = build_preview_html($campaign, $previewRecipient, $templateRow, $unsubscribeBaseUrl);
            $previewSubject = build_preview_subject($campaign, $previewRecipient);
            $sent = send_system_mail($testEmail, $previewSubject, $previewHtml, ['channel' => 'marketing']);
            if ($sent) {
                add_flash('success', 'Email di test inviata a ' . sanitize_output($testEmail) . '.');
            } else {
                $errors[] = 'Invio test fallito. Controlla la configurazione Resend.';
            }
        }
    }

    if ($action === 'send') {
        $mode = $_POST['mode'] ?? 'send';
        $dryRun = $mode === 'preview';
        $dispatcher = new CampaignDispatcher($pdo, static function (string $to, string $subject, string $html, array $options = []): bool {
            if (!isset($options['channel'])) {
                $options['channel'] = 'marketing';
            }
            return send_system_mail($to, $subject, $html, $options);
        }, $unsubscribeBaseUrl);

        try {
            $summary = $dispatcher->dispatch($campaignId, $dryRun);
            if ($dryRun) {
                add_flash('info', sprintf('Destinatari stimati: %d totali, %d inviabili, %d esclusi.', $summary['total'], $summary['sent'], $summary['skipped']));
            } else {
                add_flash('success', sprintf('Campagna inviata: %d consegnate, %d errori, %d esclusi.', $summary['sent'], $summary['failed'], $summary['skipped']));
                $campaign = load_campaign($pdo, $campaignId);
                $logStmt = $pdo->prepare('INSERT INTO log_attivita (user_id, modulo, azione, dettagli, created_at)
                    VALUES (:user_id, :modulo, :azione, :dettagli, NOW())');
                $logStmt->execute([
                    ':user_id' => (int) $_SESSION['user_id'],
                    ':modulo' => 'Email marketing',
                    ':azione' => 'Invio campagna',
                    ':dettagli' => $campaign['name'] ?? ('Campagna #' . $campaignId),
                ]);
            }
        } catch (RuntimeException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Campaign send failed: ' . $exception->getMessage());
            $errors[] = 'Errore durante l\'invio. Dettaglio: ' . $exception->getMessage();
        }
    }
}

$filters = decode_campaign_filters($campaign['audience_filters'] ?? null);
$manualEmailsText = flatten_manual_recipients($filters['manual_emails'] ?? []);
$audienceCount = estimate_audience_count($pdo, $campaign['audience_type'] ?? 'all_clients', $filters);

$recipientStmt = $pdo->prepare('SELECT email, first_name, last_name, status, sent_at, last_error FROM email_campaign_recipients WHERE campaign_id = :id ORDER BY created_at DESC LIMIT 150');
$recipientStmt->execute([':id' => $campaignId]);
$recipientRows = $recipientStmt->fetchAll() ?: [];

$recipientSummary = [
    'total' => 0,
    'pending' => 0,
    'sent' => 0,
    'failed' => 0,
    'skipped' => 0,
];
foreach ($recipientRows as $row) {
    $recipientSummary['total']++;
    $status = $row['status'] ?? 'pending';
    if (isset($recipientSummary[$status])) {
        $recipientSummary[$status]++;
    }
}

$metrics = [];
if (!empty($campaign['metrics_summary'])) {
    try {
        $metrics = json_decode((string) $campaign['metrics_summary'], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $metrics = [];
    }
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4 d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <h1 class="h3 mb-1"><?php echo sanitize_output($campaign['name'] ?? 'Campagna email'); ?></h1>
                <p class="text-muted mb-0">Stato: <?php echo campaign_status_badge($campaign['status'] ?? 'draft'); ?> • Destinatari stimati: <?php echo number_format($audienceCount); ?></p>
            </div>
            <div class="toolbar-actions">
                <a class="btn btn-outline-light" href="index.php"><i class="fa-solid fa-arrow-left me-2"></i>Ritorna</a>
            </div>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?php foreach ($errors as $error): ?>
                    <div><?php echo sanitize_output($error); ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="row g-4">
            <div class="col-12 col-xl-6">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Metadati</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="meta">
                            <div class="col-12">
                                <label class="form-label" for="name">Nome</label>
                                <input class="form-control" id="name" name="name" value="<?php echo sanitize_output($campaign['name']); ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="subject">Oggetto</label>
                                <input class="form-control" id="subject" name="subject" value="<?php echo sanitize_output($campaign['subject']); ?>" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="from_name">Mittente</label>
                                <input class="form-control" id="from_name" name="from_name" value="<?php echo sanitize_output($campaign['from_name']); ?>" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="from_email">Email mittente</label>
                                <input class="form-control" id="from_email" name="from_email" type="email" value="<?php echo sanitize_output($campaign['from_email']); ?>" required>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="reply_to">Rispondi a</label>
                                <input class="form-control" id="reply_to" name="reply_to" type="email" value="<?php echo sanitize_output($campaign['reply_to']); ?>" placeholder="Lascia vuoto per usare il mittente">
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label" for="scheduled_at">Pianifica invio</label>
                                <input class="form-control" id="scheduled_at" name="scheduled_at" type="datetime-local" value="<?php echo $campaign['scheduled_at'] ? sanitize_output(date('Y-m-d\TH:i', strtotime((string) $campaign['scheduled_at']))) : ''; ?>">
                                <small class="text-muted">Opzionale, per promemoria.</small>
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Destinatari</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="audience">
                            <div class="col-12">
                                <label class="form-label d-block">Tipologia</label>
                                <?php $audienceType = $campaign['audience_type'] ?? 'all_clients'; ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="audience_type" id="audience_all" value="all_clients" <?php echo $audienceType === 'all_clients' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="audience_all">Tutti i clienti con email</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="audience_type" id="audience_list" value="list" <?php echo $audienceType === 'list' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="audience_list">Liste marketing</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="audience_type" id="audience_manual" value="manual" <?php echo $audienceType === 'manual' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="audience_manual">Destinatari manuali</label>
                                </div>
                            </div>
                            <div class="col-12" id="audience_lists_block" <?php echo $audienceType === 'list' ? '' : 'hidden'; ?>>
                                <label class="form-label" for="list_ids">Liste</label>
                                <select class="form-select" id="list_ids" name="list_ids[]" multiple size="5">
                                    <?php foreach ($lists as $list): ?>
                                        <option value="<?php echo (int) $list['id']; ?>" <?php echo in_array((int) $list['id'], (array) ($filters['list_ids'] ?? []), true) ? 'selected' : ''; ?>><?php echo sanitize_output($list['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">I destinatari disiscritti vengono esclusi automaticamente.</small>
                            </div>
                            <div class="col-12" id="audience_manual_block" <?php echo $audienceType === 'manual' ? '' : 'hidden'; ?>>
                                <label class="form-label" for="manual_emails">Elenco email</label>
                                <textarea class="form-control" id="manual_emails" name="manual_emails" rows="6"><?php echo sanitize_output($manualEmailsText); ?></textarea>
                                <small class="text-muted">Formato supportato: <code>Nome Cognome &lt;email@example.com&gt;</code> o solo email, separati da virgole o righe.</small>
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-arrows-rotate me-2"></i>Aggiorna destinatari</button>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0">
                        <h5 class="card-title mb-0">Contenuto</h5>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="content">
                            <div class="col-12">
                                <label class="form-label" for="template_id">Modello</label>
                                <select class="form-select" id="template_id" name="template_id">
                                    <option value="">Nessun modello</option>
                                    <?php foreach ($templates as $template): ?>
                                        <option value="<?php echo (int) $template['id']; ?>" <?php echo ((string) ($campaign['template_id'] ?? '') === (string) $template['id']) ? 'selected' : ''; ?>><?php echo sanitize_output($template['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="content_html">HTML personalizzato</label>
                                <textarea class="form-control" id="content_html" name="content_html" rows="12" placeholder="<p>Ciao {{first_name}}, ...</p>"><?php echo sanitize_output($campaign['content_html'] ?? ''); ?></textarea>
                                <small class="text-muted">Token disponibili: <code>{{first_name}}</code>, <code>{{last_name}}</code>, <code>{{full_name}}</code>, <code>{{email}}</code>, <code>{{unsubscribe_url}}</code>.</small>
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-warning text-dark" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Salva contenuto</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-6">
                <div class="card ag-card mb-4">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Invio e test</h5>
                        <span class="badge ag-badge">Resend</span>
                    </div>
                    <div class="card-body">
                        <form method="post" class="row g-3 mb-4">
                            <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="test">
                            <div class="col-12">
                                <label class="form-label" for="test_email">Email di test</label>
                                <input class="form-control" id="test_email" name="test_email" type="email" required placeholder="test@example.com">
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="test_first_name">Nome</label>
                                <input class="form-control" id="test_first_name" name="test_first_name" value="Test">
                            </div>
                            <div class="col-6">
                                <label class="form-label" for="test_last_name">Cognome</label>
                                <input class="form-control" id="test_last_name" name="test_last_name" value="User">
                            </div>
                            <div class="col-12 text-end">
                                <button class="btn btn-outline-warning" type="submit"><i class="fa-solid fa-paper-plane me-2"></i>Invia test</button>
                            </div>
                        </form>

                        <div class="d-flex flex-wrap gap-2">
                            <form method="post" class="me-2">
                                <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="send">
                                <input type="hidden" name="mode" value="preview">
                                <button class="btn btn-outline-light" type="submit"><i class="fa-solid fa-user-check me-2"></i>Calcola destinatari</button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="send">
                                <input type="hidden" name="mode" value="send">
                                <button class="btn btn-warning text-dark" type="submit" <?php echo ($campaign['status'] === 'sent') ? 'disabled' : ''; ?> onclick="return confirm('Confermi l\'invio tramite Resend?');"><i class="fa-solid fa-envelope-open-text me-2"></i>Invia campagna</button>
                            </form>
                        </div>
                        <div class="mt-3 small text-muted">
                            <div>Totale stimato: <?php echo number_format($audienceCount); ?> destinatari.</div>
                            <?php if ($metrics): ?>
                                <div>Ultimo invio: <?php echo sanitize_output(format_datetime($campaign['sent_at'] ?? '')); ?> • Consegnate: <?php echo (int) ($metrics['sent'] ?? 0); ?> • Errori: <?php echo (int) ($metrics['failed'] ?? 0); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="card ag-card">
                    <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Destinatari generati</h5>
                        <span class="badge ag-badge">Totalizzati: <?php echo number_format($recipientSummary['total']); ?></span>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Email</th>
                                        <th>Nome</th>
                                        <th>Stato</th>
                                        <th>Inviata</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recipientRows): ?>
                                        <?php foreach ($recipientRows as $recipient): ?>
                                            <tr>
                                                <td><?php echo sanitize_output($recipient['email']); ?></td>
                                                <td><?php echo sanitize_output(trim(($recipient['first_name'] ?? '') . ' ' . ($recipient['last_name'] ?? '')) ?: '—'); ?></td>
                                                <td><?php echo sanitize_output(ucfirst((string) $recipient['status'])); ?></td>
                                                <td><?php echo sanitize_output($recipient['sent_at'] ? format_datetime($recipient['sent_at']) : '—'); ?></td>
                                                <td><?php echo sanitize_output($recipient['last_error'] ?: ''); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="5" class="text-center text-muted py-4">I destinatari saranno generati al primo invio.</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="border-top p-3 small text-muted">
                            <div>In attesa: <?php echo number_format($recipientSummary['pending']); ?> • Inviati: <?php echo number_format($recipientSummary['sent']); ?> • Errori: <?php echo number_format($recipientSummary['failed']); ?> • Esclusi: <?php echo number_format($recipientSummary['skipped']); ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
<script>
    (function() {
        const radios = document.querySelectorAll('input[name="audience_type"]');
        const listBlock = document.getElementById('audience_lists_block');
        const manualBlock = document.getElementById('audience_manual_block');

        function toggleBlocks(value) {
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
        }

        radios.forEach(function(radio) {
            radio.addEventListener('change', function() {
                toggleBlocks(this.value);
            });
        });
    })();
</script>
