CREATE TABLE IF NOT EXISTS fedelta_movimenti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    tipo_movimento VARCHAR(120) NOT NULL,
    descrizione VARCHAR(255) NOT NULL,
    punti INT NOT NULL DEFAULT 0,
    saldo_post_movimento INT NOT NULL DEFAULT 0,
    ricompensa VARCHAR(160) NULL,
    operatore VARCHAR(120) NULL,
    note TEXT NULL,
    data_movimento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fedelta_cliente (cliente_id),
    INDEX idx_fedelta_cliente_data (cliente_id, data_movimento),
    INDEX idx_fedelta_tipo (tipo_movimento),
    CONSTRAINT fk_fedelta_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
