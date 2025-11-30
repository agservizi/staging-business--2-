<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/mailer.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    add_flash('warning', 'Metodo non consentito.');
    header('Location: index.php');
    exit;
}

require_valid_csrf();

$praticaId = (int) ($_POST['pratica_id'] ?? 0);
$channel = strtolower(trim((string) ($_POST['channel'] ?? 'email')));
$recipient = trim((string) ($_POST['recipient'] ?? ''));
$messageBody = trim((string) ($_POST['message'] ?? ''));

if ($praticaId <= 0) {
    add_flash('warning', 'Pratica non valida.');
    header('Location: index.php');
    exit;
}

$allowedChannels = ['email', 'pec'];
if (!in_array($channel, $allowedChannels, true)) {
    $channel = 'email';
}

if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    add_flash('warning', 'Indirizzo email non valido.');
    header('Location: view_request.php?id=' . $praticaId);
    exit;
}

$pratica = anpr_fetch_pratica($pdo, $praticaId);
if (!$pratica) {
    add_flash('warning', 'Pratica non trovata.');
    header('Location: index.php');
    exit;
}

if (empty($pratica['certificato_path'])) {
    add_flash('warning', 'Carica prima il certificato per questa pratica.');
    header('Location: view_request.php?id=' . $praticaId);
    exit;
}

$downloadUrl = base_url($pratica['certificato_path']);
$clienteNome = trim(($pratica['ragione_sociale'] ?? '') !== ''
    ? (string) $pratica['ragione_sociale']
    : trim((string) ($pratica['cognome'] ?? '') . ' ' . (string) ($pratica['nome'] ?? '')));

$subject = 'Certificato ANPR ' . ($pratica['pratica_code'] ?? '');

$customMessage = $messageBody !== ''
    ? nl2br(sanitize_output($messageBody))
    : 'In allegato il certificato richiesto. Puoi scaricarlo dal link sottostante.';

$content = '<p>Gentile ' . sanitize_output($clienteNome !== '' ? $clienteNome : 'cliente') . ',</p>' .
    '<p>' . $customMessage . '</p>' .
    '<p><a href="' . sanitize_output($downloadUrl) . '" target="_blank" rel="noopener">Scarica il certificato ANPR</a></p>' .
    '<p><strong>Codice pratica:</strong> ' . sanitize_output($pratica['pratica_code'] ?? '') . '</p>' .
    '<p>Grazie per aver scelto i servizi della nostra agenzia.</p>';

$htmlBody = render_mail_template($subject, $content);

if (!send_system_mail($recipient, $subject, $htmlBody)) {
    add_flash('warning', 'Invio non riuscito. Controlla le impostazioni email e riprova.');
    header('Location: view_request.php?id=' . $praticaId);
    exit;
}

try {
    anpr_record_certificate_delivery($pdo, $praticaId, $channel, $recipient);
    anpr_log_action($pdo, 'Certificato inviato', 'Certificato inviato (' . strtoupper($channel) . ') a ' . $recipient . ' per ' . ($pratica['pratica_code'] ?? 'pratica ' . $praticaId));
    add_flash('success', 'Certificato inviato con successo al cliente.');
} catch (Throwable $exception) {
    error_log('ANPR send certificate log failed: ' . $exception->getMessage());
}

header('Location: view_request.php?id=' . $praticaId);
exit;
