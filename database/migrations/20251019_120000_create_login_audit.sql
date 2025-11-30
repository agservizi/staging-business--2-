CREATE TABLE IF NOT EXISTS login_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    username VARCHAR(191) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(500) NOT NULL,
    note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_login_audit_username (username),
    INDEX idx_login_audit_created_at (created_at),
    INDEX idx_login_audit_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
