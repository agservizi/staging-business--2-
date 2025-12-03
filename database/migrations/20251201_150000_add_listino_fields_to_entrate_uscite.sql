ALTER TABLE entrate_uscite
    ADD COLUMN listino_voce VARCHAR(180) NULL AFTER descrizione,
    ADD COLUMN listino_costo_rivenditore DECIMAL(10,2) NULL AFTER listino_voce,
    ADD COLUMN listino_costo_cliente DECIMAL(10,2) NULL AFTER listino_costo_rivenditore,
    ADD COLUMN listino_margine DECIMAL(10,2) NULL AFTER listino_costo_cliente;
