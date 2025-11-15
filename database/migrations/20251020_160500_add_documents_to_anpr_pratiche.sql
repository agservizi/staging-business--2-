ALTER TABLE anpr_pratiche
    ADD COLUMN IF NOT EXISTS delega_path VARCHAR(255) NULL AFTER certificato_caricato_at,
    ADD COLUMN IF NOT EXISTS delega_hash CHAR(64) NULL AFTER delega_path,
    ADD COLUMN IF NOT EXISTS delega_caricato_at DATETIME NULL AFTER delega_hash,
    ADD COLUMN IF NOT EXISTS documento_path VARCHAR(255) NULL AFTER delega_caricato_at,
    ADD COLUMN IF NOT EXISTS documento_hash CHAR(64) NULL AFTER documento_path,
    ADD COLUMN IF NOT EXISTS documento_caricato_at DATETIME NULL AFTER documento_hash;