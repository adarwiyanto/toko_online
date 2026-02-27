<?php
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_login();
inventory_ensure_tables();

$u = current_user();
$role = (string)($u['role'] ?? '');
if (!in_array($role, ['owner','admin','manager_dapur','pegawai_dapur'], true)) {
  http_response_code(403);
  exit('Forbidden');
}
$userId = (int)($u['id'] ?? 0);
$sourceBranchId = inventory_active_branch_id();
$sourceBranch = inventory_active_branch();
if (!$sourceBranch || (string)$sourceBranch['branch_type'] !== 'dapur') {
  inventory_set_flash('error', 'Cabang aktif harus bertipe dapur untuk membuat kiriman.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'send_draft') {
    $transferId = (int)($_POST['transfer_id'] ?? 0);
    if ($transferId <= 0) {
      inventory_set_flash('error', 'Draft tidak valid.');
      redirect(base_url('admin/inventory_kitchen_transfers.php'));
    }

    $now = inventory_now();
    db()->beginTransaction();
    try {
      $t = db()->prepare("SELECT * FROM inv_kitchen_transfers WHERE id=? AND source_branch_id=? AND status='DRAFT' LIMIT 1");
      $t->execute([$transferId, $sourceBranchId]);
      $tr = $t->fetch();
      if (!$tr) throw new RuntimeException('Draft tidak ditemukan.');

      $itemsStmt = db()->prepare("SELECT i.*, p.name FROM inv_kitchen_transfer_items i JOIN inv_products p ON p.id=i.product_id WHERE i.transfer_id=?");
      $itemsStmt->execute([$transferId]);
      $items = $itemsStmt->fetchAll();
      if (!$items) throw new RuntimeException('Draft belum memiliki item.');

      // validasi stok dapur cukup
      foreach ($items as $it) {
        $pid = (int)$it['product_id'];
        $qty = (float)$it['qty_sent'];
        if ($qty <= 0) continue;
        $productStmt = db()->prepare("SELECT id FROM inv_products WHERE id=? AND audience='dapur' AND kitchen_group='finished' AND is_deleted=0 AND is_hidden=0 LIMIT 1");
        $productStmt->execute([$pid]);
        if (!$productStmt->fetch()) throw new RuntimeException('Produk draft tidak valid.');
        $avail = stock_get_qty($sourceBranchId, $pid);
        if ($avail + 1e-9 < $qty) throw new RuntimeException('Stok tidak cukup untuk: ' . (string)$it['name']);
      }

      // ledger + stok keluar
      $ledger = db()->prepare("INSERT INTO inv_stock_ledger (branch_id, product_id, ref_type, ref_id, qty_in, qty_out, note, created_at) VALUES (?, ?, 'KITCHEN_SEND', ?, 0, ?, 'Kirim dari dapur ke toko', ?)");
      foreach ($items as $it) {
        $pid = (int)$it['product_id'];
        $qty = (float)$it['qty_sent'];
        if ($qty <= 0) continue;
        $ledger->execute([$sourceBranchId, $pid, $transferId, $qty, $now]);
        $productId = ensure_products_row_from_inv_product($pid);
        if ($productId > 0) {
          stok_barang_add_qty($sourceBranchId, $productId, -1 * $qty);
        }
      }

      $up = db()->prepare("UPDATE inv_kitchen_transfers SET status='SENT', sent_at=? WHERE id=?");
      $up->execute([$now, $transferId]);
      db()->commit();
      inventory_set_flash('ok', 'Draft berhasil dikirim ke toko.');
    } catch (Throwable $e) {
      db()->rollBack();
      inventory_set_flash('error', $e->getMessage());
    }
    redirect(base_url('admin/inventory_kitchen_transfers.php'));
  }

  if ($action === 'delete_draft') {
    $transferId = (int)($_POST['transfer_id'] ?? 0);
    if ($transferId <= 0) {
      inventory_set_flash('error', 'Draft tidak valid.');
      redirect(base_url('admin/inventory_kitchen_transfers.php'));
    }
    db()->beginTransaction();
    try {
      $t = db()->prepare("SELECT id FROM inv_kitchen_transfers WHERE id=? AND source_branch_id=? AND status='DRAFT' LIMIT 1");
      $t->execute([$transferId, $sourceBranchId]);
      if (!$t->fetch()) throw new RuntimeException('Draft tidak ditemukan.');

      db()->prepare("DELETE FROM inv_kitchen_transfer_items WHERE transfer_id=?")->execute([$transferId]);
      db()->prepare("DELETE FROM inv_kitchen_transfers WHERE id=?")->execute([$transferId]);
      db()->commit();
      inventory_set_flash('ok', 'Draft dihapus.');
    } catch (Throwable $e) {
      db()->rollBack();
      inventory_set_flash('error', $e->getMessage());
    }
    redirect(base_url('admin/inventory_kitchen_transfers.php'));
  }

  // default: create and send (existing behaviour)
  $targetBranchId = (int)($_POST['target_branch_id'] ?? 0);
  $qtyMap = $_POST['qty'] ?? [];
  $items = [];
  foreach ($qtyMap as $pidRaw => $qtyRaw) {
    $pid = (int)$pidRaw;
    $qty = (float)$qtyRaw;
    if ($pid > 0 && $qty > 0) {
      $items[] = ['product_id' => $pid, 'qty' => $qty];
    }
  }
  if (!$items) {
    inventory_set_flash('error', 'Isi minimal satu produk finished untuk dikirim.');
    redirect(base_url('admin/inventory_kitchen_transfers.php'));
  }

  $targetStmt = db()->prepare("SELECT id FROM branches WHERE id=? AND branch_type='toko' AND is_active=1 LIMIT 1");
  $targetStmt->execute([$targetBranchId]);
  if (!$targetStmt->fetch()) {
    inventory_set_flash('error', 'Cabang tujuan toko tidak valid.');
    redirect(base_url('admin/inventory_kitchen_transfers.php'));
  }

  $now = inventory_now();
  db()->beginTransaction();
  try {
    $h = db()->prepare("INSERT INTO inv_kitchen_transfers (transfer_date, source_branch_id, target_branch_id, status, note, created_by, sent_at) VALUES (?, ?, ?, 'SENT', ?, ?, ?)");
    $h->execute([date('Y-m-d'), $sourceBranchId, $targetBranchId, trim((string)($_POST['note'] ?? '')) ?: null, $userId, $now]);
    $transferId = (int)db()->lastInsertId();
    $i = db()->prepare("INSERT INTO inv_kitchen_transfer_items (transfer_id, product_id, qty_sent, qty_received, note) VALUES (?,?,?,?,NULL)");
    $ledger = db()->prepare("INSERT INTO inv_stock_ledger (branch_id, product_id, ref_type, ref_id, qty_in, qty_out, note, created_at) VALUES (?, ?, 'KITCHEN_SEND', ?, 0, ?, 'Kirim dari dapur ke toko', ?)");
    $productStmt = db()->prepare("SELECT id FROM inv_products WHERE id=? AND audience='dapur' AND kitchen_group='finished' AND is_deleted=0 AND is_hidden=0 LIMIT 1");
    foreach ($items as $item) {
      $productStmt->execute([$item['product_id']]);
      if (!$productStmt->fetch()) {
        throw new RuntimeException('Produk kirim tidak valid.');
      }
      $i->execute([$transferId, $item['product_id'], $item['qty'], 0]);
      $ledger->execute([$sourceBranchId, $item['product_id'], $transferId, $item['qty'], $now]);
      $productId = ensure_products_row_from_inv_product((int)$item['product_id']);
      if ($productId > 0) {
        stok_barang_add_qty($sourceBranchId, $productId, -1 * (float)$item['qty']);
      }
    }
    db()->commit();
    inventory_set_flash('ok', 'Kiriman dapur berhasil dibuat, menunggu konfirmasi toko.');
  } catch (Throwable $e) {
    db()->rollBack();
    inventory_set_flash('error', 'Gagal membuat kiriman dapur.');
  }
  redirect(base_url('admin/inventory_kitchen_transfers.php'));
}

$products = db()->query("SELECT id,name,sku,unit FROM inv_products WHERE is_deleted=0 AND is_hidden=0 AND audience='dapur' AND kitchen_group='finished' ORDER BY name ASC")->fetchAll();
$targetBranches = db()->query("SELECT id,name FROM branches WHERE branch_type='toko' AND is_active=1 ORDER BY name ASC")->fetchAll();

$draftStmt = db()->prepare("SELECT t.id,t.transfer_date,t.note,u.name AS created_by_name,t.sent_at,b2.name AS target_name
  FROM inv_kitchen_transfers t
  LEFT JOIN users u ON u.id=t.created_by
  LEFT JOIN branches b2 ON b2.id=t.target_branch_id
  WHERE t.source_branch_id=? AND t.status='DRAFT'
  ORDER BY t.id DESC LIMIT 20");
$draftStmt->execute([$sourceBranchId]);
$drafts = $draftStmt->fetchAll();
$listStmt = db()->prepare("SELECT t.id,t.transfer_date,t.status,u.name AS created_by_name,t.sent_at,b1.name AS source_name,b2.name AS target_name FROM inv_kitchen_transfers t LEFT JOIN users u ON u.id=t.created_by LEFT JOIN branches b1 ON b1.id=t.source_branch_id LEFT JOIN branches b2 ON b2.id=t.target_branch_id WHERE t.source_branch_id=? ORDER BY t.id DESC LIMIT 30");
$listStmt->execute([$sourceBranchId]);
$list = $listStmt->fetchAll();
$flash = inventory_get_flash();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kirim Dapur ke Toko</title><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"></head><body>
<div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><span style="color:#fff;font-weight:700">POS Dapur / Kirim ke Toko</span></div><div class="content">
<?php if ($flash): ?><div class="card" style="margin-bottom:12px"><?php echo e($flash['message']); ?></div><?php endif; ?>
<div class="card" style="margin-bottom:14px"><h3 style="margin-top:0">Buat Kiriman</h3><p>Dari cabang: <strong><?php echo e((string)($sourceBranch['name'] ?? '-')); ?></strong></p><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="row"><label>Tujuan Toko</label><select name="target_branch_id" required><?php foreach($targetBranches as $tb): ?><option value="<?php echo e((string)$tb['id']); ?>"><?php echo e((string)$tb['name']); ?></option><?php endforeach; ?></select></div><div class="row"><label>Catatan</label><input name="note"></div><div style="overflow-x:auto"><table class="table"><thead><tr><th>Produk Finished Dapur</th><th>Qty Kirim</th></tr></thead><tbody><?php foreach($products as $p): ?><tr><td><?php echo e($p['name']); ?> (<?php echo e((string)$p['unit']); ?>)</td><td><input type="number" step="0.001" min="0" name="qty[<?php echo e((string)$p['id']); ?>]" value="0"></td></tr><?php endforeach; ?></tbody></table></div><button class="btn" type="submit" style="width:100%">Kirim ke Toko</button></form></div>

<?php if (!empty($drafts)): ?>
  <div class="card" style="overflow-x:auto;margin-bottom:14px">
    <h3 style="margin-top:0">Draft Kiriman</h3>
    <small>Draft tidak mengurangi stok. Stok berkurang saat draft dikirim.</small>
    <table class="table">
      <thead><tr><th>ID</th><th>Tanggal</th><th>Tujuan</th><th>Item</th><th>Catatan</th><th>Aksi</th></tr></thead>
      <tbody>
      <?php foreach ($drafts as $d): ?>
        <?php
          $itemsStmt = db()->prepare("SELECT i.qty_sent, p.name, p.unit FROM inv_kitchen_transfer_items i JOIN inv_products p ON p.id=i.product_id WHERE i.transfer_id=?");
          $itemsStmt->execute([(int)$d['id']]);
          $its = $itemsStmt->fetchAll();
          $labels = [];
          foreach ($its as $it) {
            $labels[] = (string)$it['name'] . ' (' . (string)$it['qty_sent'] . ' ' . (string)$it['unit'] . ')';
          }
        ?>
        <tr>
          <td>#<?php echo e((string)$d['id']); ?></td>
          <td><?php echo e((string)$d['transfer_date']); ?></td>
          <td><?php echo e((string)($d['target_name'] ?? '-')); ?></td>
          <td><?php echo e(implode(', ', $labels)); ?></td>
          <td><?php echo e((string)($d['note'] ?? '')); ?></td>
          <td style="white-space:nowrap">
            <form method="post" style="display:inline">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="send_draft">
              <input type="hidden" name="transfer_id" value="<?php echo e((string)$d['id']); ?>">
              <button class="btn" type="submit">Kirim</button>
            </form>
            <form method="post" style="display:inline" onsubmit="return confirm('Hapus draft ini?')">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="delete_draft">
              <input type="hidden" name="transfer_id" value="<?php echo e((string)$d['id']); ?>">
              <button class="btn btn-light" type="submit">Hapus</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div class="card" style="overflow-x:auto"><h3 style="margin-top:0">Riwayat Kiriman</h3><table class="table"><thead><tr><th>ID</th><th>Tanggal</th><th>Asal</th><th>Tujuan</th><th>Status</th><th>Pengirim</th><th>Waktu</th></tr></thead><tbody><?php foreach($list as $r): ?><tr><td>#<?php echo e((string)$r['id']); ?></td><td><?php echo e((string)$r['transfer_date']); ?></td><td><?php echo e((string)$r['source_name']); ?></td><td><?php echo e((string)$r['target_name']); ?></td><td><?php echo e((string)$r['status']); ?></td><td><?php echo e((string)$r['created_by_name']); ?></td><td><?php echo e((string)$r['sent_at']); ?></td></tr><?php endforeach; ?></tbody></table></div>
</div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
