-- Consenti movimenti senza cliente associato
ALTER TABLE entrate_uscite
    MODIFY cliente_id INT UNSIGNED NULL;
