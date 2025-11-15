<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/pickup_service.php';

// Autenticazione richiesta
$customer = require_authentication();
$pickupService = new PickupService();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Ottieni pacchi del cliente
        $status = $_GET['status'] ?? '';
        $limit = min((int) ($_GET['limit'] ?? 50), portal_config('max_page_size'));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        
        $packages = $pickupService->getCustomerPackages($customer['id'], [
            'status' => $status,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
        // Formatta i pacchi per l'output
        $formattedPackages = array_map(function($package) use ($pickupService) {
            return [
                'id' => $package['id'],
                'tracking_code' => $package['tracking_code'],
                'courier_name' => $package['courier_name'],
                'recipient_name' => $package['recipient_name'],
                'expected_delivery_date' => $package['expected_delivery_date'],
                'delivery_location' => $package['delivery_location'],
                'notes' => $package['notes'],
                'status' => $package['status'],
                'pickup_status' => $package['pickup_status'],
                'pickup_location' => $package['location_name'],
                'delivered_at' => $package['delivered_at'],
                'created_at' => $package['created_at'],
                'updated_at' => $package['updated_at'],
                'status_badge' => $pickupService->getStatusBadge($package['pickup_status'] ?: $package['status']),
                'can_track' => !empty($package['pickup_id'])
            ];
        }, $packages);
        
        api_success([
            'packages' => $formattedPackages,
            'total' => count($packages),
            'has_more' => count($packages) === $limit
        ]);
        
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Crea nuovo report pacco
        $data = get_json_input();
        if (empty($data)) {
            $data = $_POST;
        }
        
        validate_csrf_token($data);
        
        $reportData = [
            'tracking_code' => trim($data['tracking_code'] ?? ''),
            'courier_name' => trim($data['courier_name'] ?? ''),
            'recipient_name' => trim($data['recipient_name'] ?? ''),
            'expected_delivery_date' => trim($data['expected_delivery_date'] ?? '') ?: null,
            'delivery_location' => trim($data['delivery_location'] ?? ''),
            'notes' => trim($data['notes'] ?? '')
        ];
        
        $report = $pickupService->reportPackage($customer['id'], $reportData);
        
        api_success($report, 'Pacco segnalato con successo');
        
    } else {
        api_error('Metodo non consentito', 405);
    }
    
} catch (Exception $e) {
    portal_error_log('Packages API error: ' . $e->getMessage(), [
        'customer_id' => $customer['id'],
        'method' => $_SERVER['REQUEST_METHOD']
    ]);
    
    api_error($e->getMessage());
}