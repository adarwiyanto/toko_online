-- Modul Produk & Inventory (stok only)
-- Additive schema only (tidak mengubah tabel legacy)

CREATE TABLE IF NOT EXISTS branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(50) NULL,
  name VARCHAR(120) NOT NULL,
  address TEXT NULL,
  branch_type ENUM('toko','dapur') NOT NULL DEFAULT 'toko',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_branch_name (name),
  UNIQUE KEY uq_branch_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(100) NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(50) NOT NULL,
  type VARCHAR(30) NOT NULL DEFAULT 'RAW',
  audience ENUM('toko','dapur') NOT NULL DEFAULT 'toko',
  kitchen_group ENUM('raw','finished') NULL,
  cost_price DECIMAL(18,2) NULL,
  sell_price DECIMAL(18,2) NULL,
  image_path VARCHAR(255) NULL,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_inv_products_hidden_deleted (is_hidden, is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_stock_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NULL,
  product_id INT NOT NULL,
  ref_type VARCHAR(30) NOT NULL,
  ref_id INT NULL,
  qty_in DECIMAL(18,3) NOT NULL DEFAULT 0,
  qty_out DECIMAL(18,3) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_inv_stock_ledger_product_created (product_id, created_at),
  INDEX idx_inv_stock_ledger_branch (branch_id),
  CONSTRAINT fk_inv_stock_ledger_product FOREIGN KEY (product_id) REFERENCES inv_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_opening_stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_inv_opening_stock_created_at (created_at),
  INDEX idx_inv_opening_stock_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_opening_stock_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  opening_id INT NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(18,3) NOT NULL,
  INDEX idx_inv_opening_stock_items_opening (opening_id),
  INDEX idx_inv_opening_stock_items_product (product_id),
  CONSTRAINT fk_inv_opening_stock_items_opening FOREIGN KEY (opening_id) REFERENCES inv_opening_stock(id),
  CONSTRAINT fk_inv_opening_stock_items_product FOREIGN KEY (product_id) REFERENCES inv_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_stock_opname (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NULL,
  opname_date DATE NOT NULL,
  status ENUM('DRAFT','POSTED') NOT NULL DEFAULT 'DRAFT',
  created_by INT NOT NULL,
  posted_by INT NULL,
  created_at DATETIME NOT NULL,
  posted_at DATETIME NULL,
  INDEX idx_inv_stock_opname_date (opname_date),
  INDEX idx_inv_stock_opname_status (status),
  INDEX idx_inv_stock_opname_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_stock_opname_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  opname_id INT NOT NULL,
  product_id INT NOT NULL,
  system_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  counted_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  diff_qty DECIMAL(18,3) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  INDEX idx_inv_stock_opname_items_opname (opname_id),
  INDEX idx_inv_stock_opname_items_product (product_id),
  CONSTRAINT fk_inv_stock_opname_items_opname FOREIGN KEY (opname_id) REFERENCES inv_stock_opname(id),
  CONSTRAINT fk_inv_stock_opname_items_product FOREIGN KEY (product_id) REFERENCES inv_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_purchases (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NULL,
  purchase_date DATE NOT NULL,
  supplier_name VARCHAR(160) NULL,
  note VARCHAR(255) NULL,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_inv_purchases_date (purchase_date),
  INDEX idx_inv_purchases_branch (branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_purchase_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  purchase_id INT NOT NULL,
  product_id INT NOT NULL,
  qty DECIMAL(18,3) NOT NULL,
  unit_cost DECIMAL(18,2) NOT NULL,
  line_total DECIMAL(18,2) NOT NULL,
  INDEX idx_inv_purchase_items_purchase (purchase_id),
  INDEX idx_inv_purchase_items_product (product_id),
  CONSTRAINT fk_inv_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES inv_purchases(id),
  CONSTRAINT fk_inv_purchase_items_product FOREIGN KEY (product_id) REFERENCES inv_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branch_product_price (
  id INT AUTO_INCREMENT PRIMARY KEY,
  branch_id INT NOT NULL,
  product_id INT NOT NULL,
  sell_price DECIMAL(12,2) NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_branch_product_price (branch_id, product_id),
  INDEX idx_branch_product_price_product (product_id),
  CONSTRAINT fk_branch_product_price_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
  CONSTRAINT fk_branch_product_price_product FOREIGN KEY (product_id) REFERENCES inv_products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_kitchen_transfers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transfer_date DATE NOT NULL,
  source_branch_id INT NULL,
  target_branch_id INT NULL,
  status ENUM('DRAFT','SENT','RECEIVED') NOT NULL DEFAULT 'SENT',
  note VARCHAR(255) NULL,
  created_by INT NOT NULL,
  sent_at DATETIME NOT NULL,
  received_by INT NULL,
  received_at DATETIME NULL,
  INDEX idx_inv_kitchen_transfers_date (transfer_date),
  INDEX idx_inv_kitchen_transfers_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_kitchen_transfer_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  transfer_id INT NOT NULL,
  product_id INT NOT NULL,
  qty_sent DECIMAL(18,3) NOT NULL,
  qty_received DECIMAL(18,3) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  INDEX idx_inv_kitchen_transfer_items_transfer (transfer_id),
  INDEX idx_inv_kitchen_transfer_items_product (product_id),
  CONSTRAINT fk_inv_kitchen_transfer_items_transfer FOREIGN KEY (transfer_id) REFERENCES inv_kitchen_transfers(id),
  CONSTRAINT fk_inv_kitchen_transfer_items_product FOREIGN KEY (product_id) REFERENCES inv_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catatan migrasi legacy (jalankan hanya jika kolom belum ada):
-- ALTER TABLE users ADD COLUMN branch_id INT NULL AFTER role;
-- CREATE INDEX idx_users_branch_id ON users (branch_id);
-- ALTER TABLE users ADD CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL;
-- ALTER TABLE products ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER is_best_seller;
-- ALTER TABLE products ADD COLUMN inventory_product_id INT NULL AFTER image_path;
-- CREATE UNIQUE INDEX uq_products_inventory_product ON products (inventory_product_id);
