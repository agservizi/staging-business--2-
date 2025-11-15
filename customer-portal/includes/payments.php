<?php
declare(strict_types=1);

use PDO;
use RuntimeException;

final class PickupPortalPaymentManager
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? portal_db();
    }

    /**
     * @param array{price:float,label:string,max_weight:float|null,max_volume:float|null} $tier
     * @param array<string,mixed> $payload
     * @return array{ id:int, reference:string, amount_cents:int, currency:string }
     */
    public function createPendingPayment(int $customerId, array $tier, array $payload, string $currency): array
    {
        $price = isset($tier['price']) ? (float) $tier['price'] : 0.0;
        if ($price <= 0) {
            throw new RuntimeException('Impossibile creare il pagamento: prezzo non valido.');
        }

        $amountCents = (int) round($price * 100);
        if ($amountCents <= 0) {
            throw new RuntimeException('Impossibile creare il pagamento: importo non valido.');
        }

        $reference = strtoupper(bin2hex(random_bytes(13))); // 26 chars

        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            throw new RuntimeException('Impossibile serializzare i dati della spedizione.');
        }

        $tierIndex = isset($tier['index']) ? (int) $tier['index'] : 0;
        $tierLabel = isset($tier['label']) && $tier['label'] !== '' ? (string) $tier['label'] : 'Scaglione';

        $data = [
            'public_reference' => $reference,
            'customer_id' => $customerId,
            'status' => 'pending',
            'amount_cents' => $amountCents,
            'currency' => strtoupper($currency),
            'tier_index' => $tierIndex,
            'tier_label' => $tierLabel,
            'shipment_payload' => $payloadJson,
        ];

        $paymentId = portal_insert('pickup_portal_payments', $data);

        return [
            'id' => $paymentId,
            'reference' => $reference,
            'amount_cents' => $amountCents,
            'currency' => strtoupper($currency),
        ];
    }

    public function updateStripeSession(int $paymentId, string $sessionId, ?string $paymentIntentId): void
    {
        portal_update('pickup_portal_payments', [
            'stripe_session_id' => $sessionId,
            'stripe_payment_intent_id' => $paymentIntentId,
        ], ['id' => $paymentId]);
    }

    public function markPaid(int $paymentId, int $portalShipmentId, int $coreShipmentId, ?string $paymentIntentId = null): void
    {
        $fields = [
            'status' => 'paid',
            'shipment_portal_id' => $portalShipmentId,
            'shipment_core_id' => $coreShipmentId,
            'paid_at' => date('Y-m-d H:i:s'),
        ];
        if ($paymentIntentId !== null) {
            $fields['stripe_payment_intent_id'] = $paymentIntentId;
        }
        portal_update('pickup_portal_payments', $fields, ['id' => $paymentId]);
    }

    public function markFailed(int $paymentId, string $message): void
    {
        portal_update('pickup_portal_payments', [
            'status' => 'failed',
            'error_message' => $message,
        ], ['id' => $paymentId]);
    }

    public function markCancelled(string $reference): void
    {
        portal_update('pickup_portal_payments', [
            'status' => 'cancelled',
        ], ['public_reference' => $reference]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByReference(string $reference): ?array
    {
        return portal_fetch_one(
            'SELECT * FROM pickup_portal_payments WHERE public_reference = ? LIMIT 1',
            [$reference]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findPendingByReference(string $reference): ?array
    {
        return portal_fetch_one(
            'SELECT * FROM pickup_portal_payments WHERE public_reference = ? AND status = ? LIMIT 1',
            [$reference, 'pending']
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByStripeSession(string $sessionId): ?array
    {
        return portal_fetch_one(
            'SELECT * FROM pickup_portal_payments WHERE stripe_session_id = ? LIMIT 1',
            [$sessionId]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findPaidByReference(string $reference, int $customerId): ?array
    {
        return portal_fetch_one(
            'SELECT * FROM pickup_portal_payments WHERE public_reference = ? AND customer_id = ? LIMIT 1',
            [$reference, $customerId]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $paymentId): ?array
    {
        return portal_fetch_one(
            'SELECT * FROM pickup_portal_payments WHERE id = ? LIMIT 1',
            [$paymentId]
        );
    }

    public function transitionStatus(int $paymentId, string $fromStatus, string $toStatus): bool
    {
        $updated = portal_update(
            'pickup_portal_payments',
            ['status' => $toStatus],
            ['id' => $paymentId, 'status' => $fromStatus]
        );

        return $updated > 0;
    }

    public function setStatus(int $paymentId, string $status): void
    {
        portal_update('pickup_portal_payments', ['status' => $status], ['id' => $paymentId]);
    }
}
