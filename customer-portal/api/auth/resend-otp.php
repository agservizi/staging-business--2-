<?php
// cspell:ignore valido
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

if ($customerId <= 0) {
    api_error('ID cliente non valido');
}

try {
    // Ottieni informazioni cliente
    $customer = portal_fetch_one('SELECT * FROM pickup_customers WHERE id = ?', [$customerId]);
    
    if (!$customer) {
        api_error('Cliente non trovato');
    }
    
    // Determina metodo di invio
    if (empty($customer['email'])) {
        api_error('Nessuna email disponibile per questo account. Contatta il supporto.');
    }

    $method = 'email';
    
    // Genera e invia nuovo OTP
    $otpResult = CustomerAuth::generateAndSendOtp($customerId, $method);
    
    // Log attività
    portal_info_log('OTP resent for customer', [
        'customer_id' => $customerId,
        'method' => $method,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
    ]);
    
    api_success([
        'method' => $otpResult['method'],
        'destination' => $otpResult['destination'],
        'expires_in' => $otpResult['expires_in']
    ], 'Nuovo codice OTP inviato');
    
} catch (Exception $e) {
    portal_error_log('Resend OTP error: ' . $e->getMessage(), [
        'customer_id' => $customerId
    ]);
    
    api_error($e->getMessage());
}