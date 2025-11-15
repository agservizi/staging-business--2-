-- Crea tabelle per la gestione delle visure catastali
CREATE TABLE IF NOT EXISTS servizi_visure (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visura_id VARCHAR(64) NOT NULL UNIQUE,
    entita ENUM('immobile','soggetto') NOT NULL,
    stato ENUM('in_erogazione','evasa','errore') NOT NULL DEFAULT 'in_erogazione',
    tipo_visura VARCHAR(32) NOT NULL DEFAULT '',
    richiedente VARCHAR(160) NOT NULL DEFAULT '',
    owner VARCHAR(160) NULL,
    esito VARCHAR(120) NULL,
    documento_nome VARCHAR(255) NULL,
    documento_path VARCHAR(255) NULL,
    documento_mime VARCHAR(120) NULL,
    documento_hash CHAR(64) NULL,
    documento_size BIGINT UNSIGNED NULL,
    cliente_id INT UNSIGNED NULL,
    parametri_json LONGTEXT NULL,
    risultato_json LONGTEXT NULL,
    richiesta_timestamp DATETIME NULL,
    completata_il DATETIME NULL,
    sincronizzata_il DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    documento_aggiornato_il DATETIME NULL,
    notificata_il DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_servizi_visure_stato (stato),
    INDEX idx_servizi_visure_cliente (cliente_id),
    INDEX idx_servizi_visure_timestamp (richiesta_timestamp),
    CONSTRAINT fk_servizi_visure_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_visure_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    servizi_visure_id INT UNSIGNED NOT NULL,
    evento VARCHAR(80) NOT NULL,
    messaggio TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_servizi_visure_log_visura (servizi_visure_id),
    CONSTRAINT fk_servizi_visure_log_visura FOREIGN KEY (servizi_visure_id) REFERENCES servizi_visure(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
