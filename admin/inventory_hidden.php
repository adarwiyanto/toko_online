<?php
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_role(['owner', 'admin']);
inventory_ensure_tables();

$u = current_user();
$isOwner = (($u['role'] ?? '') === 'owner');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  $id = (int)($_POST['id'] ?? 0);

  if ($action === 'unhide') {
    $stmt = db()->prepare("UPDATE inv_products SET is_hidden=0, updated_at=? WHERE id=? AND is_deleted=0");
    $stmt->execute([inventory_now(), $id]);
    inventory_set_flash('ok', 'Produk ditampilkan kembali.');
  } elseif ($action === 'delete' && $isOwner) {
    $stmt = db()->prepare("UPDATE inv_products SET is_deleted=1, updated_at=? WHERE id=?");
    $stmt->execute([inventory_now(), $id]);
    inventory_set_flash('ok', 'Produk di-soft delete.');
  }
  redirect(base_url('admin/inventory_hidden.php'));
}

$products = db()->query("SELECT * FROM inv_products WHERE is_hidden=1 AND is_deleted=0 ORDER BY updated_at DESC, id DESC")->fetchAll();
$flash = inventory_get_flash();
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Hide Product</title>
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
      <span style="color:#fff;font-weight:700">Produk & Inventory / Hide Product</span>
    </div>
    <div class="content">
      <?php if ($flash): ?><div class="card" style="margin-bottom:12px"><?php echo e($flash['message']); ?></div><?php endif; ?>
      <div class="card">
        <h3 style="margin-top:0">Produk Hidden</h3>
        <table class="table">
          <thead><tr><th>Nama</th><th>SKU</th><th>Unit</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php foreach ($products as $p): ?>
            <tr>
              <td><?php echo e($p['name']); ?></td>
              <td><?php echo e((string)$p['sku']); ?></td>
              <td><?php echo e($p['unit']); ?></td>
              <td style="display:flex;gap:8px">
                <form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="unhide"><input type="hidden" name="id" value="<?php echo e((string)$p['id']); ?>"><button class="btn" type="submit">Unhide</button></form>
                <?php if ($isOwner): ?><form method="post" data-confirm="Soft delete produk hidden ini?"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e((string)$p['id']); ?>"><button class="btn danger" type="submit">Delete</button></form><?php endif; ?>
              </td>
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
