<?php
require_once __DIR__ . '/inventory_helpers.php';
require_once __DIR__ . '/../core/csrf.php';

start_secure_session();
require_role(['owner', 'admin', 'manager_dapur']);
inventory_ensure_tables();
ensure_inv_stocks_table();

$flash = inventory_get_flash();
$customCss = setting('custom_css', '');

$activeBranch = inventory_active_branch();
$activeBranchId = (int)($activeBranch['id'] ?? 0);
$activeBranchType = (string)($activeBranch['branch_type'] ?? '');

if ($activeBranchId <= 0) {
  inventory_set_flash('error', 'Cabang aktif belum dipilih.');
  redirect(base_url('admin/dashboard.php'));
}
if ($activeBranchType !== 'dapur') {
  inventory_set_flash('error', 'Menu produksi hanya untuk cabang tipe dapur.');
  redirect(base_url('admin/dashboard.php'));
}

$u = current_user();
$userId = (int)($u['id'] ?? 0);

function production_safe_decimal($v): float {
  if ($v === null) return 0.0;
  $v = str_replace(',', '.', (string)$v);
  return (float)$v;
}

function production_load_recipe(int $finishedProductId): ?array {
  $stmt = db()->prepare("SELECT * FROM inv_recipes WHERE finished_product_id=? LIMIT 1");
  $stmt->execute([$finishedProductId]);
  $r = $stmt->fetch();
  if (!$r) return null;

  $it = db()->prepare("SELECT ri.*, p.name, p.unit
    FROM inv_recipe_items ri
    JOIN inv_products p ON p.id=ri.raw_product_id
    WHERE ri.recipe_id=?
    ORDER BY p.name ASC");
  $it->execute([(int)$r['id']]);
  $items = $it->fetchAll();

  $r['items'] = $items;
  return $r;
}

function production_get_toko_branches(): array {
  return db()->query("SELECT id,name FROM branches WHERE branch_type='toko' AND is_active=1 ORDER BY name ASC")->fetchAll();
}

function production_get_production(int $prodId, int $branchId): ?array {
  $st = db()->prepare("SELECT pr.*, p.name AS finished_name, p.unit AS finished_unit
    FROM inv_productions pr
    JOIN inv_products p ON p.id=pr.finished_product_id
    WHERE pr.id=? AND pr.branch_id=? LIMIT 1");
  $st->execute([$prodId, $branchId]);
  $r = $st->fetch();
  return $r ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'save_recipe') {
      $finishedId = (int)($_POST['finished_product_id'] ?? 0);
      if ($finishedId <= 0) throw new Exception('Pilih produk finished.');

      // Validasi: finished harus dapur/finished
      $chk = db()->prepare("SELECT id FROM inv_products WHERE id=? AND audience='dapur' AND (type='FINISHED' OR kitchen_group='finished') AND is_deleted=0 AND is_hidden=0 LIMIT 1");
      $chk->execute([$finishedId]);
      if (!$chk->fetchColumn()) throw new Exception('Produk finished tidak valid untuk dapur.');

      $now = inventory_now();
      $db = db();
      $db->beginTransaction();

      // upsert recipe
      $stmt = $db->prepare("SELECT id FROM inv_recipes WHERE finished_product_id=? LIMIT 1");
      $stmt->execute([$finishedId]);
      $recipeId = (int)($stmt->fetchColumn() ?: 0);

      if ($recipeId <= 0) {
        $ins = $db->prepare("INSERT INTO inv_recipes (finished_product_id, is_active, created_at, updated_at) VALUES (?,1,?,?)");
        $ins->execute([$finishedId, $now, $now]);
        $recipeId = (int)$db->lastInsertId();
      } else {
        $up = $db->prepare("UPDATE inv_recipes SET updated_at=? WHERE id=?");
        $up->execute([$now, $recipeId]);
      }

      // replace items (simple & safe)
      $db->prepare("DELETE FROM inv_recipe_items WHERE recipe_id=?")->execute([$recipeId]);

      $rawIds = $_POST['raw_product_id'] ?? [];
      $qtys = $_POST['qty_per_unit'] ?? [];
      $count = 0;

      $insIt = $db->prepare("INSERT INTO inv_recipe_items (recipe_id, raw_product_id, qty_per_unit, created_at, updated_at) VALUES (?,?,?,?,?)");

      foreach ($rawIds as $i => $ridRaw) {
        $rawId = (int)$ridRaw;
        $qty = production_safe_decimal($qtys[$i] ?? 0);
        if ($rawId <= 0) continue;
        if ($qty <= 0) continue;

        // Validasi raw
        $chkR = $db->prepare("SELECT id FROM inv_products WHERE id=? AND audience='dapur' AND (type='RAW' OR kitchen_group='raw') AND is_deleted=0 AND is_hidden=0 LIMIT 1");
        $chkR->execute([$rawId]);
        if (!$chkR->fetchColumn()) continue;

        $insIt->execute([$recipeId, $rawId, $qty, $now, $now]);
        $count++;
      }

      if ($count <= 0) {
        throw new Exception('Minimal isi 1 raw item dengan qty > 0.');
      }

      $db->commit();
      inventory_set_flash('ok', 'Resep produksi disimpan.');
      redirect(base_url('admin/inventory_production.php?tab=resep&finished=' . $finishedId));
    }

    if ($action === 'run_production') {
      $finishedId = (int)($_POST['finished_product_id'] ?? 0);
      $batchQty = production_safe_decimal($_POST['batch_qty'] ?? 0);
      $note = trim((string)($_POST['note'] ?? ''));

      if ($finishedId <= 0) throw new Exception('Pilih produk finished.');
      if ($batchQty <= 0) throw new Exception('Qty produksi harus > 0.');

      $recipe = production_load_recipe($finishedId);
      if (!$recipe) throw new Exception('Resep belum dibuat untuk produk ini.');

      $recipeId = (int)($recipe['id'] ?? 0);
      if ($recipeId <= 0) throw new Exception('Resep tidak valid.');

      $rawIds = $_POST['raw_product_id'] ?? [];
      $usedQtys = $_POST['qty_used'] ?? [];

      // Map qty_used per raw
      $usedMap = [];
      foreach ($rawIds as $i => $ridRaw) {
        $rawId = (int)$ridRaw;
        $qty = production_safe_decimal($usedQtys[$i] ?? 0);
        if ($rawId > 0) {
          $usedMap[$rawId] = $qty;
        }
      }

      // Validasi stok cukup
      foreach (($recipe['items'] ?? []) as $it) {
        $rawId = (int)$it['raw_product_id'];
        $need = (float)($usedMap[$rawId] ?? ((float)$it['qty_per_unit'] * $batchQty));
        if ($need < 0) throw new Exception('Qty raw tidak boleh negatif.');
        $available = stock_get_qty($activeBranchId, $rawId);
        if ($available + 1e-9 < $need) {
          throw new Exception('Stok raw tidak cukup: ' . (string)$it['name'] . ' (butuh ' . $need . ', tersedia ' . $available . ').');
        }
      }

      $db = db();
      $db->beginTransaction();
      $now = inventory_now();

      // insert production header
      $ins = $db->prepare("INSERT INTO inv_productions (branch_id, recipe_id, finished_product_id, batch_qty, note, status, created_by, created_at, updated_at)
                           VALUES (?,?,?,?,?,'done',?,?,?)");
      $ins->execute([$activeBranchId, $recipeId, $finishedId, $batchQty, ($note !== '' ? $note : null), ($userId > 0 ? $userId : null), $now, $now]);
      $prodId = (int)$db->lastInsertId();

      // insert items + deduct raw
      $insIt = $db->prepare("INSERT INTO inv_production_items (production_id, raw_product_id, qty_used, created_at, updated_at) VALUES (?,?,?,?,?)");
      foreach (($recipe['items'] ?? []) as $it) {
        $rawId = (int)$it['raw_product_id'];
        $need = (float)($usedMap[$rawId] ?? ((float)$it['qty_per_unit'] * $batchQty));
        $insIt->execute([$prodId, $rawId, $need, $now, $now]);
        if ($need > 0) {
          stock_add_qty($activeBranchId, $rawId, -1 * $need);
        }
      }

      // add finished stock to dapur
      stock_add_qty($activeBranchId, $finishedId, $batchQty);

      $db->commit();
      inventory_set_flash('ok', 'Produksi selesai. Stok raw berkurang dan finished bertambah di cabang dapur.');
      redirect(base_url('admin/inventory_production.php?tab=produksi&finished=' . $finishedId . '&last_prod=' . $prodId));
    }

    if ($action === 'create_transfer_draft') {
      $prodId = (int)($_POST['production_id'] ?? 0);
      $finishedId = (int)($_POST['finished_product_id'] ?? 0);
      $qtySend = production_safe_decimal($_POST['qty_send'] ?? 0);
      $targetBranchId = (int)($_POST['target_branch_id'] ?? 0);
      $note = trim((string)($_POST['note'] ?? ''));

      if ($prodId <= 0) throw new Exception('Produksi tidak valid.');
      if ($finishedId <= 0) throw new Exception('Produk finished tidak valid.');
      if ($qtySend <= 0) throw new Exception('Qty kirim harus > 0.');

      // target toko valid
      $tb = db()->prepare("SELECT id FROM branches WHERE id=? AND branch_type='toko' AND is_active=1 LIMIT 1");
      $tb->execute([$targetBranchId]);
      if (!$tb->fetch()) throw new Exception('Cabang tujuan toko tidak valid.');

      // finished valid (dapur finished)
      $chk = db()->prepare("SELECT id FROM inv_products WHERE id=? AND audience='dapur' AND (type='FINISHED' OR kitchen_group='finished') AND is_deleted=0 AND is_hidden=0 LIMIT 1");
      $chk->execute([$finishedId]);
      if (!$chk->fetchColumn()) throw new Exception('Produk finished tidak valid untuk dapur.');

      // produksi harus milik cabang aktif
      $pr = production_get_production($prodId, $activeBranchId);
      if (!$pr) throw new Exception('Produksi tidak ditemukan pada cabang aktif.');

      $now = inventory_now();
      $db = db();
      $db->beginTransaction();
      $h = $db->prepare("INSERT INTO inv_kitchen_transfers (transfer_date, source_branch_id, target_branch_id, status, note, created_by, sent_at)
                         VALUES (?,?,?,?,?,?,?)");
      $draftNote = $note !== '' ? $note : ('Draft dari produksi #' . $prodId);
      $h->execute([date('Y-m-d'), $activeBranchId, $targetBranchId, 'DRAFT', $draftNote, ($userId > 0 ? $userId : 1), $now]);
      $transferId = (int)$db->lastInsertId();

      $i = $db->prepare("INSERT INTO inv_kitchen_transfer_items (transfer_id, product_id, qty_sent, qty_received, note) VALUES (?,?,?,?,NULL)");
      $i->execute([$transferId, $finishedId, $qtySend, 0]);
      $db->commit();

      inventory_set_flash('ok', 'Draft kiriman ke toko dibuat. Buka menu "Kirim Dapur ke Toko" untuk mengirim draft tersebut.');
      redirect(base_url('admin/inventory_kitchen_transfers.php'));
    }

  } catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
      $db->rollBack();
    }
    inventory_set_flash('error', $e->getMessage());
    redirect(base_url('admin/inventory_production.php'));
  }
}

$tab = (string)($_GET['tab'] ?? 'resep');
if (!in_array($tab, ['resep', 'produksi'], true)) $tab = 'resep';

$finishedProducts = db()->query("SELECT id,name,sku,unit FROM inv_products
  WHERE is_deleted=0 AND is_hidden=0 AND audience='dapur' AND (type='FINISHED' OR kitchen_group='finished')
  ORDER BY name ASC")->fetchAll();

$rawProducts = db()->query("SELECT id,name,sku,unit FROM inv_products
  WHERE is_deleted=0 AND is_hidden=0 AND audience='dapur' AND (type='RAW' OR kitchen_group='raw')
  ORDER BY name ASC")->fetchAll();

$selectedFinished = (int)($_GET['finished'] ?? 0);
$selectedRecipe = $selectedFinished > 0 ? production_load_recipe($selectedFinished) : null;

$lastProdId = (int)($_GET['last_prod'] ?? 0);
$lastProd = $lastProdId > 0 ? production_get_production($lastProdId, $activeBranchId) : null;
$tokoBranches = production_get_toko_branches();

$recentProductions = db()->prepare("SELECT pr.id, pr.created_at, pr.batch_qty, pr.note, p.name AS finished_name
  FROM inv_productions pr
  JOIN inv_products p ON p.id = pr.finished_product_id
  WHERE pr.branch_id=?
  ORDER BY pr.id DESC
  LIMIT 25");
$recentProductions->execute([$activeBranchId]);
$recentProductions = $recentProductions->fetchAll();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Produksi</title>
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
      <span style="color:#fff;font-weight:700">Produk & Inventory / Produksi</span>
    </div>

    <div class="content">
      <?php if ($flash): ?>
        <div class="card" style="margin-bottom:12px;border-color:<?php echo $flash['type'] === 'error' ? '#fecaca' : '#bbf7d0'; ?>"><?php echo e($flash['message']); ?></div>
      <?php endif; ?>

      <div class="card" style="margin-bottom:14px">
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <a class="btn <?php echo $tab==='resep'?'':'btn-light'; ?>" href="<?php echo e(base_url('admin/inventory_production.php?tab=resep')); ?>">Resep (BOM)</a>
          <a class="btn <?php echo $tab==='produksi'?'':'btn-light'; ?>" href="<?php echo e(base_url('admin/inventory_production.php?tab=produksi')); ?>">Mulai Produksi</a>
        </div>
        <small>Cabang aktif: <b><?php echo e((string)$activeBranch['name']); ?></b> (<?php echo e($activeBranchType); ?>)</small>
      </div>

      <?php if ($tab === 'resep'): ?>
        <div class="card">
          <h3 style="margin-top:0">Resep Produksi (RAW per 1 unit FINISHED)</h3>

          <form method="get" style="margin-bottom:10px">
            <input type="hidden" name="tab" value="resep">
            <label>Pilih produk finished (dapur)</label>
            <select name="finished" required>
              <option value="">-- pilih --</option>
              <?php foreach ($finishedProducts as $fp): ?>
                <option value="<?php echo e((string)$fp['id']); ?>" <?php echo (int)$fp['id'] === $selectedFinished ? 'selected' : ''; ?>>
                  <?php echo e($fp['name']); ?><?php echo $fp['sku'] ? ' (' . e($fp['sku']) . ')' : ''; ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn" type="submit" style="margin-top:8px">Load</button>
          </form>

          <?php if ($selectedFinished > 0): ?>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="save_recipe">
              <input type="hidden" name="finished_product_id" value="<?php echo e((string)$selectedFinished); ?>">

              <div class="card" style="background:#0b1220;color:#fff;margin:10px 0">
                Isi resep: pilih RAW item + qty per 1 unit finished. Saat produksi berjalan, qty raw masih bisa diedit sebelum diproses.
              </div>

              <table class="table">
                <thead><tr><th>RAW</th><th>Qty / 1 unit FINISHED</th><th>Unit</th></tr></thead>
                <tbody>
                  <?php
                    $existing = [];
                    if ($selectedRecipe && !empty($selectedRecipe['items'])) {
                      foreach ($selectedRecipe['items'] as $it) { $existing[(int)$it['raw_product_id']] = (float)$it['qty_per_unit']; }
                    }
                    // tampilkan 8 baris default
                    $rows = max(8, count($existing) + 2);
                    for ($i=0; $i<$rows; $i++):
                      $rawIdSel = (int)array_keys($existing)[$i] ?? 0;
                      $qtySel = $rawIdSel ? (float)$existing[$rawIdSel] : 0;
                  ?>
                    <tr>
                      <td>
                        <select name="raw_product_id[]">
                          <option value="">-- pilih raw --</option>
                          <?php foreach ($rawProducts as $rp): ?>
                            <option value="<?php echo e((string)$rp['id']); ?>" <?php echo (int)$rp['id'] === $rawIdSel ? 'selected' : ''; ?>>
                              <?php echo e($rp['name']); ?><?php echo $rp['sku'] ? ' (' . e($rp['sku']) . ')' : ''; ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </td>
                      <td><input type="number" step="0.001" name="qty_per_unit[]" value="<?php echo e($qtySel > 0 ? (string)$qtySel : ''); ?>"></td>
                      <td>
                        <?php
                          $unit = '';
                          foreach ($rawProducts as $rp) { if ((int)$rp['id'] === $rawIdSel) { $unit = (string)$rp['unit']; break; } }
                          echo e($unit);
                        ?>
                      </td>
                    </tr>
                  <?php endfor; ?>
                </tbody>
              </table>

              <button class="btn" type="submit">Simpan Resep</button>
            </form>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <div class="card">
          <h3 style="margin-top:0">Mulai Produksi</h3>

          <?php if ($lastProd): ?>
            <div class="card" style="margin:10px 0;background:#0b1220;color:#fff">
              <b>Produksi terakhir:</b>
              #<?php echo e((string)$lastProd['id']); ?> — <?php echo e((string)$lastProd['finished_name']); ?> (<?php echo e((string)$lastProd['batch_qty']); ?> <?php echo e((string)$lastProd['finished_unit']); ?>)
              <br><small>Opsional: buat draft kiriman ke toko dari hasil produksi ini.</small>
            </div>

            <form method="post" style="margin:10px 0">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="create_transfer_draft">
              <input type="hidden" name="production_id" value="<?php echo e((string)$lastProd['id']); ?>">
              <input type="hidden" name="finished_product_id" value="<?php echo e((string)$lastProd['finished_product_id']); ?>">

              <div class="grid cols-2">
                <div class="row">
                  <label>Tujuan toko</label>
                  <select name="target_branch_id" required>
                    <option value="">-- pilih toko --</option>
                    <?php foreach ($tokoBranches as $tb): ?>
                      <option value="<?php echo e((string)$tb['id']); ?>"><?php echo e((string)$tb['name']); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="row">
                  <label>Qty draft kirim</label>
                  <input type="number" step="0.001" name="qty_send" required value="<?php echo e((string)$lastProd['batch_qty']); ?>">
                </div>
                <div class="row" style="grid-column:1/-1">
                  <label>Catatan (opsional)</label>
                  <input type="text" name="note" value="">
                </div>
              </div>
              <button class="btn" type="submit">Buat Draft Kirim ke Toko</button>
              <a class="btn btn-light" href="<?php echo e(base_url('admin/inventory_kitchen_transfers.php')); ?>" style="margin-left:8px">Buka Kirim Dapur ke Toko</a>
            </form>
          <?php endif; ?>

          <form method="post" style="margin-bottom:14px">
            <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="hidden" name="action" value="run_production">

            <div class="grid cols-2">
              <div class="row">
                <label>Produk finished (dapur)</label>
                <select name="finished_product_id" required onchange="location.href='<?php echo e(base_url('admin/inventory_production.php?tab=produksi&finished=')); ?>'+this.value">
                  <option value="">-- pilih --</option>
                  <?php foreach ($finishedProducts as $fp): ?>
                    <option value="<?php echo e((string)$fp['id']); ?>" <?php echo (int)$fp['id'] === $selectedFinished ? 'selected' : ''; ?>>
                      <?php echo e($fp['name']); ?><?php echo $fp['sku'] ? ' (' . e($fp['sku']) . ')' : ''; ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <small>Pilih finished -> sistem load resepnya.</small>
              </div>

              <div class="row">
                <label>Qty produksi (unit finished)</label>
                <input id="batch_qty" type="number" step="0.001" name="batch_qty" required value="">
                <small>Contoh: 10 pcs atau 2.5 kilogram (sesuai unit finished).</small>
              </div>

              <div class="row" style="grid-column:1/-1">
                <label>Catatan (opsional)</label>
                <input type="text" name="note" value="">
              </div>
            </div>

            <?php if ($selectedFinished > 0 && $selectedRecipe && !empty($selectedRecipe['items'])): ?>
              <div class="card" style="margin:12px 0">
                <b>Raw yang akan dipakai</b><br>
                <small>Default = qty_per_unit × qty produksi. Bisa diedit sebelum diproses.</small>
                <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
                  <button class="btn btn-light" type="button" id="btnFillDefault">Isi default (auto)</button>
                  <small style="align-self:center">Kalau sudah kamu edit manual, sistem tidak akan menimpa lagi.</small>
                </div>
              </div>

              <table class="table">
                <thead><tr><th>RAW</th><th>Default per unit</th><th>Qty dipakai (editable)</th><th>Unit</th><th>Stok tersedia</th></tr></thead>
                <tbody>
                  <?php foreach ($selectedRecipe['items'] as $it): ?>
                    <?php
                      $rawId = (int)$it['raw_product_id'];
                      $per = (float)$it['qty_per_unit'];
                      $avail = stock_get_qty($activeBranchId, $rawId);
                    ?>
                    <tr>
                      <td><?php echo e($it['name']); ?>
                        <input type="hidden" name="raw_product_id[]" value="<?php echo e((string)$rawId); ?>">
                      </td>
                      <td><?php echo e((string)$per); ?></td>
                      <td>
                        <input
                          class="qtyUsed"
                          data-per="<?php echo e((string)$per); ?>"
                          type="number" step="0.001" name="qty_used[]" value="">
                      </td>
                      <td><?php echo e((string)$it['unit']); ?></td>
                      <td><?php echo e((string)$avail); ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>

              <button class="btn" type="submit">Proses Produksi</button>
            <?php else: ?>
              <div class="card" style="margin-top:12px">
                <small>Belum ada resep untuk produk ini, atau belum pilih produk finished.</small>
              </div>
            <?php endif; ?>
          </form>

          <h3 style="margin:0 0 10px">Riwayat Produksi Terakhir</h3>
          <table class="table">
            <thead><tr><th>ID</th><th>Waktu</th><th>Produk</th><th>Qty</th><th>Catatan</th></tr></thead>
            <tbody>
              <?php foreach ($recentProductions as $rp): ?>
                <tr>
                  <td><?php echo e((string)$rp['id']); ?></td>
                  <td><?php echo e((string)$rp['created_at']); ?></td>
                  <td><?php echo e((string)$rp['finished_name']); ?></td>
                  <td><?php echo e((string)$rp['batch_qty']); ?></td>
                  <td><?php echo e((string)($rp['note'] ?? '')); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
<script>
(function(){
  var qtyEl = document.getElementById('batch_qty');
  var btn = document.getElementById('btnFillDefault');

  function toNum(v){
    if (v === null || v === undefined) return 0;
    v = (''+v).replace(',', '.');
    var n = parseFloat(v);
    return isNaN(n) ? 0 : n;
  }
  function round3(n){
    return Math.round(n * 1000) / 1000;
  }

  function fill(force){
    if (!qtyEl) return;
    var batch = toNum(qtyEl.value);
    if (batch <= 0) return;
    var inputs = document.querySelectorAll('input.qtyUsed[data-per]');
    inputs.forEach(function(inp){
      var manual = inp.dataset.manual === '1';
      if (!force && manual) return;
      var per = toNum(inp.dataset.per);
      var val = round3(per * batch);
      inp.value = (val > 0 ? val : 0);
      if (force) inp.dataset.manual = '0';
    });
  }

  document.addEventListener('input', function(e){
    if (e.target && e.target.classList && e.target.classList.contains('qtyUsed')) {
      e.target.dataset.manual = '1';
    }
  });

  if (qtyEl) {
    qtyEl.addEventListener('input', function(){ fill(false); });
    qtyEl.addEventListener('change', function(){ fill(false); });
  }
  if (btn) {
    btn.addEventListener('click', function(){ fill(true); });
  }

  // initial
  setTimeout(function(){ fill(false); }, 50);
})();
</script>
</body>
</html>
