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

  $branchName = '-';
  if ($branchId > 0) {
    $stmtBranch = db()->prepare('SELECT id, name FROM branches WHERE id=? AND is_active=1 LIMIT 1');
    $stmtBranch->execute([$branchId]);
    $branchRow = $stmtBranch->fetch();
    if (!$branchRow) {
      json_out(400, ['ok' => false, 'error' => 'Cabang tidak valid']);
    }
    $branchName = (string)($branchRow['name'] ?? '-');
  }

  $sqlProducts = 'SELECT id, sku, name, unit, type FROM inv_products WHERE is_deleted=0 AND is_hidden=0';
  $paramsProducts = [];
  if ($q !== '') {
    $sqlProducts .= ' AND (name LIKE ? OR sku LIKE ?)';
    $like = '%' . $q . '%';
    $paramsProducts = [$like, $like];
  }
  $sqlProducts .= ' ORDER BY name ASC';

  $stmtProducts = db()->prepare($sqlProducts);
  $stmtProducts->execute($paramsProducts);
  $products = $stmtProducts->fetchAll();

  if (!$products) {
    json_out(200, [
      'ok' => true,
      'server_time' => date('Y-m-d H:i:s'),
      'branch_id' => $branchId,
      'rows' => [],
    ]);
  }

  $productMap = [];
  $productIds = [];
  foreach ($products as $product) {
    $pid = (int)$product['id'];
    $productIds[] = $pid;
    $productMap[$pid] = [
      'sku' => $product['sku'] ?? null,
      'name' => (string)$product['name'],
      'unit' => (string)$product['unit'],
      'type' => (string)$product['type'],
    ];
  }

  $rows = [];
  if ($branchId > 0) {
    $stockMap = [];
    if ($productIds) {
      $productPh = implode(',', array_fill(0, count($productIds), '?'));
      $sqlLedger = "SELECT product_id, COALESCE(SUM(qty_in - qty_out),0) stock_qty
        FROM inv_stock_ledger
        WHERE branch_id=? AND product_id IN ($productPh)
        GROUP BY product_id";
      $stmtLedger = db()->prepare($sqlLedger);
      $stmtLedger->execute(array_merge([$branchId], $productIds));
      foreach ($stmtLedger->fetchAll() as $ledgerRow) {
        $stockMap[(int)$ledgerRow['product_id']] = (float)$ledgerRow['stock_qty'];
      }
    }

    foreach ($products as $product) {
      $pid = (int)$product['id'];
      $rows[] = [
        'product_id' => $pid,
        'sku' => $product['sku'] ?? null,
        'name' => (string)$product['name'],
        'unit' => (string)$product['unit'],
        'type' => (string)$product['type'],
        'branch_id' => $branchId,
        'branch_name' => $branchName,
        'stock_qty' => (float)($stockMap[$pid] ?? 0),
      ];
    }
  } else {
    $branches = db()->query('SELECT id, name FROM branches WHERE is_active=1 ORDER BY id ASC')->fetchAll();
    $branchIds = [];
    $branchMap = [];
    foreach ($branches as $branch) {
      $bid = (int)$branch['id'];
      $branchIds[] = $bid;
      $branchMap[$bid] = (string)$branch['name'];
    }

    if ($branchIds && $productIds) {
      $branchPh = implode(',', array_fill(0, count($branchIds), '?'));
      $productPh = implode(',', array_fill(0, count($productIds), '?'));
      $sqlLedger = "SELECT branch_id, product_id, SUM(qty_in - qty_out) stock_qty
        FROM inv_stock_ledger
        WHERE branch_id IN ($branchPh)
          AND product_id IN ($productPh)
        GROUP BY branch_id, product_id";
      $stmtLedger = db()->prepare($sqlLedger);
      $stmtLedger->execute(array_merge($branchIds, $productIds));

      foreach ($stmtLedger->fetchAll() as $ledgerRow) {
        $bid = (int)$ledgerRow['branch_id'];
        $pid = (int)$ledgerRow['product_id'];
        if (!isset($branchMap[$bid], $productMap[$pid])) {
          continue;
        }

        $rows[] = [
          'product_id' => $pid,
          'sku' => $productMap[$pid]['sku'],
          'name' => $productMap[$pid]['name'],
          'unit' => $productMap[$pid]['unit'],
          'type' => $productMap[$pid]['type'],
          'branch_id' => $bid,
          'branch_name' => $branchMap[$bid],
          'stock_qty' => (float)$ledgerRow['stock_qty'],
        ];
      }
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
