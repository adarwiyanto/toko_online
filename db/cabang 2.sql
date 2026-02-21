-- Patch multi cabang inventory (additive, safe migration)
-- charset/collation utf8mb4_unicode_ci
SET NAMES utf8mb4;

START TRANSACTION;

-- 1) Ensure default branch exists
INSERT INTO branches (name, branch_type, is_active, created_at, updated_at)
SELECT 'Pusat', 'toko', 1, NOW(), NOW()
WHERE NOT EXISTS (SELECT 1 FROM branches WHERE name = 'Pusat');

SET @default_branch_id := (SELECT id FROM branches WHERE name='Pusat' ORDER BY id ASC LIMIT 1);

-- 2) Add branch_id columns (NULL first)
ALTER TABLE inv_stock_ledger ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER id;
ALTER TABLE inv_stock_opname ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER id;
ALTER TABLE inv_opening_stock ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER id;
ALTER TABLE inv_purchases ADD COLUMN IF NOT EXISTS branch_id INT NULL AFTER id;

-- 3) Backfill generic legacy rows
UPDATE inv_stock_ledger SET branch_id=@default_branch_id WHERE branch_id IS NULL;
UPDATE inv_stock_opname SET branch_id=@default_branch_id WHERE branch_id IS NULL;
UPDATE inv_opening_stock SET branch_id=@default_branch_id WHERE branch_id IS NULL;
UPDATE inv_purchases SET branch_id=@default_branch_id WHERE branch_id IS NULL;

-- 4) Improve transfer-ledger branch mapping
UPDATE inv_stock_ledger l
JOIN inv_kitchen_transfers t ON t.id = l.ref_id
SET l.branch_id = t.source_branch_id
WHERE l.ref_type IN ('KITCHEN_SEND')
  AND l.ref_id IS NOT NULL
  AND t.source_branch_id IS NOT NULL;

UPDATE inv_stock_ledger l
JOIN inv_kitchen_transfers t ON t.id = l.ref_id
SET l.branch_id = t.target_branch_id
WHERE l.ref_type IN ('KITCHEN_RECEIVE')
  AND l.ref_id IS NOT NULL
  AND t.target_branch_id IS NOT NULL;

-- 5) Add indexes
CREATE INDEX IF NOT EXISTS idx_inv_stock_ledger_branch_product_created ON inv_stock_ledger (branch_id, product_id, created_at);
CREATE INDEX IF NOT EXISTS idx_inv_stock_opname_branch_date ON inv_stock_opname (branch_id, opname_date);
CREATE INDEX IF NOT EXISTS idx_inv_purchases_branch_date ON inv_purchases (branch_id, purchase_date);

-- 6) Foreign keys
ALTER TABLE inv_stock_ledger ADD CONSTRAINT fk_inv_stock_ledger_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE inv_stock_opname ADD CONSTRAINT fk_inv_stock_opname_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE inv_opening_stock ADD CONSTRAINT fk_inv_opening_stock_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;
ALTER TABLE inv_purchases ADD CONSTRAINT fk_inv_purchases_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT;

-- 7) lock down not-null after backfill
ALTER TABLE inv_stock_ledger MODIFY branch_id INT NOT NULL;
ALTER TABLE inv_stock_opname MODIFY branch_id INT NOT NULL;
ALTER TABLE inv_opening_stock MODIFY branch_id INT NOT NULL;
ALTER TABLE inv_purchases MODIFY branch_id INT NOT NULL;

COMMIT;
