<?php
declare(strict_types=1);

require_once __DIR__ . '/../modules/servizi/brt/db.php';
require_once __DIR__ . '/../modules/servizi/brt/functions.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/mailer.php';

use App\Services\Automata\AutomataService;
use App\Services\Brt\BrtTrackingService;

$pdo = brt_db();
$automata = new AutomataService();
$trackingService = new BrtTrackingService();

$stmt = $pdo->query("SELECT id, parcel_id, tracking_by_parcel_id, customer_email, stato FROM brt_shipments WHERE stato NOT IN ('consegnata','annullata','cancellata') AND parcel_id IS NOT NULL ORDER BY updated_at DESC LIMIT 50");
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

foreach ($rows as $row) {
    $id = (int) ($row['id'] ?? 0);
    $parcelId = trim((string) ($row['parcel_id'] ?? ''));
    if ($id <= 0 || $parcelId === '') {
        continue;
    }
    try {
        $tracking = $trackingService->trackingByParcelId($parcelId);
        brt_update_tracking($id, $tracking);
        $email = trim((string) ($row['customer_email'] ?? ''));
        if ($email !== '' && function_exists('send_system_mail')) {
            $draft = $automata->draftCustomerMessage('email', 'brt_tracking_update', [
                'parcel_id' => $parcelId,
                'shipment_id' => $id,
            ]);
            send_system_mail($email, $draft['subject'], $draft['body']);
        }
    } catch (Throwable $exception) {
        fwrite(STDERR, 'BRT poll #' . $id . ': ' . $exception->getMessage() . PHP_EOL);
    }
}

fwrite(STDOUT, "BRT tracking poll completato.\n");
