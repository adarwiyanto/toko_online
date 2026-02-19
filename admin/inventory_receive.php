<?php
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_login();
inventory_ensure_tables();

$u = current_user();
$role = (string)($u['role'] ?? '');
if (!in_array($role, ['owner','admin','manager_toko','pegawai_pos','pegawai_non_pos'], true)) {
  http_response_code(403);
  exit('Forbidden');
}
$userId = (int)($u['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $transferId = (int)($_POST['transfer_id'] ?? 0);
  $qtyMap = $_POST['qty_received'] ?? [];
  $noteMap = $_POST['item_note'] ?? [];

  db()->beginTransaction();
  try {
    $h = db()->prepare("SELECT * FROM inv_kitchen_transfers WHERE id=? FOR UPDATE");
    $h->execute([$transferId]);
    $header = $h->fetch();
    if (!$header || (string)$header['status'] !== 'SENT') {
      throw new RuntimeException('invalid');
    }

    $itemsStmt = db()->prepare("SELECT * FROM inv_kitchen_transfer_items WHERE transfer_id=?");
    $itemsStmt->execute([$transferId]);
    $items = $itemsStmt->fetchAll();

    $up = db()->prepare("UPDATE inv_kitchen_transfer_items SET qty_received=?, note=? WHERE id=?");
    $ledger = db()->prepare("INSERT INTO inv_stock_ledger (product_id, ref_type, ref_id, qty_in, qty_out, note, created_at) VALUES (?, 'KITCHEN_RECEIVE', ?, ?, 0, ?, ?)");
    $now = inventory_now();

    foreach ($items as $item) {
      $itemId = (int)$item['id'];
      $qtySent = (float)$item['qty_sent'];
      $qtyReceived = isset($qtyMap[$itemId]) ? (float)$qtyMap[$itemId] : $qtySent;
      if ($qtyReceived < 0) $qtyReceived = 0;
      $note = trim((string)($noteMap[$itemId] ?? ''));
      $up->execute([$qtyReceived, $note !== '' ? $note : null, $itemId]);
      if ($qtyReceived > 0) {
        $ledger->execute([(int)$item['product_id'], $transferId, $qtyReceived, 'Penerimaan dari dapur', $now]);
      }
    }

    $done = db()->prepare("UPDATE inv_kitchen_transfers SET status='RECEIVED', received_by=?, received_at=? WHERE id=?");
    $done->execute([$userId, $now, $transferId]);
    db()->commit();
    inventory_set_flash('ok', 'Penerimaan kiriman berhasil dikonfirmasi dan masuk stok toko.');
  } catch (Throwable $e) {
    db()->rollBack();
    inventory_set_flash('error', 'Gagal konfirmasi penerimaan.');
  }
  redirect(base_url('admin/inventory_receive.php'));
}

$list = db()->query("SELECT t.*, u.name AS sender_name FROM inv_kitchen_transfers t LEFT JOIN users u ON u.id=t.created_by WHERE t.status='SENT' ORDER BY t.id DESC")->fetchAll();
$flash = inventory_get_flash();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Penerimaan Stok Toko</title><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"></head><body>
<div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><span style="color:#fff;font-weight:700">Penerimaan Kiriman Dapur</span></div><div class="content">
<?php if ($flash): ?><div class="card" style="margin-bottom:12px"><?php echo e($flash['message']); ?></div><?php endif; ?>
<?php foreach($list as $h): ?>
  <?php $it = db()->prepare("SELECT i.*,p.name,p.unit FROM inv_kitchen_transfer_items i JOIN inv_products p ON p.id=i.product_id WHERE i.transfer_id=?"); $it->execute([(int)$h['id']]); $items=$it->fetchAll(); ?>
  <div class="card" style="margin-bottom:12px"><h3 style="margin-top:0">Kiriman #<?php echo e((string)$h['id']); ?> - <?php echo e((string)$h['transfer_date']); ?></h3><p>Pengirim: <?php echo e((string)$h['sender_name']); ?></p><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><input type="hidden" name="transfer_id" value="<?php echo e((string)$h['id']); ?>"><table class="table"><thead><tr><th>Produk</th><th>Qty Kirim</th><th>Qty Diterima</th><th>Catatan</th></tr></thead><tbody><?php foreach($items as $row): ?><tr><td><?php echo e($row['name']); ?></td><td><?php echo e(number_format((float)$row['qty_sent'],3,'.',',')); ?> <?php echo e((string)$row['unit']); ?></td><td><input type="number" step="0.001" min="0" name="qty_received[<?php echo e((string)$row['id']); ?>]" value="<?php echo e((string)$row['qty_sent']); ?>"></td><td><input type="text" name="item_note[<?php echo e((string)$row['id']); ?>]" value=""></td></tr><?php endforeach; ?></tbody></table><button class="btn" type="submit">Konfirmasi Penerimaan</button></form></div>
<?php endforeach; ?>
</div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
