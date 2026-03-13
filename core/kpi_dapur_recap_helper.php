<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function kitchen_kpi_normalize_period(string $filter, ?string $startDate = null, ?string $endDate = null, ?int $month = null, ?int $year = null): array {
  $today = new DateTimeImmutable(app_today_jakarta(), new DateTimeZone('Asia/Jakarta'));

  $month = (int)($month ?? 0);
  $year = (int)($year ?? 0);
  if ($month < 1 || $month > 12) {
    $month = (int)$today->format('n');
  }
  if ($year < 2024 || $year > 2035) {
    $year = (int)$today->format('Y');
  }

  $start = $today->format('Y-m-d');
  $end = $today->format('Y-m-d');
  $label = $today->format('d M Y');

  if ($filter === 'bulan_ini') {
    $start = $today->modify('first day of this month')->format('Y-m-d');
    $end = $today->modify('last day of this month')->format('Y-m-d');
    $month = (int)$today->format('n');
    $year = (int)$today->format('Y');
  } elseif ($filter === 'bulan_lalu') {
    $last = $today->modify('first day of last month');
    $start = $last->format('Y-m-01');
    $end = $last->modify('last day of this month')->format('Y-m-d');
    $month = (int)$last->format('n');
    $year = (int)$last->format('Y');
  } elseif ($filter === 'custom') {
    $candidateStart = is_string($startDate) ? trim($startDate) : '';
    $candidateEnd = is_string($endDate) ? trim($endDate) : '';
    $startObj = DateTimeImmutable::createFromFormat('Y-m-d', $candidateStart ?: $start);
    $endObj = DateTimeImmutable::createFromFormat('Y-m-d', $candidateEnd ?: $end);
    if ($startObj instanceof DateTimeImmutable && $endObj instanceof DateTimeImmutable) {
      if ($startObj > $endObj) {
        [$startObj, $endObj] = [$endObj, $startObj];
      }
      $start = $startObj->format('Y-m-d');
      $end = $endObj->format('Y-m-d');
      $month = (int)$startObj->format('n');
      $year = (int)$startObj->format('Y');
    }
    $label = $start . ' s/d ' . $end;
  } elseif ($filter === 'perbulan' || ($month > 0 && $year > 0)) {
    $period = DateTimeImmutable::createFromFormat('Y-n-j', $year . '-' . $month . '-1', new DateTimeZone('Asia/Jakarta'));
    if (!$period instanceof DateTimeImmutable) {
      $period = $today->modify('first day of this month');
    }
    $start = $period->format('Y-m-01');
    $end = $period->modify('last day of this month')->format('Y-m-d');
    $month = (int)$period->format('n');
    $year = (int)$period->format('Y');
  }

  $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
  if ($filter !== 'custom') {
    $label = ($bulan[$month] ?? ('Bulan ' . $month)) . ' ' . $year;
  }

  return [
    'start_date' => $start,
    'end_date' => $end,
    'month' => $month,
    'year' => $year,
    'label' => $label,
  ];
}

function kitchen_kpi_get_active_kitchen_branch_id(): int {
  try {
    if (!function_exists('inventory_active_branch_id')) {
      return 0;
    }
    $branchId = (int)inventory_active_branch_id();
    if ($branchId <= 0) {
      return 0;
    }

    $stmt = db()->prepare("SELECT id FROM branches WHERE id = ? AND branch_type = 'dapur' AND is_active = 1 LIMIT 1");
    $stmt->execute([$branchId]);
    return (int)($stmt->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    return 0;
  }
}

function kitchen_kpi_get_dapur_users(PDO $pdo, int $branchId = 0): array {
  $branchId = max(0, (int)$branchId);
  $sql = "SELECT u.id, u.name, u.role, u.branch_id
    FROM users u
    LEFT JOIN branches b ON b.id = u.branch_id
    WHERE u.role IN ('pegawai_dapur','manager_dapur')";
  $params = [];

  if ($branchId > 0) {
    $sql .= ' AND u.branch_id = ?';
    $params[] = $branchId;
  } else {
    $sql .= " AND (u.branch_id IS NULL OR b.branch_type = 'dapur')";
  }

  $sql .= ' ORDER BY u.name ASC';
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll() ?: [];
}

function kitchen_kpi_get_target_summary(PDO $pdo, string $startDate, string $endDate, int $branchId = 0): array {
  $branchId = max(0, (int)$branchId);
  $sql = "SELECT t.user_id,
      SUM(t.target_qty) AS target_qty,
      SUM(t.target_qty * COALESCE(a.point_value, 0)) AS target_point,
      COUNT(*) AS target_rows,
      COUNT(DISTINCT t.target_date) AS target_days
    FROM kitchen_kpi_targets t
    JOIN users u ON u.id = t.user_id
    JOIN kitchen_kpi_activities a ON a.id = t.activity_id
    WHERE t.target_date BETWEEN ? AND ?";
  $params = [$startDate, $endDate];
  if ($branchId > 0) {
    $sql .= ' AND u.branch_id = ?';
    $params[] = $branchId;
  }
  $sql .= ' GROUP BY t.user_id';

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll() ?: [];

  $map = [];
  foreach ($rows as $row) {
    $uid = (int)($row['user_id'] ?? 0);
    if ($uid <= 0) {
      continue;
    }
    $map[$uid] = [
      'target_qty' => (int)($row['target_qty'] ?? 0),
      'target_point' => (int)($row['target_point'] ?? 0),
      'target_rows' => (int)($row['target_rows'] ?? 0),
      'target_days' => (int)($row['target_days'] ?? 0),
    ];
  }
  return $map;
}

function kitchen_kpi_get_realization_summary(PDO $pdo, string $startDate, string $endDate, int $branchId = 0): array {
  $branchId = max(0, (int)$branchId);
  $sql = "SELECT r.user_id,
      SUM(r.qty) AS realized_qty,
      SUM(r.qty * COALESCE(a.point_value, 0)) AS realization_point,
      COUNT(DISTINCT r.id) AS realization_rows,
      COUNT(ap.id) AS approval_total,
      SUM(CASE WHEN ap.approved_at IS NOT NULL THEN 1 ELSE 0 END) AS approval_approved
    FROM kitchen_kpi_realizations r
    JOIN users u ON u.id = r.user_id
    JOIN kitchen_kpi_activities a ON a.id = r.activity_id
    LEFT JOIN kitchen_kpi_realization_approvals ap ON ap.realization_id = r.id
    WHERE r.realization_date BETWEEN ? AND ?";
  $params = [$startDate, $endDate];
  if ($branchId > 0) {
    $sql .= ' AND u.branch_id = ?';
    $params[] = $branchId;
  }
  $sql .= ' GROUP BY r.user_id';

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $rows = $stmt->fetchAll() ?: [];

  $map = [];
  foreach ($rows as $row) {
    $uid = (int)($row['user_id'] ?? 0);
    if ($uid <= 0) {
      continue;
    }
    $map[$uid] = [
      'realized_qty' => (int)($row['realized_qty'] ?? 0),
      'realization_point' => (int)($row['realization_point'] ?? 0),
      'realization_rows' => (int)($row['realization_rows'] ?? 0),
      'approval_total' => (int)($row['approval_total'] ?? 0),
      'approval_approved' => (int)($row['approval_approved'] ?? 0),
    ];
  }
  return $map;
}

function kitchen_kpi_get_employee_totals(PDO $pdo, string $startDate, string $endDate, int $branchId = 0): array {
  $users = kitchen_kpi_get_dapur_users($pdo, $branchId);
  $targets = kitchen_kpi_get_target_summary($pdo, $startDate, $endDate, $branchId);
  $realizations = kitchen_kpi_get_realization_summary($pdo, $startDate, $endDate, $branchId);

  $userMap = [];
  foreach ($users as $user) {
    $uid = (int)($user['id'] ?? 0);
    if ($uid > 0) {
      $userMap[$uid] = $user;
    }
  }

  $userIds = array_unique(array_merge(array_keys($userMap), array_keys($targets), array_keys($realizations)));
  $rows = [];

  foreach ($userIds as $userId) {
    $uid = (int)$userId;
    if ($uid <= 0) {
      continue;
    }

    $user = $userMap[$uid] ?? ['id' => $uid, 'name' => 'User #' . $uid, 'role' => '-', 'branch_id' => 0];
    $target = $targets[$uid] ?? ['target_qty' => 0, 'target_point' => 0, 'target_rows' => 0, 'target_days' => 0];
    $real = $realizations[$uid] ?? ['realized_qty' => 0, 'realization_point' => 0, 'realization_rows' => 0, 'approval_total' => 0, 'approval_approved' => 0];

    $targetQty = (int)$target['target_qty'];
    $realizedQty = (int)$real['realized_qty'];
    $targetPoint = (int)$target['target_point'];
    $totalPoint = (int)$real['realization_point'];
    $realizationRows = (int)$real['realization_rows'];
    $approvalTotal = (int)$real['approval_total'];
    $approvalApproved = (int)$real['approval_approved'];

    if ($targetQty > 0 && $realizedQty > 0) {
      $dataStatusText = 'Target & realisasi ada';
    } elseif ($targetQty === 0 && $realizedQty > 0) {
      $dataStatusText = 'Hanya realisasi';
    } elseif ($targetQty > 0 && $realizedQty === 0) {
      $dataStatusText = 'Hanya target';
    } else {
      $dataStatusText = 'Belum ada data';
    }

    if ($realizationRows === 0) {
      $approvalStatusText = 'Belum ada realisasi';
    } elseif ($approvalTotal === 0) {
      $approvalStatusText = 'Tidak perlu approval / belum tersinkron';
    } else {
      $approvalStatusText = $approvalApproved . '/' . $approvalTotal . ' disetujui';
    }

    $rows[] = [
      'user_id' => $uid,
      'name' => (string)($user['name'] ?? ('User #' . $uid)),
      'role' => (string)($user['role'] ?? '-'),
      'branch_id' => (int)($user['branch_id'] ?? 0),
      'target_qty' => $targetQty,
      'realized_qty' => $realizedQty,
      'target_point' => $targetPoint,
      'total_point' => $totalPoint,
      'realization_point' => $totalPoint,
      'selisih_qty' => $realizedQty - $targetQty,
      'selisih_point' => $totalPoint - $targetPoint,
      'persentase_capaian' => $targetPoint > 0 ? (($totalPoint / $targetPoint) * 100) : null,
      'approval_status_text' => $approvalStatusText,
      'data_status_text' => $dataStatusText,
      'target_rows' => (int)$target['target_rows'],
      'target_days' => (int)$target['target_days'],
      'realization_rows' => $realizationRows,
      'approval_total' => $approvalTotal,
      'approval_approved' => $approvalApproved,
    ];
  }

  usort($rows, static function (array $a, array $b): int {
    return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
  });

  return $rows;
}

function kitchen_kpi_get_recap_grand_totals(array $rows): array {
  $totalTargetQty = 0;
  $totalRealizedQty = 0;
  $totalTargetPoint = 0;
  $totalRealizationPoint = 0;
  $capaianTotal = 0.0;
  $capaianCount = 0;

  foreach ($rows as $row) {
    $totalTargetQty += (int)($row['target_qty'] ?? 0);
    $totalRealizedQty += (int)($row['realized_qty'] ?? 0);
    $totalTargetPoint += (int)($row['target_point'] ?? 0);
    $totalRealizationPoint += (int)($row['realization_point'] ?? ($row['total_point'] ?? 0));
    if (isset($row['persentase_capaian']) && $row['persentase_capaian'] !== null) {
      $capaianTotal += (float)$row['persentase_capaian'];
      $capaianCount++;
    }
  }

  return [
    'total_target_qty' => $totalTargetQty,
    'total_realized_qty' => $totalRealizedQty,
    'total_target_point' => $totalTargetPoint,
    'total_realization_point' => $totalRealizationPoint,
    'total_point_all' => $totalRealizationPoint,
    'avg_capaian_percent' => $capaianCount > 0 ? ($capaianTotal / $capaianCount) : null,
  ];
}

function kitchen_kpi_get_debug_meta(PDO $pdo, string $startDate, string $endDate, int $branchId = 0): array {
  $activeUsers = kitchen_kpi_get_dapur_users($pdo, $branchId);

  $targetSql = 'SELECT COUNT(*) FROM kitchen_kpi_targets t JOIN users u ON u.id=t.user_id WHERE t.target_date BETWEEN ? AND ?';
  $targetParams = [$startDate, $endDate];
  if ($branchId > 0) {
    $targetSql .= ' AND u.branch_id = ?';
    $targetParams[] = $branchId;
  }
  $targetStmt = $pdo->prepare($targetSql);
  $targetStmt->execute($targetParams);
  $targetCount = (int)$targetStmt->fetchColumn();

  $realSql = 'SELECT COUNT(*) FROM kitchen_kpi_realizations r JOIN users u ON u.id=r.user_id WHERE r.realization_date BETWEEN ? AND ?';
  $realParams = [$startDate, $endDate];
  if ($branchId > 0) {
    $realSql .= ' AND u.branch_id = ?';
    $realParams[] = $branchId;
  }
  $realStmt = $pdo->prepare($realSql);
  $realStmt->execute($realParams);
  $realCount = (int)$realStmt->fetchColumn();

  $finalRows = kitchen_kpi_get_employee_totals($pdo, $startDate, $endDate, $branchId);

  return [
    'active_dapur_user_count' => count($activeUsers),
    'target_row_count' => $targetCount,
    'realization_row_count' => $realCount,
    'final_row_count' => count($finalRows),
  ];
}
