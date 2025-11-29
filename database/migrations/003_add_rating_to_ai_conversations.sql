-- Aggiunge colonna rating alla tabella ai_conversations per feedback
ALTER TABLE ai_conversations ADD COLUMN rating TINYINT NULL COMMENT 'Rating da 1 a 5, NULL se non valutato';