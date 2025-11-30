ALTER TABLE servizi_appuntamenti
    ADD COLUMN google_event_id VARCHAR(128) NULL AFTER reminder_sent_at,
    ADD COLUMN google_event_synced_at DATETIME NULL AFTER google_event_id,
    ADD COLUMN google_event_sync_error TEXT NULL AFTER google_event_synced_at,
    ADD INDEX idx_appuntamenti_google_event (google_event_id);
