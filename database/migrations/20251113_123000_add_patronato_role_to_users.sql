ALTER TABLE users
  MODIFY ruolo ENUM('Admin','Manager','Operatore','Patronato','Cliente','Collaboratore') NOT NULL DEFAULT 'Operatore';

UPDATE users
SET ruolo = 'Patronato'
WHERE username IN ('vincenzocinque')
  AND (ruolo = '' OR ruolo IS NULL);
