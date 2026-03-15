<?php
declare(strict_types=1);

use PDO;
use RuntimeException;

final class ExpressPortalPaymentManager
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? portal_db();
        $this->ensureSchema();
    }

    public function ensureSchema(): void
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS servizi_express_portal_payments (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                public_reference CHAR(26) NOT NULL,
                customer_id INT NOT NULL,
                business_customer_id INT UNSIGNED NOT NULL,
                request_id INT UNSIGNED NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending',
                amount_cents INT UNSIGNED NOT NULL,
                currency CHAR(3) NOT NULL,
                title VARCHAR(160) NOT NULL,
                description TEXT NULL,
                stripe_session_id VARCHAR(255) DEFAULT NULL,
                stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
                metadata_json LONGTEXT NULL,
                entrata_uscita_id INT UNSIGNED NULL,
                error_message TEXT NULL,
                paid_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_servizi_express_portal_payments_reference (public_reference),
                UNIQUE KEY uniq_servizi_express_portal_payments_session (stripe_session_id),
                KEY idx_servizi_express_portal_payments_customer (customer_id, status),
                KEY idx_servizi_express_portal_payments_request (request_id),
                CONSTRAINT fk_servizi_express_portal_payments_customer FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE,
                CONSTRAINT fk_servizi_express_portal_payments_business_customer FOREIGN KEY (business_customer_id) REFERENCES clienti(id) ON DELETE CASCADE,
                CONSTRAINT fk_servizi_express_portal_payments_request FOREIGN KEY (request_id) REFERENCES servizi_express_richieste(id) ON DELETE SET NULL,
                CONSTRAINT fk_servizi_express_portal_payments_movement FOREIGN KEY (entrata_uscita_id) REFERENCES entrate_uscite(id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
            "ALTER TABLE pickup_customer_preferences ADD COLUMN IF NOT EXISTS express_privacy_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER timezone",
            "ALTER TABLE pickup_customer_preferences ADD COLUMN IF NOT EXISTS express_terms_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER express_privacy_accepted",
            "ALTER TABLE pickup_customer_preferences ADD COLUMN IF NOT EXISTS express_marketing_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER express_terms_accepted",
        ];

        foreach ($statements as $statement) {
            try {
                $this->pdo->exec($statement);
            } catch (Throwable $exception) {
                portal_error_log('Express portal payment schema statement failed', [
                    'statement' => $statement,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    public function createPendingPayment(
        int $portalCustomerId,
        int $businessCustomerId,
        ?int $requestId,
        float $amount,
        string $title,
        ?string $description,
        array $metadata = [],
        string $currency = 'EUR'
    ): array {
        $amountCents = (int) round($amount * 100);
        if ($amountCents <= 0) {
            throw new RuntimeException('Importo pagamento Express non valido.');
        }

        $reference = strtoupper(bin2hex(random_bytes(13)));
        $metadataJson = json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($metadataJson === false) {
            throw new RuntimeException('Impossibile serializzare i metadati del pagamento Express.');
        }

        $paymentId = portal_insert('servizi_express_portal_payments', [
            'public_reference' => $reference,
            'customer_id' => $portalCustomerId,
            'business_customer_id' => $businessCustomerId,
            'request_id' => $requestId,
            'status' => 'pending',
            'amount_cents' => $amountCents,
            'currency' => strtoupper($currency),
            'title' => $title,
            'description' => $description,
            'metadata_json' => $metadataJson,
        ]);

        return [
            'id' => $paymentId,
            'reference' => $reference,
            'amount_cents' => $amountCents,
            'currency' => strtoupper($currency),
        ];
    }

    public function updateStripeSession(int $paymentId, string $sessionId, ?string $paymentIntentId): void
    {
        portal_update('servizi_express_portal_payments', [
            'stripe_session_id' => $sessionId,
            'stripe_payment_intent_id' => $paymentIntentId,
        ], ['id' => $paymentId]);
    }

    public function markPaid(int $paymentId, int $movementId, ?string $paymentIntentId = null): void
    {
        $fields = [
            'status' => 'paid',
            'entrata_uscita_id' => $movementId,
            'paid_at' => date('Y-m-d H:i:s'),
        ];
        if ($paymentIntentId !== null) {
            $fields['stripe_payment_intent_id'] = $paymentIntentId;
        }

        portal_update('servizi_express_portal_payments', $fields, ['id' => $paymentId]);
    }

    public function markFailed(int $paymentId, string $message): void
    {
        portal_update('servizi_express_portal_payments', [
            'status' => 'failed',
            'error_message' => $message,
        ], ['id' => $paymentId]);
    }

    public function markCancelled(string $reference): void
    {
        portal_update('servizi_express_portal_payments', [
            'status' => 'cancelled',
        ], ['public_reference' => $reference]);
    }

    public function findByReference(string $reference): ?array
    {
        return portal_fetch_one('SELECT * FROM servizi_express_portal_payments WHERE public_reference = ? LIMIT 1', [$reference]);
    }

    public function findById(int $paymentId): ?array
    {
        return portal_fetch_one('SELECT * FROM servizi_express_portal_payments WHERE id = ? LIMIT 1', [$paymentId]);
    }

    public function transitionStatus(int $paymentId, string $fromStatus, string $toStatus): bool
    {
        $updated = portal_update('servizi_express_portal_payments', ['status' => $toStatus], ['id' => $paymentId, 'status' => $fromStatus]);
        return $updated > 0;
    }
}

function express_portal_base_url(): string
{
    $baseUrl = rtrim(env('PORTAL_URL', ''), '/');
    if ($baseUrl !== '') {
        return $baseUrl;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $protocol . '://' . $host . '/customer-portal';
}

function express_portal_stripe_success_url(string $reference): string
{
    return express_portal_base_url() . '/express-payment-complete.php?ref=' . urlencode($reference);
}

function express_portal_stripe_cancel_url(): string
{
    return express_portal_base_url() . '/express-support.php?payment_cancel=1';
}