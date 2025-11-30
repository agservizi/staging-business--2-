<?php
declare(strict_types=1);

use RuntimeException;
use Throwable;

require_once __DIR__ . '/payments.php';
require_once __DIR__ . '/stripe.php';
require_once __DIR__ . '/brt_service.php';

/**
 * Esegue il tentativo di finalizzazione del pagamento Stripe creando la spedizione.
 *
 * @param array<string,mixed> $paymentRow
 * @return array{status:string,payment:array<string,mixed>,shipment:array<string,mixed>|null,message:?string}
 */
function portal_finalize_payment(array $paymentRow, int $customerId): array
{
    $manager = new PickupPortalPaymentManager();
    $paymentId = (int) ($paymentRow['id'] ?? 0);
    $reference = (string) ($paymentRow['public_reference'] ?? '');
    $status = (string) ($paymentRow['status'] ?? 'pending');

    if ($paymentId <= 0) {
        throw new RuntimeException('Pagamento non valido.');
    }

    if (!in_array($status, ['pending', 'processing'], true)) {
        return [
            'status' => $status,
            'payment' => $paymentRow,
            'shipment' => null,
            'message' => null,
        ];
    }

    if ($status === 'pending') {
        $locked = $manager->transitionStatus($paymentId, 'pending', 'processing');
        if (!$locked) {
            $latest = $manager->findById($paymentId);
            $latestStatus = is_array($latest) ? (string) ($latest['status'] ?? $status) : $status;

            return [
                'status' => $latestStatus,
                'payment' => $latest ?? $paymentRow,
                'shipment' => null,
                'message' => $latestStatus === 'paid'
                    ? 'Pagamento già completato da un altro processo.'
                    : 'Aggiornamento stato in corso, riprova tra qualche secondo.',
            ];
        }

        $paymentRow['status'] = 'processing';
    }

    $sessionId = (string) ($paymentRow['stripe_session_id'] ?? '');
    if ($sessionId === '') {
        $manager->transitionStatus($paymentId, 'processing', 'pending');
        throw new RuntimeException('Sessione Stripe non disponibile per il pagamento ' . $reference);
    }

    try {
        $stripe = portal_stripe_client();
        $session = $stripe->checkout->sessions->retrieve($sessionId, []);
    } catch (Throwable $exception) {
        $manager->transitionStatus($paymentId, 'processing', 'pending');
        throw new RuntimeException('Recupero della sessione Stripe non riuscito: ' . $exception->getMessage());
    }

    $paymentIntentId = isset($session->payment_intent) ? (string) $session->payment_intent : null;
    $paymentStatus = (string) ($session->payment_status ?? '');

    if ($paymentStatus !== 'paid') {
        $manager->transitionStatus($paymentId, 'processing', 'pending');

        return [
            'status' => 'pending',
            'payment' => $manager->findById($paymentId) ?? $paymentRow,
            'shipment' => null,
            'message' => 'Pagamento non ancora confermato da Stripe.',
        ];
    }

    $payloadRaw = $paymentRow['shipment_payload'] ?? '';
    try {
        $payload = json_decode((string) $payloadRaw, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        $manager->markFailed($paymentId, 'Payload spedizione non valido: ' . $exception->getMessage());
        throw new RuntimeException('Dati spedizione non validi, contatta l’assistenza.');
    }

    if (!is_array($payload)) {
        $manager->markFailed($paymentId, 'Payload spedizione non valido: formato inatteso.');
        throw new RuntimeException('Dati spedizione non validi, contatta l’assistenza.');
    }

    try {
        $service = new PickupBrtService();
    } catch (Throwable $exception) {
        $manager->markFailed($paymentId, 'Modulo BRT non disponibile: ' . $exception->getMessage());
        throw new RuntimeException('Configurazione BRT non disponibile, impossibile creare la spedizione.');
    }

    try {
        $shipment = $service->createShipment($customerId, $payload);
    } catch (Throwable $exception) {
        $manager->markFailed($paymentId, 'Creazione spedizione non riuscita: ' . $exception->getMessage());
        portal_error_log('Portal payment shipment creation failed', [
            'payment_id' => $paymentId,
            'reference' => $reference,
            'customer_id' => $customerId,
            'error' => $exception->getMessage(),
        ]);
        throw new RuntimeException('Impossibile creare la spedizione: ' . $exception->getMessage());
    }

    $portalShipmentId = (int) ($shipment['id'] ?? 0);
    $coreShipmentId = (int) ($shipment['core_id'] ?? 0);

    $manager->markPaid($paymentId, $portalShipmentId, $coreShipmentId, $paymentIntentId);

    $updated = $manager->findById($paymentId) ?? $paymentRow;

    return [
        'status' => 'paid',
        'payment' => $updated,
        'shipment' => $shipment,
        'message' => 'Pagamento confermato e spedizione creata.',
    ];
}
