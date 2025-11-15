<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Questo script va eseguito da riga di comando.\n");
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Uso: php send_test_otp.php EMAIL_DESTINATARIO [NOME]" . PHP_EOL);
    exit(1);
}

$email = trim($argv[1]);
$name = $argv[2] ?? '';

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Indirizzo email non valido: {$email}" . PHP_EOL);
    exit(1);
}

try {
    $customer = CustomerAuth::registerOrUpdateCustomer([
        'email' => $email,
        'phone' => '',
        'name' => $name,
    ]);

    $result = CustomerAuth::generateAndSendOtp((int) $customer['id'], 'email');

    fwrite(STDOUT, "OTP inviato con successo a {$result['destination']}" . PHP_EOL);
    fwrite(STDOUT, "Validità (secondi): {$result['expires_in']}" . PHP_EOL);
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Invio OTP fallito: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
