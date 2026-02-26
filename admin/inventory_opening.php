<?php
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_role(['owner', 'admin']);
inventory_ensure_tables();

$u = current_user();
$userId = (int)($u['id'] ?? 0);
$branchId = inventory_active_branch_id();
$branch = inventory_active_branch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $qtyMap = $_POST['qty'] ?? [];
  if (!is_array($qtyMap)) {
    $qtyMap = [];
  }

  $items = [];
  foreach ($qtyMap as $productId => $qtyRaw) {
    $pid = (int)$productId;
    $qty = (float)$qtyRaw;
    if ($pid > 0 && abs($qty) > 0) {
      $items[$pid] = $qty;
    }
  }

  if (count($items) === 0) {
    inventory_set_flash('error', 'Isi minimal satu qty stok awal.');
    redirect(base_url('admin/inventory_opening.php'));
  }

  $now = inventory_now();
  db()->beginTransaction();
  try {
    $stmt = db()->prepare("INSERT INTO inv_opening_stock (branch_id, created_by, created_at) VALUES (?,?,?)");
    $stmt->execute([$branchId, $userId, $now]);
    $openingId = (int)db()->lastInsertId();

    $stmtItem = db()->prepare("INSERT INTO inv_opening_stock_items (opening_id, product_id, qty) VALUES (?,?,?)");
    $stmtLedger = db()->prepare("INSERT INTO inv_stock_ledger (branch_id, product_id, ref_type, ref_id, qty_in, qty_out, note, created_at) VALUES (?,?,?,?,?,?,?,?)");

    foreach ($items as $pid => $qty) {
      $stmtItem->execute([$openingId, $pid, $qty]);
      if ($qty >= 0) {
        $stmtLedger->execute([$branchId, $pid, 'OPENING', $openingId, $qty, 0, 'Stock awal', $now]);
      } else {
        $stmtLedger->execute([$branchId, $pid, 'OPENING', $openingId, 0, abs($qty), 'Stock awal', $now]);
      }
      $productId = ensure_products_row_from_inv_product((int)$pid);
      if ($productId > 0) {
        stok_barang_set_qty($branchId, $productId, $qty);
      }
    }
    db()->commit();
    inventory_set_flash('ok', 'Stok awal berhasil diposting. Stok realtime diperbarui.');
  } catch (Throwable $e) {
    db()->rollBack();
    inventory_set_flash('error', 'Gagal menyimpan stok awal.');
  }
  redirect(base_url('admin/inventory_opening.php'));
}

$products = db()->query("SELECT id, name, sku, unit FROM inv_products WHERE is_deleted=0 AND is_hidden=0 ORDER BY name ASC")->fetchAll();
$stmtOpening = db()->prepare("SELECT COUNT(*) AS c FROM inv_opening_stock WHERE branch_id=?");
$stmtOpening->execute([$branchId]);
$openingCount = (int)$stmtOpening->fetch()['c'];
$flash = inventory_get_flash();
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Stok Awal</title>
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
      <span style="color:#fff;font-weight:700">Produk & Inventory / Stok Awal</span>
    </div>
    <div class="content">
      <?php if ($openingCount > 0): ?><div class="card" style="margin-bottom:12px">Perhatian: Stok awal sudah pernah diinput sebelumnya (<?php echo e((string)$openingCount); ?> dokumen).</div><?php endif; ?>
      <?php if ($flash): ?><div class="card" style="margin-bottom:12px"><?php echo e($flash['message']); ?></div><?php endif; ?>
      <div class="card">
        <h3 style="margin-top:0">Input Stok Awal</h3><p>Cabang aktif: <strong><?php echo e((string)($branch['name'] ?? '-')); ?></strong></p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <table class="table">
            <thead><tr><th>Produk</th><th>SKU</th><th>Unit</th><th>Qty Awal</th></tr></thead>
            <tbody>
              <?php foreach ($products as $p): ?>
              <tr>
                <td><?php echo e($p['name']); ?></td>
                <td><?php echo e((string)$p['sku']); ?></td>
                <td><?php echo e($p['unit']); ?></td>
                <td><input type="number" step="0.001" name="qty[<?php echo e((string)$p['id']); ?>]" value="0"></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <button class="btn" type="submit">Simpan Stok Awal</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
