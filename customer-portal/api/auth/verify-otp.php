<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

require_method('POST');

// Ottieni i dati
$data = get_json_input();
if (empty($data)) {
    $data = $_POST;
}

// Validazione CSRF
validate_csrf_token($data);

// Validazione input
$customerId = (int) ($data['customer_id'] ?? 0);
$otp = trim($data['otp'] ?? '');
$rememberRaw = $data['remember_login'] ?? false;
$remember = filter_var($rememberRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
if ($remember === null) {
    $remember = (bool) $rememberRaw;
}

if ($customerId <= 0) {
    api_error('ID cliente non valido');
}

if (empty($otp) || strlen($otp) !== portal_config('otp_length')) {
    api_error('Codice OTP non valido');
}

if (!preg_match('/^\d+$/', $otp)) {
    api_error('Il codice deve contenere solo numeri');
}

try {
    // Verifica OTP e effettua login
    $sessionData = CustomerAuth::verifyOtpAndLogin($customerId, $otp, $remember);
    
    // Log attività
    portal_info_log('Customer login successful', [
        'customer_id' => $customerId,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
    
    api_success($sessionData, 'Accesso effettuato con successo');
    
} catch (Exception $e) {
    portal_error_log('OTP verification error: ' . $e->getMessage(), [
        'customer_id' => $customerId,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
    
    api_error($e->getMessage(), 400);
}