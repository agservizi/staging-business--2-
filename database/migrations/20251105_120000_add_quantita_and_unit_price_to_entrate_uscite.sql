ALTER TABLE entrate_uscite
    ADD COLUMN quantita INT UNSIGNED NOT NULL DEFAULT 1 AFTER importo,
    ADD COLUMN prezzo_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER quantita;

UPDATE entrate_uscite
SET prezzo_unitario = importo;
