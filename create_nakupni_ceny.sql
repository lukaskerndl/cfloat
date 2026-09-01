-- Nakupni_ceny (SIMPLE) - 1 řádek na 1 EAN (unikátní napříč dodavateli)
CREATE TABLE IF NOT EXISTS `Nakupni_ceny` (
  `ean` VARCHAR(50) NOT NULL,
  `price` DECIMAL(20,8) NULL,   -- cena za kus (už přepočtená dle pravidel dodavatele)
  `currency` CHAR(3) NOT NULL DEFAULT 'CZK',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ean`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
