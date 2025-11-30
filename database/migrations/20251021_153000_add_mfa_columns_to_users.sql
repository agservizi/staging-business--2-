ALTER TABLE users
    ADD COLUMN IF NOT EXISTS mfa_secret VARCHAR(128) NULL AFTER password,
    ADD COLUMN IF NOT EXISTS mfa_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER mfa_secret,
    ADD COLUMN IF NOT EXISTS mfa_recovery_codes TEXT NULL AFTER mfa_enabled,
    ADD COLUMN IF NOT EXISTS mfa_enabled_at DATETIME NULL AFTER mfa_recovery_codes;
