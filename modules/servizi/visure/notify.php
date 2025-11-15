<?php

use App\Services\ServiziWeb\VisureService;
use Throwable;

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/mailer.php';

require_role('Admin', 'Operatore', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_valid_csrf();

$visuraId = isset($_POST['visura_id']) ? trim((string) $_POST['visura_id']) : '';
$recipient = isset($_POST['recipient']) ? trim((string) $_POST['recipient']) : '';
$message = isset($_POST['message']) ? trim((string) $_POST['message']) : '';
$attachRequested = isset($_POST['attach_document']) && (string) $_POST['attach_document'] === '1';

if ($visuraId === '') {
    add_flash('warning', 'Seleziona una visura da notificare.');
    header('Location: index.php');
    exit;
}

if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    add_flash('warning', 'Inserisci un indirizzo email valido.');
    header('Location: view.php?id=' . urlencode($visuraId));
    exit;
}

if ($message === '') {
    add_flash('warning', 'Aggiungi un messaggio per il destinatario.');
    header('Location: view.php?id=' . urlencode($visuraId));
    exit;
}

try {
    $service = new VisureService($pdo, dirname(__DIR__, 3));
    $visura = $service->getVisura($visuraId);
} catch (Throwable $exception) {
    add_flash('danger', 'Impossibile recuperare la visura: ' . $exception->getMessage());
    header('Location: index.php');
    exit;
}

$documentUrl = null;
if ($attachRequested && !empty($visura['documento_path'])) {
    $documentUrl = base_url($visura['documento_path']);
}

$subject = 'Visura catastale ' . $visuraId;

$escapedMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
$content = '<p>' . $escapedMessage . '</p>';

if ($documentUrl) {
    $content .= '<p>Puoi scaricare la visura al seguente link: <a href="' . htmlspecialchars($documentUrl, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($documentUrl, ENT_QUOTES, 'UTF-8') . '</a></p>';
} elseif (!empty($visura['documento_nome'])) {
    $content .= '<p>Il documento "' . htmlspecialchars((string) $visura['documento_nome'], ENT_QUOTES, 'UTF-8') . '" è disponibile nell\'area riservata.</p>';
}

$htmlBody = render_mail_template($subject, $content);

if (send_system_mail($recipient, $subject, $htmlBody)) {
    $service->registerNotification($visuraId, $recipient, (int) ($_SESSION['user_id'] ?? 0));
    add_flash('success', 'Notifica inviata correttamente a ' . $recipient . '.');
    if ($documentUrl) {
        add_flash('info', 'È stato incluso il link al documento archiviato.');
    }
} else {
    add_flash('danger', 'Invio email non riuscito. Verifica la configurazione del mailer.');
}

header('Location: view.php?id=' . urlencode($visuraId));
exit;
