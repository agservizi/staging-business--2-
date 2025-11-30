ALTER TABLE anpr_pratiche
    ADD COLUMN IF NOT EXISTS spid_verificato_at DATETIME NULL AFTER documento_caricato_at,
    ADD COLUMN IF NOT EXISTS spid_operatore_id INT UNSIGNED NULL AFTER spid_verificato_at,
    ADD COLUMN IF NOT EXISTS certificato_inviato_at DATETIME NULL AFTER spid_operatore_id,
    ADD COLUMN IF NOT EXISTS certificato_inviato_via ENUM('email','pec') NULL AFTER certificato_inviato_at,
    ADD COLUMN IF NOT EXISTS certificato_inviato_destinatario VARCHAR(190) NULL AFTER certificato_inviato_via;

SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'anpr_pratiche'
      AND constraint_name = 'fk_anpr_spid_operatore'
);

SET @fk_sql := IF(
    @fk_exists = 0,
    'ALTER TABLE anpr_pratiche ADD CONSTRAINT fk_anpr_spid_operatore FOREIGN KEY (spid_operatore_id) REFERENCES users(id) ON DELETE SET NULL',
    'DO 0'
);

PREPARE fk_stmt FROM @fk_sql;
EXECUTE fk_stmt;
DEALLOCATE PREPARE fk_stmt;