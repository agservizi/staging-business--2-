CREATE TABLE IF NOT EXISTS opportunity_drafts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    collaborator_id INT UNSIGNED NOT NULL,
    payload JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_opportunity_drafts_collaborator (collaborator_id),
    INDEX idx_opportunity_drafts_updated_at (updated_at),
    CONSTRAINT fk_opportunity_drafts_user FOREIGN KEY (collaborator_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
