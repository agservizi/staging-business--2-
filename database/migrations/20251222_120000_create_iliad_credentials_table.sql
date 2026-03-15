-- Migration: Create iliad_credentials table
-- Date: 2025-12-22

CREATE TABLE IF NOT EXISTS iliad_credentials (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fibra_id VARCHAR(64) NULL,
    fibra_password VARCHAR(128) NOT NULL,
    mobile_id VARCHAR(64) NULL,
    mobile_password VARCHAR(128) NOT NULL,
    include_fibra TINYINT(1) NOT NULL DEFAULT 1,
    include_mobile TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_iliad_credentials_created_by (created_by),
    CONSTRAINT fk_iliad_credentials_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;