CREATE TABLE IF NOT EXISTS brt_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level VARCHAR(20) NOT NULL,
    message VARCHAR(255) NOT NULL,
    context LONGTEXT NULL,
    created_by VARCHAR(180) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_brt_logs_level_created (level, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
