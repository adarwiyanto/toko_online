<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';

/**
 * Inventory module helper (Produk & Inventory).
 * Modul ini berdiri sendiri dan hanya menghitung stok dari inv_stock_ledger.
 */
function require_role(array $roles): void {
  require_login();
  $u = current_user();
  $role = (string)($u['role'] ?? '');
  if (!in_array($role, $roles, true)) {
    http_response_code(403);
    exit('Forbidden');
  }
}

function inventory_now(): string {
  return date('Y-m-d H:i:s');
}

function inventory_ensure_tables(): void {
  db()->exec("CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    branch_type ENUM('toko','dapur') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_branch_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  try {
    $stmt = db()->query("SHOW COLUMNS FROM users LIKE 'branch_id'");
    if (!(bool)$stmt->fetch()) {
      db()->exec("ALTER TABLE users ADD COLUMN branch_id INT NULL AFTER role");
      db()->exec("CREATE INDEX idx_users_branch_id ON users (branch_id)");
    }
  } catch (Throwable $e) {
    // Diamkan agar halaman tidak gagal total.
  }

  try {
    db()->exec("ALTER TABLE users ADD CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL");
  } catch (Throwable $e) {
    // Sudah ada FK / gagal aman.
  }

  db()->exec("CREATE TABLE IF NOT EXISTS inv_products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(100) NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    unit VARCHAR(50) NOT NULL,
    type VARCHAR(30) NOT NULL DEFAULT 'RAW',
    cost_price DECIMAL(18,2) NULL,
    sell_price DECIMAL(18,2) NULL,
    image_path VARCHAR(255) NULL,
    is_hidden TINYINT(1) NOT NULL DEFAULT 0,
    is_deleted TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_inv_products_hidden_deleted (is_hidden, is_deleted)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  try {
    $stmt = db()->query("SHOW COLUMNS FROM inv_products LIKE 'image_path'");
    if (!(bool)$stmt->fetch()) {
      db()->exec("ALTER TABLE inv_products ADD COLUMN image_path VARCHAR(255) NULL AFTER sell_price");
    }
  } catch (Throwable $e) {
    // Diamkan agar halaman tidak gagal total.
  }

  try {
    $stmt = db()->query("SHOW COLUMNS FROM inv_products LIKE 'audience'");
    if (!(bool)$stmt->fetch()) {
      db()->exec("ALTER TABLE inv_products ADD COLUMN audience ENUM('toko','dapur') NOT NULL DEFAULT 'toko' AFTER type");
    }
  } catch (Throwable $e) {
    // Diamkan agar halaman tidak gagal total.
  }

  try {
    $stmt = db()->query("SHOW COLUMNS FROM inv_products LIKE 'kitchen_group'");
    if (!(bool)$stmt->fetch()) {
      db()->exec("ALTER TABLE inv_products ADD COLUMN kitchen_group ENUM('raw','finished') NULL AFTER audience");
    }
  } catch (Throwable $e) {
    // Diamkan agar halaman tidak gagal total.
  }

  db()->exec("CREATE TABLE IF NOT EXISTS inv_stock_ledger (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  db()->exec("CREATE TABLE IF NOT EXISTS inv_opening_stock (
    id INT AUTO_INCREMENT PRIMARY KEY,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_inv_opening_stock_created_at (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  db()->exec("CREATE TABLE IF NOT EXISTS inv_opening_stock_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    opening_id INT NOT NULL,
    product_id INT NOT NULL,
    qty DECIMAL(18,3) NOT NULL,
    INDEX idx_inv_opening_stock_items_opening (opening_id),
    INDEX idx_inv_opening_stock_items_product (product_id),
    CONSTRAINT fk_inv_opening_stock_items_opening FOREIGN KEY (opening_id) REFERENCES inv_opening_stock(id),
    CONSTRAINT fk_inv_opening_stock_items_product FOREIGN KEY (product_id) REFERENCES inv_products(id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  db()->exec("CREATE TABLE IF NOT EXISTS inv_stock_opname (
    id INT AUTO_INCREMENT PRIMARY KEY,
    opname_date DATE NOT NULL,
    status ENUM('DRAFT','POSTED') NOT NULL DEFAULT 'DRAFT',
    created_by INT NOT NULL,
    posted_by INT NULL,
    created_at DATETIME NOT NULL,
    posted_at DATETIME NULL,
    INDEX idx_inv_stock_opname_date (opname_date),
    INDEX idx_inv_stock_opname_status (status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  db()->exec("CREATE TABLE IF NOT EXISTS inv_stock_opname_items (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  db()->exec("CREATE TABLE IF NOT EXISTS inv_purchases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    purchase_date DATE NOT NULL,
    supplier_name VARCHAR(160) NULL,
    note VARCHAR(255) NULL,
    created_by INT NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_inv_purchases_date (purchase_date)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  db()->exec("CREATE TABLE IF NOT EXISTS inv_purchase_items (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  ensure_products_hidden_column();
  ensure_products_inventory_ref_column();

  db()->exec("CREATE TABLE IF NOT EXISTS inv_kitchen_transfers (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  db()->exec("CREATE TABLE IF NOT EXISTS inv_kitchen_transfer_items (
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
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function ensure_products_hidden_column(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $stmt = db()->query("SHOW COLUMNS FROM products LIKE 'is_hidden'");
    if (!(bool)$stmt->fetch()) {
      db()->exec("ALTER TABLE products ADD COLUMN is_hidden TINYINT(1) NOT NULL DEFAULT 0 AFTER is_best_seller");
    }
  } catch (Throwable $e) {
    // Diamkan agar halaman tidak gagal total.
  }
}

function ensure_products_inventory_ref_column(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    $stmt = db()->query("SHOW COLUMNS FROM products LIKE 'inventory_product_id'");
    if (!(bool)$stmt->fetch()) {
      db()->exec("ALTER TABLE products ADD COLUMN inventory_product_id INT NULL AFTER image_path");
      db()->exec("CREATE UNIQUE INDEX uq_products_inventory_product ON products (inventory_product_id)");
    }
  } catch (Throwable $e) {
    try {
      db()->exec("CREATE UNIQUE INDEX uq_products_inventory_product ON products (inventory_product_id)");
    } catch (Throwable $ignored) {
      // Diamkan agar halaman tidak gagal total.
    }
  }
}

function inventory_sync_finished_product_to_pos(array $inventoryProduct): void {
  $inventoryId = (int)($inventoryProduct['id'] ?? 0);
  if ($inventoryId <= 0) {
    return;
  }

  $isFinished = strtoupper((string)($inventoryProduct['type'] ?? 'RAW')) === 'FINISHED';
  $isActive = (int)($inventoryProduct['is_deleted'] ?? 0) === 0 && (int)($inventoryProduct['is_hidden'] ?? 0) === 0;

  $stmtExisting = db()->prepare("SELECT id FROM products WHERE inventory_product_id=? LIMIT 1");
  $stmtExisting->execute([$inventoryId]);
  $existing = $stmtExisting->fetch();

  if ($isFinished) {
    $name = (string)($inventoryProduct['name'] ?? '');
    $price = (float)($inventoryProduct['sell_price'] ?? 0);
    $imagePath = $inventoryProduct['image_path'] ?? null;
    $isHidden = $isActive ? 0 : 1;

    if ($existing) {
      $stmt = db()->prepare("UPDATE products SET name=?, price=?, image_path=?, is_hidden=? WHERE id=?");
      $stmt->execute([$name, $price, $imagePath, $isHidden, (int)$existing['id']]);
    } else {
      $stmt = db()->prepare("INSERT INTO products (name, category, is_best_seller, price, image_path, inventory_product_id, is_hidden) VALUES (?, '', 0, ?, ?, ?, ?)");
      $stmt->execute([$name, $price, $imagePath, $inventoryId, $isHidden]);
    }
    return;
  }

  if ($existing) {
    $stmt = db()->prepare("UPDATE products SET is_hidden=1 WHERE id=?");
    $stmt->execute([(int)$existing['id']]);
  }
}

function inventory_stock_map(array $productIds): array {
  if (count($productIds) === 0) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($productIds), '?'));
  $stmt = db()->prepare("SELECT product_id, COALESCE(SUM(qty_in - qty_out),0) AS stock_qty FROM inv_stock_ledger WHERE product_id IN ($placeholders) GROUP BY product_id");
  $stmt->execute(array_values($productIds));
  $rows = $stmt->fetchAll();
  $map = [];
  foreach ($rows as $row) {
    $map[(int)$row['product_id']] = (float)$row['stock_qty'];
  }
  return $map;
}

function inventory_stock_by_product(int $productId): float {
  $stmt = db()->prepare("SELECT COALESCE(SUM(qty_in - qty_out),0) AS stock_qty FROM inv_stock_ledger WHERE product_id=?");
  $stmt->execute([$productId]);
  $row = $stmt->fetch();
  return (float)($row['stock_qty'] ?? 0);
}

function inventory_set_flash(string $type, string $message): void {
  start_secure_session();
  $_SESSION['inventory_flash'] = ['type' => $type, 'message' => $message];
}

function inventory_get_flash(): ?array {
  start_secure_session();
  $flash = $_SESSION['inventory_flash'] ?? null;
  unset($_SESSION['inventory_flash']);
  return is_array($flash) ? $flash : null;
}
