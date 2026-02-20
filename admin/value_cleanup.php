<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/inventory_helpers.php';

start_secure_session();
require_admin();

$me = current_user();
if (($me['role'] ?? '') !== 'owner') {
  http_response_code(403);
  exit('Forbidden');
}

$branchId = inventory_active_branch_id();
$activeBranch = inventory_active_branch();
$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $confirm = trim((string)($_POST['confirm_text'] ?? ''));
  if ($confirm !== 'HAPUS') {
    $error = 'Ketik HAPUS untuk konfirmasi.';
  } elseif ($branchId <= 0) {
    $error = 'Cabang aktif tidak valid.';
  } else {
    try {
      $db = db();
      $db->beginTransaction();

      $stmt = $db->prepare("SELECT id FROM users WHERE branch_id=?");
      $stmt->execute([$branchId]);
      $userIds = array_map(static fn($r) => (int)$r['id'], $stmt->fetchAll());

      if ($userIds) {
        $phUsers = implode(',', array_fill(0, count($userIds), '?'));
        $stmtAtt = $db->prepare("DELETE FROM employee_attendance WHERE user_id IN ($phUsers)");
        $stmtAtt->execute($userIds);

        $stmtSales = $db->prepare("DELETE FROM sales WHERE created_by IN ($phUsers)");
        $stmtSales->execute($userIds);
      }

      $stmt = $db->prepare("SELECT id FROM inv_opening_stock WHERE branch_id=?");
      $stmt->execute([$branchId]);
      $openingIds = array_map(static fn($r) => (int)$r['id'], $stmt->fetchAll());
      if ($openingIds) {
        $ph = implode(',', array_fill(0, count($openingIds), '?'));
        $db->prepare("DELETE FROM inv_opening_stock_items WHERE opening_id IN ($ph)")->execute($openingIds);
      }
      $db->prepare("DELETE FROM inv_opening_stock WHERE branch_id=?")->execute([$branchId]);

      $stmt = $db->prepare("SELECT id FROM inv_purchases WHERE branch_id=?");
      $stmt->execute([$branchId]);
      $purchaseIds = array_map(static fn($r) => (int)$r['id'], $stmt->fetchAll());
      if ($purchaseIds) {
        $ph = implode(',', array_fill(0, count($purchaseIds), '?'));
        $db->prepare("DELETE FROM inv_purchase_items WHERE purchase_id IN ($ph)")->execute($purchaseIds);
      }
      $db->prepare("DELETE FROM inv_purchases WHERE branch_id=?")->execute([$branchId]);

      $stmt = $db->prepare("SELECT id FROM inv_stock_opname WHERE branch_id=?");
      $stmt->execute([$branchId]);
      $opnameIds = array_map(static fn($r) => (int)$r['id'], $stmt->fetchAll());
      if ($opnameIds) {
        $ph = implode(',', array_fill(0, count($opnameIds), '?'));
        $db->prepare("DELETE FROM inv_stock_opname_items WHERE opname_id IN ($ph)")->execute($opnameIds);
      }
      $db->prepare("DELETE FROM inv_stock_opname WHERE branch_id=?")->execute([$branchId]);

      $stmt = $db->prepare("SELECT id FROM inv_kitchen_transfers WHERE source_branch_id=? OR target_branch_id=?");
      $stmt->execute([$branchId, $branchId]);
      $transferIds = array_map(static fn($r) => (int)$r['id'], $stmt->fetchAll());
      if ($transferIds) {
        $ph = implode(',', array_fill(0, count($transferIds), '?'));
        $db->prepare("DELETE FROM inv_kitchen_transfer_items WHERE transfer_id IN ($ph)")->execute($transferIds);
        $db->prepare("DELETE FROM inv_kitchen_transfers WHERE id IN ($ph)")->execute($transferIds);
      }

      $db->prepare("DELETE FROM inv_stock_ledger WHERE branch_id=?")->execute([$branchId]);

      $db->commit();
      $flash = 'Data nilai cabang berhasil dihapus. Data master (nama cabang, nama produk, nama pegawai) tetap aman.';
    } catch (Throwable $e) {
      if (db()->inTransaction()) {
        db()->rollBack();
      }
      $error = 'Gagal menghapus data: ' . $e->getMessage();
    }
  }
}

$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Reset Data Nilai</title>
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
    </div>
    <div class="content">
      <div class="card">
        <h3 style="margin-top:0">Reset Data Nilai Cabang<?php if ($activeBranch): ?> - <?php echo e((string)$activeBranch['name']); ?><?php endif; ?></h3>
        <p>Halaman ini hanya untuk <strong>owner</strong>. Akan menghapus data bernilai seperti absensi, transaksi, dan histori stok untuk cabang aktif.</p>
        <p><strong>Tidak menghapus:</strong> nama cabang, nama produk, nama pegawai, akun user, dan data master lainnya.</p>
        <?php if ($flash): ?><div class="ok"><?php echo e($flash); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="err"><?php echo e($error); ?></div><?php endif; ?>
        <form method="post" data-confirm="Yakin hapus seluruh data nilai untuk cabang aktif?">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
          <div class="row">
            <label>Ketik <code>HAPUS</code> untuk konfirmasi</label>
            <input type="text" name="confirm_text" autocomplete="off" required>
          </div>
          <button class="btn danger" type="submit">Hapus Data Nilai Cabang Aktif</button>
        </form>
      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
