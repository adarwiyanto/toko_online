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

  $sql = "SELECT x.product_id, p.sku, p.name, p.unit, p.type, p.kitchen_group, x.branch_id, b.name AS branch_name, x.stock_qty
    FROM (
      SELECT s.branch_id, s.inv_product_id AS product_id, s.qty AS stock_qty
      FROM inv_stocks s
      UNION
      SELECT l.branch_id, l.product_id AS product_id, COALESCE(SUM(l.qty_in - l.qty_out),0) AS stock_qty
      FROM inv_stock_ledger l
      WHERE l.branch_id IS NOT NULL
      GROUP BY l.branch_id, l.product_id
    ) x
    JOIN inv_products p ON p.id=x.product_id
    LEFT JOIN branches b ON b.id=x.branch_id
    WHERE p.is_deleted=0 AND p.is_hidden=0";
  $params = [];

  if ($branchId > 0) {
    $sql .= " AND x.branch_id=?";
    $params[] = $branchId;
  }

  if ($q !== '') {
    $sql .= " AND (p.name LIKE ? OR p.sku LIKE ?)";
    $like = '%' . $q . '%';
    $params[] = $like;
    $params[] = $like;
  }

  $sql .= " GROUP BY x.branch_id, x.product_id, p.sku, p.name, p.unit, p.type, p.kitchen_group, b.name ORDER BY x.branch_id ASC, p.name ASC";

  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $rows = [];
  foreach ($stmt->fetchAll() as $row) {
    $kitchenGroup = strtolower(trim((string)($row['kitchen_group'] ?? '')));
    $type = '';
    if ($kitchenGroup !== '') {
      if ($kitchenGroup === 'raw') {
        $type = 'RAW';
      } elseif ($kitchenGroup === 'finished') {
        $type = 'FINISHED';
      }
    }
    if ($type === '') {
      $type = strtoupper(trim((string)($row['type'] ?? '')));
      if ($type === '') {
        $type = 'RAW';
      }
    }

    $branchIdRow = (int)$row['branch_id'];
    $branchName = trim((string)($row['branch_name'] ?? ''));
    if ($branchName === '') {
      $branchName = 'Cabang ID ' . $branchIdRow;
    }

    $rows[] = [
      'product_id' => (int)$row['product_id'],
      'sku' => $row['sku'] ?? null,
      'name' => (string)$row['name'],
      'unit' => (string)($row['unit'] ?? ''),
      'type' => $type,
      'branch_id' => $branchIdRow,
      'branch_name' => $branchName,
      'stock_qty' => (float)$row['stock_qty'],
    ];
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
