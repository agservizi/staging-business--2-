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

$action = $_POST['action'] ?? '';
$praticaId = (int) ($_POST['pratica_id'] ?? 0);
$recipient = trim((string) ($_POST['recipient'] ?? ''));
$otpInput = trim((string) ($_POST['otp'] ?? ''));

if ($praticaId <= 0) {
    add_flash('warning', 'Pratica non valida.');
    header('Location: index.php');
    exit;
}

$pratica = anpr_fetch_pratica($pdo, $praticaId);
if (!$pratica) {
    add_flash('warning', 'Pratica non trovata.');
    header('Location: index.php');
    exit;
}

try {
    switch ($action) {
        case 'send':
            if (empty($pratica['delega_path']) && anpr_can_generate_delega($pratica)) {
                anpr_auto_generate_delega($pdo, $praticaId, $pratica);
                $pratica = anpr_fetch_pratica($pdo, $praticaId);
            }

            if (empty($pratica['delega_path'])) {
                throw new RuntimeException('Carica o genera una delega prima di inviare la firma digitale.');
            }

            if ($recipient === '' || !filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Indica un indirizzo email valido per il cliente.');
            }

            $otpData = anpr_signature_generate_otp();
            anpr_signature_store_send($pdo, $praticaId, $otpData['hash'], $otpData['salt'], 'email', $recipient);

            $subject = 'OTP firma digitale delega ' . ($pratica['pratica_code'] ?? '');
            $content = '<p>Gentile cliente,</p>' .
                '<p>per confermare la firma digitale della delega ANPR utilizza il seguente codice OTP:</p>' .
                '<p style="font-size:22px;font-weight:bold;letter-spacing:4px;">' . sanitize_output($otpData['otp']) . '</p>' .
                '<p>Il codice scade tra 15 minuti. Comunicalo al nostro operatore per completare la procedura.</p>' .
                '<p>Grazie per la collaborazione.</p>';

            $html = render_mail_template($subject, $content);
            if (!send_system_mail($recipient, $subject, $html)) {
                anpr_signature_clear($pdo, $praticaId);
                throw new RuntimeException('Invio OTP non riuscito. Controlla le impostazioni email.');
            }

            anpr_log_action($pdo, 'OTP firma delega inviato', 'OTP inviato a ' . $recipient . ' per ' . ($pratica['pratica_code'] ?? 'pratica ' . $praticaId));
            add_flash('success', 'OTP inviato al cliente. Ricorda che scade dopo 15 minuti.');
            break;

        case 'verify':
            if ($pratica['delega_firma_status'] !== 'otp_inviato') {
                throw new RuntimeException('Non risulta un OTP attivo per questa delega.');
            }

            if ($otpInput === '') {
                throw new RuntimeException('Inserisci il codice OTP ricevuto dal cliente.');
            }

            if (anpr_signature_is_expired($pratica)) {
                anpr_signature_increment_attempt($pdo, $praticaId, true);
                throw new RuntimeException('OTP scaduto. Invia un nuovo codice.');
            }

            $salt = (string) ($pratica['delega_firma_otp_salt'] ?? '');
            $hash = hash('sha256', $salt . $otpInput);
            if (hash_equals((string) ($pratica['delega_firma_hash'] ?? ''), $hash)) {
                anpr_signature_mark_verified($pdo, $praticaId);
                anpr_log_action($pdo, 'Delega firmata', 'Firma digitale completata per ' . ($pratica['pratica_code'] ?? 'pratica ' . $praticaId));
                add_flash('success', 'Firma digitale registrata correttamente.');
            } else {
                anpr_signature_increment_attempt($pdo, $praticaId);
                $attempts = (int) $pratica['delega_firma_attempts'] + 1;
                if ($attempts >= ANPR_SIGNATURE_MAX_ATTEMPTS) {
                    anpr_signature_increment_attempt($pdo, $praticaId, true);
                    throw new RuntimeException('OTP non valido. Numero massimo di tentativi raggiunto.');
                }
                throw new RuntimeException('OTP non valido. Riprova.');
            }
            break;

        case 'reset':
            anpr_signature_clear($pdo, $praticaId);
            anpr_log_action($pdo, 'Firma delega azzerata', 'Firma digitale resettata per ' . ($pratica['pratica_code'] ?? 'pratica ' . $praticaId));
            add_flash('success', 'Stato firma delega azzerato.');
            break;

        default:
            throw new RuntimeException('Azione non riconosciuta.');
    }
} catch (RuntimeException $exception) {
    add_flash('warning', $exception->getMessage());
} catch (Throwable $exception) {
    error_log('ANPR delega signature error: ' . $exception->getMessage());
    add_flash('warning', 'Errore durante la gestione della firma digitale.');
}

header('Location: view_request.php?id=' . $praticaId);
exit;
