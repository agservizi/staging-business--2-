ALTER TABLE opportunity_providers
    MODIFY category ENUM('telefonia','luce','gas','paytv') NOT NULL;

ALTER TABLE opportunities
    MODIFY category ENUM('telefonia','luce','gas','paytv') NOT NULL;
