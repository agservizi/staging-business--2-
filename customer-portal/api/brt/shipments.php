<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/brt_service.php';
require_once __DIR__ . '/../../includes/payments.php';
require_once __DIR__ . '/../../includes/stripe.php';

$customer = require_authentication();
$service = new PickupBrtService();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $limit = (int) ($_GET['limit'] ?? 25);
        $offset = (int) ($_GET['offset'] ?? 0);
        $status = $_GET['status'] ?? '';
        $search = $_GET['search'] ?? '';

        $result = $service->listCustomerShipments((int) $customer['id'], [
            'limit' => $limit,
            'offset' => $offset,
            'status' => $status,
            'search' => $search,
        ]);

        api_success($result);
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = get_json_input();
        if ($data === []) {
            $data = $_POST;
        }

        validate_csrf_token($data);

        $customerId = (int) ($customer['id'] ?? 0);
        if ($customerId <= 0) {
            throw new RuntimeException('Cliente non valido per la sessione corrente.');
        }

        $parseNumber = static function ($value): float {
            if ($value === null) {
                return 0.0;
            }
            if (is_float($value) || is_int($value)) {
                return (float) $value;
            }
            if (!is_string($value)) {
                return 0.0;
            }
            $normalized = str_replace([' ', ','], ['', '.'], trim($value));
            if ($normalized === '' || !is_numeric($normalized)) {
                return 0.0;
            }
            return (float) $normalized;
        };

        $length = $parseNumber($data['length_cm'] ?? null);
        $depth = $parseNumber($data['depth_cm'] ?? null);
        $height = $parseNumber($data['height_cm'] ?? null);
        $parcels = (int) round($parseNumber($data['parcels'] ?? 1));
        $parcels = max(1, $parcels);

        if ($length <= 0 || $depth <= 0 || $height <= 0) {
            throw new RuntimeException('Compila dimensioni valide per calcolare il volume.');
        }

        $perParcelVolume = ($length * $depth * $height) / 1_000_000; // cm³ -> m³
        $volume = $perParcelVolume * $parcels;
        if ($volume <= 0) {
            throw new RuntimeException('Il volume risulta nullo, verifica le dimensioni inserite.');
        }

        $weight = $parseNumber($data['weight'] ?? null);
        if ($weight <= 0) {
            throw new RuntimeException('Indica un peso valido in chilogrammi.');
        }

        $matchedTier = $service->matchPortalPricingTier($weight, $volume);
        if ($matchedTier === null) {
            throw new RuntimeException('I valori inseriti non rientrano in nessuno scaglione tariffario.');
        }

        $pricing = $service->getPortalPricing();

        $payloadSnapshot = $data;
        $payloadSnapshot['parcels'] = (string) $parcels;
        $payloadSnapshot['volume'] = number_format($volume, 3, '.', '');
        $payloadSnapshot['volumetric_weight'] = number_format((($length * $depth * $height) / 4000) * $parcels, 2, '.', '');
        $payloadSnapshot['weight'] = number_format($weight, 3, '.', '');

        $payloadSnapshot['pricing_snapshot'] = [
            'currency' => $pricing['currency'],
            'tier' => $matchedTier,
        ];

        $paymentManager = new PickupPortalPaymentManager();
        $payment = $paymentManager->createPendingPayment($customerId, $matchedTier, $payloadSnapshot, $pricing['currency']);

        $stripe = portal_stripe_client();
        $currency = strtolower($payment['currency']);
        $session = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'client_reference_id' => $payment['reference'],
            'success_url' => portal_stripe_success_url($payment['reference']),
            'cancel_url' => portal_stripe_cancel_url(),
            'customer_email' => isset($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL) ? (string) $data['email'] : null,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $payment['amount_cents'],
                    'product_data' => [
                        'name' => 'Spedizione BRT - ' . $matchedTier['label'],
                        'description' => 'Pagamento spedizione tramite Pickup Portal',
                    ],
                ],
            ]],
            'metadata' => [
                'portal_payment_reference' => $payment['reference'],
                'portal_customer_id' => (string) $customerId,
            ],
        ]);

        $paymentManager->updateStripeSession($payment['id'], $session->id, isset($session->payment_intent) ? (string) $session->payment_intent : null);

        api_success([
            'payment' => [
                'checkout_url' => $session->url,
                'reference' => $payment['reference'],
                'amount_cents' => $payment['amount_cents'],
                'currency' => $payment['currency'],
                'tier_label' => $matchedTier['label'],
            ],
        ], 'Procedi con il pagamento per completare la spedizione');
    } else {
        api_error('Metodo non consentito', 405);
    }
} catch (Exception $exception) {
    portal_error_log('BRT shipments API error: ' . $exception->getMessage(), [
        'customer_id' => $customer['id'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'],
    ]);
    api_error($exception->getMessage());
}
