<?php
declare(strict_types=1);

session_start();

use App\Services\EmailMarketing\EventRecorder;
use PDO;
use Throwable;

require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/helpers.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_valid_csrf();
}

$token = trim((string) ($_POST['token'] ?? ($_GET['token'] ?? '')));
$recipient = $token !== '' ? load_recipient_by_token($pdo, $token) : null;
$initialRecipient = $recipient;

$csrfToken = csrf_token();
$completed = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($recipient === null) {
        $error = 'Il link utilizzato non è valido o è già stato usato.';
    } else {
        $recorder = new EventRecorder($pdo);
        try {
            $recorder->apply(
                (int) $recipient['campaign_id'],
                (int) $recipient['id'],
                'unsubscribe',
                [
                    'provider_type' => 'self-service',
                    'reason' => 'Disiscrizione richiesta dall\'utente tramite pagina pubblica',
                    'recipient' => $recipient['email'],
                ]
            );
            $completed = true;
            $recipient = load_recipient_by_token($pdo, $token);
        } catch (Throwable $exception) {
            error_log('Unsubscribe page failure: ' . $exception->getMessage());
            $error = 'Non siamo riusciti a completare la disiscrizione. Riprova più tardi.';
        }
    }
}

$campaignName = $recipient['campaign_name'] ?? ($initialRecipient['campaign_name'] ?? null);
$displayEmail = $recipient['email'] ?? ($initialRecipient['email'] ?? ($token !== '' ? 'indirizzo sconosciuto' : ''));
?><!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Disiscrizione email | Coresuite Business</title>
    <link href="<?php echo asset('assets/vendor/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('assets/css/custom.css'); ?>" rel="stylesheet">
    <style>
        body.unsubscribe-body {
            background: linear-gradient(135deg, #0b2f6b, #12468f);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: #1c2534;
        }
        .unsubscribe-card {
            background: #ffffff;
            border-radius: 16px;
            box-shadow: 0 18px 45px rgba(12, 35, 76, 0.25);
            max-width: 480px;
            width: 100%;
            padding: 32px;
        }
        .unsubscribe-card .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
        }
        .unsubscribe-card .brand span {
            font-size: 1.25rem;
            font-weight: 600;
            letter-spacing: 0.04em;
            color: #0b2f6b;
        }
    </style>
</head>
<body class="unsubscribe-body">
    <main class="unsubscribe-card">
        <div class="brand">
            <div class="rounded-circle bg-warning" style="width: 40px; height: 40px; display:flex; align-items:center; justify-content:center; font-weight: 600; color: #0b2f6b;">
                &#9993;
            </div>
            <span>Coresuite Business</span>
        </div>
        <h1 class="h4 mb-3">Gestisci l'iscrizione</h1>
        <p class="text-muted">Utilizza questa pagina per interrompere la ricezione delle nostre comunicazioni.</p>

        <?php if ($completed): ?>
            <div class="alert alert-success">
                <p class="mb-1">Abbiamo registrato la tua disiscrizione per l'indirizzo <strong><?php echo sanitize_output($displayEmail); ?></strong>.</p>
                <p class="mb-0">Potrebbero volerci alcune ore perché l'aggiornamento sia applicato a tutti gli invii.</p>
            </div>
            <p class="mb-0"><a class="link-warning" href="https://www.agenziaplinio.it" target="_blank" rel="noopener">Torna al sito agenziaplinio.it</a></p>
        <?php elseif ($error !== ''): ?>
            <div class="alert alert-danger mb-4"><?php echo sanitize_output($error); ?></div>
            <p class="mb-0 text-muted">Se il problema persiste scrivi a <a class="link-warning" href="mailto:privacy@agenziaplinio.it">privacy@agenziaplinio.it</a>.</p>
        <?php elseif ($recipient === null): ?>
            <div class="alert alert-warning">
                <p class="mb-1">Non siamo riusciti a trovare una sottoscrizione attiva per questo link.</p>
                <p class="mb-0">Potresti aver già completato la disiscrizione oppure il collegamento è scaduto.</p>
            </div>
            <p class="mb-0 text-muted">Per assistenza contatta <a class="link-warning" href="mailto:privacy@agenziaplinio.it">privacy@agenziaplinio.it</a>.</p>
        <?php else: ?>
            <div class="card bg-light border-0 mb-4">
                <div class="card-body">
                    <p class="mb-1">Indirizzo associato:</p>
                    <p class="fw-semibold mb-2"><?php echo sanitize_output($displayEmail); ?></p>
                    <?php if ($campaignName): ?>
                        <p class="text-muted mb-0" style="font-size: 0.95rem;">Campagna: <?php echo sanitize_output($campaignName); ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <form method="post" class="d-grid gap-3">
                <input type="hidden" name="_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="token" value="<?php echo sanitize_output($token); ?>">
                <div>
                    <p class="mb-1">Confermi di voler annullare l'iscrizione alle comunicazioni?</p>
                    <p class="text-muted mb-0" style="font-size: 0.95rem;">Potrai sempre riattivarla contattandoci.</p>
                </div>
                <button type="submit" class="btn btn-danger btn-lg">Disiscrivimi</button>
                <a class="btn btn-outline-secondary" href="https://www.agenziaplinio.it" target="_blank" rel="noopener">Visita il sito agenziaplinio.it</a>
            </form>
        <?php endif; ?>
    </main>
    <script src="<?php echo asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
</body>
</html>
<?php

/**
 * @return array<string, mixed>|null
 */
function load_recipient_by_token(PDO $pdo, string $token): ?array
{
    $stmt = $pdo->prepare('SELECT r.id, r.campaign_id, r.email, r.first_name, r.last_name, r.unsubscribe_token, c.name AS campaign_name
        FROM email_campaign_recipients r
        INNER JOIN email_campaigns c ON c.id = r.campaign_id
        WHERE r.unsubscribe_token = :token
        LIMIT 1');
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
