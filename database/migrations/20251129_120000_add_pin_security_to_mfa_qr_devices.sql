ALTER TABLE mfa_qr_devices
    ADD COLUMN IF NOT EXISTS failed_pin_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER pin_hash,
    ADD COLUMN IF NOT EXISTS pin_locked_until DATETIME DEFAULT NULL AFTER failed_pin_attempts;
