-- Tabella morosita per gestore
CREATE TABLE IF NOT EXISTS `customer_morosita_providers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `customer_id` INT UNSIGNED NOT NULL,
  `provider_id` INT UNSIGNED NOT NULL,
  `esito` VARCHAR(20) NOT NULL DEFAULT 'ok',
  `note` TEXT NULL,
  `fonte` VARCHAR(50) NOT NULL DEFAULT 'verifica-manuale',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_customer_provider` (`customer_id`,`provider_id`),
  KEY `idx_provider` (`provider_id`),
  KEY `idx_customer` (`customer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
