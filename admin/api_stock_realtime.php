<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/inventory_helpers.php';

date_default_timezone_set('Asia/Jakarta');

start_secure_session();

header('Content-Type: application/json; charset=utf-8');

function json_out(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  require_login();
} catch (Throwable $e) {
  json_out(401, ['ok' => false, 'error' => 'Unauthorized']);
}

$u = current_user();
$role = (string)($u['role'] ?? '');
if ($role !== 'owner' && $role !== 'admin') {
  json_out(403, ['ok' => false, 'error' => 'Forbidden']);
}

try {
  inventory_ensure_tables();
} catch (Throwable $e) {
  // Tetap lanjut jika provisioning tabel gagal.
}

try {
  $branchId = (int)($_GET['branch_id'] ?? 0);
  $q = trim((string)($_GET['q'] ?? ''));
  if (strlen($q) > 80) {
    $q = substr($q, 0, 80);
  }

  if ($branchId > 0) {
    $stmtBranch = db()->prepare('SELECT id, name FROM branches WHERE id=? AND is_active=1 LIMIT 1');
    $stmtBranch->execute([$branchId]);
    if (!$stmtBranch->fetch()) {
      json_out(400, ['ok' => false, 'error' => 'Cabang tidak valid']);
    }

    $sql = "SELECT sb.inv_product_id AS product_id, p.sku, p.name, p.unit, b.id AS branch_id, b.name AS branch_name,
      sb.qty AS stock_qty
      FROM inv_stocks sb
      JOIN inv_products p ON p.id=sb.inv_product_id
      JOIN branches b ON b.id=sb.branch_id AND b.is_active=1
      WHERE sb.branch_id=?";
    $params = [$branchId];

    if ($q !== '') {
      $sql .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
      $like = '%' . $q . '%';
      $params[] = $like;
      $params[] = $like;
    }

    $sql .= " ORDER BY p.name ASC";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
      $rows[] = [
        'product_id' => (int)$row['product_id'],
        'sku' => $row['sku'] ?? null,
        'name' => (string)$row['name'],
        'unit' => (string)($row['unit'] ?? ''),
        'type' => '',
        'branch_id' => (int)$row['branch_id'],
        'branch_name' => (string)$row['branch_name'],
        'stock_qty' => (float)$row['stock_qty'],
      ];
    }
  } else {
    $sql = "SELECT sb.inv_product_id AS product_id, p.sku, p.name, p.unit, sb.branch_id, b.name AS branch_name, sb.qty AS stock_qty
      FROM inv_stocks sb
      JOIN inv_products p ON p.id=sb.inv_product_id
      JOIN branches b ON b.id=sb.branch_id AND b.is_active=1
      WHERE 1=1";
    $params = [];
    if ($q !== '') {
      $sql .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
      $like = '%' . $q . '%';
      $params[] = $like;
      $params[] = $like;
    }
    $sql .= " ORDER BY b.name ASC, p.name ASC";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    foreach ($stmt->fetchAll() as $row) {
      $rows[] = [
        'product_id' => (int)$row['product_id'],
        'sku' => $row['sku'] ?? null,
        'name' => (string)$row['name'],
        'unit' => (string)($row['unit'] ?? ''),
        'type' => '',
        'branch_id' => (int)$row['branch_id'],
        'branch_name' => (string)$row['branch_name'],
        'stock_qty' => (float)$row['stock_qty'],
      ];
    }
  }

  json_out(200, [
    'ok' => true,
    'server_time' => date('Y-m-d H:i:s'),
    'branch_id' => $branchId,
    'rows' => $rows,
  ]);
} catch (Throwable $e) {
  $payload = ['ok' => false, 'error' => 'Server error'];
  if ((bool)app_config()['app']['debug']) {
    $payload['detail'] = $e->getMessage();
  }
  json_out(500, $payload);
}
