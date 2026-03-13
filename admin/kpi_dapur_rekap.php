<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/../core/kpi_dapur_recap_helper.php';

start_secure_session();
require_admin();
ensure_kitchen_kpi_tables();

$me = current_user();
$role = (string)($me['role'] ?? '');
if (!in_array($role, ['owner', 'admin', 'manager_dapur'], true)) {
  http_response_code(403);
  exit('Forbidden');
}

$branchId = kitchen_kpi_get_active_kitchen_branch_id();

$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');
  try {
    if ($action === 'approve_realization') {
      $approvalId = (int)($_POST['approval_id'] ?? 0);
      if ($approvalId <= 0) {
        throw new Exception('Data persetujuan tidak valid.');
      }
      $stmt = db()->prepare('UPDATE kitchen_kpi_realization_approvals SET approved_at=NOW() WHERE id=? AND approver_user_id=?');
      $stmt->execute([$approvalId, (int)($me['id'] ?? 0)]);
      if ($stmt->rowCount() <= 0) {
        throw new Exception('Persetujuan tidak ditemukan atau bukan jatah Anda.');
      }
      $ok = 'Realisasi berhasil disetujui.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$filter = (string)($_GET['filter'] ?? 'hari_ini');
$monthInput = isset($_GET['month']) ? (int)$_GET['month'] : null;
$yearInput = isset($_GET['year']) ? (int)$_GET['year'] : null;
$startInput = isset($_GET['start_date']) ? (string)$_GET['start_date'] : null;
$endInput = isset($_GET['end_date']) ? (string)$_GET['end_date'] : null;

if ($monthInput !== null && $yearInput !== null) {
  $filter = 'perbulan';
}

$period = kitchen_kpi_normalize_period($filter, $startInput, $endInput, $monthInput, $yearInput);
$start = $period['start_date'];
$end = $period['end_date'];
$currentMonth = (int)$period['month'];
$currentYear = (int)$period['year'];

// existing stable logic preserved
$baseQuery = "SELECT r.id AS realization_id,
    u.name,
    a.activity_name,
    t.target_date AS period_date,
    t.target_qty,
    t.approved_at AS target_approved_at,
    COALESCE(r.qty,0) AS realized_qty,
    COUNT(ap.id) AS approver_total,
    SUM(CASE WHEN ap.approved_at IS NOT NULL THEN 1 ELSE 0 END) AS approver_approved
  FROM kitchen_kpi_targets t
  JOIN users u ON u.id=t.user_id
  JOIN kitchen_kpi_activities a ON a.id=t.activity_id
  LEFT JOIN kitchen_kpi_realizations r ON r.user_id=t.user_id AND r.activity_id=t.activity_id AND r.realization_date=t.target_date
  LEFT JOIN kitchen_kpi_realization_approvals ap ON ap.realization_id=r.id
  WHERE t.target_date BETWEEN ? AND ?";
$params = [$start, $end];
if ($branchId > 0) {
  $baseQuery .= ' AND u.branch_id=?';
  $params[] = $branchId;
}
$baseQuery .= ' GROUP BY r.id, u.name, a.activity_name, t.target_date, t.target_qty, t.approved_at, r.qty
  ORDER BY t.target_date DESC, u.name ASC, a.activity_name ASC';

$stmt = db()->prepare($baseQuery);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$pendingQuery = "SELECT ap.id, u.name, a.activity_name, r.realization_date, r.qty
  FROM kitchen_kpi_realization_approvals ap
  JOIN kitchen_kpi_realizations r ON r.id=ap.realization_id
  JOIN users u ON u.id=r.user_id
  JOIN kitchen_kpi_activities a ON a.id=r.activity_id
  WHERE ap.approver_user_id=? AND ap.approved_at IS NULL";
$pendingParams = [(int)($me['id'] ?? 0)];
if ($branchId > 0) {
  $pendingQuery .= ' AND u.branch_id=?';
  $pendingParams[] = $branchId;
}
$pendingQuery .= ' ORDER BY r.realization_date DESC, u.name ASC, a.activity_name ASC';
$stmtPending = db()->prepare($pendingQuery);
$stmtPending->execute($pendingParams);
$pendingApprovals = $stmtPending->fetchAll();

$safeRows = kitchen_kpi_get_employee_totals(db(), $start, $end, $branchId);
$safeTotals = kitchen_kpi_get_recap_grand_totals($safeRows);
$debugMeta = kitchen_kpi_get_debug_meta(db(), $start, $end, $branchId);

$yearOptions = [];
for ($y = ((int)date('Y')) - 1; $y <= ((int)date('Y')) + 1; $y++) {
  $yearOptions[] = $y;
}
$monthNames = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
$exportUrl = base_url('admin/kpi_dapur_export.php?month=' . $currentMonth . '&year=' . $currentYear);
if ($filter === 'custom') {
  $exportUrl = base_url('admin/kpi_dapur_export.php?filter=custom&start_date=' . urlencode($start) . '&end_date=' . urlencode($end));
}
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>KPI Dapur - Rekap</title>
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <div class="badge">KPI Dapur - Rekap</div>
    </div>
    <div class="content">
      <?php if ($err): ?><div class="card" style="background:rgba(251,113,133,.12)"><?php echo e($err); ?></div><?php endif; ?>
      <?php if ($ok): ?><div class="card" style="background:rgba(52,211,153,.12)"><?php echo e($ok); ?></div><?php endif; ?>

      <div class="card">
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end">
          <div class="row"><label>Filter</label><select name="filter"><option value="hari_ini" <?php echo $filter === 'hari_ini' ? 'selected' : ''; ?>>Perhari (Hari ini)</option><option value="perbulan" <?php echo $filter === 'perbulan' ? 'selected' : ''; ?>>Perbulan</option><option value="bulan_ini" <?php echo $filter === 'bulan_ini' ? 'selected' : ''; ?>>Bulan ini</option><option value="bulan_lalu" <?php echo $filter === 'bulan_lalu' ? 'selected' : ''; ?>>Bulan lalu</option><option value="custom" <?php echo $filter === 'custom' ? 'selected' : ''; ?>>Custom</option></select></div>
          <div class="row"><label>Start</label><input type="date" name="start_date" value="<?php echo e($start); ?>"></div>
          <div class="row"><label>End</label><input type="date" name="end_date" value="<?php echo e($end); ?>"></div>
          <button class="btn" type="submit">Terapkan</button>
        </form>
      </div>

      <div class="card">
        <h3>Persetujuan Realisasi</h3>
        <table class="table">
          <thead><tr><th>Tanggal</th><th>Pegawai</th><th>Kegiatan</th><th>Qty</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php if (!$pendingApprovals): ?>
            <tr><td colspan="5">Tidak ada realisasi yang menunggu persetujuan Anda.</td></tr>
          <?php else: foreach ($pendingApprovals as $p): ?>
            <tr>
              <td><?php echo e($p['realization_date']); ?></td>
              <td><?php echo e($p['name']); ?></td>
              <td><?php echo e($p['activity_name']); ?></td>
              <td><?php echo e((string)$p['qty']); ?></td>
              <td>
                <form method="post" style="margin:0">
                  <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                  <input type="hidden" name="action" value="approve_realization">
                  <input type="hidden" name="approval_id" value="<?php echo e((string)$p['id']); ?>">
                  <button class="btn" type="submit">Setujui</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="card">
        <table class="table">
          <thead><tr><th>Tanggal</th><th>Pegawai</th><th>Kegiatan</th><th>Status Target</th><th>Target</th><th>Realisasi</th><th>Status Persetujuan Realisasi</th></tr></thead>
          <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="7">Belum ada data KPI pada periode ini.</td></tr>
          <?php else: foreach ($rows as $r): $approved = (int)$r['approver_approved']; $total = (int)$r['approver_total']; $isApproved = (int)$r['realization_id'] > 0 && $approved >= 1; ?>
            <tr>
              <td><?php echo e($r['period_date']); ?></td><td><?php echo e($r['name']); ?></td><td><?php echo e($r['activity_name']); ?></td>
              <td><?php echo !empty($r['target_approved_at']) ? 'Disetujui' : 'Menunggu persetujuan'; ?></td>
              <td><?php echo e((string)$r['target_qty']); ?></td><td><?php echo e((string)$r['realized_qty']); ?></td>
              <td><?php if ((int)$r['realization_id'] <= 0): ?>Belum diinput<?php else: ?><?php echo e((string)$approved . '/' . (string)$total); ?><?php echo $isApproved ? ' (Disetujui)' : ' (Menunggu persetujuan)'; ?><?php endif; ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <?php if (!$rows && $safeRows): ?>
        <div class="card" style="background:rgba(251,191,36,.12);border-color:rgba(251,191,36,.35)">
          Rekap lama tidak menemukan pasangan target dan realisasi lengkap untuk periode ini. Menampilkan rekap aman tambahan.
        </div>
      <?php endif; ?>

      <div class="card kpi-safe-card">
        <h3>Rekap Total Point Pegawai Dapur</h3>
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;margin-bottom:12px">
          <input type="hidden" name="filter" value="perbulan">
          <div class="row"><label>Bulan</label><select name="month"><?php foreach ($monthNames as $idx => $name): ?><option value="<?php echo e((string)$idx); ?>" <?php echo $currentMonth === $idx ? 'selected' : ''; ?>><?php echo e($name); ?></option><?php endforeach; ?></select></div>
          <div class="row"><label>Tahun</label><select name="year"><?php foreach ($yearOptions as $yearOpt): ?><option value="<?php echo e((string)$yearOpt); ?>" <?php echo $currentYear === (int)$yearOpt ? 'selected' : ''; ?>><?php echo e((string)$yearOpt); ?></option><?php endforeach; ?></select></div>
          <button class="btn" type="submit">Tampilkan</button>
          <a class="btn" href="<?php echo e($exportUrl); ?>">Export CSV</a>
        </form>
        <div style="margin-bottom:8px">Periode: <strong><?php echo e($period['label']); ?></strong> (<?php echo e($start); ?> s/d <?php echo e($end); ?>)</div>

        <table class="table kpi-safe-table">
          <thead>
          <tr><th>No</th><th>Nama Pegawai</th><th>Role</th><th>Target Qty</th><th>Realisasi Qty</th><th>Target Point</th><th>Total Point</th><th>Selisih Point</th><th>Capaian %</th><th>Approval</th><th>Status Data</th></tr>
          </thead>
          <tbody>
          <?php if (!$safeRows): ?>
            <tr><td colspan="11">Belum ada data KPI untuk periode ini.</td></tr>
          <?php else: $no = 1; foreach ($safeRows as $safe): ?>
            <tr>
              <td><?php echo e((string)$no++); ?></td>
              <td><?php echo e((string)$safe['name']); ?></td>
              <td><?php echo e((string)$safe['role']); ?></td>
              <td><?php echo e(number_format((int)$safe['target_qty'], 0, ',', '.')); ?></td>
              <td><?php echo e(number_format((int)$safe['realized_qty'], 0, ',', '.')); ?></td>
              <td><?php echo e(number_format((int)$safe['target_point'], 0, ',', '.')); ?></td>
              <td><?php echo e(number_format((int)$safe['total_point'], 0, ',', '.')); ?></td>
              <td><?php echo e(number_format((int)$safe['selisih_point'], 0, ',', '.')); ?></td>
              <td><?php echo $safe['persentase_capaian'] === null ? '-' : e(number_format((float)$safe['persentase_capaian'], 2, ',', '.') . '%'); ?></td>
              <td><?php echo e((string)$safe['approval_status_text']); ?></td>
              <td><?php echo e((string)$safe['data_status_text']); ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
          <tfoot>
          <tr>
            <th colspan="3">TOTAL</th>
            <th><?php echo e(number_format((int)$safeTotals['total_target_qty'], 0, ',', '.')); ?></th>
            <th><?php echo e(number_format((int)$safeTotals['total_realized_qty'], 0, ',', '.')); ?></th>
            <th><?php echo e(number_format((int)$safeTotals['total_target_point'], 0, ',', '.')); ?></th>
            <th><?php echo e(number_format((int)$safeTotals['total_point_all'], 0, ',', '.')); ?></th>
            <th><?php echo e(number_format(((int)$safeTotals['total_point_all'] - (int)$safeTotals['total_target_point']), 0, ',', '.')); ?></th>
            <th><?php echo $safeTotals['avg_capaian_percent'] === null ? '-' : e(number_format((float)$safeTotals['avg_capaian_percent'], 2, ',', '.') . '%'); ?></th>
            <th colspan="2">Ringkasan Semua Pegawai</th>
          </tr>
          </tfoot>
        </table>
      </div>

      <?php if (isset($_GET['debug_kpi']) && (string)$_GET['debug_kpi'] === '1'): ?>
        <div class="card kpi-debug-box">
          <h3>Debug KPI Dapur</h3>
          <ul style="margin:0;padding-left:18px">
            <li>Periode: <?php echo e($start); ?> s/d <?php echo e($end); ?></li>
            <li>branch_id: <?php echo e((string)$branchId); ?></li>
            <li>active_dapur_user_count: <?php echo e((string)$debugMeta['active_dapur_user_count']); ?></li>
            <li>target_row_count: <?php echo e((string)$debugMeta['target_row_count']); ?></li>
            <li>realization_row_count: <?php echo e((string)$debugMeta['realization_row_count']); ?></li>
            <li>final_row_count: <?php echo e((string)$debugMeta['final_row_count']); ?></li>
          </ul>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
