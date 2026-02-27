<?php
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../lib/upload_secure.php';

start_secure_session();
require_role(['owner', 'admin']);
inventory_ensure_tables();

$u = current_user();
$role = (string)($u['role'] ?? '');
$isOwner = $role === 'owner';
$allowedUnits = ['gram', 'kilogram', 'pcs', 'packs'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'save') {
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $sku = trim((string)($_POST['sku'] ?? ''));
    $unit = strtolower(trim((string)($_POST['unit'] ?? 'pcs')));
    $type = strtoupper(trim((string)($_POST['type'] ?? 'RAW')));
    $audience = (string)($_POST['audience'] ?? 'toko');
    $kitchenGroup = null; // akan dinormalisasi otomatis
    $sellPrice = $_POST['sell_price'] !== '' ? (float)$_POST['sell_price'] : null;
    $showOnLanding = isset($_POST['show_on_landing']) ? 1 : 0;

    if ($name === '') {
      inventory_set_flash('error', 'Nama produk wajib diisi.');
      redirect(base_url('admin/inventory_products.php'));
    }

    if ($type !== 'RAW' && $type !== 'FINISHED') {
      $type = 'RAW';
    }
    if ($audience !== 'toko' && $audience !== 'dapur') {
      $audience = 'toko';
    }
    if ($audience === 'toko') {
      $type = 'FINISHED';
    }
    if ($type === 'RAW') {
      $audience = 'dapur';
    }

// Set kitchen_group agar modul dapur konsisten (raw vs finished).
if ($audience === 'toko') {
  $kitchenGroup = 'finished';
} else {
  $kitchenGroup = ($type === 'RAW') ? 'raw' : 'finished';
}

    if (!in_array($unit, $allowedUnits, true)) {
      inventory_set_flash('error', 'Satuan tidak valid. Pilih gram, kilogram, pcs, atau packs.');
      redirect(base_url('admin/inventory_products.php' . ($id > 0 ? '?edit=' . $id : '')));
    }

    $existingImagePath = null;
    if ($id > 0) {
      $stmt = db()->prepare("SELECT image_path FROM inv_products WHERE id=? LIMIT 1");
      $stmt->execute([$id]);
      $row = $stmt->fetch();
      $existingImagePath = $row['image_path'] ?? null;
    }

    $imagePath = $existingImagePath;
    if (!empty($_FILES['image']['name'])) {
      $upload = upload_secure($_FILES['image'], 'image');
      if (empty($upload['ok'])) {
        inventory_set_flash('error', (string)($upload['error'] ?? 'Upload gambar gagal.'));
        redirect(base_url('admin/inventory_products.php' . ($id > 0 ? '?edit=' . $id : '')));
      }
      if (!empty($existingImagePath)) {
        upload_secure_delete((string)$existingImagePath, 'image');
      }
      $imagePath = (string)$upload['name'];
    }

    $now = inventory_now();
    try {
      if ($id > 0) {
        $stmt = db()->prepare("UPDATE inv_products SET sku=?, name=?, unit=?, type=?, audience=?, kitchen_group=?, sell_price=?, image_path=?, show_on_landing=?, updated_at=? WHERE id=?");
        $stmt->execute([$sku !== '' ? $sku : null, $name, $unit, $type, $audience, $kitchenGroup, $sellPrice, $imagePath, $showOnLanding, $now, $id]);
        $stmt = db()->prepare("SELECT * FROM inv_products WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $savedProduct = $stmt->fetch() ?: [];
        inventory_sync_finished_product_to_pos($savedProduct);
        inventory_set_flash('ok', 'Produk berhasil diperbarui.');
      } else {
        $stmt = db()->prepare("INSERT INTO inv_products (sku, name, unit, type, audience, kitchen_group, sell_price, image_path, show_on_landing, is_hidden, is_deleted, created_at, updated_at) VALUES (?,?,?,?,?,?,?, ?,?,0,0,?,?)");
        $stmt->execute([$sku !== '' ? $sku : null, $name, $unit, $type, $audience, $kitchenGroup, $sellPrice, $imagePath, $showOnLanding, $now, $now]);
        $newId = (int)db()->lastInsertId();
        $stmt = db()->prepare("SELECT * FROM inv_products WHERE id=? LIMIT 1");
        $stmt->execute([$newId]);
        $savedProduct = $stmt->fetch() ?: [];
        inventory_sync_finished_product_to_pos($savedProduct);
        inventory_set_flash('ok', 'Produk berhasil ditambahkan.');
      }
    } catch (Throwable $e) {
      inventory_set_flash('error', 'Gagal menyimpan produk. SKU mungkin sudah digunakan.');
    }

    redirect(base_url('admin/inventory_products.php'));
  }

  if ($action === 'hide') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare("UPDATE inv_products SET is_hidden=1, updated_at=? WHERE id=? AND is_deleted=0");
    $stmt->execute([inventory_now(), $id]);
    $stmt = db()->prepare("SELECT * FROM inv_products WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $savedProduct = $stmt->fetch() ?: [];
    inventory_sync_finished_product_to_pos($savedProduct);
    inventory_set_flash('ok', 'Produk disembunyikan.');
    redirect(base_url('admin/inventory_products.php'));
  }

  if ($action === 'delete' && $isOwner) {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = db()->prepare("UPDATE inv_products SET is_deleted=1, updated_at=? WHERE id=?");
    $stmt->execute([inventory_now(), $id]);
    $stmt = db()->prepare("SELECT * FROM inv_products WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $savedProduct = $stmt->fetch() ?: [];
    inventory_sync_finished_product_to_pos($savedProduct);
    inventory_set_flash('ok', 'Produk di-soft delete.');
    redirect(base_url('admin/inventory_products.php'));
  }
}

$editId = (int)($_GET['edit'] ?? 0);
$editData = null;
if ($editId > 0) {
  $stmt = db()->prepare("SELECT * FROM inv_products WHERE id=? AND is_deleted=0 LIMIT 1");
  $stmt->execute([$editId]);
  $editData = $stmt->fetch();
}

$viewAudience = (string)($_GET['audience'] ?? 'all');
if (!in_array($viewAudience, ['all','toko','dapur'], true)) $viewAudience='all';
$sqlProducts = "SELECT * FROM inv_products WHERE is_deleted=0 AND is_hidden=0";
if ($viewAudience !== 'all') { $sqlProducts .= " AND audience='" . $viewAudience . "'"; }
$sqlProducts .= " ORDER BY id DESC";
$products = db()->query($sqlProducts)->fetchAll();
$flash = inventory_get_flash();
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Inventory Produk</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style>
    .inventory-product-note{
      display:block;
      min-height:40px;
      line-height:1.4;
    }

    <?php echo $customCss; ?>
  </style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <span style="color:#fff;font-weight:700">Produk & Inventory / Produk</span>
    </div>

    <div class="content">
      <?php if ($flash): ?>
        <div class="card" style="margin-bottom:12px;border-color:<?php echo $flash['type'] === 'error' ? '#fecaca' : '#bbf7d0'; ?>"><?php echo e($flash['message']); ?></div>
      <?php endif; ?>

      <div class="card" style="margin-bottom:14px">
        <h3 style="margin-top:0"><?php echo $editData ? 'Edit Produk' : 'Tambah Produk'; ?></h3>
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="save">
          <input type="hidden" name="id" value="<?php echo e((string)($editData['id'] ?? 0)); ?>">
          <div class="grid cols-2">
            <div class="row"><label>Nama</label><input type="text" name="name" required value="<?php echo e((string)($editData['name'] ?? '')); ?>"></div>
            <div class="row"><label>SKU</label><input type="text" name="sku" value="<?php echo e((string)($editData['sku'] ?? '')); ?>"></div>
            <div class="row"><label>Unit</label>
              <?php $selectedUnit = strtolower((string)($editData['unit'] ?? 'pcs')); ?>
              <select name="unit" required>
                <?php foreach ($allowedUnits as $unitOption): ?>
                  <option value="<?php echo e($unitOption); ?>" <?php echo $selectedUnit === $unitOption ? 'selected' : ''; ?>><?php echo e($unitOption); ?></option>
                <?php endforeach; ?>
              </select>
              <small class="inventory-product-note">Standar baku: 1 kilogram = 1000 gram.</small>
            </div>
            <div class="row"><label>Tipe Cabang Produk</label>
              <?php $selectedAudience = (string)($editData['audience'] ?? 'toko'); ?>
              <select name="audience">
                <option value="toko" <?php echo $selectedAudience === 'toko' ? 'selected' : ''; ?>>Toko</option>
                <option value="dapur" <?php echo $selectedAudience === 'dapur' ? 'selected' : ''; ?>>Dapur</option>
              </select>
              <small class="inventory-product-note">Raw hanya bisa dipakai untuk cabang dapur.</small>
            </div>
            <div class="row"><label>Tipe</label>
              <select name="type">
                <?php $selectedType = (string)($editData['type'] ?? 'RAW'); ?>
                <option value="RAW" <?php echo $selectedType === 'RAW' ? 'selected' : ''; ?>>RAW</option>
                <option value="FINISHED" <?php echo $selectedType === 'FINISHED' ? 'selected' : ''; ?>>FINISHED</option>
              </select>
              <small class="inventory-product-note">Cabang toko hanya menerima tipe finished.</small>
            </div>
            <div class="row"><label>Harga Jual</label><input type="number" step="0.01" name="sell_price" value="<?php echo e(isset($editData['sell_price']) ? (string)$editData['sell_price'] : ''); ?>"></div>
<div class="row"><label>Tampilkan di Landing Page</label>
  <?php $selectedShow = (int)($editData['show_on_landing'] ?? 1); ?>
  <label style="display:flex;align-items:center;gap:8px">
    <input type="checkbox" name="show_on_landing" value="1" <?php echo $selectedShow ? 'checked' : ''; ?>>
    <span>Aktif</span>
  </label>
  <small class="inventory-product-note">Jika dimatikan, produk tidak tampil di landing page (katalog/checkout).</small>
</div>

            <div class="row"><label>Gambar Produk</label><input type="file" name="image" accept=".jpg,.jpeg,.png"></div>
          </div>
          <?php if (!empty($editData['image_path'])): ?>
            <div class="row"><small>Gambar saat ini:</small><br><img class="thumb" src="<?php echo e(upload_url($editData['image_path'], 'image')); ?>"></div>
          <?php endif; ?>
          <button class="btn" type="submit"><?php echo $editData ? 'Update Produk' : 'Tambah Produk'; ?></button>
        </form>
      </div>

      <div class="card">
        <h3 style="margin-top:0">Daftar Produk Aktif</h3><div style="margin-bottom:8px;display:flex;gap:8px"><a class="btn" href="<?php echo e(base_url('admin/inventory_products.php?audience=all')); ?>">Semua</a><a class="btn" href="<?php echo e(base_url('admin/inventory_products.php?audience=toko')); ?>">Produk Toko</a><a class="btn" href="<?php echo e(base_url('admin/inventory_products.php?audience=dapur')); ?>">Produk Dapur</a></div>
        <table class="table">
          <thead><tr><th>Nama</th><th>SKU</th><th>Unit</th><th>Cabang</th><th>Tipe</th><th>Grup Dapur</th><th>Harga Jual</th><th>Aksi</th></tr></thead>
          <tbody>
            <?php foreach ($products as $p): ?>
            <tr>
              <td><?php echo e($p['name']); ?></td>
              <td><?php echo e((string)$p['sku']); ?></td>
              <td><?php echo e($p['unit']); ?></td>
              <td><?php echo e((string)($p['audience'] ?? 'toko')); ?></td>
              <td><?php echo e($p['type']); ?></td>
              <td><?php echo e((string)($p['kitchen_group'] ?? '-')); ?></td>
              <td><?php echo e($p['sell_price'] !== null ? number_format((float)$p['sell_price'], 2, '.', ',') : '-'); ?></td>
              <td style="display:flex;gap:8px;flex-wrap:wrap">
                <a class="btn" href="<?php echo e(base_url('admin/inventory_products.php?edit=' . (int)$p['id'])); ?>">Edit</a>
                <form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="hide"><input type="hidden" name="id" value="<?php echo e((string)$p['id']); ?>"><button class="btn" type="submit">Hide</button></form>
                <?php if ($isOwner): ?>
                <form method="post" data-confirm="Soft delete produk ini?"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo e((string)$p['id']); ?>"><button class="btn danger" type="submit">Delete</button></form>
                <?php endif; ?>
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
