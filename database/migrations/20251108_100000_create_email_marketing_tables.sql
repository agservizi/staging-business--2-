CREATE TABLE IF NOT EXISTS email_subscribers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(160) NOT NULL UNIQUE,
    first_name VARCHAR(80) NULL,
    last_name VARCHAR(80) NULL,
    tags JSON NULL,
    status ENUM('active','unsubscribed','bounced') NOT NULL DEFAULT 'active',
    source VARCHAR(60) NOT NULL DEFAULT 'manual',
    last_engagement_at DATETIME NULL,
    unsubscribed_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email_subscribers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_lists (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_list_subscribers (
    list_id INT UNSIGNED NOT NULL,
    subscriber_id INT UNSIGNED NOT NULL,
    status ENUM('active','unsubscribed','bounced') NOT NULL DEFAULT 'active',
    subscribed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unsubscribed_at DATETIME NULL,
    PRIMARY KEY (list_id, subscriber_id),
    CONSTRAINT fk_email_list_subscriber_list FOREIGN KEY (list_id) REFERENCES email_lists(id) ON DELETE CASCADE,
    CONSTRAINT fk_email_list_subscriber_subscriber FOREIGN KEY (subscriber_id) REFERENCES email_subscribers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_templates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    preheader VARCHAR(200) NULL,
    html MEDIUMTEXT NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email_templates_created_by (created_by),
    CONSTRAINT fk_email_templates_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    from_name VARCHAR(160) NOT NULL,
    from_email VARCHAR(160) NOT NULL,
    reply_to VARCHAR(160) NULL,
    template_id INT UNSIGNED NULL,
    content_html MEDIUMTEXT NULL,
    content_plain MEDIUMTEXT NULL,
    audience_type ENUM('all_clients','list','manual') NOT NULL DEFAULT 'all_clients',
    audience_filters JSON NULL,
    status ENUM('draft','scheduled','sending','sent','cancelled','failed') NOT NULL DEFAULT 'draft',
    scheduled_at DATETIME NULL,
    sent_at DATETIME NULL,
    metrics_summary JSON NULL,
    last_error TEXT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_email_campaigns_status (status),
    INDEX idx_email_campaigns_scheduled (scheduled_at),
    CONSTRAINT fk_email_campaigns_template FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL,
    CONSTRAINT fk_email_campaigns_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_campaign_recipients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    subscriber_id INT UNSIGNED NULL,
    email VARCHAR(160) NOT NULL,
    first_name VARCHAR(80) NULL,
    last_name VARCHAR(80) NULL,
    status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
    sent_at DATETIME NULL,
    last_error TEXT NULL,
    opens SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_open_at DATETIME NULL,
    clicks SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_click_at DATETIME NULL,
    unsubscribe_token CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_email_campaign_recipient (campaign_id, email),
    INDEX idx_email_campaign_recipient_status (campaign_id, status),
    CONSTRAINT fk_email_campaign_recipient_campaign FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_email_campaign_recipient_subscriber FOREIGN KEY (subscriber_id) REFERENCES email_subscribers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_campaign_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,
    event_type ENUM('open','click','bounce','complaint','unsubscribe') NOT NULL,
    meta JSON NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_campaign_events_type (campaign_id, event_type),
    CONSTRAINT fk_email_campaign_events_campaign FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE,
    CONSTRAINT fk_email_campaign_events_recipient FOREIGN KEY (recipient_id) REFERENCES email_campaign_recipients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
