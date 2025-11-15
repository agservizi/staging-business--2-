<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/payments.php';
require_once __DIR__ . '/../../includes/payment_finalizer.php';

require_method('GET');
$customer = require_authentication();

$referenceParam = isset($_GET['ref']) ? (string) $_GET['ref'] : '';
$reference = strtoupper(trim($referenceParam));
$reference = preg_replace('/[^A-Z0-9]/', '', $reference);

if ($reference === '') {
    api_error('Riferimento pagamento mancante.', 400);
}

$paymentManager = new PickupPortalPaymentManager();
$payment = $paymentManager->findByReference($reference);

if ($payment === null) {
    api_error('Pagamento non trovato.', 404);
}

if ((int) $payment['customer_id'] !== (int) ($customer['id'] ?? 0)) {
    api_error('Accesso non autorizzato a questo pagamento.', 403);
}

$status = (string) $payment['status'];
$message = null;
$shipment = null;

if (in_array($status, ['pending', 'processing'], true)) {
    try {
        $result = portal_finalize_payment($payment, (int) $customer['id']);
        $payment = $result['payment'];
        $status = $result['status'];
        $message = $result['message'];
        $shipment = $result['shipment'];
    } catch (Throwable $exception) {
        $message = $exception->getMessage();
        $payment = $paymentManager->findByReference($reference) ?? $payment;
        $status = (string) ($payment['status'] ?? 'failed');
    }
}

$response = [
    'status' => $status,
    'message' => $message,
    'payment' => [
        'reference' => $payment['public_reference'],
        'status' => $status,
        'amount_cents' => (int) $payment['amount_cents'],
        'currency' => $payment['currency'],
        'tier_label' => $payment['tier_label'],
        'paid_at' => $payment['paid_at'],
        'created_at' => $payment['created_at'],
        'updated_at' => $payment['updated_at'],
    ],
];

if ($shipment !== null && is_array($shipment)) {
    $response['shipment'] = [
        'id' => $shipment['id'] ?? null,
        'core_id' => $shipment['core_id'] ?? null,
        'reference' => $shipment['reference'] ?? null,
        'tracking_id' => $shipment['tracking_id'] ?? null,
    ];
}

api_success($response);
