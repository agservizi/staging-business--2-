SET @sql := (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'request_code'
        ),
    'ALTER TABLE cie_prenotazioni CHANGE COLUMN request_code prenotazione_code VARCHAR(40) NOT NULL',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'comune'
        ),
    'ALTER TABLE cie_prenotazioni CHANGE COLUMN comune comune_richiesta VARCHAR(150) NOT NULL',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'preferenza_data'
        ),
    'ALTER TABLE cie_prenotazioni CHANGE COLUMN preferenza_data disponibilita_data DATE NULL',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'preferenza_fascia'
        ),
    'ALTER TABLE cie_prenotazioni CHANGE COLUMN preferenza_fascia disponibilita_fascia VARCHAR(80) NULL',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'slot_data'
        ),
    'ALTER TABLE cie_prenotazioni CHANGE COLUMN slot_data appuntamento_data DATE NULL',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'slot_orario'
        ),
    'ALTER TABLE cie_prenotazioni CHANGE COLUMN slot_orario appuntamento_orario VARCHAR(20) NULL',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'slot_protocollo'
        ),
    'ALTER TABLE cie_prenotazioni CHANGE COLUMN slot_protocollo appuntamento_numero VARCHAR(80) NULL',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'reminder_sent_at'
        ),
    'ALTER TABLE cie_prenotazioni CHANGE COLUMN reminder_sent_at reminder_email_sent_at DATETIME NULL',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'documento_identita_path'
        ),
    'ALTER TABLE cie_prenotazioni ADD COLUMN documento_identita_path VARCHAR(255) NULL AFTER stato',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'documento_identita_nome'
        ),
    'ALTER TABLE cie_prenotazioni ADD COLUMN documento_identita_nome VARCHAR(160) NULL AFTER documento_identita_path',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'documento_identita_mime'
        ),
    'ALTER TABLE cie_prenotazioni ADD COLUMN documento_identita_mime VARCHAR(80) NULL AFTER documento_identita_nome',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'foto_cittadino_path'
        ),
    'ALTER TABLE cie_prenotazioni ADD COLUMN foto_cittadino_path VARCHAR(255) NULL AFTER documento_identita_mime',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'foto_cittadino_nome'
        ),
    'ALTER TABLE cie_prenotazioni ADD COLUMN foto_cittadino_nome VARCHAR(160) NULL AFTER foto_cittadino_path',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'foto_cittadino_mime'
        ),
    'ALTER TABLE cie_prenotazioni ADD COLUMN foto_cittadino_mime VARCHAR(80) NULL AFTER foto_cittadino_nome',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'ricevuta_path'
        ),
    'ALTER TABLE cie_prenotazioni ADD COLUMN ricevuta_path VARCHAR(255) NULL AFTER foto_cittadino_mime',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'ricevuta_nome'
        ),
    'ALTER TABLE cie_prenotazioni ADD COLUMN ricevuta_nome VARCHAR(160) NULL AFTER ricevuta_path',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'ricevuta_mime'
        ),
    'ALTER TABLE cie_prenotazioni ADD COLUMN ricevuta_mime VARCHAR(80) NULL AFTER ricevuta_nome',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'esito'
        ),
    'ALTER TABLE cie_prenotazioni ADD COLUMN esito TEXT NULL AFTER note',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'conferma_email_sent_at'
        ),
    'ALTER TABLE cie_prenotazioni ADD COLUMN conferma_email_sent_at DATETIME NULL AFTER esito',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'reminder_email_sent_at'
        ),
    'ALTER TABLE cie_prenotazioni ADD COLUMN reminder_email_sent_at DATETIME NULL AFTER conferma_email_sent_at',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @sql := (
    SELECT IF(
        NOT EXISTS (
            SELECT 1 FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'cie_prenotazioni'
              AND COLUMN_NAME = 'reminder_whatsapp_sent_at'
        ),
    'ALTER TABLE cie_prenotazioni ADD COLUMN reminder_whatsapp_sent_at DATETIME NULL AFTER reminder_email_sent_at',
    'SET @dummy := 0'
    )
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS cie_prenotazioni_notifiche (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    prenotazione_id INT UNSIGNED NOT NULL,
    channel VARCHAR(40) NOT NULL,
    message_subject VARCHAR(180) NOT NULL,
    notes TEXT NULL,
    sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cie_notifiche_prenotazione (prenotazione_id),
    INDEX idx_cie_notifiche_channel (channel),
    FOREIGN KEY (prenotazione_id) REFERENCES cie_prenotazioni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
