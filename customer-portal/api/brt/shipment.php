<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/brt_service.php';

$customer = require_authentication();
$service = new PickupBrtService();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        if ($id <= 0) {
            api_error('ID spedizione non valido', 400);
        }

        $shipment = $service->getShipment((int) $customer['id'], $id);
        api_success(['shipment' => $shipment]);
        return;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        api_error('Metodo non consentito', 405);
    }

    $data = get_json_input();
    if ($data === []) {
        $data = $_POST;
    }

    validate_csrf_token($data);

    $id = (int) ($data['id'] ?? 0);
    $action = trim((string) ($data['action'] ?? ''));

    if ($id <= 0 || $action === '') {
        api_error('Richiesta non valida', 400);
    }

    switch ($action) {
        case 'refresh_tracking':
            $shipment = $service->refreshTracking((int) $customer['id'], $id);
            api_success(['shipment' => $shipment], 'Tracking aggiornato');
            break;

        case 'reprint_label':
            $shipment = $service->reprintLabel((int) $customer['id'], $id);
            api_success(['shipment' => $shipment], 'Etichetta aggiornata');
            break;

        default:
            api_error('Azione non supportata', 400);
    }
} catch (Exception $exception) {
    portal_error_log('BRT shipment API error: ' . $exception->getMessage(), [
        'customer_id' => $customer['id'] ?? null,
        'method' => $_SERVER['REQUEST_METHOD'],
    ]);
    api_error($exception->getMessage());
}
