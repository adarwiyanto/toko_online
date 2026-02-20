<?php
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_role(['owner', 'admin']);
inventory_ensure_tables();
$me = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? 'create');
  $name = trim((string)($_POST['name'] ?? ''));
  $code = trim((string)($_POST['code'] ?? ''));
  $address = trim((string)($_POST['address'] ?? ''));
  $branchType = (string)($_POST['branch_type'] ?? 'toko');
  $isActive = (int)($_POST['is_active'] ?? 1) === 1 ? 1 : 0;

  if ($branchType !== 'toko' && $branchType !== 'dapur') {
    $branchType = 'toko';
  }

  if ($action === 'toggle') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      inventory_set_flash('error', 'Data cabang tidak valid.');
      redirect(base_url('admin/branches.php'));
    }

    try {
      $stmt = db()->prepare("UPDATE branches SET is_active = CASE WHEN is_active=1 THEN 0 ELSE 1 END, updated_at=? WHERE id=?");
      $stmt->execute([inventory_now(), $id]);
      inventory_set_flash('ok', 'Status cabang berhasil diperbarui.');
    } catch (Throwable $e) {
      inventory_log_error('Toggle status cabang gagal', $e);
      inventory_set_flash('error', inventory_user_friendly_db_error($e));
    }
    redirect(base_url('admin/branches.php'));
  }

  if ($action === 'delete') {
    if (($me['role'] ?? '') !== 'owner') {
      inventory_set_flash('error', 'Hanya owner yang dapat menghapus cabang.');
      redirect(base_url('admin/branches.php'));
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      inventory_set_flash('error', 'Data cabang tidak valid.');
      redirect(base_url('admin/branches.php'));
    }

    try {
      $stmt = db()->prepare("DELETE FROM branches WHERE id=?");
      $stmt->execute([$id]);
      if ($stmt->rowCount() > 0) {
        inventory_set_flash('ok', 'Cabang berhasil dihapus.');
      } else {
        inventory_set_flash('error', 'Cabang tidak ditemukan.');
      }
    } catch (Throwable $e) {
      inventory_log_error('Hapus cabang gagal', $e);
      inventory_set_flash('error', 'Cabang tidak bisa dihapus karena masih dipakai pada data lain.');
    }
    redirect(base_url('admin/branches.php'));
  }

  if ($name === '') {
    inventory_set_flash('error', 'Nama cabang wajib diisi.');
    redirect(base_url('admin/branches.php'));
  }

  if ($action === 'create') {
    $now = inventory_now();
    try {
      $stmt = db()->prepare("INSERT INTO branches (code, name, address, branch_type, is_active, created_at, updated_at) VALUES (?,?,?,?,?,?,?)");
      $stmt->execute([$code !== '' ? $code : null, $name, $address !== '' ? $address : null, $branchType, $isActive, $now, $now]);
      inventory_set_flash('ok', 'Cabang berhasil ditambahkan.');
    } catch (Throwable $e) {
      inventory_log_error('Tambah cabang gagal', $e);
      inventory_set_flash('error', inventory_user_friendly_db_error($e));
    }
    redirect(base_url('admin/branches.php'));
  }

  if ($action === 'update') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
      inventory_set_flash('error', 'Data cabang tidak valid.');
      redirect(base_url('admin/branches.php'));
    }

    try {
      $stmt = db()->prepare("UPDATE branches SET code=?, name=?, address=?, branch_type=?, is_active=?, updated_at=? WHERE id=?");
      $stmt->execute([$code !== '' ? $code : null, $name, $address !== '' ? $address : null, $branchType, $isActive, inventory_now(), $id]);
      inventory_set_flash('ok', 'Cabang berhasil diperbarui.');
    } catch (Throwable $e) {
      inventory_log_error('Update cabang gagal', $e);
      inventory_set_flash('error', inventory_user_friendly_db_error($e));
    }
    redirect(base_url('admin/branches.php'));
  }

  inventory_set_flash('error', 'Aksi tidak dikenali.');
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
      <?php if ($flash): ?>
        <div class="card" style="margin-bottom:12px"><?php echo e((string)$flash['message']); ?></div>
      <?php endif; ?>
      <div class="card" style="margin-bottom:14px">
        <h3 style="margin-top:0">Tambah Cabang</h3>
        <form method="post" class="grid cols-2">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <input type="hidden" name="action" value="create">
          <div class="row"><label>Kode Cabang</label><input name="code" placeholder="Opsional"></div>
          <div class="row"><label>Nama Cabang</label><input name="name" required></div>
          <div class="row"><label>Tipe Cabang</label><select name="branch_type"><option value="toko">Toko</option><option value="dapur">Dapur</option></select></div>
          <div class="row"><label>Status</label><select name="is_active"><option value="1">Aktif</option><option value="0">Nonaktif</option></select></div>
          <div class="row" style="grid-column:span 2"><label>Alamat</label><textarea name="address" rows="2" placeholder="Opsional"></textarea></div>
          <div class="row" style="align-self:end"><button class="btn" type="submit">Simpan Cabang</button></div>
        </form>
      </div>

      <div class="card" style="overflow-x:auto">
        <h3 style="margin-top:0">Daftar Cabang</h3>
        <table class="table">
          <thead>
            <tr>
              <th>Kode</th><th>Nama</th><th>Tipe</th><th>Status</th><th>Alamat</th><th>Aksi</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($branches as $b): ?>
            <tr>
              <form method="post">
                <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                <input type="hidden" name="id" value="<?php echo e((string)$b['id']); ?>">
                <td><input name="code" value="<?php echo e((string)($b['code'] ?? '')); ?>"></td>
                <td><input name="name" value="<?php echo e((string)$b['name']); ?>" required></td>
                <td>
                  <select name="branch_type">
                    <option value="toko" <?php echo ($b['branch_type'] ?? 'toko')==='toko'?'selected':''; ?>>Toko</option>
                    <option value="dapur" <?php echo ($b['branch_type'] ?? '')==='dapur'?'selected':''; ?>>Dapur</option>
                  </select>
                </td>
                <td>
                  <span><?php echo (int)($b['is_active'] ?? 1) === 1 ? 'Aktif' : 'Nonaktif'; ?></span>
                  <input type="hidden" name="is_active" value="<?php echo (int)($b['is_active'] ?? 1) === 1 ? '1' : '0'; ?>">
                </td>
                <td><textarea name="address" rows="2"><?php echo e((string)($b['address'] ?? '')); ?></textarea></td>
                <td style="white-space:nowrap">
                  <button class="btn" type="submit" name="action" value="update">Edit</button>
                  <button class="btn" type="submit" name="action" value="toggle" formnovalidate style="margin-left:6px"><?php echo (int)($b['is_active'] ?? 1) === 1 ? 'Nonaktifkan' : 'Aktifkan'; ?></button>
                  <?php if (($me['role'] ?? '') === 'owner'): ?>
                    <button class="btn" type="submit" name="action" value="delete" formnovalidate style="margin-left:6px" onclick="return confirm('Hapus cabang ini? Data terkait yang masih dipakai akan menolak penghapusan.');">Hapus</button>
                  <?php endif; ?>
                </td>
              </form>
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
