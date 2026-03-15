CREATE TABLE IF NOT EXISTS servizi_express_portale_clienti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pickup_customer_id INT NOT NULL,
    cliente_id INT UNSIGNED NOT NULL,
    stato ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_servizi_express_portale_pickup (pickup_customer_id),
    UNIQUE KEY uniq_servizi_express_portale_cliente (cliente_id),
    INDEX idx_servizi_express_portale_stato (stato),
    CONSTRAINT fk_servizi_express_portale_pickup FOREIGN KEY (pickup_customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_servizi_express_portale_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;