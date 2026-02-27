<?php
require_once __DIR__ . '/inventory_helpers.php';

start_secure_session();
require_role(['owner', 'admin']);
inventory_ensure_tables();

$activeBranch = inventory_active_branch();
$activeBranchId = (int)($activeBranch['id'] ?? 0);
$stocks = [];

if ($activeBranchId > 0) {
  $stmt = db()->prepare(
    "SELECT p.sku, p.name, p.unit, COALESCE(bs.stock, 0) AS stock
     FROM branch_stock bs
     INNER JOIN inv_products p ON p.id = bs.product_id
     WHERE bs.branch_id = ?
     ORDER BY p.name ASC"
  );
  $stmt->execute([$activeBranchId]);
  $stocks = $stmt->fetchAll();
}

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Stok Cabang</title>
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
      <span style="color:#fff;font-weight:700">Produk & Inventory / Stok Cabang</span>
    </div>

    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Stok Cabang <?php echo e((string)($activeBranch['name'] ?? '-')); ?></h3>
        <?php if ($activeBranchId <= 0): ?>
          <p>Pilih cabang aktif terlebih dahulu.</p>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>No</th>
                <th>SKU</th>
                <th>Nama Produk</th>
                <th>Satuan</th>
                <th>Stok</th>
              </tr>
            </thead>
            <tbody>
              <?php if (count($stocks) === 0): ?>
                <tr>
                  <td colspan="5">Belum ada data stok untuk cabang ini.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($stocks as $idx => $row): ?>
                  <tr>
                    <td><?php echo e((string)($idx + 1)); ?></td>
                    <td><?php echo e((string)($row['sku'] ?? '-')); ?></td>
                    <td><?php echo e((string)($row['name'] ?? '-')); ?></td>
                    <td><?php echo e((string)($row['unit'] ?? '-')); ?></td>
                    <td><?php echo e(number_format((float)($row['stock'] ?? 0), 3, '.', ',')); ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
