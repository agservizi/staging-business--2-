<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/brt_service.php';

$customer = require_authentication();
$service = new PickupBrtService();

$shipmentId = (int) ($_GET['id'] ?? 0);
if ($shipmentId <= 0) {
    http_response_code(400);
    echo 'ID spedizione non valido';
    exit;
}

try {
    $label = $service->resolveLabelPath((int) $customer['id'], $shipmentId);
    $absolute = $label['absolute_path'];

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $label['filename'] . '"');
    header('Content-Length: ' . (string) filesize($absolute));
    header('X-Content-Type-Options: nosniff');

    readfile($absolute);
    exit;
} catch (Exception $exception) {
    portal_error_log('BRT label download error: ' . $exception->getMessage(), [
        'customer_id' => $customer['id'] ?? null,
        'shipment_id' => $shipmentId,
    ]);

    http_response_code(404);
    echo 'Impossibile scaricare l\'etichetta: ' . htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}
