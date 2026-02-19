<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/attendance.php';

start_secure_session();
require_login();
ensure_employee_roles();
ensure_employee_attendance_tables();
ensure_company_announcements_table();

$me = current_user();
$role = (string)($me['role'] ?? '');
$isKitchenRole = in_array($role, ['pegawai_dapur', 'manager_dapur'], true);
$isAdminRole = $role === 'admin';
$isPosRole = in_array($role, ['pegawai_pos', 'pegawai_non_pos', 'manager_toko'], true);

if (!$isKitchenRole && !$isAdminRole && !$isPosRole) {
  redirect(base_url('pos/index.php'));
}

$attendanceToday = attendance_today_for_user((int)($me['id'] ?? 0));
$hasCheckinToday = !empty($attendanceToday['checkin_time']);
$audience = $isKitchenRole ? 'dapur' : 'toko';
$announcement = latest_active_announcement($audience);
$jobHomeUrl = $isKitchenRole ? kitchen_job_home_by_role($role) : ($isAdminRole ? base_url('admin/dashboard.php') : base_url('pos/index.php'));
$confirmSessionKey = $isKitchenRole ? 'kitchen_attendance_confirmed' : ($isAdminRole ? 'admin_attendance_confirmed' : 'pos_attendance_confirmed');
$pendingSessionKey = $isKitchenRole ? 'kitchen_attendance_gate_pending' : ($isAdminRole ? 'admin_attendance_gate_pending' : 'pos_attendance_gate_pending');

if (($_GET['attendance_confirm'] ?? '') === 'sudah') {
  $_SESSION[$confirmSessionKey] = true;
  unset($_SESSION[$pendingSessionKey]);
  redirect($jobHomeUrl);
}

if ($hasCheckinToday) {
  $_SESSION[$confirmSessionKey] = true;
  unset($_SESSION[$pendingSessionKey]);
  redirect($jobHomeUrl);
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Konfirmasi Absensi</title>
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
</head>
<body>
<div class="container" style="max-width:640px;margin:20px auto">
  <div class="card">
    <h3>Konfirmasi Absensi Hari Ini</h3>
    <p>Apakah Anda sudah absen masuk hari ini?</p>
    <?php if ($announcement): ?>
      <div class="card" style="margin-top:12px;background:rgba(96,165,250,.10);border-color:rgba(96,165,250,.35)">
        <h4 style="margin:0 0 6px 0">Pengumuman Perusahaan</h4>
        <div style="font-weight:600"><?php echo e((string)$announcement['title']); ?></div>
        <p style="margin:6px 0 0 0;white-space:pre-line"><?php echo e((string)$announcement['message']); ?></p>
        <small>Diumumkan oleh: <?php echo e((string)($announcement['posted_by_name'] ?? 'Admin')); ?></small>
      </div>
    <?php endif; ?>
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px">
      <a class="btn" href="<?php echo e(base_url('pos/attendance_confirm.php?attendance_confirm=sudah')); ?>">Sudah</a>
      <a class="btn" href="<?php echo e(base_url('pos/absen.php?type=in')); ?>">Belum, Absen Sekarang</a>
    </div>
  </div>
</div>
</body>
</html>
