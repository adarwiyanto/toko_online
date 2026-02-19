-- Modul Produk & Inventory (stok only)
-- Additive schema only (tidak mengubah tabel legacy)

CREATE TABLE IF NOT EXISTS inv_products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(100) NULL UNIQUE,
  name VARCHAR(255) NOT NULL,
  unit VARCHAR(50) NOT NULL,
  type VARCHAR(30) NOT NULL DEFAULT 'RAW',
  cost_price DECIMAL(18,2) NULL,
  sell_price DECIMAL(18,2) NULL,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_inv_products_hidden_deleted (is_hidden, is_deleted)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_stock_ledger (
  id INT AUTO_INCREMENT PRIMARY KEY,
  product_id INT NOT NULL,
  ref_type VARCHAR(30) NOT NULL,
  ref_id INT NULL,
  qty_in DECIMAL(18,3) NOT NULL DEFAULT 0,
  qty_out DECIMAL(18,3) NOT NULL DEFAULT 0,
  note VARCHAR(255) NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_inv_stock_ledger_product_created (product_id, created_at),
  CONSTRAINT fk_inv_stock_ledger_product FOREIGN KEY (product_id) REFERENCES inv_products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS inv_opening_stock (
  id INT AUTO_INCREMENT PRIMARY KEY,
  created_by INT NOT NULL,
  created_at DATETIME NOT NULL,
  INDEX idx_inv_opening_stock_created_at (created_at)
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
  opname_date DATE NOT NULL,
  status ENUM('DRAFT','POSTED') NOT NULL DEFAULT 'DRAFT',
  created_by INT NOT NULL,
  posted_by INT NULL,
  created_at DATETIME NOT NULL,
  posted_at DATETIME NULL,
  INDEX idx_inv_stock_opname_date (opname_date),
  INDEX idx_inv_stock_opname_status (status)
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
