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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
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

  $now = inventory_now();
  db()->beginTransaction();
  try {
    $h = db()->prepare("INSERT INTO inv_kitchen_transfers (transfer_date, status, note, created_by, sent_at) VALUES (?, 'SENT', ?, ?, ?)");
    $h->execute([date('Y-m-d'), trim((string)($_POST['note'] ?? '')) ?: null, $userId, $now]);
    $transferId = (int)db()->lastInsertId();
    $i = db()->prepare("INSERT INTO inv_kitchen_transfer_items (transfer_id, product_id, qty_sent, qty_received, note) VALUES (?,?,?,?,NULL)");
    $ledger = db()->prepare("INSERT INTO inv_stock_ledger (product_id, ref_type, ref_id, qty_in, qty_out, note, created_at) VALUES (?, 'KITCHEN_SEND', ?, 0, ?, 'Kirim dari dapur ke toko', ?)");
    foreach ($items as $item) {
      $i->execute([$transferId, $item['product_id'], $item['qty'], 0]);
      $ledger->execute([$item['product_id'], $transferId, $item['qty'], $now]);
    }
    db()->commit();
    inventory_set_flash('ok', 'Kiriman dapur berhasil dibuat, menunggu konfirmasi toko.');
  } catch (Throwable $e) {
    db()->rollBack();
    inventory_set_flash('error', 'Gagal membuat kiriman dapur.');
  }
  redirect(base_url('admin/inventory_kitchen_transfers.php'));
}

$products = db()->query("SELECT id,name,sku,unit FROM inv_products WHERE is_deleted=0 AND is_hidden=0 AND audience='dapur' AND type='FINISHED' ORDER BY name ASC")->fetchAll();
$list = db()->query("SELECT t.id,t.transfer_date,t.status,u.name AS created_by_name,t.sent_at FROM inv_kitchen_transfers t LEFT JOIN users u ON u.id=t.created_by ORDER BY t.id DESC LIMIT 30")->fetchAll();
$flash = inventory_get_flash();
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Kirim Dapur ke Toko</title><link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>"></head><body>
<div class="container"><?php include __DIR__ . '/partials_sidebar.php'; ?><div class="main"><div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><span style="color:#fff;font-weight:700">POS Dapur / Kirim ke Toko</span></div><div class="content">
<?php if ($flash): ?><div class="card" style="margin-bottom:12px"><?php echo e($flash['message']); ?></div><?php endif; ?>
<div class="card" style="margin-bottom:14px"><h3 style="margin-top:0">Buat Kiriman</h3><form method="post"><input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>"><div class="row"><label>Catatan</label><input name="note"></div><table class="table"><thead><tr><th>Produk Finished Dapur</th><th>Qty Kirim</th></tr></thead><tbody><?php foreach($products as $p): ?><tr><td><?php echo e($p['name']); ?> (<?php echo e((string)$p['unit']); ?>)</td><td><input type="number" step="0.001" min="0" name="qty[<?php echo e((string)$p['id']); ?>]" value="0"></td></tr><?php endforeach; ?></tbody></table><button class="btn" type="submit">Kirim ke Toko</button></form></div>
<div class="card"><h3 style="margin-top:0">Riwayat Kiriman</h3><table class="table"><thead><tr><th>ID</th><th>Tanggal</th><th>Status</th><th>Pengirim</th><th>Waktu</th></tr></thead><tbody><?php foreach($list as $r): ?><tr><td>#<?php echo e((string)$r['id']); ?></td><td><?php echo e((string)$r['transfer_date']); ?></td><td><?php echo e((string)$r['status']); ?></td><td><?php echo e((string)$r['created_by_name']); ?></td><td><?php echo e((string)$r['sent_at']); ?></td></tr><?php endforeach; ?></tbody></table></div>
</div></div></div><script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script></body></html>
