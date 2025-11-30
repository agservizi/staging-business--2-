-- Migration per il Customer Portal Pickup
-- Data creazione: 2025-10-27

-- Tabella per i clienti del portale
CREATE TABLE IF NOT EXISTS pickup_customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NULL,
    phone VARCHAR(20) NULL,
    name VARCHAR(100) NULL,
    status ENUM('active', 'suspended', 'deleted') DEFAULT 'active',
    email_verified BOOLEAN DEFAULT FALSE,
    phone_verified BOOLEAN DEFAULT FALSE,
    failed_login_attempts INT DEFAULT 0,
    locked_until DATETIME NULL,
    last_login DATETIME NULL,
    last_login_attempt DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    
    INDEX idx_email (email),
    INDEX idx_phone (phone),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    
    CONSTRAINT uq_pickup_customers_email UNIQUE (email),
    CONSTRAINT uq_pickup_customers_phone UNIQUE (phone),
    CONSTRAINT chk_contact_method CHECK (email IS NOT NULL OR phone IS NOT NULL)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella per gli OTP dei clienti
CREATE TABLE IF NOT EXISTS pickup_customer_otps (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    otp_code VARCHAR(255) NOT NULL,
    delivery_method ENUM('email', 'sms', 'whatsapp') NOT NULL,
    expires_at DATETIME NOT NULL,
    used BOOLEAN DEFAULT FALSE,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    
    INDEX idx_customer_id (customer_id),
    INDEX idx_expires_at (expires_at),
    INDEX idx_used (used),
    
    FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella per le sessioni del portale
CREATE TABLE IF NOT EXISTS pickup_customer_sessions (
    id VARCHAR(128) PRIMARY KEY,
    customer_id INT NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT,
    created_at DATETIME NOT NULL,
    last_activity DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    
    INDEX idx_customer_id (customer_id),
    INDEX idx_last_activity (last_activity),
    INDEX idx_expires_at (expires_at),
    
    FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella per le segnalazioni di pacchi da parte dei clienti
CREATE TABLE IF NOT EXISTS pickup_customer_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    tracking_code VARCHAR(100) NOT NULL,
    courier_name VARCHAR(100) NULL,
    expected_delivery_date DATE NULL,
    delivery_location VARCHAR(255) NULL,
    recipient_name VARCHAR(100) NULL,
    notes TEXT NULL,
    status ENUM('reported', 'confirmed', 'arrived', 'cancelled') DEFAULT 'reported',
    pickup_id INT NULL, -- collegamento con la tabella principale pickup
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    
    INDEX idx_customer_id (customer_id),
    INDEX idx_tracking_code (tracking_code),
    INDEX idx_status (status),
    INDEX idx_created_at (created_at),
    INDEX idx_pickup_id (pickup_id),
    
    FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE
    -- FOREIGN KEY (pickup_id) REFERENCES pickup(id) ON DELETE SET NULL -- Decommentare quando disponibile
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella per le notifiche del portale
CREATE TABLE IF NOT EXISTS pickup_customer_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    type ENUM('package_arrived', 'package_ready', 'package_reminder', 'package_expired', 'system_message') NOT NULL,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    tracking_code VARCHAR(100) NULL,
    read_at DATETIME NULL,
    sent_via_email BOOLEAN DEFAULT FALSE,
    sent_via_sms BOOLEAN DEFAULT FALSE,
    created_at DATETIME NOT NULL,
    
    INDEX idx_customer_id (customer_id),
    INDEX idx_type (type),
    INDEX idx_read_at (read_at),
    INDEX idx_created_at (created_at),
    INDEX idx_tracking_code (tracking_code),
    
    FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella per il rate limiting delle API
CREATE TABLE IF NOT EXISTS pickup_api_rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(255) NOT NULL, -- IP address o customer_id
    endpoint VARCHAR(255) NOT NULL,
    request_count INT DEFAULT 1,
    window_start DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    
    INDEX idx_identifier (identifier),
    INDEX idx_endpoint (endpoint),
    INDEX idx_window_start (window_start),
    
    UNIQUE KEY uq_rate_limit (identifier, endpoint, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella per i log delle attività del portale
CREATE TABLE IF NOT EXISTS pickup_customer_activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NULL,
    action VARCHAR(100) NOT NULL,
    resource_type VARCHAR(50) NULL,
    resource_id INT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent TEXT NULL,
    details JSON NULL,
    created_at DATETIME NOT NULL,
    
    INDEX idx_customer_id (customer_id),
    INDEX idx_action (action),
    INDEX idx_resource (resource_type, resource_id),
    INDEX idx_created_at (created_at),
    INDEX idx_ip_address (ip_address),
    
    FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabella per le preferenze dei clienti
CREATE TABLE IF NOT EXISTS pickup_customer_preferences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    notification_email BOOLEAN DEFAULT TRUE,
    notification_sms BOOLEAN DEFAULT FALSE,
    notification_whatsapp BOOLEAN DEFAULT FALSE,
    language VARCHAR(5) DEFAULT 'it',
    timezone VARCHAR(50) DEFAULT 'Europe/Rome',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    
    UNIQUE KEY uq_customer_preferences (customer_id),
    
    FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserimento delle preferenze di default per i clienti esistenti
INSERT IGNORE INTO pickup_customer_preferences (customer_id, created_at, updated_at)
SELECT id, NOW(), NOW() FROM pickup_customers;

-- Vista per combinare le informazioni principali del cliente
CREATE OR REPLACE VIEW pickup_customer_summary AS
SELECT 
    c.id,
    c.email,
    c.phone,
    c.name,
    c.status,
    c.email_verified,
    c.phone_verified,
    c.last_login,
    c.created_at,
    p.notification_email,
    p.notification_sms,
    p.notification_whatsapp,
    p.language,
    COUNT(DISTINCT r.id) as total_reports,
    COUNT(DISTINCT CASE WHEN r.status = 'reported' THEN r.id END) as pending_reports,
    COUNT(DISTINCT n.id) as total_notifications,
    COUNT(DISTINCT CASE WHEN n.read_at IS NULL THEN n.id END) as unread_notifications
FROM pickup_customers c
LEFT JOIN pickup_customer_preferences p ON c.id = p.customer_id
LEFT JOIN pickup_customer_reports r ON c.id = r.customer_id
LEFT JOIN pickup_customer_notifications n ON c.id = n.customer_id
GROUP BY c.id, c.email, c.phone, c.name, c.status, c.email_verified, 
         c.phone_verified, c.last_login, c.created_at, p.notification_email,
         p.notification_sms, p.notification_whatsapp, p.language;