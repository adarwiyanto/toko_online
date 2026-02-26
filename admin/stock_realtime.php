<?php
require_once __DIR__ . '/inventory_helpers.php';

start_secure_session();
require_login();
$u = current_user();
$role = (string)($u['role'] ?? '');
if (!in_array($role, ['owner', 'admin'], true)) {
  http_response_code(403);
  exit('Forbidden');
}

inventory_ensure_tables();
$branches = inventory_branch_options();
$customCss = setting('custom_css', '');
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Stok Cabang (Realtime)</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style><?php echo $customCss; ?></style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar"><button class="btn" data-toggle-sidebar type="button">Menu</button><span style="color:#fff;font-weight:700">Produk & Inventory / Stok Cabang (Realtime)</span></div>
    <div class="content">
      <div class="card" style="margin-bottom:14px">
        <h3 style="margin-top:0">Stok Cabang (Realtime)</h3>
        <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
          <div class="row" style="min-width:220px;max-width:280px;">
            <label>Cabang</label>
            <select id="branch_id">
              <option value="0">Semua Cabang</option>
              <?php foreach ($branches as $branch): ?>
                <option value="<?php echo e((string)$branch['id']); ?>"><?php echo e((string)$branch['name']); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="row" style="min-width:220px;max-width:340px;">
            <label>Cari Produk (nama/SKU)</label>
            <input type="text" id="q" placeholder="Ketik nama atau SKU...">
          </div>
          <div class="row" style="min-width:160px;max-width:220px;">
            <label>Interval Refresh</label>
            <select id="interval_sec">
              <option value="2">2 detik</option>
              <option value="5">5 detik</option>
              <option value="10">10 detik</option>
              <option value="30">30 detik</option>
              <option value="3600" selected>1 jam</option>
            </select>
          </div>
          <button class="btn" type="button" id="btn_refresh">Refresh</button>
          <span class="badge" id="last_update">Terakhir update: -</span>
        </div>
      </div>

      <div id="fetch_error" class="card" style="display:none;margin-bottom:14px;color:#b00020"></div>

      <div class="card">
        <table class="table">
          <thead>
          <tr>
            <th>Produk</th>
            <th>Unit</th>
            <th>Tipe</th>
            <th>Cabang</th>
            <th>Stok</th>
          </tr>
          </thead>
          <tbody id="rows_stock">
          <tr><td colspan="5">Memuat data...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
  const apiUrlBase = "<?= e(base_url('admin/api_stock_realtime.php')); ?>";
  const branchEl = document.getElementById('branch_id');
  const qEl = document.getElementById('q');
  const intervalEl = document.getElementById('interval_sec');
  const rowsEl = document.getElementById('rows_stock');
  const lastUpdateEl = document.getElementById('last_update');
  const btnRefresh = document.getElementById('btn_refresh');
  const fetchErrorEl = document.getElementById('fetch_error');
  let timer = null;

  function formatStock(value) {
    const num = Number(value || 0);
    return num.toLocaleString('id-ID', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
  }

  function escapeHtml(s) {
    return String(s || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/\"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function showError(message) {
    fetchErrorEl.style.display = 'block';
    fetchErrorEl.textContent = 'Gagal mengambil data: ' + message;
    rowsEl.innerHTML = '<tr><td colspan="5">Gagal mengambil data.</td></tr>';
  }

  function clearError() {
    fetchErrorEl.style.display = 'none';
    fetchErrorEl.textContent = '';
  }

  function renderRows(rows) {
    if (!Array.isArray(rows) || rows.length === 0) {
      rowsEl.innerHTML = '<tr><td colspan="5">Tidak ada data.</td></tr>';
      return;
    }
    let html = '';
    rows.forEach(function(row){
      const sku = row.sku ? ' (' + escapeHtml(row.sku) + ')' : '';
      html += '<tr>' +
        '<td>' + escapeHtml(row.name) + sku + '</td>' +
        '<td>' + escapeHtml(row.unit) + '</td>' +
        '<td>' + escapeHtml(row.type) + '</td>' +
        '<td>' + escapeHtml(row.branch_name) + '</td>' +
        '<td>' + formatStock(row.stock_qty) + '</td>' +
      '</tr>';
    });
    rowsEl.innerHTML = html;
  }

  function loadData() {
    rowsEl.innerHTML = '<tr><td colspan="5">Memuat data...</td></tr>';
    const branchId = encodeURIComponent(branchEl.value || '0');
    const q = encodeURIComponent(qEl.value || '');
    const url = apiUrlBase + '?branch_id=' + branchId + '&q=' + q;

    fetch(url, {
      cache: 'no-store',
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' }
    })
      .then(function(resp){
        if (!resp.ok) {
          return resp.text().then(function(text){
            throw new Error('HTTP ' + resp.status + ': ' + text.slice(0, 120));
          });
        }
        const ct = resp.headers.get('content-type') || '';
        if (ct.indexOf('application/json') === -1) {
          return resp.text().then(function(text){
            throw new Error('Non-JSON response: ' + text.slice(0, 120));
          });
        }
        return resp.json();
      })
      .then(function(json){
        if (!json || json.ok !== true) {
          throw new Error((json && json.error) ? json.error : 'API error');
        }
        clearError();
        renderRows(json.rows || []);
        lastUpdateEl.textContent = 'Terakhir update: ' + (json.server_time || '-');
      })
      .catch(function(err){
        showError(err && err.message ? err.message : 'Unknown error');
      });
  }

  function restartTimer() {
    if (timer) {
      clearInterval(timer);
    }
    const sec = Number(intervalEl.value || 3600);
    timer = setInterval(loadData, Math.max(2, sec) * 1000);
  }

  let searchTimer = null;
  qEl.addEventListener('input', function(){
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadData, 300);
  });
  branchEl.addEventListener('change', loadData);
  intervalEl.addEventListener('change', restartTimer);
  btnRefresh.addEventListener('click', loadData);

  loadData();
  restartTimer();
})();
</script>
<script src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
