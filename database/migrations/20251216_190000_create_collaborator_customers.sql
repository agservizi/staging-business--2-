CREATE TABLE IF NOT EXISTS collaborator_customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    collaborator_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_collaborator_customer (collaborator_id, customer_id),
    INDEX idx_collaborator_customers_collaborator (collaborator_id),
    INDEX idx_collaborator_customers_customer (customer_id),
    CONSTRAINT fk_collaborator_customers_user FOREIGN KEY (collaborator_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_collaborator_customers_customer FOREIGN KEY (customer_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
