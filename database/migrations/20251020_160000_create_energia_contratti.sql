CREATE TABLE IF NOT EXISTS energia_contratti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NULL,
    contract_code VARCHAR(32) NULL,
    nominativo VARCHAR(160) NOT NULL,
    codice_fiscale VARCHAR(32) NULL,
    email VARCHAR(160) NOT NULL,
    telefono VARCHAR(40) NULL,
    fornitura VARCHAR(20) NOT NULL,
    operazione VARCHAR(40) NOT NULL,
    note TEXT NULL,
    stato VARCHAR(40) NOT NULL DEFAULT 'Registrato',
    email_sent_at DATETIME NULL,
    reminder_sent_at DATETIME NULL,
    last_reminder_subject VARCHAR(180) NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_energia_cliente (cliente_id),
    INDEX idx_energia_fornitura (fornitura),
    INDEX idx_energia_operazione (operazione),
    INDEX idx_energia_stato (stato),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_energia_contract_code (contract_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS energia_contratti_allegati (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    contratto_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contratto_id) REFERENCES energia_contratti(id) ON DELETE CASCADE,
    INDEX idx_energia_allegati_contratto (contratto_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
