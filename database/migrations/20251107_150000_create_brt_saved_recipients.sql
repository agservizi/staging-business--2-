CREATE TABLE IF NOT EXISTS brt_saved_recipients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(120) NOT NULL,
    company_name VARCHAR(180) NOT NULL,
    address VARCHAR(255) NOT NULL,
    zip VARCHAR(20) NOT NULL,
    city VARCHAR(150) NOT NULL,
    province VARCHAR(10) NULL,
    country VARCHAR(4) NOT NULL DEFAULT 'IT',
    contact_name VARCHAR(120) NULL,
    phone VARCHAR(40) NULL,
    mobile VARCHAR(40) NULL,
    email VARCHAR(160) NULL,
    pudo_id VARCHAR(120) NULL,
    pudo_description VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_brt_saved_recipients_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
