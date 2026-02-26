<?php
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_role(['owner', 'admin']);
inventory_ensure_tables();

$u = current_user();
$userId = (int)($u['id'] ?? 0);
$branchId = inventory_active_branch_id();
$branch = inventory_active_branch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'create') {
    $opnameDate = (string)($_POST['opname_date'] ?? date('Y-m-d'));
    $now = inventory_now();

    db()->beginTransaction();
    try {
      $stmt = db()->prepare("INSERT INTO inv_stock_opname (branch_id, opname_date, status, created_by, created_at) VALUES (?, ?, 'DRAFT', ?, ?)");
      $stmt->execute([$branchId, $opnameDate, $userId, $now]);
      $opnameId = (int)db()->lastInsertId();

      $productSql = "SELECT id FROM inv_products WHERE is_deleted=0 AND is_hidden=0";
      if (($branch['branch_type'] ?? '') === 'toko') {
        $productSql .= " AND audience='toko' AND (type='FINISHED' OR kitchen_group='finished')";
      } else {
        $productSql .= " AND audience='dapur'";
      }
      $products = db()->query($productSql)->fetchAll();
      $insertItem = db()->prepare("INSERT INTO inv_stock_opname_items (opname_id, product_id, system_qty, counted_qty, diff_qty, note) VALUES (?,?,?,?,?,NULL)");
      foreach ($products as $product) {
        $pid = (int)$product['id'];
        $systemQty = inventory_stock_by_product($pid);
        $insertItem->execute([$opnameId, $pid, $systemQty, $systemQty, 0]);
      }
      db()->commit();
      inventory_set_flash('ok', 'Dokumen opname draft berhasil dibuat.');
      redirect(base_url('admin/inventory_opname.php?view=' . $opnameId));
    } catch (Throwable $e) {
      db()->rollBack();
      inventory_set_flash('error', 'Gagal membuat dokumen opname.');
      redirect(base_url('admin/inventory_opname.php'));
    }
  }

  if ($action === 'save_items') {
    $opnameId = (int)($_POST['opname_id'] ?? 0);
    $statusStmt = db()->prepare("SELECT status FROM inv_stock_opname WHERE id=? AND branch_id=? LIMIT 1");
    $statusStmt->execute([$opnameId, $branchId]);
    $header = $statusStmt->fetch();
    if (!$header || $header['status'] !== 'DRAFT') {
      inventory_set_flash('error', 'Dokumen sudah POSTED dan terkunci.');
      redirect(base_url('admin/inventory_opname.php?view=' . $opnameId));
    }

    $countedMap = $_POST['counted_qty'] ?? [];
    $noteMap = $_POST['note'] ?? [];
    $itemStmt = db()->prepare("UPDATE inv_stock_opname_items SET counted_qty=?, diff_qty=?, note=? WHERE id=? AND opname_id=?");
    $readStmt = db()->prepare("SELECT id, system_qty FROM inv_stock_opname_items WHERE id=? AND opname_id=?");

    foreach ($countedMap as $itemIdRaw => $countedRaw) {
      $itemId = (int)$itemIdRaw;
      $counted = (float)$countedRaw;
      $readStmt->execute([$itemId, $opnameId]);
      $item = $readStmt->fetch();
      if (!$item) {
        continue;
      }
      $systemQty = (float)$item['system_qty'];
      $diffQty = $counted - $systemQty;
      $note = trim((string)($noteMap[$itemIdRaw] ?? ''));
      if (abs($diffQty) > 0.0005 && $note === '') {
        inventory_set_flash('error', 'Alasan wajib diisi ketika stok input berbeda dengan stok saat ini.');
        redirect(base_url('admin/inventory_opname.php?view=' . $opnameId));
      }
      $itemStmt->execute([$counted, $diffQty, $note !== '' ? $note : null, $itemId, $opnameId]);
    }
    inventory_set_flash('ok', 'Draft opname disimpan.');
    redirect(base_url('admin/inventory_opname.php?view=' . $opnameId));
  }

  if ($action === 'post') {
    $opnameId = (int)($_POST['opname_id'] ?? 0);
    db()->beginTransaction();
    try {
      $headerStmt = db()->prepare("SELECT * FROM inv_stock_opname WHERE id=? AND branch_id=? FOR UPDATE");
      $headerStmt->execute([$opnameId, $branchId]);
      $header = $headerStmt->fetch();
      if (!$header || $header['status'] !== 'DRAFT') {
        throw new RuntimeException('invalid');
      }

      $itemStmt = db()->prepare("SELECT * FROM inv_stock_opname_items WHERE opname_id=?");
      $itemStmt->execute([$opnameId]);
      $items = $itemStmt->fetchAll();

      $ledgerStmt = db()->prepare("INSERT INTO inv_stock_ledger (branch_id, product_id, ref_type, ref_id, qty_in, qty_out, note, created_at) VALUES (?, ?, 'OPNAME', ?, ?, ?, ?, ?)");
      $now = inventory_now();

      foreach ($items as $item) {
        $diff = (float)$item['diff_qty'];
        $productId = ensure_products_row_from_inv_product((int)$item['product_id']);
        if ($productId > 0) {
          stok_barang_set_qty($branchId, $productId, (float)$item['counted_qty']);
        }

        if (abs($diff) < 0.0005) {
          continue;
        }
        if ($diff > 0) {
          $ledgerStmt->execute([$branchId, (int)$item['product_id'], $opnameId, $diff, 0, 'Adjustment opname', $now]);
        } else {
          $ledgerStmt->execute([$branchId, (int)$item['product_id'], $opnameId, 0, abs($diff), 'Adjustment opname', $now]);
        }
      }

      $postStmt = db()->prepare("UPDATE inv_stock_opname SET status='POSTED', posted_by=?, posted_at=? WHERE id=?");
      $postStmt->execute([$userId, $now, $opnameId]);
      db()->commit();
      inventory_set_flash('ok', 'Stock opname berhasil di-POST.');
    } catch (Throwable $e) {
      db()->rollBack();
      inventory_set_flash('error', 'Gagal posting opname.');
    }
    redirect(base_url('admin/inventory_opname.php?view=' . $opnameId));
  }
}

$viewId = (int)($_GET['view'] ?? 0);
$viewHeader = null;
$viewItems = [];
if ($viewId > 0) {
  $stmt = db()->prepare("SELECT o.*, u.name AS created_by_name, p.name AS posted_by_name
    FROM inv_stock_opname o
    LEFT JOIN users u ON u.id=o.created_by
    LEFT JOIN users p ON p.id=o.posted_by
    WHERE o.id=? AND o.branch_id=? LIMIT 1");
  $stmt->execute([$viewId, $branchId]);
  $viewHeader = $stmt->fetch();

  if ($viewHeader) {
    $stmtItems = db()->prepare("SELECT i.*, pr.name AS product_name, pr.sku, pr.unit FROM inv_stock_opname_items i JOIN inv_products pr ON pr.id=i.product_id WHERE i.opname_id=? ORDER BY pr.name ASC");
    $stmtItems->execute([$viewId]);
    $viewItems = $stmtItems->fetchAll();
  }
}

$stmtList = db()->prepare("SELECT id, opname_date, status, created_at FROM inv_stock_opname WHERE branch_id=? ORDER BY id DESC LIMIT 50");
$stmtList->execute([$branchId]);
$list = $stmtList->fetchAll();
$flash = inventory_get_flash();
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Stok Opname</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><span style="color:#fff;font-weight:700">Produk & Inventory / Stok Opname</span></div>
    <div class="content">
      <?php if ($flash): ?><div class="card" style="margin-bottom:12px"><?php echo e($flash['message']); ?></div><?php endif; ?>

      <div class="card" style="margin-bottom:14px">
        <h3 style="margin-top:0">Buat Dokumen Opname</h3><p>Cabang aktif: <strong><?php echo e((string)($branch['name'] ?? '-')); ?></strong></p>
        <form method="post" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="create">
          <div class="row" style="max-width:220px"><label>Tanggal Opname</label><input type="date" name="opname_date" value="<?php echo e(date('Y-m-d')); ?>"></div>
          <button class="btn" type="submit">Buat Draft</button>
        </form>
      </div>

      <div class="card" style="margin-bottom:14px">
        <h3 style="margin-top:0">Riwayat Stok Opname</h3>
        <table class="table">
          <thead><tr><th>ID</th><th>Tanggal</th><th>Status</th><th>Dibuat</th><th>Aksi</th></tr></thead>
          <tbody><?php foreach ($list as $row): ?><tr><td>#<?php echo e((string)$row['id']); ?></td><td><?php echo e($row['opname_date']); ?></td><td><?php echo e($row['status']); ?></td><td><?php echo e($row['created_at']); ?></td><td><a class="btn" href="<?php echo e(base_url('admin/inventory_opname.php?view=' . (int)$row['id'])); ?>">Detail</a></td></tr><?php endforeach; ?></tbody>
        </table>
      </div>

      <?php if ($viewHeader): ?>
      <div class="card">
        <h3 style="margin-top:0">Detail Opname #<?php echo e((string)$viewHeader['id']); ?> (<?php echo e($viewHeader['status']); ?>)</h3>
        <p style="margin-top:0">Tanggal: <?php echo e($viewHeader['opname_date']); ?> | Created: <?php echo e((string)$viewHeader['created_by_name']); ?> | Posted: <?php echo e((string)$viewHeader['posted_by_name']); ?></p>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="save_items"><input type="hidden" name="opname_id" value="<?php echo e((string)$viewHeader['id']); ?>">
          <table class="table">
            <thead><tr><th>Produk</th><th>Stok Saat Ini</th><th>Input Stok Opname</th><th>Selisih</th><th>Alasan</th></tr></thead>
            <tbody>
              <?php foreach ($viewItems as $item): ?>
              <tr>
                <td><?php echo e($item['product_name']); ?> (<?php echo e((string)$item['unit']); ?>)</td>
                <td><?php echo e(number_format((float)$item['system_qty'], 3, '.', ',')); ?></td>
                <td><input <?php echo $viewHeader['status'] === 'POSTED' ? 'readonly' : ''; ?> type="number" step="0.001" name="counted_qty[<?php echo e((string)$item['id']); ?>]" value="<?php echo e((string)$item['counted_qty']); ?>"></td>
                <td><?php echo e(number_format((float)$item['diff_qty'], 3, '.', ',')); ?></td>
                <td><input <?php echo $viewHeader['status'] === 'POSTED' ? 'readonly' : ''; ?> type="text" name="note[<?php echo e((string)$item['id']); ?>]" value="<?php echo e((string)$item['note']); ?>"></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php if ($viewHeader['status'] === 'DRAFT'): ?>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
              <button class="btn" type="submit">Simpan Draft</button>
            </div>
          <?php else: ?>
            <div class="badge">Dokumen POSTED terkunci (read-only)</div>
          <?php endif; ?>
        </form>
        <?php if ($viewHeader['status'] === 'DRAFT'): ?>
        <form method="post" style="margin-top:10px" data-confirm="Posting dokumen ini? Setelah POSTED tidak bisa diedit.">
          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="action" value="post"><input type="hidden" name="opname_id" value="<?php echo e((string)$viewHeader['id']); ?>">
          <button class="btn danger" type="submit">POST Opname</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
