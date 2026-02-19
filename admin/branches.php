<?php
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_role(['owner', 'admin']);
inventory_ensure_tables();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $name = trim((string)($_POST['name'] ?? ''));
  $branchType = (string)($_POST['branch_type'] ?? 'toko');
  if ($branchType !== 'toko' && $branchType !== 'dapur') {
    $branchType = 'toko';
  }

  if ($name === '') {
    inventory_set_flash('error', 'Nama cabang wajib diisi.');
    redirect(base_url('admin/branches.php'));
  }

  $now = inventory_now();
  try {
    $stmt = db()->prepare("INSERT INTO branches (name, branch_type, is_active, created_at, updated_at) VALUES (?,?,?,?,?)");
    $stmt->execute([$name, $branchType, 1, $now, $now]);
    inventory_set_flash('ok', 'Cabang berhasil ditambahkan.');
  } catch (Throwable $e) {
    inventory_set_flash('error', 'Gagal menambahkan cabang. Nama cabang mungkin sudah digunakan.');
  }
  redirect(base_url('admin/branches.php'));
}

$branches = db()->query("SELECT * FROM branches ORDER BY id DESC")->fetchAll();
$flash = inventory_get_flash();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Cabang</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><span style="color:#fff;font-weight:700">Master Cabang</span></div>
    <div class="content">
      <?php if ($flash): ?><div class="card" style="margin-bottom:12px"><?php echo e($flash['message']); ?></div><?php endif; ?>
      <div class="card" style="margin-bottom:14px">
        <h3 style="margin-top:0">Tambah Cabang</h3>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <div class="grid cols-2">
            <div class="row"><label>Nama Cabang</label><input name="name" required></div>
            <div class="row"><label>Tipe Cabang</label><select name="branch_type"><option value="toko">Toko</option><option value="dapur">Dapur</option></select></div>
          </div>
          <button class="btn" type="submit">Simpan Cabang</button>
        </form>
      </div>
      <div class="card">
        <h3 style="margin-top:0">Daftar Cabang</h3>
        <table class="table"><thead><tr><th>Nama</th><th>Tipe</th><th>Status</th></tr></thead><tbody>
        <?php foreach ($branches as $b): ?>
          <tr><td><?php echo e((string)$b['name']); ?></td><td><?php echo e((string)$b['branch_type']); ?></td><td><?php echo (int)$b['is_active'] === 1 ? 'Aktif' : 'Nonaktif'; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
