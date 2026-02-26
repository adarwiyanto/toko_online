-- WEB migration to retire products usage in landing/cart/checkout/orders.

CREATE TABLE IF NOT EXISTS inv_stocks (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  inv_product_id INT NOT NULL,
  qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_inv_stocks_branch_product (branch_id, inv_product_id),
  INDEX idx_inv_stocks_branch (branch_id),
  INDEX idx_inv_stocks_product (inv_product_id),
  CONSTRAINT fk_inv_stocks_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  CONSTRAINT fk_inv_stocks_product FOREIGN KEY (inv_product_id) REFERENCES inv_products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

ALTER TABLE orders ADD COLUMN branch_id INT NULL AFTER customer_id;
ALTER TABLE orders ADD COLUMN stock_deducted_at DATETIME NULL AFTER completed_at;

ALTER TABLE order_items ADD COLUMN inv_product_id INT NULL AFTER order_id;

UPDATE order_items oi
JOIN products p ON p.id = oi.product_id
JOIN inv_products ip ON ip.sku = p.sku
SET oi.inv_product_id = ip.id
WHERE oi.inv_product_id IS NULL;

ALTER TABLE order_items MODIFY inv_product_id INT NOT NULL;

-- Drop old FK + old column if present.
SET @fk_name := (
  SELECT kcu.CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE kcu
  WHERE kcu.TABLE_SCHEMA = DATABASE()
    AND kcu.TABLE_NAME = 'order_items'
    AND kcu.COLUMN_NAME = 'product_id'
    AND kcu.REFERENCED_TABLE_NAME = 'products'
  LIMIT 1
);
SET @sql := IF(@fk_name IS NULL, 'SELECT 1', CONCAT('ALTER TABLE order_items DROP FOREIGN KEY ', @fk_name));
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

ALTER TABLE order_items ADD CONSTRAINT fk_order_items_inv_product FOREIGN KEY (inv_product_id) REFERENCES inv_products(id) ON DELETE CASCADE;
ALTER TABLE order_items DROP COLUMN product_id;

ALTER TABLE loyalty_rewards ADD COLUMN inv_product_id INT NULL AFTER id;
UPDATE loyalty_rewards lr
JOIN products p ON p.id = lr.product_id
JOIN inv_products ip ON ip.sku = p.sku
SET lr.inv_product_id = ip.id
WHERE lr.inv_product_id IS NULL;
ALTER TABLE loyalty_rewards MODIFY inv_product_id INT NOT NULL;

SET @fk_name2 := (
  SELECT kcu.CONSTRAINT_NAME
  FROM information_schema.KEY_COLUMN_USAGE kcu
  WHERE kcu.TABLE_SCHEMA = DATABASE()
    AND kcu.TABLE_NAME = 'loyalty_rewards'
    AND kcu.COLUMN_NAME = 'product_id'
    AND kcu.REFERENCED_TABLE_NAME = 'products'
  LIMIT 1
);
SET @sql2 := IF(@fk_name2 IS NULL, 'SELECT 1', CONCAT('ALTER TABLE loyalty_rewards DROP FOREIGN KEY ', @fk_name2));
PREPARE stmt2 FROM @sql2; EXECUTE stmt2; DEALLOCATE PREPARE stmt2;

ALTER TABLE loyalty_rewards ADD CONSTRAINT fk_loyalty_rewards_inv_product FOREIGN KEY (inv_product_id) REFERENCES inv_products(id) ON DELETE CASCADE;
ALTER TABLE loyalty_rewards DROP COLUMN product_id;
