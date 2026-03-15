CREATE TABLE IF NOT EXISTS servizi_express_operatori (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    soglia_riordino INT UNSIGNED NOT NULL DEFAULT 10,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_servizi_express_operatori_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_vendite (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    entrata_uscita_id INT UNSIGNED NULL,
    totale DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    iva DECIMAL(5,2) NOT NULL DEFAULT 22.00,
    sconto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    metodo_pagamento VARCHAR(60) NOT NULL DEFAULT 'Contanti',
    stato ENUM('Completata','Annullata','Rimborsata') NOT NULL DEFAULT 'Completata',
    data_vendita DATETIME NOT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_servizi_express_vendite_cliente (cliente_id),
    INDEX idx_servizi_express_vendite_user (user_id),
    INDEX idx_servizi_express_vendite_data (data_vendita),
    CONSTRAINT fk_servizi_express_vendite_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE,
    CONSTRAINT fk_servizi_express_vendite_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_servizi_express_vendite_movimento FOREIGN KEY (entrata_uscita_id) REFERENCES entrate_uscite(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_iccid_stock (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operatore_id INT UNSIGNED NOT NULL,
    vendita_id INT UNSIGNED NULL,
    iccid VARCHAR(32) NOT NULL,
    stato ENUM('InStock','Reserved','Sold') NOT NULL DEFAULT 'InStock',
    note TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_servizi_express_iccid_stock_iccid (iccid),
    INDEX idx_servizi_express_iccid_stock_operatore (operatore_id),
    INDEX idx_servizi_express_iccid_stock_stato (stato),
    CONSTRAINT fk_servizi_express_iccid_stock_operatore FOREIGN KEY (operatore_id) REFERENCES servizi_express_operatori(id) ON DELETE RESTRICT,
    CONSTRAINT fk_servizi_express_iccid_stock_vendita FOREIGN KEY (vendita_id) REFERENCES servizi_express_vendite(id) ON DELETE SET NULL,
    CONSTRAINT fk_servizi_express_iccid_stock_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_vendita_righe (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendita_id INT UNSIGNED NOT NULL,
    operatore_id INT UNSIGNED NULL,
    iccid_stock_id INT UNSIGNED NULL,
    prodotto_id INT UNSIGNED NULL,
    offerta_id INT UNSIGNED NULL,
    tipo ENUM('sim','prodotto','servizio') NOT NULL DEFAULT 'sim',
    descrizione VARCHAR(255) NOT NULL,
    quantita INT UNSIGNED NOT NULL DEFAULT 1,
    prezzo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    aliquota_iva DECIMAL(5,2) NOT NULL DEFAULT 22.00,
    totale_riga DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sconto_riga DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_servizi_express_vendita_righe_vendita (vendita_id),
    CONSTRAINT fk_servizi_express_vendita_righe_vendita FOREIGN KEY (vendita_id) REFERENCES servizi_express_vendite(id) ON DELETE CASCADE,
    CONSTRAINT fk_servizi_express_vendita_righe_operatore FOREIGN KEY (operatore_id) REFERENCES servizi_express_operatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_servizi_express_vendita_righe_iccid FOREIGN KEY (iccid_stock_id) REFERENCES servizi_express_iccid_stock(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_prodotti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    sku VARCHAR(100) NULL,
    imei VARCHAR(100) NULL,
    categoria VARCHAR(100) NULL,
    prezzo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    aliquota_iva DECIMAL(5,2) NOT NULL DEFAULT 22.00,
    stock_quantita INT UNSIGNED NOT NULL DEFAULT 0,
    soglia_riordino INT UNSIGNED NOT NULL DEFAULT 0,
    note TEXT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_servizi_express_prodotti_sku (sku),
    UNIQUE KEY uniq_servizi_express_prodotti_imei (imei)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_offerte (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operatore_id INT UNSIGNED NULL,
    titolo VARCHAR(150) NOT NULL,
    descrizione TEXT NULL,
    prezzo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stato ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    valid_from DATE NULL,
    valid_to DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_servizi_express_offerte_operatore (operatore_id),
    INDEX idx_servizi_express_offerte_stato (stato),
    CONSTRAINT fk_servizi_express_offerte_operatore FOREIGN KEY (operatore_id) REFERENCES servizi_express_operatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_richieste (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    prodotto_id INT UNSIGNED NULL,
    titolo VARCHAR(150) NOT NULL,
    tipo_richiesta ENUM('Purchase','Reservation','Deposit','Installment','Support') NOT NULL DEFAULT 'Purchase',
    stato ENUM('Pending','InReview','Confirmed','Completed','Cancelled','Declined') NOT NULL DEFAULT 'Pending',
    importo_acconto DECIMAL(10,2) NULL,
    numero_rate INT UNSIGNED NULL,
    metodo_pagamento VARCHAR(60) NULL,
    data_desiderata DATE NULL,
    note_cliente TEXT NULL,
    nota_interna TEXT NULL,
    gestita_da INT UNSIGNED NULL,
    gestita_il DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_servizi_express_richieste_cliente (cliente_id),
    INDEX idx_servizi_express_richieste_prodotto (prodotto_id),
    INDEX idx_servizi_express_richieste_stato (stato),
    CONSTRAINT fk_servizi_express_richieste_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE,
    CONSTRAINT fk_servizi_express_richieste_prodotto FOREIGN KEY (prodotto_id) REFERENCES servizi_express_prodotti(id) ON DELETE SET NULL,
    CONSTRAINT fk_servizi_express_richieste_user FOREIGN KEY (gestita_da) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO servizi_express_operatori (nome, soglia_riordino)
VALUES
    ('Iliad', 25),
    ('Fastweb Mobile', 20),
    ('Sky Mobile', 15),
    ('Tiscali Mobile', 15),
    ('WindTre', 25),
    ('Digi Mobile', 20)
ON DUPLICATE KEY UPDATE
    soglia_riordino = VALUES(soglia_riordino),
    attivo = 1,
    updated_at = CURRENT_TIMESTAMP;