<?php
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/inventory_helpers.php';

$appName = app_config()['app']['name'];
$u = current_user();
$role = (string)($u['role'] ?? '');
$isManagerToko = $role === 'manager_toko';
$isManagerDapur = $role === 'manager_dapur';
$avatarUrl = '';
if (!empty($u['avatar_path'])) {
  $avatarUrl = upload_url($u['avatar_path'], 'image');
}
$initial = strtoupper(substr((string)($u['name'] ?? 'U'), 0, 1));

inventory_handle_branch_context_post();
$activeBranch = inventory_active_branch();
$activeBranchId = (int)($activeBranch['id'] ?? 0);
$branchOptions = [];
if (in_array($role, ['owner', 'admin'], true)) {
  $branchOptions = inventory_branch_options();
}
?>
<div class="sidebar">
  <div class="sb-top">
    <div class="profile-card">
      <button class="profile-trigger" type="button" data-toggle-submenu="#profile-menu">
        <div class="avatar">
          <?php if ($avatarUrl): ?>
            <img src="<?php echo e($avatarUrl); ?>" alt="<?php echo e($u['name'] ?? 'User'); ?>">
          <?php else: ?>
            <?php echo e($initial); ?>
          <?php endif; ?>
        </div>
        <div class="p-text">
          <div class="p-title"><?php echo e($u['name'] ?? 'User'); ?></div>
          <div class="p-sub"><?php echo e(ucfirst($u['role'] ?? 'admin')); ?></div>
        </div>
        <div class="p-right"><span class="chev">▾</span></div>
      </button>
    </div>
    <div class="submenu profile-submenu" id="profile-menu">
      <a href="<?php echo e(base_url('profile.php')); ?>">Edit Profil</a>
      <a href="<?php echo e(base_url('password.php')); ?>">Ubah Password</a>
    </div>
  </div>


  <div class="card" style="margin:10px;padding:10px">
    <div style="font-size:12px;opacity:.8;margin-bottom:6px">Cabang Aktif</div>
    <?php if (in_array($role, ['owner', 'admin'], true)): ?>
      <form method="post">
        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
        <input type="hidden" name="action" value="set_branch_context">
        <select name="branch_id" onchange="this.form.submit()" style="width:100%">
          <?php foreach ($branchOptions as $bo): ?>
            <option value="<?php echo e((string)$bo['id']); ?>" <?php echo (int)$bo['id'] === $activeBranchId ? 'selected' : ''; ?>>
              <?php echo e((string)$bo['name']); ?> (<?php echo e((string)$bo['branch_type']); ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    <?php else: ?>
      <div style="font-weight:600"><?php echo e((string)($activeBranch['name'] ?? '-')); ?></div>
      <div style="font-size:12px;opacity:.8"><?php echo e((string)($activeBranch['branch_type'] ?? '-')); ?></div>
    <?php endif; ?>
  </div>
  <div class="nav">
    <?php if ($isManagerToko): ?>
      <div class="item"><a class="<?php echo (basename($_SERVER['PHP_SELF'])==='schedule.php')?'active':''; ?>" href="<?php echo e(base_url('admin/schedule.php')); ?>"><div class="mi">📅</div><div class="label">Jadwal Pegawai</div></a></div>
      <div class="item"><a class="<?php echo (basename($_SERVER['PHP_SELF'])==='attendance.php')?'active':''; ?>" href="<?php echo e(base_url('admin/attendance.php')); ?>"><div class="mi">🕒</div><div class="label">Rekap Absensi</div></a></div>
      <div class="item"><a href="<?php echo e(base_url('pos/index.php')); ?>" target="_blank" rel="noopener"><div class="mi">🧾</div><div class="label">POS Kasir</div></a></div>
      <?php if (in_array($u['role'] ?? '', ['pegawai_pos','pegawai_non_pos','manager_toko','admin','owner'], true)): ?>
      <div class="item"><a class="<?php echo (basename($_SERVER['PHP_SELF'])==='inventory_receive.php')?'active':''; ?>" href="<?php echo e(base_url('admin/inventory_receive.php')); ?>"><div class="mi">📥</div><div class="label">Penerimaan Stok</div></a></div>
      <?php endif; ?>
    <?php elseif ($isManagerDapur): ?>
      <div class="item"><a class="<?php echo (basename($_SERVER['PHP_SELF'])==='schedule.php')?'active':''; ?>" href="<?php echo e(base_url('admin/schedule.php')); ?>"><div class="mi">📅</div><div class="label">Jadwal Pegawai Dapur</div></a></div>
      <div class="item"><a class="<?php echo (basename($_SERVER['PHP_SELF'])==='attendance.php')?'active':''; ?>" href="<?php echo e(base_url('admin/attendance.php')); ?>"><div class="mi">🕒</div><div class="label">Rekap Absensi Dapur</div></a></div>
      <div class="item"><a class="<?php echo (basename($_SERVER['PHP_SELF'])==='kinerja_dapur.php')?'active':''; ?>" href="<?php echo e(base_url('admin/kinerja_dapur.php')); ?>"><div class="mi">🍳</div><div class="label">Kinerja Dapur</div></a></div>
      <div class="item"><a class="<?php echo (basename($_SERVER['PHP_SELF'])==='kpi_dapur_targets.php')?'active':''; ?>" href="<?php echo e(base_url('admin/kpi_dapur_targets.php')); ?>"><div class="mi">🎯</div><div class="label">KPI Dapur - Target</div></a></div>
      <div class="item"><a class="<?php echo (basename($_SERVER['PHP_SELF'])==='kpi_dapur_rekap.php')?'active':''; ?>" href="<?php echo e(base_url('admin/kpi_dapur_rekap.php')); ?>"><div class="mi">📊</div><div class="label">KPI Dapur - Rekap</div></a></div>
      <div class="item"><a class="<?php echo (basename($_SERVER['PHP_SELF'])==='users.php')?'active':''; ?>" href="<?php echo e(base_url('admin/users.php')); ?>"><div class="mi">👥</div><div class="label">User</div></a></div>
    <?php else: ?>
      <div class="item"><a href="<?php echo e(base_url('index.php')); ?>" target="_blank" rel="noopener"><div class="mi">🌐</div><div class="label">Landing Page</div></a></div>
      <div class="item"><a class="<?php echo (basename($_SERVER['PHP_SELF'])==='dashboard.php')?'active':''; ?>" href="<?php echo e(base_url('admin/dashboard.php')); ?>"><div class="mi">🏠</div><div class="label">Dasbor</div></a></div>

      <div class="item">
        <button type="button" data-toggle-submenu="#m-produk"><div class="mi">📦</div><div class="label">Produk & Inventory</div><div class="chev">▾</div></button>
        <div class="submenu" id="m-produk">
          <a href="<?php echo e(base_url('admin/products.php')); ?>">Produk POS</a>
          <a href="<?php echo e(base_url('admin/product_categories.php')); ?>">Kategori Produk</a>
          <?php if (in_array($u['role'] ?? '', ['owner', 'admin'], true)): ?>
            <a href="<?php echo e(base_url('admin/branches.php')); ?>">Cabang</a>
            <a href="<?php echo e(base_url('admin/inventory_products.php')); ?>">Produk (Global)</a>
            <a href="<?php echo e(base_url('admin/inventory_branch_prices.php')); ?>">Harga Jual Cabang</a>
            <a href="<?php echo e(base_url('admin/inventory_stock.php')); ?>">Stok Cabang</a>
            <a href="<?php echo e(base_url('admin/inventory_opening.php')); ?>">Stock Awal</a>
            <a href="<?php echo e(base_url('admin/inventory_purchases.php')); ?>">Pembelian Pihak Ketiga</a>
            <a href="<?php echo e(base_url('admin/inventory_kitchen_transfers.php')); ?>">Kirim Dapur ke Toko</a>
            <a href="<?php echo e(base_url('admin/inventory_receive.php')); ?>">Penerimaan Stok Toko</a>
            <a href="<?php echo e(base_url('admin/inventory_opname.php')); ?>">Stock Opname</a>
            <a href="<?php echo e(base_url('admin/inventory_hidden.php')); ?>">Hide Product</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="item">
        <button type="button" data-toggle-submenu="#m-transaksi"><div class="mi">💳</div><div class="label">Transaksi & Pembayaran</div><div class="chev">▾</div></button>
        <div class="submenu" id="m-transaksi"><a href="<?php echo e(base_url('admin/sales.php')); ?>">Penjualan</a><a href="<?php echo e(base_url('admin/customers.php')); ?>">Pelanggan</a></div>
      </div>

      <div class="item"><a href="<?php echo e(base_url('pos/index.php')); ?>" target="_blank" rel="noopener"><div class="mi">🧾</div><div class="label">POS Kasir</div></a></div>

      <?php if (in_array($u['role'] ?? '', ['admin', 'owner'], true)): ?>
      <div class="item">
        <button type="button" data-toggle-submenu="#m-admin"><div class="mi">⚙️</div><div class="label">Admin</div><div class="chev">▾</div></button>
        <div class="submenu" id="m-admin">
          <a href="<?php echo e(base_url('admin/users.php')); ?>">User</a>
          <a href="<?php echo e(base_url('admin/store.php')); ?>">Profil Toko</a>
          <a href="<?php echo e(base_url('admin/theme.php')); ?>">Tema / CSS</a>
          <a href="<?php echo e(base_url('admin/loyalty.php')); ?>">Loyalti Point</a>
          <a href="<?php echo e(base_url('admin/schedule.php')); ?>">Jadwal Pegawai</a>
          <a href="<?php echo e(base_url('admin/attendance.php')); ?>">Rekap Absensi</a>
          <a href="<?php echo e(base_url('admin/work_locations.php')); ?>">Lokasi Kerja</a>
          <a href="<?php echo e(base_url('admin/pengumuman.php')); ?>">Pengumuman Perusahaan</a>
          <a href="<?php echo e(base_url('admin/kinerja_dapur.php')); ?>">Kinerja Dapur</a>
          <a href="<?php echo e(base_url('admin/kpi_dapur_targets.php')); ?>">KPI Dapur - Target</a>
          <a href="<?php echo e(base_url('admin/kpi_dapur_rekap.php')); ?>">KPI Dapur - Rekap</a>
          <?php if (($u['role'] ?? '') === 'owner'): ?><a href="<?php echo e(base_url('admin/value_cleanup.php')); ?>">Reset Data Nilai</a><a href="<?php echo e(base_url('admin/backup.php')); ?>">Backup Database</a><?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    <?php endif; ?>

    <div class="item"><a href="<?php echo e(base_url('admin/logout.php')); ?>"><div class="mi">⎋</div><div class="label">Logout</div></a></div>
  </div>
</div>
