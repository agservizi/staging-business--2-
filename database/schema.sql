-- Schema Coresuite Business
-- Generato il 2025-10-19

-- Cleanup tabelle legacy pagoPA rimosse dal progetto
DROP TABLE IF EXISTS pagopa_avvisi;
DROP TABLE IF EXISTS pagopa_avvisi_eventi;
DROP TABLE IF EXISTS pagopa_bollettini;

CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(160) NOT NULL UNIQUE,
    nome VARCHAR(80) NOT NULL DEFAULT '',
    cognome VARCHAR(80) NOT NULL DEFAULT '',
    password VARCHAR(255) NOT NULL,
    mfa_secret VARCHAR(128) NULL,
    mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
    mfa_recovery_codes TEXT NULL,
    mfa_enabled_at DATETIME NULL,
    ruolo ENUM('Admin','Manager','Operatore','Cliente','Collaboratore') NOT NULL DEFAULT 'Operatore',
    theme_preference ENUM('dark','light') NOT NULL DEFAULT 'dark',
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    token CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    selector CHAR(18) NOT NULL UNIQUE,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL,
    INDEX idx_remember_tokens_user_id (user_id),
    INDEX idx_remember_tokens_expires_at (expires_at),
    CONSTRAINT fk_remember_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS configurazioni (
    chiave VARCHAR(120) PRIMARY KEY,
    valore TEXT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS log_attivita (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    modulo VARCHAR(120) NOT NULL,
    azione VARCHAR(160) NOT NULL,
    dettagli TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_modulo (modulo),
    INDEX idx_log_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opportunity_statuses (
    id SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(60) NOT NULL UNIQUE,
    label VARCHAR(160) NOT NULL,
    color VARCHAR(32) NOT NULL DEFAULT 'slate',
    is_core TINYINT(1) NOT NULL DEFAULT 0,
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO opportunity_statuses (code, label, color, is_core, ordering)
VALUES
    ('in_verifica', 'In verifica', 'warning', 1, 10),
    ('documenti_ok', 'Documenti ok', 'info', 1, 20),
    ('in_firma_otp', 'In firma OTP', 'primary', 1, 30),
    ('annullato', 'Annullato', 'danger', 1, 40),
    ('attivato', 'Attivato', 'success', 1, 50)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    color = VALUES(color),
    is_core = VALUES(is_core),
    ordering = VALUES(ordering);

CREATE TABLE IF NOT EXISTS opportunity_providers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category ENUM('telefonia','luce','gas') NOT NULL,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    default_commission DECIMAL(10,2) NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_opportunity_provider (category, slug),
    INDEX idx_opportunity_providers_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opportunity_offers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    slug VARCHAR(160) NOT NULL,
    commission DECIMAL(10,2) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_opportunity_offer (provider_id, slug),
    INDEX idx_opportunity_offers_provider (provider_id),
    CONSTRAINT fk_opportunity_offers_provider FOREIGN KEY (provider_id) REFERENCES opportunity_providers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opportunities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code CHAR(10) NOT NULL UNIQUE,
    category ENUM('telefonia','luce','gas') NOT NULL,
    status_code VARCHAR(60) NOT NULL DEFAULT 'in_verifica',
    provider_id INT UNSIGNED NOT NULL,
    offer_id INT UNSIGNED NULL,
    provider_label VARCHAR(160) NOT NULL,
    offer_label VARCHAR(160) NULL,
    collaborator_id INT UNSIGNED NOT NULL,
    managed_by INT UNSIGNED NULL,
    commission_amount DECIMAL(10,2) NULL,
    customer_first_name VARCHAR(80) NOT NULL,
    customer_last_name VARCHAR(80) NOT NULL,
    customer_tax_code VARCHAR(32) NOT NULL,
    customer_birth_date DATE NULL,
    customer_birth_place VARCHAR(120) NULL,
    customer_phone VARCHAR(40) NOT NULL,
    customer_email VARCHAR(160) NOT NULL,
    customer_address VARCHAR(255) NULL,
    customer_city VARCHAR(120) NULL,
    customer_postal_code VARCHAR(12) NULL,
    customer_province VARCHAR(8) NULL,
    document_type VARCHAR(60) NULL,
    document_number VARCHAR(60) NULL,
    document_issued_by VARCHAR(160) NULL,
    document_issued_at DATE NULL,
    document_expires_at DATE NULL,
    telefonia_current_operator VARCHAR(160) NULL,
    telefonia_line_number VARCHAR(32) NULL,
    luce_pod VARCHAR(32) NULL,
    gas_pdr VARCHAR(32) NULL,
    payment_method ENUM('iban','bollettino') NOT NULL DEFAULT 'iban',
    payment_iban VARCHAR(34) NULL,
    payment_holder_is_customer TINYINT(1) NOT NULL DEFAULT 1,
    payment_holder_first_name VARCHAR(80) NULL,
    payment_holder_last_name VARCHAR(80) NULL,
    payment_holder_tax_code VARCHAR(32) NULL,
    additional_notes TEXT NULL,
    admin_notes TEXT NULL,
    contract_code VARCHAR(64) NULL,
    client_code VARCHAR(64) NULL,
    last_status_change DATETIME NULL,
    metadata JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_opportunities_category (category),
    INDEX idx_opportunities_status (status_code),
    INDEX idx_opportunities_provider (provider_id),
    INDEX idx_opportunities_collaborator (collaborator_id),
    INDEX idx_opportunities_email (customer_email),
    CONSTRAINT fk_opportunities_status FOREIGN KEY (status_code) REFERENCES opportunity_statuses(code) ON DELETE RESTRICT,
    CONSTRAINT fk_opportunities_provider FOREIGN KEY (provider_id) REFERENCES opportunity_providers(id) ON DELETE RESTRICT,
    CONSTRAINT fk_opportunities_offer FOREIGN KEY (offer_id) REFERENCES opportunity_offers(id) ON DELETE SET NULL,
    CONSTRAINT fk_opportunities_collaborator FOREIGN KEY (collaborator_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_opportunities_manager FOREIGN KEY (managed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS opportunity_files (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    opportunity_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    checksum CHAR(64) NULL,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_opportunity_files_opportunity (opportunity_id),
    CONSTRAINT fk_opportunity_files_opportunity FOREIGN KEY (opportunity_id) REFERENCES opportunities(id) ON DELETE CASCADE,
    CONSTRAINT fk_opportunity_files_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clienti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ragione_sociale VARCHAR(160) NOT NULL DEFAULT '',
    nome VARCHAR(80) NOT NULL,
    cognome VARCHAR(80) NOT NULL,
    cf_piva VARCHAR(32) NULL,
    email VARCHAR(160) NULL,
    telefono VARCHAR(40) NULL,
    indirizzo VARCHAR(255) NULL,
    note TEXT NULL,
    morosita_flag TINYINT(1) NOT NULL DEFAULT 0,
    morosita_score ENUM('ok','attenzione','bloccato') NOT NULL DEFAULT 'ok',
    morosita_note TEXT NULL,
    morosita_aggiornato_il DATETIME NULL,
    morosita_fonte VARCHAR(100) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_clienti_ragione (ragione_sociale),
    INDEX idx_clienti_morosita_score (morosita_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS collaborator_customers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    collaborator_id INT UNSIGNED NOT NULL,
    customer_id INT UNSIGNED NOT NULL,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_collaborator_customer (collaborator_id, customer_id),
    INDEX idx_collaborator_customers_collaborator (collaborator_id),
    INDEX idx_collaborator_customers_customer (customer_id),
    CONSTRAINT fk_collaborator_customers_user FOREIGN KEY (collaborator_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_collaborator_customers_customer FOREIGN KEY (customer_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS entrate_uscite (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NULL,
    tipo_movimento ENUM('Entrata','Uscita') NOT NULL DEFAULT 'Entrata',
    descrizione VARCHAR(180) NOT NULL,
    listino_voce VARCHAR(180) NULL,
    listino_costo_rivenditore DECIMAL(10,2) NULL,
    listino_costo_cliente DECIMAL(10,2) NULL,
    listino_margine DECIMAL(10,2) NULL,
    riferimento VARCHAR(80) NULL,
    metodo VARCHAR(60) NOT NULL DEFAULT 'Bonifico',
    stato VARCHAR(40) NOT NULL DEFAULT 'In lavorazione',
    importo DECIMAL(10,2) NOT NULL DEFAULT 0,
    quantita INT UNSIGNED NOT NULL DEFAULT 1,
    prezzo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    data_scadenza DATE NULL,
    data_pagamento DATE NULL,
    note TEXT NULL,
    allegato_path VARCHAR(255) NULL,
    allegato_hash CHAR(64) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_entrate_uscite_cliente (cliente_id),
    INDEX idx_entrate_uscite_stato (stato),
    INDEX idx_entrate_uscite_scadenza (data_scadenza),
    INDEX idx_entrate_uscite_pagamento (data_pagamento),
    INDEX idx_entrate_uscite_cliente_stato (cliente_id, stato),
    INDEX idx_entrate_uscite_tipo (tipo_movimento),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS customer_morosita_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    esito ENUM('ok','attenzione','bloccato') NOT NULL,
    fonte VARCHAR(100) NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (customer_id) REFERENCES clienti(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_morosita_customer (customer_id),
    INDEX idx_morosita_esito (esito)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_appuntamenti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    titolo VARCHAR(160) NOT NULL,
    tipo_servizio VARCHAR(80) NOT NULL,
    responsabile VARCHAR(120) NULL,
    luogo VARCHAR(160) NULL,
    stato VARCHAR(40) NOT NULL DEFAULT 'Programmato',
    data_inizio DATETIME NOT NULL,
    data_fine DATETIME NULL,
    reminder_sent_at DATETIME NULL,
    google_event_id VARCHAR(128) NULL,
    google_event_synced_at DATETIME NULL,
    google_event_sync_error TEXT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_appuntamenti_cliente (cliente_id),
    INDEX idx_appuntamenti_stato (stato),
    INDEX idx_appuntamenti_responsabile (responsabile),
    INDEX idx_appuntamenti_inizio (data_inizio),
    INDEX idx_appuntamenti_reminder_sent (reminder_sent_at),
    INDEX idx_appuntamenti_google_event (google_event_id),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_aci_pratiche (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NULL,
    tipo_pratica VARCHAR(80) NOT NULL,
    tipo_pratica_altro VARCHAR(190) NULL,
    stato VARCHAR(40) NOT NULL DEFAULT 'Aperta',
    targa VARCHAR(20) NULL,
    telaio VARCHAR(40) NULL,
    intestatario VARCHAR(160) NULL,
    protocollo VARCHAR(80) NULL,
    persona_giuridica TINYINT(1) NOT NULL DEFAULT 0,
    intestatario_nome VARCHAR(150) NULL,
    intestatario_cognome VARCHAR(150) NULL,
    intestatario_codice_fiscale VARCHAR(32) NULL,
    intestatario_data_nascita DATE NULL,
    intestatario_luogo_nascita VARCHAR(150) NULL,
    intestatario_residenza TEXT NULL,
    intestatario_email VARCHAR(190) NULL,
    intestatario_telefono VARCHAR(30) NULL,
    intestatario_ragione_sociale VARCHAR(190) NULL,
    intestatario_partita_iva VARCHAR(32) NULL,
    intestatario_codice_fiscale_giuridico VARCHAR(32) NULL,
    intestatario_sede_legale TEXT NULL,
    veicolo_tipo VARCHAR(40) NULL,
    veicolo_marca VARCHAR(80) NULL,
    veicolo_modello VARCHAR(80) NULL,
    veicolo_anno_immatricolazione YEAR NULL,
    veicolo_alimentazione VARCHAR(40) NULL,
    veicolo_potenza_kw DECIMAL(8,2) NULL,
    veicolo_classe_ambientale VARCHAR(40) NULL,
    venditore_nome VARCHAR(190) NULL,
    venditore_codice_fiscale VARCHAR(32) NULL,
    venditore_indirizzo TEXT NULL,
    acquirente_nome VARCHAR(190) NULL,
    acquirente_codice_fiscale VARCHAR(32) NULL,
    acquirente_indirizzo TEXT NULL,
    venditore_acquirente_coincidono TINYINT(1) NOT NULL DEFAULT 0,
    diritti_aci DECIMAL(10,2) NOT NULL DEFAULT 0,
    imposta_bollo DECIMAL(10,2) NOT NULL DEFAULT 0,
    emolumenti DECIMAL(10,2) NOT NULL DEFAULT 0,
    compenso_agenzia DECIMAL(10,2) NOT NULL DEFAULT 0,
    totale DECIMAL(10,2) NOT NULL DEFAULT 0,
    metodo_pagamento VARCHAR(40) NULL,
    consenso_privacy TINYINT(1) NOT NULL DEFAULT 0,
    consenso_aci TINYINT(1) NOT NULL DEFAULT 0,
    consenso_veridicita TINYINT(1) NOT NULL DEFAULT 0,
    note_interne TEXT NULL,
    assigned_to INT UNSIGNED NULL,
    data_apertura DATE NULL,
    data_scadenza DATE NULL,
    data_chiusura DATE NULL,
    costo DECIMAL(10,2) NOT NULL DEFAULT 0,
    note TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_aci_cliente (cliente_id),
    INDEX idx_aci_stato (stato),
    INDEX idx_aci_tipo (tipo_pratica),
    INDEX idx_aci_targa (targa),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_aci_allegati (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pratica_id INT UNSIGNED NOT NULL,
    categoria VARCHAR(50) NOT NULL DEFAULT 'generico',
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aci_allegati_pratica (pratica_id),
    FOREIGN KEY (pratica_id) REFERENCES servizi_aci_pratiche(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_operatori (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL,
    soglia_riordino INT UNSIGNED NOT NULL DEFAULT 10,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_servizi_express_operatori_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_vendite (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    entrata_uscita_id INT UNSIGNED NULL,
    totale DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    iva DECIMAL(5,2) NOT NULL DEFAULT 22.00,
    sconto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    metodo_pagamento VARCHAR(60) NOT NULL DEFAULT 'Contanti',
    stato ENUM('Completata','Annullata','Rimborsata') NOT NULL DEFAULT 'Completata',
    data_vendita DATETIME NOT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_servizi_express_vendite_cliente (cliente_id),
    INDEX idx_servizi_express_vendite_user (user_id),
    INDEX idx_servizi_express_vendite_data (data_vendita),
    CONSTRAINT fk_servizi_express_vendite_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE,
    CONSTRAINT fk_servizi_express_vendite_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_servizi_express_vendite_movimento FOREIGN KEY (entrata_uscita_id) REFERENCES entrate_uscite(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_iccid_stock (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operatore_id INT UNSIGNED NOT NULL,
    vendita_id INT UNSIGNED NULL,
    iccid VARCHAR(32) NOT NULL,
    stato ENUM('InStock','Reserved','Sold') NOT NULL DEFAULT 'InStock',
    note TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_servizi_express_iccid_stock_iccid (iccid),
    INDEX idx_servizi_express_iccid_stock_operatore (operatore_id),
    INDEX idx_servizi_express_iccid_stock_stato (stato),
    CONSTRAINT fk_servizi_express_iccid_stock_operatore FOREIGN KEY (operatore_id) REFERENCES servizi_express_operatori(id) ON DELETE RESTRICT,
    CONSTRAINT fk_servizi_express_iccid_stock_vendita FOREIGN KEY (vendita_id) REFERENCES servizi_express_vendite(id) ON DELETE SET NULL,
    CONSTRAINT fk_servizi_express_iccid_stock_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_vendita_righe (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendita_id INT UNSIGNED NOT NULL,
    operatore_id INT UNSIGNED NULL,
    iccid_stock_id INT UNSIGNED NULL,
    prodotto_id INT UNSIGNED NULL,
    offerta_id INT UNSIGNED NULL,
    tipo ENUM('sim','prodotto','servizio') NOT NULL DEFAULT 'sim',
    descrizione VARCHAR(255) NOT NULL,
    quantita INT UNSIGNED NOT NULL DEFAULT 1,
    prezzo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    aliquota_iva DECIMAL(5,2) NOT NULL DEFAULT 22.00,
    totale_riga DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    sconto_riga DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_servizi_express_vendita_righe_vendita (vendita_id),
    CONSTRAINT fk_servizi_express_vendita_righe_vendita FOREIGN KEY (vendita_id) REFERENCES servizi_express_vendite(id) ON DELETE CASCADE,
    CONSTRAINT fk_servizi_express_vendita_righe_operatore FOREIGN KEY (operatore_id) REFERENCES servizi_express_operatori(id) ON DELETE SET NULL,
    CONSTRAINT fk_servizi_express_vendita_righe_iccid FOREIGN KEY (iccid_stock_id) REFERENCES servizi_express_iccid_stock(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_prodotti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    sku VARCHAR(100) NULL,
    imei VARCHAR(100) NULL,
    categoria VARCHAR(100) NULL,
    prezzo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    aliquota_iva DECIMAL(5,2) NOT NULL DEFAULT 22.00,
    stock_quantita INT UNSIGNED NOT NULL DEFAULT 0,
    soglia_riordino INT UNSIGNED NOT NULL DEFAULT 0,
    note TEXT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_servizi_express_prodotti_sku (sku),
    UNIQUE KEY uniq_servizi_express_prodotti_imei (imei)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_offerte (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operatore_id INT UNSIGNED NULL,
    titolo VARCHAR(150) NOT NULL,
    descrizione TEXT NULL,
    prezzo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    stato ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    valid_from DATE NULL,
    valid_to DATE NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_servizi_express_offerte_operatore (operatore_id),
    INDEX idx_servizi_express_offerte_stato (stato),
    CONSTRAINT fk_servizi_express_offerte_operatore FOREIGN KEY (operatore_id) REFERENCES servizi_express_operatori(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_richieste (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    prodotto_id INT UNSIGNED NULL,
    titolo VARCHAR(150) NOT NULL,
    tipo_richiesta ENUM('Purchase','Reservation','Deposit','Installment','Support') NOT NULL DEFAULT 'Purchase',
    stato ENUM('Pending','InReview','Confirmed','Completed','Cancelled','Declined') NOT NULL DEFAULT 'Pending',
    importo_acconto DECIMAL(10,2) NULL,
    numero_rate INT UNSIGNED NULL,
    metodo_pagamento VARCHAR(60) NULL,
    data_desiderata DATE NULL,
    note_cliente TEXT NULL,
    nota_interna TEXT NULL,
    gestita_da INT UNSIGNED NULL,
    gestita_il DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_servizi_express_richieste_cliente (cliente_id),
    INDEX idx_servizi_express_richieste_prodotto (prodotto_id),
    INDEX idx_servizi_express_richieste_stato (stato),
    CONSTRAINT fk_servizi_express_richieste_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE,
    CONSTRAINT fk_servizi_express_richieste_prodotto FOREIGN KEY (prodotto_id) REFERENCES servizi_express_prodotti(id) ON DELETE SET NULL,
    CONSTRAINT fk_servizi_express_richieste_user FOREIGN KEY (gestita_da) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_portale_clienti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pickup_customer_id INT NOT NULL,
    cliente_id INT UNSIGNED NOT NULL,
    stato ENUM('active','disabled') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_servizi_express_portale_pickup (pickup_customer_id),
    UNIQUE KEY uniq_servizi_express_portale_cliente (cliente_id),
    INDEX idx_servizi_express_portale_stato (stato),
    CONSTRAINT fk_servizi_express_portale_pickup FOREIGN KEY (pickup_customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_servizi_express_portale_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_express_portal_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    public_reference CHAR(26) NOT NULL,
    customer_id INT NOT NULL,
    business_customer_id INT UNSIGNED NOT NULL,
    request_id INT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    amount_cents INT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL,
    title VARCHAR(160) NOT NULL,
    description TEXT NULL,
    stripe_session_id VARCHAR(255) DEFAULT NULL,
    stripe_payment_intent_id VARCHAR(255) DEFAULT NULL,
    metadata_json LONGTEXT NULL,
    entrata_uscita_id INT UNSIGNED NULL,
    error_message TEXT NULL,
    paid_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_servizi_express_portal_payments_reference (public_reference),
    UNIQUE KEY uniq_servizi_express_portal_payments_session (stripe_session_id),
    KEY idx_servizi_express_portal_payments_customer (customer_id, status),
    KEY idx_servizi_express_portal_payments_request (request_id),
    CONSTRAINT fk_servizi_express_portal_payments_customer FOREIGN KEY (customer_id) REFERENCES pickup_customers(id) ON DELETE CASCADE,
    CONSTRAINT fk_servizi_express_portal_payments_business_customer FOREIGN KEY (business_customer_id) REFERENCES clienti(id) ON DELETE CASCADE,
    CONSTRAINT fk_servizi_express_portal_payments_request FOREIGN KEY (request_id) REFERENCES servizi_express_richieste(id) ON DELETE SET NULL,
    CONSTRAINT fk_servizi_express_portal_payments_movement FOREIGN KEY (entrata_uscita_id) REFERENCES entrate_uscite(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fedelta_movimenti (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    tipo_movimento VARCHAR(120) NOT NULL,
    descrizione VARCHAR(255) NOT NULL,
    punti INT NOT NULL DEFAULT 0,
    saldo_post_movimento INT NOT NULL DEFAULT 0,
    ricompensa VARCHAR(160) NULL,
    operatore VARCHAR(120) NULL,
    note TEXT NULL,
    data_movimento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_fedelta_cliente (cliente_id),
    INDEX idx_fedelta_cliente_data (cliente_id, data_movimento),
    INDEX idx_fedelta_tipo (tipo_movimento),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS daily_financial_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_date DATE NOT NULL UNIQUE,
    total_entrate DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_uscite DECIMAL(12,2) NOT NULL DEFAULT 0,
    saldo DECIMAL(12,2) NOT NULL DEFAULT 0,
    file_path VARCHAR(255) NOT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_daily_reports_date (report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_digitali (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    tipo VARCHAR(60) NOT NULL,
    stato VARCHAR(40) NOT NULL,
    note TEXT NULL,
    documento_path VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_digitali_cliente (cliente_id),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS curriculum (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    titolo VARCHAR(150) NOT NULL,
    professional_summary TEXT NULL,
    key_competences TEXT NULL,
    digital_competences TEXT NULL,
    driving_license VARCHAR(120) NULL,
    additional_information TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Bozza',
    last_generated_at DATETIME NULL,
    generated_file VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_curriculum_cliente (cliente_id),
    INDEX idx_curriculum_status (status),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS curriculum_experiences (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_id INT UNSIGNED NOT NULL,
    role_title VARCHAR(160) NOT NULL,
    employer VARCHAR(160) NOT NULL,
    city VARCHAR(120) NULL,
    country VARCHAR(120) NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 0,
    description TEXT NULL,
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (curriculum_id) REFERENCES curriculum(id) ON DELETE CASCADE,
    INDEX idx_curriculum_experiences_curriculum (curriculum_id),
    INDEX idx_curriculum_experiences_order (curriculum_id, ordering)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS curriculum_education (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    institution VARCHAR(180) NOT NULL,
    city VARCHAR(120) NULL,
    country VARCHAR(120) NULL,
    start_date DATE NOT NULL,
    end_date DATE NULL,
    qualification_level VARCHAR(120) NULL,
    description TEXT NULL,
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (curriculum_id) REFERENCES curriculum(id) ON DELETE CASCADE,
    INDEX idx_curriculum_education_curriculum (curriculum_id),
    INDEX idx_curriculum_education_order (curriculum_id, ordering)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS curriculum_languages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_id INT UNSIGNED NOT NULL,
    language VARCHAR(120) NOT NULL,
    overall_level VARCHAR(60) NOT NULL,
    listening VARCHAR(60) NULL,
    reading VARCHAR(60) NULL,
    interaction VARCHAR(60) NULL,
    production VARCHAR(60) NULL,
    writing VARCHAR(60) NULL,
    certification VARCHAR(160) NULL,
    FOREIGN KEY (curriculum_id) REFERENCES curriculum(id) ON DELETE CASCADE,
    INDEX idx_curriculum_languages_curriculum (curriculum_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS curriculum_skills (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    curriculum_id INT UNSIGNED NOT NULL,
    category VARCHAR(80) NOT NULL,
    skill VARCHAR(160) NOT NULL,
    level VARCHAR(60) NULL,
    description TEXT NULL,
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (curriculum_id) REFERENCES curriculum(id) ON DELETE CASCADE,
    INDEX idx_curriculum_skills_curriculum (curriculum_id),
    INDEX idx_curriculum_skills_order (curriculum_id, ordering)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spedizioni (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT UNSIGNED NOT NULL,
    tipo_spedizione VARCHAR(80) NOT NULL,
    mittente VARCHAR(160) NOT NULL,
    destinatario VARCHAR(160) NOT NULL,
    tracking_number VARCHAR(120) NULL,
    stato VARCHAR(40) NOT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_spedizioni_cliente (cliente_id),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titolo VARCHAR(180) NOT NULL,
    descrizione TEXT NULL,
    cliente_id INT UNSIGNED NULL,
    modulo VARCHAR(120) NOT NULL DEFAULT 'Altro',
    stato VARCHAR(40) NOT NULL DEFAULT 'Bozza',
    owner_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_documents_cliente (cliente_id),
    INDEX idx_documents_modulo (modulo),
    INDEX idx_documents_stato (stato),
    INDEX idx_documents_updated_at (updated_at),
    FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL,
    FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    versione INT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_document_versions_document (document_id),
    INDEX idx_document_versions_uploaded_by (uploaded_by),
    UNIQUE KEY uniq_document_version (document_id, versione),
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(120) NOT NULL UNIQUE,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_tag_map (
    document_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (document_id, tag_id),
    FOREIGN KEY (document_id) REFERENCES documents(id) ON DELETE CASCADE,
    FOREIGN KEY (tag_id) REFERENCES document_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posta_telematica_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel ENUM('email','pec') NOT NULL DEFAULT 'email',
    recipient_email VARCHAR(190) NOT NULL,
    subject VARCHAR(200) NOT NULL,
    body LONGTEXT NOT NULL,
    status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    error_message TEXT NULL,
    message_id_header VARCHAR(255) NULL,
    pec_receipt_invio_at DATETIME NULL,
    pec_receipt_accettazione_at DATETIME NULL,
    pec_receipt_consegna_at DATETIME NULL,
    pec_receipt_invio_body MEDIUMTEXT NULL,
    pec_receipt_accettazione_body MEDIUMTEXT NULL,
    pec_receipt_consegna_body MEDIUMTEXT NULL,
    pec_receipt_invio_message_id BIGINT UNSIGNED NULL,
    pec_receipt_accettazione_message_id BIGINT UNSIGNED NULL,
    pec_receipt_consegna_message_id BIGINT UNSIGNED NULL,
    cliente_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_posta_telematica_channel (channel),
    INDEX idx_posta_telematica_status (status),
    INDEX idx_posta_telematica_recipient (recipient_email),
    INDEX idx_posta_telematica_cliente (cliente_id),
    INDEX idx_posta_telematica_created_by (created_by),
    CONSTRAINT fk_posta_telematica_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL,
    CONSTRAINT fk_posta_telematica_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posta_telematica_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_posta_telematica_attachment_message (message_id),
    CONSTRAINT fk_posta_telematica_attachment_message FOREIGN KEY (message_id) REFERENCES posta_telematica_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posta_telematica_pec_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uid BIGINT UNSIGNED NOT NULL,
    mailbox VARCHAR(190) NOT NULL DEFAULT 'INBOX',
    message_id_header VARCHAR(255) NULL,
    sender VARCHAR(255) NULL,
    subject VARCHAR(255) NULL,
    received_at DATETIME NULL,
    seen TINYINT(1) NOT NULL DEFAULT 0,
    snippet TEXT NULL,
    body MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_posta_telematica_pec_uid (uid, mailbox),
    INDEX idx_posta_telematica_pec_seen (seen),
    INDEX idx_posta_telematica_pec_received (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS posta_telematica_pec_attachments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    message_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_posta_telematica_pec_attachment_message (message_id),
    CONSTRAINT fk_posta_telematica_pec_attachment_message FOREIGN KEY (message_id) REFERENCES posta_telematica_pec_messages(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
    FOREIGN KEY (list_id) REFERENCES email_lists(id) ON DELETE CASCADE,
    FOREIGN KEY (subscriber_id) REFERENCES email_subscribers(id) ON DELETE CASCADE
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
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
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
    FOREIGN KEY (template_id) REFERENCES email_templates(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE
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
    FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (subscriber_id) REFERENCES email_subscribers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_campaign_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    recipient_id INT UNSIGNED NOT NULL,
    event_type ENUM('open','click','bounce','complaint','unsubscribe') NOT NULL,
    meta JSON NULL,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_campaign_events_type (campaign_id, event_type),
    FOREIGN KEY (campaign_id) REFERENCES email_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES email_campaign_recipients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tipologie_pratiche (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(160) NOT NULL,
    categoria ENUM('CAF','Patronato') NOT NULL,
    campi_personalizzati JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_tipologie_pratiche_nome_categoria (nome, categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS utenti_caf_patronato (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    nome VARCHAR(80) NOT NULL,
    cognome VARCHAR(80) NOT NULL,
    email VARCHAR(160) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    ruolo ENUM('CAF','Patronato') NOT NULL,
    attivo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_utenti_caf_patronato_email (email),
    UNIQUE KEY uniq_utenti_caf_patronato_user (user_id),
    CONSTRAINT fk_utenti_caf_patronato_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pratiche_stati (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(60) NOT NULL UNIQUE,
    nome VARCHAR(160) NOT NULL,
    colore VARCHAR(32) NOT NULL DEFAULT 'slate',
    ordering SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pratiche (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titolo VARCHAR(200) NOT NULL,
    descrizione TEXT NULL,
    tipo_pratica INT UNSIGNED NOT NULL,
    categoria ENUM('CAF','Patronato') NOT NULL,
    stato VARCHAR(60) NOT NULL,
    data_creazione DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    data_aggiornamento DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    id_admin INT UNSIGNED NOT NULL,
    id_utente_caf_patronato INT UNSIGNED NULL,
    tracking_code VARCHAR(32) NULL UNIQUE,
    tracking_steps JSON NULL,
    allegati JSON NULL,
    note TEXT NULL,
    metadati JSON NULL,
    scadenza DATE NULL,
    cliente_id INT UNSIGNED NULL,
    CONSTRAINT fk_pratiche_tipologie FOREIGN KEY (tipo_pratica) REFERENCES tipologie_pratiche(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_admin FOREIGN KEY (id_admin) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_operatore FOREIGN KEY (id_utente_caf_patronato) REFERENCES utenti_caf_patronato(id) ON DELETE SET NULL,
    CONSTRAINT fk_pratiche_cliente FOREIGN KEY (cliente_id) REFERENCES clienti(id) ON DELETE SET NULL,
    INDEX idx_pratiche_categoria (categoria),
    INDEX idx_pratiche_stato (stato),
    INDEX idx_pratiche_tipo (tipo_pratica),
    INDEX idx_pratiche_utente (id_utente_caf_patronato),
    INDEX idx_pratiche_scadenza (scadenza)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pratiche_documenti (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pratica_id BIGINT UNSIGNED NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by INT UNSIGNED NULL,
    uploaded_operatore_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pratiche_documenti_pratica FOREIGN KEY (pratica_id) REFERENCES pratiche(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_documenti_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pratiche_documenti_operatore FOREIGN KEY (uploaded_operatore_id) REFERENCES utenti_caf_patronato(id) ON DELETE SET NULL,
    INDEX idx_pratiche_documenti_pratica (pratica_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pratiche_note (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pratica_id BIGINT UNSIGNED NOT NULL,
    autore_user_id INT UNSIGNED NULL,
    autore_operatore_id INT UNSIGNED NULL,
    contenuto TEXT NOT NULL,
    visibile_admin TINYINT(1) NOT NULL DEFAULT 1,
    visibile_operatore TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pratiche_note_pratica FOREIGN KEY (pratica_id) REFERENCES pratiche(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_note_user FOREIGN KEY (autore_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pratiche_note_operatore FOREIGN KEY (autore_operatore_id) REFERENCES utenti_caf_patronato(id) ON DELETE SET NULL,
    INDEX idx_pratiche_note_pratica (pratica_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pratiche_eventi (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pratica_id BIGINT UNSIGNED NOT NULL,
    evento VARCHAR(120) NOT NULL,
    messaggio TEXT NULL,
    payload JSON NULL,
    creato_da INT UNSIGNED NULL,
    creato_operatore_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pratiche_eventi_pratica FOREIGN KEY (pratica_id) REFERENCES pratiche(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_eventi_user FOREIGN KEY (creato_da) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_pratiche_eventi_operatore FOREIGN KEY (creato_operatore_id) REFERENCES utenti_caf_patronato(id) ON DELETE SET NULL,
    INDEX idx_pratiche_eventi_pratica (pratica_id),
    INDEX idx_pratiche_eventi_evento (evento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pratiche_notifiche (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pratica_id BIGINT UNSIGNED NOT NULL,
    destinatario_user_id INT UNSIGNED NULL,
    destinatario_operatore_id INT UNSIGNED NULL,
    tipo VARCHAR(60) NOT NULL,
    messaggio VARCHAR(255) NOT NULL,
    channel ENUM('dashboard','email','both') NOT NULL DEFAULT 'dashboard',
    stato ENUM('nuova','letta') NOT NULL DEFAULT 'nuova',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    CONSTRAINT fk_pratiche_notifiche_pratica FOREIGN KEY (pratica_id) REFERENCES pratiche(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_notifiche_user FOREIGN KEY (destinatario_user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_pratiche_notifiche_operatore FOREIGN KEY (destinatario_operatore_id) REFERENCES utenti_caf_patronato(id) ON DELETE CASCADE,
    INDEX idx_pratiche_notifiche_destinatario_user (destinatario_user_id),
    INDEX idx_pratiche_notifiche_destinatario_operatore (destinatario_operatore_id),
    INDEX idx_pratiche_notifiche_stato (stato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_conversations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    session_id VARCHAR(64) NOT NULL,
    question TEXT NOT NULL,
    answer TEXT NOT NULL,
    context TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_conversations_user_id (user_id),
    INDEX idx_ai_conversations_session_id (session_id),
    INDEX idx_ai_conversations_created_at (created_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ai_user_preferences (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    preference_key VARCHAR(100) NOT NULL,
    preference_value TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_preference (user_id, preference_key),
    INDEX idx_ai_user_preferences_user_id (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_visure_cr_pratiche (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo_visura VARCHAR(30) NOT NULL,
    stato VARCHAR(30) NOT NULL DEFAULT 'Inviata',
    nome VARCHAR(150) DEFAULT NULL,
    cognome VARCHAR(150) DEFAULT NULL,
    codice_fiscale VARCHAR(32) DEFAULT NULL,
    data_nascita DATE DEFAULT NULL,
    luogo_nascita VARCHAR(150) DEFAULT NULL,
    provincia_nascita VARCHAR(10) DEFAULT NULL,
    residenza TEXT DEFAULT NULL,
    email VARCHAR(190) DEFAULT NULL,
    telefono VARCHAR(30) DEFAULT NULL,
    ragione_sociale VARCHAR(190) DEFAULT NULL,
    partita_iva VARCHAR(32) DEFAULT NULL,
    codice_fiscale_giuridico VARCHAR(32) DEFAULT NULL,
    forma_giuridica VARCHAR(80) DEFAULT NULL,
    sede_legale TEXT DEFAULT NULL,
    email_aziendale VARCHAR(190) DEFAULT NULL,
    telefono_aziendale VARCHAR(30) DEFAULT NULL,
    richiedente_stesso TINYINT(1) NOT NULL DEFAULT 1,
    richiedente_nome VARCHAR(150) DEFAULT NULL,
    richiedente_cognome VARCHAR(150) DEFAULT NULL,
    richiedente_codice_fiscale VARCHAR(32) DEFAULT NULL,
    richiedente_qualifica VARCHAR(120) DEFAULT NULL,
    consenso_privacy TINYINT(1) NOT NULL DEFAULT 0,
    consenso_richiesta TINYINT(1) NOT NULL DEFAULT 0,
    consenso_veridicita TINYINT(1) NOT NULL DEFAULT 0,
    note TEXT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    updated_by INT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_visure_cr_stato (stato),
    INDEX idx_visure_cr_tipo (tipo_visura),
    INDEX idx_visure_cr_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS servizi_visure_cr_allegati (
    id INT AUTO_INCREMENT PRIMARY KEY,
    richiesta_id INT NOT NULL,
    categoria VARCHAR(50) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT NOT NULL DEFAULT 0,
    mime_type VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_visure_cr_allegati_richiesta (richiesta_id),
    CONSTRAINT fk_visure_cr_allegati_richiesta
        FOREIGN KEY (richiesta_id) REFERENCES servizi_visure_cr_pratiche(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Utente amministratore di default
INSERT INTO users (username, email, password, ruolo)
SELECT 'admin', 'admin@example.com', '$2y$12$2xHnRJMh1zsmC1WmvMRGcuE9zraFMvx6bMpiKFFitvolG/GpNZgb2', 'Admin'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

-- Configurazioni di base
INSERT INTO configurazioni (chiave, valore) VALUES
    ('ragione_sociale', 'Coresuite Business SRL'),
    ('indirizzo', 'Via Plinio 72, Milano'),
    ('telefono', '+39 02 1234567'),
    ('email', 'info@coresuitebusiness.com'),
    ('ui_theme', 'navy')
ON DUPLICATE KEY UPDATE valore = VALUES(valore);
