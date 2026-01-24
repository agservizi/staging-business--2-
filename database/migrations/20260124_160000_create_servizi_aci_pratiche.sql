CREATE TABLE IF NOT EXISTS servizi_aci_pratiche (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NULL,
    tipo_pratica VARCHAR(80) NOT NULL,
    stato VARCHAR(40) NOT NULL DEFAULT 'Aperta',
    targa VARCHAR(20) NULL,
    telaio VARCHAR(40) NULL,
    intestatario VARCHAR(160) NULL,
    protocollo VARCHAR(80) NULL,
    data_apertura DATE NULL,
    data_scadenza DATE NULL,
    data_chiusura DATE NULL,
    costo DECIMAL(10,2) NOT NULL DEFAULT 0,
    note TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_aci_cliente (cliente_id),
    INDEX idx_aci_stato (stato),
    INDEX idx_aci_tipo (tipo_pratica),
    INDEX idx_aci_targa (targa),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_aci_allegati (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pratica_id INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aci_allegati_pratica (pratica_id),
    FOREIGN KEY (pratica_id) REFERENCES servizi_aci_pratiche(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
