<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/brt_service.php';

$customer = require_authentication();
$service = new PickupBrtService();

try {
    require_method('POST');

    $data = get_json_input();
    if ($data === []) {
        $data = $_POST;
    }

    validate_csrf_token($data);

    $result = $service->getRoutingSuggestion($data);
    $message = '';
    if (isset($result['message']) && is_string($result['message'])) {
        $message = $result['message'];
        unset($result['message']);
    }
    if ($message === '') {
        $message = 'Suggerimenti BRT aggiornati.';
    }

    api_success($result, $message);
} catch (RuntimeException $exception) {
    portal_error_log('BRT routing API input error: ' . $exception->getMessage(), [
        'customer_id' => $customer['id'] ?? null,
    ]);
    api_error($exception->getMessage(), 400);
} catch (Exception $exception) {
    portal_error_log('BRT routing API error: ' . $exception->getMessage(), [
        'customer_id' => $customer['id'] ?? null,
    ]);
    api_error('Impossibile ottenere i suggerimenti BRT al momento.', 500);
}
