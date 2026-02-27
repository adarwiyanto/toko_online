<?php
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_role(['owner']);
inventory_ensure_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $confirm = trim((string)($_POST['confirm'] ?? ''));
  if ($confirm !== 'REBUILD') {
    inventory_set_flash('error', 'Konfirmasi tidak valid. Ketik REBUILD.');
    redirect(base_url('admin/stock_rebuild.php'));
  }

  db()->beginTransaction();
  try {
    db()->exec("TRUNCATE TABLE stok_barang");

    $stmt = db()->query("SELECT l.branch_id, l.product_id AS inv_product_id, COALESCE(SUM(l.qty_in - l.qty_out),0) AS qty
      FROM inv_stock_ledger l
      WHERE l.branch_id IS NOT NULL
      GROUP BY l.branch_id, l.product_id");

    foreach ($stmt->fetchAll() as $row) {
      $branchId = (int)($row['branch_id'] ?? 0);
      $invProductId = (int)($row['inv_product_id'] ?? 0);
      $qty = (float)($row['qty'] ?? 0);
      if ($branchId <= 0 || $invProductId <= 0) {
        continue;
      }
      $productId = ensure_products_row_from_inv_product($invProductId);
      if ($productId > 0) {
        stok_barang_set_qty($branchId, $productId, $qty);
      }
    }

    db()->commit();
    inventory_set_flash('ok', 'Rebuild stok_barang selesai dari histori ledger.');
  } catch (Throwable $e) {
    db()->rollBack();
    inventory_set_flash('error', 'Rebuild gagal: ' . $e->getMessage());
  }
  redirect(base_url('admin/stock_rebuild.php'));
}

$flash = inventory_get_flash();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Rebuild Stok Barang</title>
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><span style="color:#fff;font-weight:700">Rebuild Stok Barang</span></div>
    <div class="content">
      <?php if ($flash): ?><div class="card" style="margin-bottom:12px"><?php echo e($flash['message']); ?></div><?php endif; ?>
      <div class="card">
        <h3 style="margin-top:0">Rebuild Single Source Stok</h3>
        <p>Tindakan ini akan <strong>TRUNCATE stok_barang</strong> lalu membangun ulang dari agregat inv_stock_ledger.</p>
        <form method="post" data-confirm="Yakin rebuild stok_barang?">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <div class="row"><label>Ketik REBUILD untuk konfirmasi</label><input type="text" name="confirm" required></div>
          <button class="btn danger" type="submit">Jalankan Rebuild</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
