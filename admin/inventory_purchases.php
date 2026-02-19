<?php
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_login();
inventory_ensure_tables();

$u = current_user();
$role = (string)($u['role'] ?? '');
if (!in_array($role, ['owner','admin','manager_toko','pegawai_pos','pegawai_non_pos'], true)) {
  http_response_code(403);
  exit('Forbidden');
}
$userId = (int)($u['id'] ?? 0);
$branchId = inventory_active_branch_id();
$branch = inventory_active_branch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $purchaseDate = trim((string)($_POST['purchase_date'] ?? app_today_jakarta()));
  $supplierName = trim((string)($_POST['supplier_name'] ?? ''));
  $note = trim((string)($_POST['note'] ?? ''));
  $qtyMap = $_POST['qty'] ?? [];
  $buyPriceMap = $_POST['buy_price'] ?? [];

  if (!is_array($qtyMap)) {
    $qtyMap = [];
  }
  if (!is_array($buyPriceMap)) {
    $buyPriceMap = [];
  }

  $items = [];
  foreach ($qtyMap as $productId => $qtyRaw) {
    $pid = (int)$productId;
    $qty = (float)$qtyRaw;
    $buyPrice = isset($buyPriceMap[$productId]) && $buyPriceMap[$productId] !== '' ? (float)$buyPriceMap[$productId] : 0.0;
    if ($pid > 0 && $qty > 0) {
      if ($buyPrice <= 0) {
        inventory_set_flash('error', 'Harga pokok pembelian wajib diisi untuk qty yang dibeli.');
        redirect(base_url('admin/inventory_purchases.php'));
      }
      $items[] = [
        'product_id' => $pid,
        'qty' => $qty,
        'buy_price' => $buyPrice,
        'line_total' => $qty * $buyPrice,
      ];
    }
  }

  if (count($items) === 0) {
    inventory_set_flash('error', 'Isi minimal satu item pembelian.');
    redirect(base_url('admin/inventory_purchases.php'));
  }

  $now = inventory_now();
  db()->beginTransaction();
  try {
    $stmt = db()->prepare("INSERT INTO inv_purchases (branch_id, purchase_date, supplier_name, note, created_by, created_at) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$branchId, $purchaseDate, $supplierName !== '' ? $supplierName : null, $note !== '' ? $note : null, $userId, $now]);
    $purchaseId = (int)db()->lastInsertId();

    $stmtItem = db()->prepare("INSERT INTO inv_purchase_items (purchase_id, product_id, qty, unit_cost, line_total) VALUES (?,?,?,?,?)");
    $stmtLedger = db()->prepare("INSERT INTO inv_stock_ledger (branch_id, product_id, ref_type, ref_id, qty_in, qty_out, note, created_at) VALUES (?, ?, 'PURCHASE', ?, ?, 0, 'Pembelian pihak ketiga', ?)");

    foreach ($items as $item) {
      $stmtItem->execute([$purchaseId, $item['product_id'], $item['qty'], $item['buy_price'], $item['line_total']]);
      $stmtLedger->execute([$branchId, $item['product_id'], $purchaseId, $item['qty'], $now]);
    }

    db()->commit();
    inventory_set_flash('ok', 'Pembelian berhasil diposting ke stock ledger.');
  } catch (Throwable $e) {
    db()->rollBack();
    inventory_set_flash('error', 'Gagal menyimpan pembelian.');
  }

  redirect(base_url('admin/inventory_purchases.php'));
}

$products = db()->query("SELECT id, name, sku, unit FROM inv_products WHERE is_deleted=0 AND is_hidden=0 AND audience='toko' AND (type='FINISHED' OR kitchen_group='finished') ORDER BY name ASC")->fetchAll();
$stmtRecent = db()->prepare("SELECT p.id, p.purchase_date, p.supplier_name, p.note, COALESCE(SUM(i.line_total),0) AS total FROM inv_purchases p LEFT JOIN inv_purchase_items i ON i.purchase_id=p.id WHERE p.branch_id=? GROUP BY p.id ORDER BY p.id DESC LIMIT 10");
$stmtRecent->execute([$branchId]);
$recentPurchases = $stmtRecent->fetchAll();
$flash = inventory_get_flash();
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pembelian Produk Toko</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <span style="color:#fff;font-weight:700">Produk & Inventory / Pembelian</span>
    </div>
    <div class="content">
      <?php if ($flash): ?><div class="card" style="margin-bottom:12px"><?php echo e($flash['message']); ?></div><?php endif; ?>

      <div class="card" style="margin-bottom:14px">
        <h3 style="margin-top:0">Input Pembelian Pihak Ketiga (Produk Finished Toko)</h3><p>Cabang aktif: <strong><?php echo e((string)($branch['name'] ?? '-')); ?></strong></p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <div class="grid cols-2">
            <div class="row"><label>Tanggal Pembelian</label><input type="date" name="purchase_date" value="<?php echo e(app_today_jakarta()); ?>" required></div>
            <div class="row"><label>Supplier (opsional)</label><input type="text" name="supplier_name"></div>
          </div>
          <div class="row"><label>Catatan</label><input type="text" name="note"></div>
          <table class="table">
            <thead><tr><th>Produk</th><th>SKU</th><th>Unit</th><th>Qty Beli</th><th>Harga Beli</th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
              <tr>
                <td><?php echo e($p['name']); ?></td>
                <td><?php echo e((string)$p['sku']); ?></td>
                <td><?php echo e($p['unit']); ?></td>
                <td><input type="number" step="0.001" min="0" name="qty[<?php echo e((string)$p['id']); ?>]" value="0"></td>
                <td><input type="number" step="0.01" min="0" name="buy_price[<?php echo e((string)$p['id']); ?>]" value="0"></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <button class="btn" type="submit">Simpan Pembelian</button>
        </form>
      </div>

      <div class="card">
        <h3 style="margin-top:0">Riwayat Pembelian Terakhir</h3>
        <table class="table">
          <thead><tr><th>ID</th><th>Tanggal</th><th>Supplier</th><th>Total</th><th>Catatan</th></tr></thead>
          <tbody>
          <?php foreach ($recentPurchases as $row): ?>
            <tr>
              <td><?php echo e((string)$row['id']); ?></td>
              <td><?php echo e((string)$row['purchase_date']); ?></td>
              <td><?php echo e((string)($row['supplier_name'] ?? '-')); ?></td>
              <td><?php echo e(number_format((float)$row['total'], 2, '.', ',')); ?></td>
              <td><?php echo e((string)($row['note'] ?? '-')); ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
