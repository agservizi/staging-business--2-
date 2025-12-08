ALTER TABLE clienti
    ADD COLUMN morosita_flag TINYINT(1) NOT NULL DEFAULT 0 AFTER note,
    ADD COLUMN morosita_score ENUM('ok','attenzione','bloccato') NOT NULL DEFAULT 'ok' AFTER morosita_flag,
    ADD COLUMN morosita_note TEXT NULL AFTER morosita_score,
    ADD COLUMN morosita_aggiornato_il DATETIME NULL AFTER morosita_note,
    ADD COLUMN morosita_fonte VARCHAR(100) NULL AFTER morosita_aggiornato_il,
    ADD INDEX idx_clienti_morosita_score (morosita_score);

CREATE TABLE IF NOT EXISTS customer_morosita_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    esito ENUM('ok','attenzione','bloccato') NOT NULL,
    fonte VARCHAR(100) NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES clienti(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_morosita_customer (customer_id),
    INDEX idx_morosita_esito (esito)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
