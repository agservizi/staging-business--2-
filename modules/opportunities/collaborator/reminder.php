<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../../includes/mailer.php';

require_role('Collaboratore');

require_once __DIR__ . '/auto-refresh.php';

$collaboratorId = (int) ($_SESSION['user_id'] ?? 0);
$opportunityId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;

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

$teamInbox = trim((string) env('OPPORTUNITIES_TEAM_EMAIL', env('MAIL_FROM_ADDRESS', 'support@coresuite.it')));
$recipients = [];
$managerEmail = trim((string) ($opportunity['manager_email'] ?? ''));
if ($managerEmail !== '') {
    $recipients[] = $managerEmail;
}
if ($teamInbox !== '') {
    $recipients[] = $teamInbox;
}
$recipients = array_values(array_unique(array_filter($recipients)));

$errors = [];
if (!$recipients) {
    $errors[] = 'Nessun destinatario configurato per il sollecito.';
}

$customerName = trim(sprintf('%s %s', (string) ($opportunity['customer_first_name'] ?? ''), (string) ($opportunity['customer_last_name'] ?? '')));
$statusLabel = $opportunity['status_label'] ?? $opportunity['status_code'] ?? '';
$lastUpdate = format_datetime_locale($opportunity['last_status_change'] ?? $opportunity['updated_at'] ?? $opportunity['created_at'] ?? null) ?? 'data non disponibile';
$defaultMessage = sprintf(
    "Ciao team,\nmi serve un aggiornamento sulla opportunity #%s (%s) attualmente in stato %s.\nUltimo aggiornamento registrato: %s.\n\nGrazie!",
    (string) ($opportunity['code'] ?? $opportunityId),
    $customerName !== '' ? $customerName : 'cliente non indicato',
    $statusLabel !== '' ? $statusLabel : '—',
    $lastUpdate
);

$submittedMessage = isset($_POST['message']) ? trim((string) $_POST['message']) : $defaultMessage;
$csrfToken = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors) {
    $messageBody = trim((string) ($_POST['message'] ?? ''));
    if ($messageBody === '') {
        $errors[] = 'Inserisci un messaggio per il team.';
    }

    if (!$errors) {
        $subject = sprintf('Sollecito opportunity %s', (string) ($opportunity['code'] ?? ('#' . $opportunityId)));
        $content = '<p style="margin-top:0;">' . nl2br(sanitize_output($messageBody), false) . '</p>' .
            '<hr style="margin:24px 0;border:0;border-top:1px solid #e5e7eb;">' .
            '<p style="margin:0 0 8px;font-size:13px;color:#475467;">Riepilogo opportunity</p>' .
            '<ul style="padding-left:18px;margin:0;font-size:14px;color:#0f172a;">' .
                '<li>Codice: ' . sanitize_output((string) ($opportunity['code'] ?? '—')) . '</li>' .
                '<li>Cliente: ' . sanitize_output($customerName !== '' ? $customerName : '—') . '</li>' .
                '<li>Stato: ' . sanitize_output($statusLabel !== '' ? $statusLabel : '—') . '</li>' .
                '<li>Ultimo aggiornamento: ' . sanitize_output($lastUpdate) . '</li>' .
            '</ul>';

        $htmlBody = render_mail_template($subject, $content);
        $sentCount = 0;
        foreach ($recipients as $recipient) {
            $sent = send_system_mail($recipient, $subject, $htmlBody, [
                'metadata' => array_filter([
                    'opportunity_id' => (string) $opportunityId,
                    'opportunity_code' => $opportunity['code'] ?? null,
                    'reminder_sender' => current_user_display_name(),
                ]),
            ]);
            if ($sent) {
                $sentCount++;
            }
        }

        if ($sentCount === 0) {
            $errors[] = 'Impossibile inviare il sollecito. Riprova più tardi.';
        } else {
            add_flash('success', 'Sollecito inviato al team operativo.');
            header('Location: ' . opportunities_collaborator_url('view', ['id' => $opportunityId]));
            exit;
        }
    }
}

$viewUrl = opportunities_collaborator_url('view', ['id' => $opportunityId]);
$listUrl = opportunities_collaborator_url('list');

require_once __DIR__ . '/../../../includes/header.php';
require_once __DIR__ . '/../../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="d-flex justify-content-between align-items-start mb-4 flex-wrap gap-3">
            <div>
                <p class="text-uppercase small fw-semibold text-muted mb-1">Opportunity #<?php echo (int) $opportunity['id']; ?></p>
                <h1 class="h4 mb-1">Invia sollecito</h1>
                <p class="text-muted mb-0">Richiedi un aggiornamento operativo al team interno.</p>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-outline-secondary" href="<?php echo sanitize_output($viewUrl); ?>">
                    <i class="fa-solid fa-eye me-2"></i>Dettaglio opportunity
                </a>
                <a class="btn btn-outline-secondary" href="<?php echo sanitize_output($listUrl); ?>">
                    <i class="fa-solid fa-table-list me-2"></i>Vista tabellare
                </a>
            </div>
        </div>

        <?php foreach ($errors as $message): ?>
            <div class="alert alert-warning" role="alert"><?php echo sanitize_output($message); ?></div>
        <?php endforeach; ?>

        <div class="row g-4">
            <div class="col-lg-5">
                <div class="card shadow-sm h-100">
                    <div class="card-body">
                        <h2 class="h6 text-uppercase text-muted mb-3">Riepilogo opportunity</h2>
                        <dl class="row mb-0">
                            <dt class="col-5 text-muted small text-uppercase">Codice</dt>
                            <dd class="col-7 fw-semibold"><?php echo sanitize_output($opportunity['code'] ?? '—'); ?></dd>
                            <dt class="col-5 text-muted small text-uppercase">Cliente</dt>
                            <dd class="col-7"><?php echo sanitize_output($customerName !== '' ? $customerName : '—'); ?></dd>
                            <dt class="col-5 text-muted small text-uppercase">Stato</dt>
                            <dd class="col-7"><?php echo sanitize_output($statusLabel !== '' ? $statusLabel : '—'); ?></dd>
                            <dt class="col-5 text-muted small text-uppercase">Ultimo update</dt>
                            <dd class="col-7"><?php echo sanitize_output($lastUpdate); ?></dd>
                        </dl>
                        <hr>
                        <p class="small mb-1 text-muted text-uppercase">Destinatari</p>
                        <ul class="mb-0 ps-3 small">
                            <?php foreach ($recipients as $recipient): ?>
                                <li><?php echo sanitize_output($recipient); ?></li>
                            <?php endforeach; ?>
                            <?php if (!$recipients): ?>
                                <li class="text-danger">Nessuna email configurata.</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <div class="col-lg-7">
                <div class="card shadow-sm h-100">
                    <div class="card-body d-flex flex-column">
                        <h2 class="h6 text-uppercase text-muted mb-3">Messaggio al team</h2>
                        <form method="post" class="d-flex flex-column gap-3 flex-grow-1">
                            <input type="hidden" name="csrf_token" value="<?php echo sanitize_output($csrfToken); ?>">
                            <input type="hidden" name="id" value="<?php echo (int) $opportunityId; ?>">
                            <div class="flex-grow-1 d-flex flex-column">
                                <label class="form-label text-uppercase small text-muted">Richiesta</label>
                                <textarea class="form-control flex-grow-1" name="message" rows="12" placeholder="Spiega cosa serve al team" maxlength="1500"><?php echo sanitize_output($submittedMessage); ?></textarea>
                                <p class="text-muted small mb-0 mt-2">Indica dettagli utili per accelerare la lavorazione. Max 1500 caratteri.</p>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <button class="btn btn-warning text-white" type="submit" <?php echo $recipients ? '' : 'disabled'; ?>>
                                    <i class="fa-solid fa-bell me-2"></i>Invia sollecito
                                </button>
                                <a class="btn btn-outline-secondary" href="<?php echo sanitize_output($viewUrl); ?>">Annulla</a>
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
