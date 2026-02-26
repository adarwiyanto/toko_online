<?php
require_once __DIR__ . '/inventory_helpers.php';

header('Content-Type: application/json; charset=utf-8');

function stock_realtime_json(array $data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

start_secure_session();
require_login();
$u = current_user();
$role = (string)($u['role'] ?? '');
if (!in_array($role, ['owner', 'admin'], true)) {
  stock_realtime_json(['ok' => false, 'message' => 'Forbidden'], 403);
}

inventory_ensure_tables();

$branchId = (int)($_GET['branch_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

if ($branchId > 0) {
  $stmtBranch = db()->prepare("SELECT id, name FROM branches WHERE id=? AND is_active=1 LIMIT 1");
  $stmtBranch->execute([$branchId]);
  $branchRow = $stmtBranch->fetch();
  if (!$branchRow) {
    stock_realtime_json(['ok' => false, 'message' => 'Branch tidak aktif / tidak ditemukan'], 400);
  }
}

$sqlProducts = "SELECT id, sku, name, unit, type FROM inv_products WHERE is_deleted=0 AND is_hidden=0";
$paramsProducts = [];
if ($q !== '') {
  $sqlProducts .= " AND (name LIKE ? OR sku LIKE ?)";
  $like = '%' . $q . '%';
  $paramsProducts = [$like, $like];
}
$sqlProducts .= " ORDER BY name ASC";
$stmtProducts = db()->prepare($sqlProducts);
$stmtProducts->execute($paramsProducts);
$products = $stmtProducts->fetchAll();

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
  $stockMap = inv_stock_map_for_branch($branchId, $productIds);
  $stmtBranch = db()->prepare("SELECT name FROM branches WHERE id=? LIMIT 1");
  $stmtBranch->execute([$branchId]);
  $branchName = (string)($stmtBranch->fetchColumn() ?: '-');

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
  $branches = db()->query("SELECT id, name FROM branches WHERE is_active=1 ORDER BY id ASC")->fetchAll();
  $branchMap = [];
  $branchIds = [];
  foreach ($branches as $branch) {
    $bid = (int)$branch['id'];
    $branchIds[] = $bid;
    $branchMap[$bid] = (string)$branch['name'];
  }

  if (count($branchIds) > 0 && count($productIds) > 0) {
    $branchPh = implode(',', array_fill(0, count($branchIds), '?'));
    $prodPh = implode(',', array_fill(0, count($productIds), '?'));
    $sqlLedger = "SELECT branch_id, product_id, SUM(qty_in - qty_out) AS stock_qty
      FROM inv_stock_ledger
      WHERE branch_id IN ($branchPh)
        AND product_id IN ($prodPh)
      GROUP BY branch_id, product_id";
    $stmtLedger = db()->prepare($sqlLedger);
    $stmtLedger->execute(array_merge($branchIds, $productIds));
    $ledgerRows = $stmtLedger->fetchAll();

    foreach ($ledgerRows as $ledger) {
      $pid = (int)$ledger['product_id'];
      $bid = (int)$ledger['branch_id'];
      if (!isset($productMap[$pid]) || !isset($branchMap[$bid])) {
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
        'stock_qty' => (float)$ledger['stock_qty'],
      ];
    }
  }
}

stock_realtime_json([
  'ok' => true,
  'server_time' => date('Y-m-d H:i:s'),
  'branch_id' => $branchId,
  'rows' => $rows,
]);
