<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../includes/db_connect.php';
require_once __DIR__ . '/../../../includes/helpers.php';
require_once __DIR__ . '/../../../includes/mailer.php';
require_once __DIR__ . '/functions.php';

require_role('Admin', 'Operatore', 'Manager');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_valid_csrf();

$bookingId = (int) ($_POST['id'] ?? 0);
$type = isset($_POST['type']) ? trim((string) $_POST['type']) : 'summary';

if ($bookingId <= 0) {
    add_flash('warning', 'Prenotazione CIE non valida.');
    header('Location: index.php');
    exit;
}

$booking = cie_fetch_booking($pdo, $bookingId);
if ($booking === null) {
    add_flash('warning', 'Prenotazione CIE non trovata.');
    header('Location: index.php');
    exit;
}

$email = trim((string) ($booking['cittadino_email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    add_flash('warning', 'Aggiungi un indirizzo email valido per il cittadino prima di inviare il riepilogo.');
    header('Location: view.php?id=' . $bookingId);
    exit;
}

$normalizedType = strtolower($type) === 'reminder' ? 'reminder' : 'summary';
$sent = cie_send_email_notification($pdo, $booking, $normalizedType);

$label = $normalizedType === 'reminder' ? 'promemoria' : 'riepilogo';
$safeEmail = filter_var($email, FILTER_SANITIZE_EMAIL);

if ($sent) {
    add_flash('success', 'Email ' . $label . ' inviata a ' . $safeEmail . '.');
    cie_log_action($pdo, 'Invio email prenotazione', ucfirst($label) . ' inviato per prenotazione #' . $bookingId . ' a ' . $safeEmail);
} else {
    add_flash('warning', 'Invio email non riuscito. Verifica la configurazione del servizio Resend.');
    cie_log_action($pdo, 'Invio email prenotazione', 'Invio ' . $label . ' non riuscito per prenotazione #' . $bookingId);
}

header('Location: view.php?id=' . $bookingId);
exit;
