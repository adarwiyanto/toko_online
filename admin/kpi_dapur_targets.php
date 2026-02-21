<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/inventory_helpers.php';

start_secure_session();
require_admin();
ensure_kitchen_kpi_tables();

$me = current_user();
$role = (string)($me['role'] ?? '');
if (!in_array($role, ['owner', 'admin', 'manager_dapur'], true)) {
  http_response_code(403);
  exit('Forbidden');
}

$branchId = function_exists('inventory_active_branch_id') ? (int)inventory_active_branch_id() : 0;
$canAutoApprove = in_array($role, ['owner', 'admin', 'manager_dapur'], true);

$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'set_target') {
      $userId = (int)($_POST['user_id'] ?? 0);
      $activityId = (int)($_POST['activity_id'] ?? 0);
      $date = trim((string)($_POST['target_date'] ?? app_today_jakarta()));
      $qty = max(0, (int)($_POST['target_qty'] ?? 0));

      if ($userId <= 0 || $activityId <= 0) {
        throw new Exception('Data target tidak valid.');
      }

      $approvedBy = $canAutoApprove ? (int)($me['id'] ?? 0) : null;
      $approvedAt = $canAutoApprove ? app_now_jakarta('Y-m-d H:i:s') : null;

      $stmt = db()->prepare('INSERT INTO kitchen_kpi_targets (user_id,activity_id,target_date,target_qty,created_by,approved_by,approved_at)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
          target_qty=VALUES(target_qty),
          created_by=VALUES(created_by),
          approved_by=VALUES(approved_by),
          approved_at=VALUES(approved_at),
          updated_at=NOW()');
      $stmt->execute([$userId, $activityId, $date, $qty, (int)($me['id'] ?? 0), $approvedBy, $approvedAt]);
      $ok = 'Target KPI dapur disimpan.';
    }

    if ($action === 'approve_target') {
      $targetId = (int)($_POST['target_id'] ?? 0);
      if ($targetId <= 0) {
        throw new Exception('Data target tidak valid.');
      }
      $stmt = db()->prepare('UPDATE kitchen_kpi_targets SET approved_by=?, approved_at=NOW() WHERE id=? AND approved_at IS NULL');
      $stmt->execute([(int)($me['id'] ?? 0), $targetId]);
      if ($stmt->rowCount() <= 0) {
        throw new Exception('Target tidak ditemukan atau sudah disetujui.');
      }
      $ok = 'Target berhasil disetujui.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$employeeSql = "SELECT id,name FROM users WHERE role='pegawai_dapur'";
$employeeParams = [];
if ($branchId > 0) {
  $employeeSql .= ' AND branch_id=?';
  $employeeParams[] = $branchId;
}
$employeeSql .= ' ORDER BY name ASC';
$employeeStmt = db()->prepare($employeeSql);
$employeeStmt->execute($employeeParams);
$employees = $employeeStmt->fetchAll();

$activities = db()->query("SELECT id,activity_name FROM kitchen_kpi_activities ORDER BY activity_name ASC")->fetchAll();

$targetSql = "SELECT t.id, t.target_date, u.name, a.activity_name, t.target_qty, t.approved_at,
    approver.name AS approved_by_name
  FROM kitchen_kpi_targets t
  JOIN users u ON u.id=t.user_id
  JOIN kitchen_kpi_activities a ON a.id=t.activity_id
  LEFT JOIN users approver ON approver.id=t.approved_by
  WHERE 1=1";
$targetParams = [];
if ($branchId > 0) {
  $targetSql .= ' AND u.branch_id=?';
  $targetParams[] = $branchId;
}
$targetSql .= ' ORDER BY t.target_date DESC, u.name ASC, a.activity_name ASC';
$targetStmt = db()->prepare($targetSql);
$targetStmt->execute($targetParams);
$targets = $targetStmt->fetchAll();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>KPI Dapur - Target</title><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"></head>
<body><div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><div class="badge">KPI Dapur - Target</div></div><div class="content">
<?php if($err):?><div class="card" style="background:rgba(251,113,133,.12)"><?php echo e($err); ?></div><?php endif; ?><?php if($ok):?><div class="card" style="background:rgba(52,211,153,.12)"><?php echo e($ok); ?></div><?php endif; ?>
<div class="grid cols-2"><div class="card"><h3>Atur Target</h3><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="set_target"><div class="row"><label>Pegawai Dapur</label><select name="user_id"><?php foreach($employees as $emp):?><option value="<?php echo e($emp['id']); ?>"><?php echo e($emp['name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Kegiatan</label><select name="activity_id"><?php foreach($activities as $a):?><option value="<?php echo e($a['id']); ?>"><?php echo e($a['activity_name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Tanggal</label><input type="date" name="target_date" value="<?php echo e(app_today_jakarta()); ?>"></div><div class="row"><label>Target Qty</label><input type="number" min="0" name="target_qty" required></div><button class="btn" type="submit">Simpan Target</button></form></div></div>

<div class="card"><h3>Daftar Target KPI Dapur</h3><table class="table"><thead><tr><th>Tanggal</th><th>Pegawai</th><th>Kegiatan</th><th>Target</th><th>Status Target</th><th>Aksi</th></tr></thead><tbody><?php if (!$targets): ?><tr><td colspan="6">Belum ada target.</td></tr><?php else: foreach($targets as $t): ?><tr><td><?php echo e($t['target_date']); ?></td><td><?php echo e($t['name']); ?></td><td><?php echo e($t['activity_name']); ?></td><td><?php echo e((string)$t['target_qty']); ?></td><td><?php if (!empty($t['approved_at'])): ?>Disetujui<?php if (!empty($t['approved_by_name'])): ?> (<?php echo e($t['approved_by_name']); ?>)<?php endif; ?><?php else: ?>Menunggu persetujuan<?php endif; ?></td><td><?php if (empty($t['approved_at'])): ?><form method="post" style="margin:0"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="approve_target"><input type="hidden" name="target_id" value="<?php echo e((string)$t['id']); ?>"><button class="btn" type="submit">Setujui</button></form><?php else: ?>-<?php endif; ?></td></tr><?php endforeach; endif; ?></tbody></table></div>
</div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
