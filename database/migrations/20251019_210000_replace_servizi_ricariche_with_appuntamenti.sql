CREATE TABLE IF NOT EXISTS servizi_appuntamenti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    titolo VARCHAR(160) NOT NULL,
    tipo_servizio VARCHAR(80) NOT NULL,
    responsabile VARCHAR(120) NULL,
    luogo VARCHAR(160) NULL,
    stato VARCHAR(40) NOT NULL DEFAULT 'Programmato',
    data_inizio DATETIME NOT NULL,
    data_fine DATETIME NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_appuntamenti_cliente (cliente_id),
    INDEX idx_appuntamenti_stato (stato),
    INDEX idx_appuntamenti_responsabile (responsabile),
    INDEX idx_appuntamenti_inizio (data_inizio),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_ricariche (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    operatore VARCHAR(120) NOT NULL,
    numero_riferimento VARCHAR(160) NULL,
    tipo VARCHAR(80) NOT NULL,
    stato VARCHAR(40) NOT NULL DEFAULT 'Nuovo',
    data_operazione DATE NOT NULL,
    importo DECIMAL(10,2) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ricariche_cliente (cliente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO servizi_appuntamenti (cliente_id, titolo, tipo_servizio, responsabile, luogo, stato, data_inizio, data_fine, note, created_at, updated_at)
SELECT sr.cliente_id,
       CONCAT('Ricarica ', sr.operatore, ' ', sr.numero_riferimento) AS titolo,
       sr.tipo AS tipo_servizio,
       sr.operatore AS responsabile,
       NULL AS luogo,
       sr.stato,
       STR_TO_DATE(CONCAT(sr.data_operazione, ' 09:00:00'), '%Y-%m-%d %H:%i:%s') AS data_inizio,
       NULL AS data_fine,
    CONCAT('Record migrato da storico ricariche. Importo previsto: € ', FORMAT(sr.importo, 2)) AS note,
       sr.created_at,
       sr.updated_at
FROM servizi_ricariche sr
WHERE NOT EXISTS (SELECT 1 FROM servizi_appuntamenti);

DROP TABLE IF EXISTS servizi_ricariche;
