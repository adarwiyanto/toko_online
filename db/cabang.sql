-- Migration aman fitur cabang + alur dapur/toko tanpa merusak transaksi lama.

CREATE TABLE IF NOT EXISTS branches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  branch_type ENUM('toko','dapur') NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_branch_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN branch_id INT NULL AFTER role;
CREATE INDEX idx_users_branch_id ON users (branch_id);
ALTER TABLE users ADD CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL;

ALTER TABLE user_invites ADD COLUMN branch_id INT NULL AFTER role;

ALTER TABLE inv_products ADD COLUMN audience ENUM('toko','dapur') NOT NULL DEFAULT 'toko' AFTER type;
ALTER TABLE inv_products ADD COLUMN kitchen_group ENUM('raw','finished') NULL AFTER audience;

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
