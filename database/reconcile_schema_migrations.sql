-- =============================================================================
-- RECONCILE schema_migrations (phpMyAdmin)
-- NON importare pending_migrations_bundle.sql su un DB già in produzione!
--
-- 1) Esegui QUESTO file in phpMyAdmin (seleziona il database business)
-- 2) Poi esegui solo le migrazioni ancora mancanti con:
--      php tools/migrate.php   (terminale Hostinger, consigliato)
--    oppure importa database/only_pending_migrations.sql (generato dopo il passo 1
--    se hai le credenziali in .env: php tools/build_reconcile_sql.php --pending-only)
-- =============================================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    migration VARCHAR(255) NOT NULL UNIQUE,
    executed_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 20251019_120000_create_login_audit.sql (tabella login_audit)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251019_120000_create_login_audit.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'login_audit'
LIMIT 1;

-- 20251019_183500_add_tipo_movimento_to_pagamenti.sql (colonna entrate_uscite.tipo_movimento)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251019_183500_add_tipo_movimento_to_pagamenti.sql', NOW()
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'entrate_uscite'
  AND COLUMN_NAME = 'tipo_movimento'
LIMIT 1;

-- 20251019_210000_replace_servizi_ricariche_with_appuntamenti.sql (tabella servizi_appuntamenti)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251019_210000_replace_servizi_ricariche_with_appuntamenti.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'servizi_appuntamenti'
LIMIT 1;

-- 20251020_093000_add_reminder_to_servizi_appuntamenti.sql (colonna servizi_appuntamenti.reminder_sent_at)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251020_093000_add_reminder_to_servizi_appuntamenti.sql', NOW()
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'servizi_appuntamenti'
  AND COLUMN_NAME = 'reminder_sent_at'
LIMIT 1;

-- 20251020_130000_create_daily_financial_reports.sql (tabella daily_financial_reports)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251020_130000_create_daily_financial_reports.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'daily_financial_reports'
LIMIT 1;

-- 20251020_160000_create_energia_contratti.sql (tabella energia_contratti)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251020_160000_create_energia_contratti.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'energia_contratti'
LIMIT 1;

-- 20251020_160500_add_documents_to_anpr_pratiche.sql (colonna anpr_pratiche.delega_path)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251020_160500_add_documents_to_anpr_pratiche.sql', NOW()
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'anpr_pratiche'
  AND COLUMN_NAME = 'delega_path'
LIMIT 1;

-- 20251020_160500_create_energia_email_history.sql (tabella energia_email_history)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251020_160500_create_energia_email_history.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'energia_email_history'
LIMIT 1;

-- 20251020_162000_add_spid_and_delivery_to_anpr_pratiche.sql (colonna anpr_pratiche.spid_verificato_at)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251020_162000_add_spid_and_delivery_to_anpr_pratiche.sql', NOW()
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'anpr_pratiche'
  AND COLUMN_NAME = 'spid_verificato_at'
LIMIT 1;

-- 20251020_170000_add_delega_signature_fields.sql (colonna anpr_pratiche.delega_firma_status)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251020_170000_add_delega_signature_fields.sql', NOW()
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'anpr_pratiche'
  AND COLUMN_NAME = 'delega_firma_status'
LIMIT 1;

-- 20251020_170500_create_anpr_pratiche.sql (tabella anpr_pratiche)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251020_170500_create_anpr_pratiche.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'anpr_pratiche'
LIMIT 1;

-- 20251020_173500_add_delega_generata_auto_flag.sql (colonna anpr_pratiche.delega_generata_auto)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251020_173500_add_delega_generata_auto_flag.sql', NOW()
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'anpr_pratiche'
  AND COLUMN_NAME = 'delega_generata_auto'
LIMIT 1;

-- 20251021_153000_add_mfa_columns_to_users.sql (colonna users.mfa_secret)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251021_153000_add_mfa_columns_to_users.sql', NOW()
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'users'
  AND COLUMN_NAME = 'mfa_secret'
LIMIT 1;

-- 20251022_101500_replace_telefonia_with_curriculum.sql (tabella curriculum)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251022_101500_replace_telefonia_with_curriculum.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'curriculum'
LIMIT 1;

-- 20251027_140000_create_customer_portal_tables.sql (tabella pickup_customers)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251027_140000_create_customer_portal_tables.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pickup_customers'
LIMIT 1;

-- 20251028_170000_create_servizi_web_progetti.sql (tabella servizi_web_progetti)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251028_170000_create_servizi_web_progetti.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'servizi_web_progetti'
LIMIT 1;

-- 20251029_101500_alter_servizi_web_progetti_add_hostinger_fields.sql (colonna servizi_web_progetti.dominio_richiesto)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251029_101500_alter_servizi_web_progetti_add_hostinger_fields.sql', NOW()
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'servizi_web_progetti'
  AND COLUMN_NAME = 'dominio_richiesto'
LIMIT 1;

-- 20251030_100000_create_cie_prenotazioni.sql (tabella cie_prenotazioni)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251030_100000_create_cie_prenotazioni.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'cie_prenotazioni'
LIMIT 1;

-- 20251030_110500_add_audit_columns_to_cie_prenotazioni.sql (colonna cie_prenotazioni.created_by)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251030_110500_add_audit_columns_to_cie_prenotazioni.sql', NOW()
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'cie_prenotazioni'
  AND COLUMN_NAME = 'created_by'
LIMIT 1;

-- 20251030_181500_add_google_calendar_columns.sql (colonna servizi_appuntamenti.google_event_id)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251030_181500_add_google_calendar_columns.sql', NOW()
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'servizi_appuntamenti'
  AND COLUMN_NAME = 'google_event_id'
LIMIT 1;

-- 20251102_090000_create_remember_tokens_table.sql (tabella remember_tokens)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251102_090000_create_remember_tokens_table.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'remember_tokens'
LIMIT 1;

-- 20251102_110500_create_servizi_visure_tables.sql (tabella servizi_visure)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251102_110500_create_servizi_visure_tables.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'servizi_visure'
LIMIT 1;

-- 20251103_101500_create_servizi_telegrammi_tables.sql (tabella servizi_telegrammi)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251103_101500_create_servizi_telegrammi_tables.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'servizi_telegrammi'
LIMIT 1;

-- 20251105_120000_add_quantita_and_unit_price_to_entrate_uscite.sql (colonna entrate_uscite.quantita)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251105_120000_add_quantita_and_unit_price_to_entrate_uscite.sql', NOW()
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'entrate_uscite'
  AND COLUMN_NAME = 'quantita'
LIMIT 1;

-- 20251106_160000_update_cie_schema.sql (tabella cie_prenotazioni_notifiche)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251106_160000_update_cie_schema.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'cie_prenotazioni_notifiche'
LIMIT 1;

-- 20251106_200000_create_brt_tables.sql (tabella brt_shipments)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251106_200000_create_brt_tables.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'brt_shipments'
LIMIT 1;

-- 20251107_090000_add_brt_manifests.sql (tabella brt_manifests)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251107_090000_add_brt_manifests.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'brt_manifests'
LIMIT 1;

-- 20251107_150000_create_brt_saved_recipients.sql (tabella brt_saved_recipients)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251107_150000_create_brt_saved_recipients.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'brt_saved_recipients'
LIMIT 1;

-- 20251108_100000_create_email_marketing_tables.sql (tabella email_subscribers)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251108_100000_create_email_marketing_tables.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'email_subscribers'
LIMIT 1;

-- 20251109_090000_create_fedelta_movimenti.sql (tabella fedelta_movimenti)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251109_090000_create_fedelta_movimenti.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'fedelta_movimenti'
LIMIT 1;

-- 20251109_091000_create_documents_tables.sql (tabella documents)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251109_091000_create_documents_tables.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'documents'
LIMIT 1;

-- 20251109_170000_create_brt_logs.sql (tabella brt_logs)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251109_170000_create_brt_logs.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'brt_logs'
LIMIT 1;

-- 20251110_100000_create_pickup_customer_brt_shipments.sql (tabella pickup_customer_brt_shipments)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251110_100000_create_pickup_customer_brt_shipments.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pickup_customer_brt_shipments'
LIMIT 1;

-- 20251110_120000_create_pickup_portal_payments.sql (tabella pickup_portal_payments)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251110_120000_create_pickup_portal_payments.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pickup_portal_payments'
LIMIT 1;

-- 20251112_100500_create_caf_patronato_tables.sql (tabella tipologie_pratiche)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251112_100500_create_caf_patronato_tables.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'tipologie_pratiche'
LIMIT 1;

-- 20251113_090000_create_caf_patronato_pratiche.sql (tabella caf_patronato_pratiche)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251113_090000_create_caf_patronato_pratiche.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'caf_patronato_pratiche'
LIMIT 1;

-- 20251113_091500_create_caf_patronato_allegati.sql (tabella caf_patronato_allegati)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251113_091500_create_caf_patronato_allegati.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'caf_patronato_allegati'
LIMIT 1;

-- 20251115_120000_add_tracking_columns_to_pratiche.sql (colonna pratiche.tracking_code)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251115_120000_add_tracking_columns_to_pratiche.sql', NOW()
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pratiche'
  AND COLUMN_NAME = 'tracking_code'
LIMIT 1;

-- 20251115_150000_update_pratiche_recenti_view.sql (tabella pratiche_recenti)
INSERT IGNORE INTO schema_migrations (migration, executed_at)
SELECT '20251115_150000_update_pratiche_recenti_view.sql', NOW()
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'pratiche_recenti'
LIMIT 1;

-- Migrazioni senza probe automatico (verifica con php tools/migrate.php):
--   - 20251019_200500_make_cliente_id_nullable_in_entrate_uscite.sql
--   - 20251020_150000_create_user_notification_states.sql
--   - 20251113_123000_add_patronato_role_to_users.sql

SELECT migration, executed_at FROM schema_migrations ORDER BY migration;
