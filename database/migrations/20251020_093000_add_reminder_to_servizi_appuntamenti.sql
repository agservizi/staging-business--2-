ALTER TABLE servizi_appuntamenti
    ADD COLUMN IF NOT EXISTS reminder_sent_at DATETIME NULL AFTER data_fine;

CREATE INDEX IF NOT EXISTS idx_appuntamenti_reminder_sent ON servizi_appuntamenti (reminder_sent_at);
