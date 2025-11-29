CREATE TABLE IF NOT EXISTS mfa_qr_devices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    device_uuid CHAR(36) NOT NULL UNIQUE,
    device_label VARCHAR(100) NOT NULL,
    provisioning_token CHAR(64) DEFAULT NULL,
    provisioning_expires_at DATETIME DEFAULT NULL,
    pin_hash VARCHAR(255) NOT NULL,
    status ENUM('pending', 'active', 'revoked') NOT NULL DEFAULT 'pending',
    last_used_at DATETIME DEFAULT NULL,
    revoked_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mfa_qr_devices_user_id (user_id),
    INDEX idx_mfa_qr_devices_status (status),
    UNIQUE INDEX uq_mfa_qr_devices_provisioning_token (provisioning_token),
    CONSTRAINT fk_mfa_qr_devices_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mfa_qr_challenges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    device_id BIGINT UNSIGNED DEFAULT NULL,
    challenge_token CHAR(64) NOT NULL UNIQUE,
    status ENUM('pending', 'approved', 'denied', 'expired') NOT NULL DEFAULT 'pending',
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    expires_at DATETIME NOT NULL,
    approved_at DATETIME DEFAULT NULL,
    denied_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_mfa_qr_challenges_user_id (user_id),
    INDEX idx_mfa_qr_challenges_status (status),
    INDEX idx_mfa_qr_challenges_expires_at (expires_at),
    CONSTRAINT fk_mfa_qr_challenges_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_mfa_qr_challenges_device FOREIGN KEY (device_id) REFERENCES mfa_qr_devices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
