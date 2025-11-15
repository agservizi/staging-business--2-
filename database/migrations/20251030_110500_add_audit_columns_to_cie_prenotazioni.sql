SET @schema := DATABASE();

SET @missing_created_by := (
    SELECT COUNT(*) = 0
    FROM information_schema.columns
    WHERE table_schema = @schema
      AND table_name = 'cie_prenotazioni'
      AND column_name = 'created_by'
);

SET @sql := IF(@missing_created_by,
  'ALTER TABLE cie_prenotazioni ADD COLUMN created_by INT UNSIGNED NULL',
  'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @missing_updated_by := (
    SELECT COUNT(*) = 0
    FROM information_schema.columns
    WHERE table_schema = @schema
      AND table_name = 'cie_prenotazioni'
      AND column_name = 'updated_by'
);

SET @sql := IF(@missing_updated_by,
  'ALTER TABLE cie_prenotazioni ADD COLUMN updated_by INT UNSIGNED NULL',
  'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @missing_fk_created := (
    SELECT COUNT(*) = 0
    FROM information_schema.key_column_usage
    WHERE table_schema = @schema
      AND table_name = 'cie_prenotazioni'
      AND referenced_table_name = 'users'
      AND column_name = 'created_by'
);

SET @sql := IF(@missing_fk_created,
  'ALTER TABLE cie_prenotazioni
    ADD CONSTRAINT cie_prenotazioni_created_by_fk FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL',
  'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @missing_fk_updated := (
    SELECT COUNT(*) = 0
    FROM information_schema.key_column_usage
    WHERE table_schema = @schema
      AND table_name = 'cie_prenotazioni'
      AND referenced_table_name = 'users'
      AND column_name = 'updated_by'
);

SET @sql := IF(@missing_fk_updated,
  'ALTER TABLE cie_prenotazioni
    ADD CONSTRAINT cie_prenotazioni_updated_by_fk FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL',
  'DO 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
