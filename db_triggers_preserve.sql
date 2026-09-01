-- POJISTKA: nezruš EAN a nakupni_cena při UPDATE (volitelné)
-- Spusť v phpMyAdmin -> SQL (MySQL 5.7)

DROP TRIGGER IF EXISTS bu_order_items_preserve_ean_price;

DELIMITER //
CREATE TRIGGER bu_order_items_preserve_ean_price
BEFORE UPDATE ON order_items
FOR EACH ROW
BEGIN
  IF NEW.EAN IS NULL OR NEW.EAN = '' THEN
    SET NEW.EAN = OLD.EAN;
  END IF;

  IF NEW.nakupni_cena IS NULL OR NEW.nakupni_cena = 0 THEN
    SET NEW.nakupni_cena = OLD.nakupni_cena;
  END IF;
END//
DELIMITER ;
