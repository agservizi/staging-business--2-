ALTER TABLE pratiche
    ADD COLUMN tracking_code VARCHAR(32) NULL AFTER id_utente_caf_patronato,
    ADD UNIQUE KEY idx_pratiche_tracking_code (tracking_code),
    ADD COLUMN tracking_steps JSON NULL AFTER tracking_code;
