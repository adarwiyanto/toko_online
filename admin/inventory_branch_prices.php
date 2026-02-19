<?php
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_role(['owner', 'admin']);
inventory_ensure_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $branchId = (int)($_POST['branch_id'] ?? 0);
  $priceMap = $_POST['sell_price'] ?? [];

  if ($branchId <= 0) {
    inventory_set_flash('error', 'Cabang wajib dipilih.');
    redirect(base_url('admin/inventory_branch_prices.php'));
  }

  if (!is_array($priceMap)) {
    $priceMap = [];
  }

  $now = inventory_now();
  try {
    $stmt = db()->prepare("INSERT INTO branch_product_price (branch_id, product_id, sell_price, updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE sell_price=VALUES(sell_price), updated_at=VALUES(updated_at)");
    foreach ($priceMap as $productId => $rawPrice) {
      $pid = (int)$productId;
      if ($pid <= 0 || $rawPrice === '') {
        continue;
      }
      $sellPrice = max(0, (float)$rawPrice);
      $stmt->execute([$branchId, $pid, $sellPrice, $now]);
    }
    inventory_set_flash('ok', 'Harga jual per cabang berhasil disimpan.');
  } catch (Throwable $e) {
    inventory_log_error('Simpan harga jual cabang gagal', $e);
    inventory_set_flash('error', 'Gagal menyimpan harga jual cabang: ' . $e->getMessage());
  }

  redirect(base_url('admin/inventory_branch_prices.php?branch_id=' . $branchId));
}

$branches = db()->query("SELECT id, name, branch_type, is_active FROM branches ORDER BY is_active DESC, name ASC")->fetchAll();
$selectedBranchId = (int)($_GET['branch_id'] ?? 0);
if ($selectedBranchId <= 0 && !empty($branches)) {
  $selectedBranchId = (int)$branches[0]['id'];
}

$products = db()->query("SELECT id, name, sku, unit, sell_price FROM inv_products WHERE is_deleted=0 AND is_hidden=0 ORDER BY name ASC")->fetchAll();
$priceMap = [];
if ($selectedBranchId > 0) {
  $stmt = db()->prepare("SELECT product_id, sell_price FROM branch_product_price WHERE branch_id=?");
  $stmt->execute([$selectedBranchId]);
  foreach ($stmt->fetchAll() as $row) {
    $priceMap[(int)$row['product_id']] = (float)$row['sell_price'];
  }
}
$flash = inventory_get_flash();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Harga Jual Cabang</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><span style="color:#fff;font-weight:700">Produk & Inventory / Harga Jual Cabang</span></div>
    <div class="content">
      <?php if ($flash): ?><div class="card" style="margin-bottom:12px"><?php echo e($flash['message']); ?></div><?php endif; ?>

      <div class="card" style="margin-bottom:14px">
        <h3 style="margin-top:0">Filter Cabang</h3>
        <form method="get" class="row">
          <label>Cabang</label>
          <select name="branch_id" onchange="this.form.submit()">
            <?php foreach ($branches as $b): ?>
              <option value="<?php echo e((string)$b['id']); ?>" <?php echo (int)$b['id'] === $selectedBranchId ? 'selected' : ''; ?>><?php echo e((string)$b['name']); ?> (<?php echo e((string)$b['branch_type']); ?>)</option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>

      <div class="card">
        <h3 style="margin-top:0">Set Harga Jual per Produk</h3>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="branch_id" value="<?php echo e((string)$selectedBranchId); ?>">
          <table class="table">
            <thead><tr><th>Produk</th><th>SKU</th><th>Unit</th><th>Harga Default Produk</th><th>Harga Jual Cabang</th></tr></thead>
            <tbody>
            <?php foreach ($products as $p): ?>
              <?php $curr = $priceMap[(int)$p['id']] ?? (float)($p['sell_price'] ?? 0); ?>
              <tr>
                <td><?php echo e((string)$p['name']); ?></td>
                <td><?php echo e((string)($p['sku'] ?? '-')); ?></td>
                <td><?php echo e((string)$p['unit']); ?></td>
                <td><?php echo e(number_format((float)($p['sell_price'] ?? 0), 2, '.', ',')); ?></td>
                <td><input type="number" step="0.01" min="0" name="sell_price[<?php echo e((string)$p['id']); ?>]" value="<?php echo e((string)$curr); ?>"></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <button class="btn" type="submit">Simpan Harga Jual Cabang</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
