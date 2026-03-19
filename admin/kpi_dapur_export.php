<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
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
$filter = (string)($_GET['filter'] ?? 'perbulan');
$month = isset($_GET['month']) ? (int)$_GET['month'] : null;
$year = isset($_GET['year']) ? (int)$_GET['year'] : null;
$startDate = isset($_GET['start_date']) ? (string)$_GET['start_date'] : null;
$endDate = isset($_GET['end_date']) ? (string)$_GET['end_date'] : null;

$period = kitchen_kpi_normalize_period($filter, $startDate, $endDate, $month, $year);
$rows = kitchen_kpi_get_employee_totals(db(), $period['start_date'], $period['end_date'], $branchId);
$totals = kitchen_kpi_get_recap_grand_totals($rows);

$filename = 'kpi-dapur-pegawai-' . $period['year'] . '-' . str_pad((string)$period['month'], 2, '0', STR_PAD_LEFT) . '.csv';
if ($filter === 'custom') {
  $filename = 'kpi-dapur-pegawai-' . $period['start_date'] . '_sampai_' . $period['end_date'] . '.csv';
}

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'wb');

fputcsv($out, ['No', 'Nama Pegawai', 'Role', 'Target Qty', 'Realisasi Qty', 'Target Point', 'Total Point', 'Selisih Point', 'Capaian Percent', 'Approval', 'Status Data']);

$no = 1;
foreach ($rows as $row) {
  fputcsv($out, [
    $no++,
    (string)$row['name'],
    (string)$row['role'],
    number_format((float)$row['target_qty'], 1, '.', ''),
    number_format((float)$row['realized_qty'], 1, '.', ''),
    number_format((float)$row['target_point'], 1, '.', ''),
    number_format((float)$row['total_point'], 1, '.', ''),
    number_format((float)$row['selisih_point'], 1, '.', ''),
    $row['persentase_capaian'] === null ? '-' : number_format((float)$row['persentase_capaian'], 1, '.', ''),
    (string)$row['approval_status_text'],
    (string)$row['data_status_text'],
  ]);
}

fputcsv($out, []);
fputcsv($out, ['TOTAL']);
fputcsv($out, ['Total Target Qty', number_format((float)$totals['total_target_qty'], 1, '.', '')]);
fputcsv($out, ['Total Realisasi Qty', number_format((float)$totals['total_realized_qty'], 1, '.', '')]);
fputcsv($out, ['Total Target Point', number_format((float)$totals['total_target_point'], 1, '.', '')]);
fputcsv($out, ['Total Point Semua Pegawai', number_format((float)$totals['total_point_all'], 1, '.', '')]);
fputcsv($out, ['Rata-rata Capaian', $totals['avg_capaian_percent'] === null ? '-' : number_format((float)$totals['avg_capaian_percent'], 1, '.', '')]);

fclose($out);
exit;
