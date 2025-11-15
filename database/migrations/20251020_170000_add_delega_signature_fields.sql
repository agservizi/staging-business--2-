ALTER TABLE anpr_pratiche
    ADD COLUMN delega_firma_status ENUM('non_inviata','otp_inviato','firmata','scaduta') NOT NULL DEFAULT 'non_inviata' AFTER delega_caricato_at,
    ADD COLUMN delega_firma_hash CHAR(64) NULL AFTER delega_firma_status,
    ADD COLUMN delega_firma_otp_salt CHAR(16) NULL AFTER delega_firma_hash,
    ADD COLUMN delega_firma_inviata_il DATETIME NULL AFTER delega_firma_otp_salt,
    ADD COLUMN delega_firma_verificata_il DATETIME NULL AFTER delega_firma_inviata_il,
    ADD COLUMN delega_firma_recipient VARCHAR(190) NULL AFTER delega_firma_verificata_il,
    ADD COLUMN delega_firma_channel ENUM('email') NULL AFTER delega_firma_recipient,
    ADD COLUMN delega_firma_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER delega_firma_channel;