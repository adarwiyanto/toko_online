<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/security.php';

/**
 * Inventory module helper (Produk & Inventory).
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

function inventory_log_error(string $context, Throwable $e): void {
  $dir = __DIR__ . '/../logs';
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  $line = sprintf(
    "[%s] %s | %s in %s:%d\n",
    inventory_now(),
    $context,
    $e->getMessage(),
    $e->getFile(),
    $e->getLine()
  );
  @file_put_contents($dir . '/app.log', $line, FILE_APPEND);
}

function inventory_column_exists(string $table, string $column): bool {
  static $cache = [];
  $key = strtolower($table . '.' . $column);
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  $stmt = db()->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
  $stmt->execute([$column]);
  $cache[$key] = (bool)$stmt->fetch();
  return $cache[$key];
}

function inventory_constraint_exists(string $table, string $constraint): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? LIMIT 1";
  $stmt = db()->prepare($sql);
  $stmt->execute([$table, $constraint]);
  return (bool)$stmt->fetchColumn();
}

function inventory_index_exists(string $table, string $indexName): bool {
  $sql = "SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1";
  $stmt = db()->prepare($sql);
  $stmt->execute([$table, $indexName]);
  return (bool)$stmt->fetchColumn();
}

function inventory_create_index_if_missing(string $sqlCreate, string $table, string $indexName): void {
  try {
    if (!inventory_index_exists($table, $indexName)) {
      db()->exec($sqlCreate);
    }
  } catch (Throwable $e) {
    // Diamkan agar halaman tidak gagal total.
  }
}

function inventory_is_duplicate_key(Throwable $e): bool {
  if (!($e instanceof PDOException)) {
    return false;
  }
  return $e->getCode() === '23000';
}

function inventory_user_friendly_db_error(Throwable $e): string {
  if (inventory_is_duplicate_key($e)) {
    $message = strtolower((string)$e->getMessage());
    if (strpos($message, 'uq_branch_code') !== false || strpos($message, 'for key \'code\'') !== false) {
      return 'Kode cabang sudah dipakai.';
    }
    if (strpos($message, 'uq_branch_name') !== false || strpos($message, 'for key \'name\'') !== false) {
      return 'Nama cabang sudah dipakai.';
    }
    return 'Data sudah digunakan.';
  }

  return 'Terjadi kendala saat menyimpan data. Silakan coba lagi.';
}

function inventory_ensure_tables(): void {
  db()->exec("CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) NULL,
    name VARCHAR(120) NOT NULL,
    address TEXT NULL,
    branch_type ENUM('toko','dapur','adm') NOT NULL DEFAULT 'toko',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_branch_name (name),
    UNIQUE KEY uq_branch_code (code)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  try {
    if (!inventory_column_exists('branches', 'code')) {
      db()->exec("ALTER TABLE branches ADD COLUMN code VARCHAR(50) NULL AFTER id");
    }
    if (!inventory_column_exists('branches', 'address')) {
      db()->exec("ALTER TABLE branches ADD COLUMN address TEXT NULL AFTER name");
    }
    if (!inventory_column_exists('branches', 'branch_type')) {
      db()->exec("ALTER TABLE branches ADD COLUMN branch_type ENUM('toko','dapur','adm') NOT NULL DEFAULT 'toko' AFTER address");
    }
    db()->exec("ALTER TABLE branches MODIFY branch_type ENUM('toko','dapur','adm') NOT NULL DEFAULT 'toko'");
    if (!inventory_column_exists('branches', 'is_active')) {
      db()->exec("ALTER TABLE branches ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER branch_type");
    }
    if (!inventory_column_exists('branches', 'created_at')) {
      db()->exec("ALTER TABLE branches ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_active");
    }
    if (!inventory_column_exists('branches', 'updated_at')) {
      db()->exec("ALTER TABLE branches ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
    }
    db()->exec("CREATE UNIQUE INDEX uq_branch_code ON branches (code)");
  } catch (Throwable $e) {
    // Safety net only.
  }

  try {
    if (!inventory_column_exists('users', 'branch_id')) {
      db()->exec("ALTER TABLE users ADD COLUMN branch_id INT NULL AFTER role");
      db()->exec("CREATE INDEX idx_users_branch_id ON users (branch_id)");
    }
  } catch (Throwable $e) {
    // Diamkan agar halaman tidak gagal total.
  }

  if (inventory_column_exists('users', 'branch_id')) {
    try {
      if (!inventory_constraint_exists('users', 'fk_users_branch')) {
        db()->exec("ALTER TABLE users ADD CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL");
      }
    } catch (Throwable $e) {
      // Sudah ada FK / gagal aman.
    }
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
    if (!inventory_column_exists('inv_products', 'image_path')) {
      db()->exec("ALTER TABLE inv_products ADD COLUMN image_path VARCHAR(255) NULL AFTER sell_price");
    }
  } catch (Throwable $e) {
    // Diamkan agar halaman tidak gagal total.
  }

  try {
    if (!inventory_column_exists('inv_products', 'audience')) {
      db()->exec("ALTER TABLE inv_products ADD COLUMN audience ENUM('toko','dapur') NOT NULL DEFAULT 'toko' AFTER type");
    }
  } catch (Throwable $e) {
    // Diamkan agar halaman tidak gagal total.
  }

  try {
    if (!inventory_column_exists('inv_products', 'kitchen_group')) {
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

  db()->exec("CREATE TABLE IF NOT EXISTS branch_product_price (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    sell_price DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_branch_product_price (branch_id, product_id),
    INDEX idx_branch_product_price_product (product_id),
    CONSTRAINT fk_branch_product_price_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_branch_product_price_product FOREIGN KEY (product_id) REFERENCES inv_products(id) ON DELETE CASCADE
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

  try {
    if (!inventory_column_exists('inv_stock_ledger', 'branch_id')) {
      db()->exec("ALTER TABLE inv_stock_ledger ADD COLUMN branch_id INT NULL AFTER id");
    }
    if (!inventory_column_exists('inv_stock_opname', 'branch_id')) {
      db()->exec("ALTER TABLE inv_stock_opname ADD COLUMN branch_id INT NULL AFTER id");
    }
    if (!inventory_column_exists('inv_opening_stock', 'branch_id')) {
      db()->exec("ALTER TABLE inv_opening_stock ADD COLUMN branch_id INT NULL AFTER id");
    }
    if (!inventory_column_exists('inv_purchases', 'branch_id')) {
      db()->exec("ALTER TABLE inv_purchases ADD COLUMN branch_id INT NULL AFTER id");
    }
  } catch (Throwable $e) {
    // Safety net only.
  }

  inventory_create_index_if_missing(
    "CREATE INDEX idx_inv_stock_ledger_branch_product_created ON inv_stock_ledger (branch_id, product_id, created_at)",
    'inv_stock_ledger',
    'idx_inv_stock_ledger_branch_product_created'
  );

  inventory_create_index_if_missing(
    "CREATE INDEX idx_inv_stock_ledger_branch_product ON inv_stock_ledger (branch_id, product_id)",
    'inv_stock_ledger',
    'idx_inv_stock_ledger_branch_product'
  );

  try {
    if (!inventory_constraint_exists('inv_stock_ledger', 'fk_inv_stock_ledger_branch')) {
      db()->exec("ALTER TABLE inv_stock_ledger ADD CONSTRAINT fk_inv_stock_ledger_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE RESTRICT");
    }
  } catch (Throwable $e) {
    // Diamkan bila gagal (engine/constraint existing data).
  }

  ensure_products_stok_barang_columns();
  ensure_stok_barang_table();
  ensure_orders_stock_deducted_column();
}

function ensure_products_stok_barang_columns(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    if (!inventory_column_exists('products', 'sku')) {
      db()->exec("ALTER TABLE products ADD COLUMN sku VARCHAR(100) NULL AFTER id");
    }
    if (!inventory_column_exists('products', 'unit')) {
      db()->exec("ALTER TABLE products ADD COLUMN unit VARCHAR(50) NULL AFTER name");
    }
    if (!inventory_column_exists('products', 'is_hidden_global')) {
      db()->exec("ALTER TABLE products ADD COLUMN is_hidden_global TINYINT(1) NOT NULL DEFAULT 0 AFTER is_hidden");
    }
  } catch (Throwable $e) {
    // Safety net only.
  }

  inventory_create_index_if_missing(
    "CREATE INDEX idx_products_sku ON products (sku)",
    'products',
    'idx_products_sku'
  );
}

function ensure_stok_barang_table(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  db()->exec("CREATE TABLE IF NOT EXISTS stok_barang (
    id INT AUTO_INCREMENT PRIMARY KEY,
    branch_id INT NOT NULL,
    product_id INT NOT NULL,
    qty DECIMAL(18,3) NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_stok_barang (branch_id, product_id),
    INDEX idx_stok_barang_branch (branch_id),
    INDEX idx_stok_barang_product (product_id),
    CONSTRAINT fk_stok_barang_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_stok_barang_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
  ) ENGINE=InnoDB");
}

function stok_barang_get_qty($branchId, $productId): float {
  ensure_stok_barang_table();
  $branchId = (int)$branchId;
  $productId = (int)$productId;
  if ($branchId <= 0 || $productId <= 0) {
    return 0.0;
  }
  $stmt = db()->prepare("SELECT qty FROM stok_barang WHERE branch_id=? AND product_id=? LIMIT 1");
  $stmt->execute([$branchId, $productId]);
  $row = $stmt->fetch();
  return (float)($row['qty'] ?? 0);
}

function stok_barang_set_qty($branchId, $productId, $qty): void {
  ensure_stok_barang_table();
  $branchId = (int)$branchId;
  $productId = (int)$productId;
  if ($branchId <= 0 || $productId <= 0) {
    return;
  }
  $stmt = db()->prepare("INSERT INTO stok_barang (branch_id, product_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty=VALUES(qty)");
  $stmt->execute([$branchId, $productId, (float)$qty]);
}

function stok_barang_add_qty($branchId, $productId, $delta): void {
  ensure_stok_barang_table();
  $branchId = (int)$branchId;
  $productId = (int)$productId;
  if ($branchId <= 0 || $productId <= 0) {
    return;
  }
  $stmt = db()->prepare("INSERT INTO stok_barang (branch_id, product_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)");
  $stmt->execute([$branchId, $productId, (float)$delta]);
}

function ensure_products_row_from_inv_product($invProductId): int {
  ensure_products_stok_barang_columns();
  $invProductId = (int)$invProductId;
  if ($invProductId <= 0) {
    return 0;
  }
  $stmtInv = db()->prepare("SELECT id, sku, name, unit FROM inv_products WHERE id=? LIMIT 1");
  $stmtInv->execute([$invProductId]);
  $inv = $stmtInv->fetch();
  if (!$inv) {
    return 0;
  }

  $skuRaw = trim((string)($inv['sku'] ?? ''));
  if ($skuRaw !== '') {
    $stmtP = db()->prepare("SELECT id FROM products WHERE sku=? LIMIT 1");
    $stmtP->execute([$skuRaw]);
    $found = $stmtP->fetch();
    if ($found) {
      return (int)$found['id'];
    }
  }

  $skuInsert = $skuRaw !== '' ? $skuRaw : ('INV-' . $invProductId);
  $stmtFallback = db()->prepare("SELECT id FROM products WHERE sku=? LIMIT 1");
  $stmtFallback->execute([$skuInsert]);
  $existing = $stmtFallback->fetch();
  if ($existing) {
    return (int)$existing['id'];
  }

  $stmtIns = db()->prepare("INSERT INTO products (sku, name, unit, is_hidden_global, created_at, updated_at) VALUES (?, ?, ?, 0, NOW(), NOW())");
  $stmtIns->execute([
    $skuInsert,
    (string)($inv['name'] ?? ''),
    (string)($inv['unit'] ?? ''),
  ]);
  return (int)db()->lastInsertId();
}

function ensure_orders_stock_deducted_column(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;
  try {
    if (!inventory_column_exists('orders', 'stock_deducted_at')) {
      db()->exec("ALTER TABLE orders ADD COLUMN stock_deducted_at DATETIME NULL AFTER completed_at");
    }
  } catch (Throwable $e) {
    // Safety net only.
  }
}

function inventory_default_sales_branch_id(): int {
  $stmt = db()->query("SELECT id FROM branches WHERE branch_type='toko' AND is_active=1 ORDER BY id ASC LIMIT 1");
  $row = $stmt->fetch();
  return (int)($row['id'] ?? 0);
}

function inventory_web_sales_branch_id(): int {
  $saved = (int)setting('web_sales_branch_id', '0');
  if ($saved > 0) {
    $stmt = db()->prepare("SELECT id FROM branches WHERE id=? AND branch_type='toko' AND is_active=1 LIMIT 1");
    $stmt->execute([$saved]);
    if ($stmt->fetch()) {
      return $saved;
    }
  }
  $defaultId = inventory_default_sales_branch_id();
  if ($defaultId > 0) {
    set_setting('web_sales_branch_id', (string)$defaultId);
  }
  return $defaultId;
}

function deduct_stok_barang_for_order_if_needed(int $orderId, int $branchId): bool {
  ensure_orders_stock_deducted_column();
  ensure_inv_stocks_table();
  if ($orderId <= 0 || $branchId <= 0) {
    return false;
  }

  $stmtOrder = db()->prepare("SELECT id, stock_deducted_at FROM orders WHERE id=? FOR UPDATE");
  $stmtOrder->execute([$orderId]);
  $order = $stmtOrder->fetch();
  if (!$order || !empty($order['stock_deducted_at'])) {
    return false;
  }

  $stmtItems = db()->prepare("SELECT inv_product_id, SUM(qty) AS qty_sum FROM order_items WHERE order_id=? GROUP BY inv_product_id");
  $stmtItems->execute([$orderId]);
  foreach ($stmtItems->fetchAll() as $row) {
    $pid = (int)($row['inv_product_id'] ?? 0);
    $qty = (float)($row['qty_sum'] ?? 0);
    if ($pid > 0 && abs($qty) > 0.0005) {
      $current = stock_get_qty($branchId, $pid);
      if ($current < $qty) {
        throw new RuntimeException('Stok tidak cukup untuk order #' . $orderId);
      }
      stock_add_qty($branchId, $pid, -1 * $qty);
    }
  }

  $stmtUpdate = db()->prepare("UPDATE orders SET stock_deducted_at=NOW() WHERE id=?");
  $stmtUpdate->execute([$orderId]);
  return true;
}



function ensure_products_hidden_column(): void {
  static $ensured = false;
  if ($ensured) return;
  $ensured = true;

  try {
    if (!inventory_column_exists('products', 'is_hidden')) {
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
    if (!inventory_column_exists('products', 'inventory_product_id')) {
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
  $branchId = inventory_active_branch_id();
  if ($branchId <= 0) {
    return [];
  }
  if (count($productIds) === 0) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($productIds), '?'));
  $stmt = db()->prepare("SELECT product_id, qty AS stock_qty FROM stok_barang WHERE branch_id=? AND product_id IN ($placeholders)");
  $params = array_merge([$branchId], array_values($productIds));
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
  $map = [];
  foreach ($rows as $row) {
    $map[(int)$row['product_id']] = (float)$row['stock_qty'];
  }
  return $map;
}

function inv_stock_map_for_branch(int $branchId, array $productIds): array {
  if ($branchId <= 0) {
    return [];
  }
  if (count($productIds) === 0) {
    return [];
  }
  $placeholders = implode(',', array_fill(0, count($productIds), '?'));
  $stmt = db()->prepare("SELECT product_id, qty AS stock_qty FROM stok_barang WHERE branch_id=? AND product_id IN ($placeholders)");
  $params = array_merge([$branchId], array_values($productIds));
  $stmt->execute($params);
  $rows = $stmt->fetchAll();
  $map = [];
  foreach ($rows as $row) {
    $map[(int)$row['product_id']] = (float)$row['stock_qty'];
  }
  return $map;
}

function inventory_stock_by_product(int $productId): float {
  $branchId = inventory_active_branch_id();
  return inv_get_stock($branchId, $productId);
}

function inv_get_stock(int $branchId, int $productId): float {
  return stock_get_qty($branchId, $productId);
}

function inventory_user_branch(array $user): ?array {
  $branchId = (int)($user['branch_id'] ?? 0);
  if ($branchId <= 0) {
    return null;
  }
  $stmt = db()->prepare("SELECT * FROM branches WHERE id=? LIMIT 1");
  $stmt->execute([$branchId]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function inventory_active_branch_id(): int {
  start_secure_session();
  $u = current_user();
  if (!$u) {
    return 0;
  }
  $role = (string)($u['role'] ?? '');
  if (in_array($role, ['owner', 'admin'], true)) {
    if (isset($_GET['set_branch_context']) && isset($_GET['branch_id'])) {
      $branchId = (int)($_GET['branch_id'] ?? 0);
      $stmt = db()->prepare("SELECT id FROM branches WHERE id=? AND is_active=1 LIMIT 1");
      $stmt->execute([$branchId]);
      if ($stmt->fetch()) {
        $_SESSION['inventory_branch_id'] = $branchId;
      }
    }
    $sessionBranch = (int)($_SESSION['inventory_branch_id'] ?? 0);
    if ($sessionBranch > 0) {
      return $sessionBranch;
    }
    $stmt = db()->query("SELECT id FROM branches WHERE is_active=1 ORDER BY id ASC LIMIT 1");
    $first = $stmt->fetch();
    if ($first) {
      $_SESSION['inventory_branch_id'] = (int)$first['id'];
      return (int)$first['id'];
    }
    return 0;
  }
  return (int)($u['branch_id'] ?? 0);
}

function inventory_active_branch(): ?array {
  $branchId = inventory_active_branch_id();
  if ($branchId <= 0) {
    return null;
  }
  $stmt = db()->prepare("SELECT * FROM branches WHERE id=? LIMIT 1");
  $stmt->execute([$branchId]);
  $row = $stmt->fetch();
  return $row ?: null;
}

function inventory_handle_branch_context_post(): void {
  start_secure_session();
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    return;
  }
  if (($_POST['action'] ?? '') !== 'set_branch_context') {
    return;
  }
  csrf_check();
  $u = current_user();
  $role = (string)($u['role'] ?? '');
  if (!in_array($role, ['owner', 'admin'], true)) {
    redirect(base_url('admin/dashboard.php'));
  }
  $branchId = (int)($_POST['branch_id'] ?? 0);
  $stmt = db()->prepare("SELECT id FROM branches WHERE id=? AND is_active=1 LIMIT 1");
  $stmt->execute([$branchId]);
  if ($stmt->fetch()) {
    $_SESSION['inventory_branch_id'] = $branchId;
  }
  redirect($_SERVER['REQUEST_URI'] ?? base_url('admin/dashboard.php'));
}

function inventory_branch_options(): array {
  return db()->query("SELECT id, name, branch_type FROM branches WHERE is_active=1 ORDER BY name ASC")->fetchAll();
}

function inventory_sales_branch_filter(string $salesAlias = 's', string $userAlias = 'u'): array {
  $branchId = inventory_active_branch_id();
  if ($branchId <= 0) {
    return ['sql' => '', 'params' => []];
  }
  return [
    'sql' => " AND COALESCE({$userAlias}.branch_id, 0) = ?",
    'params' => [$branchId],
  ];
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
