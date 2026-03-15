CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    role VARCHAR(50) NULL,
    type ENUM('info', 'success', 'warning', 'error', 'bug') NOT NULL DEFAULT 'info',
    title VARCHAR(190) NOT NULL,
    message TEXT NOT NULL,
    metadata JSON NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user_read (user_id, is_read, created_at),
    INDEX idx_notifications_role_read (role, is_read, created_at),
    INDEX idx_notifications_created (created_at)
);