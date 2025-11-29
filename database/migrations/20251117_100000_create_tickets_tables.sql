CREATE TABLE IF NOT EXISTS tickets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(32) NOT NULL,
    customer_id INT UNSIGNED NULL,
    customer_name VARCHAR(190) NULL,
    customer_email VARCHAR(190) NULL,
    customer_phone VARCHAR(60) NULL,
    subject VARCHAR(200) NOT NULL,
    type ENUM('SUPPORT','TECH','ADMIN','SALES') NOT NULL DEFAULT 'SUPPORT',
    priority ENUM('LOW','MEDIUM','HIGH','URGENT') NOT NULL DEFAULT 'MEDIUM',
    status ENUM('OPEN','IN_PROGRESS','WAITING_CLIENT','WAITING_PARTNER','RESOLVED','CLOSED','ARCHIVED') NOT NULL DEFAULT 'OPEN',
    channel ENUM('PORTAL','EMAIL','PHONE','INTERNAL') NOT NULL DEFAULT 'PORTAL',
    assigned_to INT UNSIGNED NULL,
    tags JSON NULL,
    sla_due_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    last_message_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tickets_codice (codice),
    INDEX idx_tickets_status (status),
    INDEX idx_tickets_priority (priority),
    INDEX idx_tickets_type (type),
    INDEX idx_tickets_channel (channel),
    INDEX idx_tickets_customer (customer_id),
    INDEX idx_tickets_assigned (assigned_to),
    INDEX idx_tickets_sla (sla_due_at),
    INDEX idx_tickets_last_message (last_message_at),
    CONSTRAINT fk_tickets_customer FOREIGN KEY (customer_id) REFERENCES clienti(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_tickets_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ticket_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_id BIGINT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NULL,
    author_name VARCHAR(190) NOT NULL,
    body TEXT NOT NULL,
    attachments JSON NULL,
    is_internal TINYINT(1) NOT NULL DEFAULT 0,
    visibility ENUM('customer','internal','system') NOT NULL DEFAULT 'customer',
    status_snapshot VARCHAR(40) NOT NULL,
    notified_client TINYINT(1) NOT NULL DEFAULT 0,
    notified_admin TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_ticket_messages_ticket FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
    CONSTRAINT fk_ticket_messages_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_ticket_messages_ticket_created (ticket_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO tickets (id, codice, customer_id, customer_name, customer_email, customer_phone, subject, type, priority, status, channel, assigned_to, tags, sla_due_at, created_by, last_message_at, created_at, updated_at)
SELECT
    t.id,
    CONCAT('LEGACY', LPAD(t.id, 6, '0')) AS codice,
    t.cliente_id,
    NULLIF(COALESCE(NULLIF(c.ragione_sociale, ''), TRIM(CONCAT(COALESCE(c.cognome, ''), ' ', COALESCE(c.nome, '')))), '') AS customer_name,
    NULLIF(c.email, '') AS customer_email,
    NULLIF(c.telefono, '') AS customer_phone,
    t.titolo AS subject,
    'SUPPORT' AS type,
    'MEDIUM' AS priority,
    CASE
        WHEN LOWER(COALESCE(t.stato, '')) IN ('chiuso', 'closed') THEN 'CLOSED'
        WHEN LOWER(COALESCE(t.stato, '')) LIKE 'archiv%' THEN 'ARCHIVED'
        WHEN LOWER(COALESCE(t.stato, '')) LIKE 'risolt%' THEN 'RESOLVED'
        WHEN LOWER(COALESCE(t.stato, '')) LIKE 'attesa%' THEN 'WAITING_CLIENT'
        WHEN LOWER(COALESCE(t.stato, '')) LIKE 'in corso%' OR LOWER(COALESCE(t.stato, '')) LIKE 'in lavor%' OR LOWER(COALESCE(t.stato, '')) LIKE 'lavor%' THEN 'IN_PROGRESS'
        ELSE 'OPEN'
    END AS status,
    'PORTAL' AS channel,
    NULL AS assigned_to,
    NULL AS tags,
    NULL AS sla_due_at,
    NULL AS created_by,
    COALESCE(tm_last.last_message_at, t.updated_at, t.created_at) AS last_message_at,
    t.created_at,
    t.updated_at
FROM ticket t
LEFT JOIN clienti c ON c.id = t.cliente_id
LEFT JOIN (
    SELECT ticket_id, MAX(created_at) AS last_message_at
    FROM ticket_messaggi
    GROUP BY ticket_id
) tm_last ON tm_last.ticket_id = t.id
WHERE NOT EXISTS (SELECT 1 FROM tickets new_t WHERE new_t.id = t.id);

INSERT INTO ticket_messages (id, ticket_id, author_id, author_name, body, attachments, is_internal, visibility, status_snapshot, notified_client, notified_admin, created_at, updated_at)
SELECT
    tm.id,
    tm.ticket_id,
    tm.utente_id,
    TRIM(COALESCE(NULLIF(CONCAT(COALESCE(u.cognome, ''), ' ', COALESCE(u.nome, '')), ''), NULLIF(u.username, ''), 'Operatore')) AS author_name,
    tm.messaggio AS body,
    CASE WHEN tm.allegato_path IS NULL OR tm.allegato_path = '' THEN JSON_ARRAY() ELSE JSON_ARRAY(tm.allegato_path) END AS attachments,
    0 AS is_internal,
    'customer' AS visibility,
    COALESCE((SELECT status FROM tickets tk WHERE tk.id = tm.ticket_id), 'OPEN') AS status_snapshot,
    0 AS notified_client,
    0 AS notified_admin,
    tm.created_at,
    tm.created_at
FROM ticket_messaggi tm
LEFT JOIN users u ON u.id = tm.utente_id
WHERE NOT EXISTS (SELECT 1 FROM ticket_messages new_tm WHERE new_tm.id = tm.id);

UPDATE tickets t
SET last_message_at = COALESCE(
    (SELECT MAX(created_at) FROM ticket_messages tm WHERE tm.ticket_id = t.id),
    t.last_message_at,
    t.updated_at,
    t.created_at
);

DROP TABLE IF EXISTS ticket_messaggi;
DROP TABLE IF EXISTS ticket;
