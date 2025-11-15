<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/pickup_service.php';

$customer = require_authentication();
$pickupService = new PickupService();

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            $status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
            $limit = max(1, min((int) ($_GET['limit'] ?? portal_config('default_page_size')), portal_config('max_page_size')));
            $offset = max(0, (int) ($_GET['offset'] ?? 0));

            $options = [
                'limit' => $limit,
                'offset' => $offset,
            ];

            if ($status !== '') {
                $options['status'] = $status;
            }

            $reports = $pickupService->getCustomerReports((int) $customer['id'], $options);

            api_success([
                'reports' => $reports,
                'total' => count($reports),
                'has_more' => count($reports) === $limit,
            ]);
            break;

        case 'POST':
            $payload = get_json_input();
            if (empty($payload)) {
                $payload = $_POST;
            }

            validate_csrf_token($payload);

            $reportData = [
                'tracking_code' => trim((string) ($payload['tracking_code'] ?? '')),
                'courier_name' => trim((string) ($payload['courier_name'] ?? '')),
                'recipient_name' => trim((string) ($payload['recipient_name'] ?? '')),
                'expected_delivery_date' => trim((string) ($payload['expected_delivery_date'] ?? '')) ?: null,
                'delivery_location' => trim((string) ($payload['delivery_location'] ?? '')),
                'notes' => trim((string) ($payload['notes'] ?? '')),
            ];

            $report = $pickupService->reportPackage((int) $customer['id'], $reportData);

            api_success(['report' => $report], 'Segnalazione creata con successo');
            break;

        default:
            api_error('Metodo non consentito', 405);
    }
} catch (Exception $exception) {
    portal_error_log('Reports API error: ' . $exception->getMessage(), [
        'customer_id' => $customer['id'],
        'method' => $_SERVER['REQUEST_METHOD'],
    ]);

    api_error($exception->getMessage());
}
