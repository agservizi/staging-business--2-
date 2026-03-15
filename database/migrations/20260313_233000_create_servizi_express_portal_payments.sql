CREATE TABLE IF NOT EXISTS servizi_express_portal_payments (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pickup_customer_preferences ADD COLUMN IF NOT EXISTS express_privacy_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER timezone;
ALTER TABLE pickup_customer_preferences ADD COLUMN IF NOT EXISTS express_terms_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER express_privacy_accepted;
ALTER TABLE pickup_customer_preferences ADD COLUMN IF NOT EXISTS express_marketing_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER express_terms_accepted;