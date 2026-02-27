<?php
require_once __DIR__ . '/../core/db.php';
require_once __DIR__ . '/../core/functions.php';
require_once __DIR__ . '/../core/security.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/csrf.php';
require_once __DIR__ . '/../core/email.php';
require_once __DIR__ . '/inventory_helpers.php';

start_secure_session();
require_admin();
ensure_owner_role();
ensure_user_profile_columns();
inventory_ensure_tables();
ensure_user_invites_table();

$me = current_user();

function user_manageable_roles_for(string $role): array {
  if ($role === 'owner') {
    return ['owner', 'admin', 'pegawai_pos', 'pegawai_non_pos', 'manager_toko', 'pegawai_dapur', 'manager_dapur'];
  }
  if ($role === 'admin') {
    return ['pegawai_pos', 'pegawai_non_pos', 'manager_toko', 'pegawai_dapur', 'manager_dapur'];
  }
  if ($role === 'manager_toko') {
    return ['pegawai_pos', 'pegawai_non_pos'];
  }
  if ($role === 'manager_dapur') {
    return ['pegawai_dapur'];
  }
  return [];
}

$manageableRoles = user_manageable_roles_for((string)($me['role'] ?? ''));

function role_branch_type(?string $role): ?string {
  $role = (string)$role;
  if (in_array($role, ['pegawai_pos','pegawai_non_pos','manager_toko'], true)) return 'toko';
  if (in_array($role, ['pegawai_dapur','manager_dapur'], true)) return 'dapur';
  if ($role === 'admin') return 'adm';
  return null;
}

$branches = db()->query("SELECT id, name, branch_type FROM branches WHERE is_active=1 ORDER BY name ASC")->fetchAll();
$branchTypeById = [];
foreach ($branches as $b) { $branchTypeById[(int)$b['id']] = (string)$b['branch_type']; }


$err = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $action = $_POST['action'] ?? '';

  try {
    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) {
        $stmt = db()->prepare("SELECT id, role FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if ($target && (int)$target['id'] !== (int)($me['id'] ?? 0)) {
          if (($me['role'] ?? '') === 'admin' && in_array(($target['role'] ?? ''), ['owner', 'superadmin'], true)) {
            throw new Exception('Admin tidak bisa menghapus owner.');
          }
          $del = db()->prepare("DELETE FROM users WHERE id=?");
          $del->execute([$id]);
          redirect(base_url('admin/users.php'));
        }
      }
    }

    if ($action === 'update_role') {
      if (!in_array(($me['role'] ?? ''), ['owner', 'admin'], true)) {
        throw new Exception('Anda tidak punya akses mengubah role user.');
      }
      $id = (int)($_POST['id'] ?? 0);
      $role = $_POST['role'] ?? 'pegawai_pos';
      $branchId = (int)($_POST['branch_id'] ?? 0);
      $branchId = (int)($_POST['branch_id'] ?? 0);
      if (!in_array($role, $manageableRoles, true)) {
        throw new Exception('Role tujuan tidak diizinkan.');
      }
      if ($id > 0 && $id !== (int)($me['id'] ?? 0)) {
        $stmt = db()->prepare("SELECT id, role FROM users WHERE id=? LIMIT 1");
        $stmt->execute([$id]);
        $target = $stmt->fetch();
        if (!$target) {
          throw new Exception('User tidak ditemukan.');
        }
        if (($me['role'] ?? '') === 'admin' && in_array((string)($target['role'] ?? ''), ['owner', 'superadmin', 'admin'], true)) {
          throw new Exception('Admin tidak bisa mengubah role owner/admin.');
        }
        $expectedBranchType = role_branch_type($role);
        if ($expectedBranchType !== null) {
          if ($branchId <= 0 || (($branchTypeById[$branchId] ?? '') !== $expectedBranchType)) {
            throw new Exception('Role wajib sesuai dengan tipe cabang user.');
          }
        } else {
          $branchId = 0;
        }
        $stmt = db()->prepare("UPDATE users SET role=?, branch_id=? WHERE id=?");
        $stmt->execute([$role, $branchId > 0 ? $branchId : null, $id]);
        redirect(base_url('admin/users.php'));
      }
    }

    if ($action === 'toggle_attendance_geotagging') {
      if (!in_array(($me['role'] ?? ''), ['owner', 'admin'], true)) {
        throw new Exception('Anda tidak punya akses mengubah geotagging user.');
      }
      $id = (int)($_POST['id'] ?? 0);
      $enabled = (int)($_POST['attendance_geotagging_enabled'] ?? 0) === 1 ? 1 : 0;
      if ($id <= 0 || $id === (int)($me['id'] ?? 0)) {
        throw new Exception('User tidak valid.');
      }

      $stmt = db()->prepare("SELECT id, role FROM users WHERE id=? LIMIT 1");
      $stmt->execute([$id]);
      $target = $stmt->fetch();
      if (!$target) {
        throw new Exception('User tidak ditemukan.');
      }
      $targetRole = (string)($target['role'] ?? '');
      $targetRoleNorm = $targetRole === 'superadmin' ? 'owner' : $targetRole;
      if (($me['role'] ?? '') === 'admin' && in_array($targetRoleNorm, ['owner', 'admin'], true)) {
        throw new Exception('Admin tidak bisa mengubah geotagging owner/admin.');
      }

      $stmt = db()->prepare("UPDATE users SET attendance_geotagging_enabled=? WHERE id=?");
      $stmt->execute([$enabled, $id]);
      redirect(base_url('admin/users.php'));
    }

    if ($action === 'invite') {
      if (!in_array(($me['role'] ?? ''), ['owner', 'admin', 'manager_toko', 'manager_dapur'], true)) {
        throw new Exception('Anda tidak punya akses mengundang user.');
      }
      $email = trim($_POST['email'] ?? '');
      $role = $_POST['role'] ?? 'pegawai_pos';
      $branchId = (int)($_POST['branch_id'] ?? 0);
      if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email tidak valid.');
      }
      if (!in_array($role, $manageableRoles, true)) {
        throw new Exception('Role undangan tidak diizinkan.');
      }

      $token = bin2hex(random_bytes(16));
      $tokenHash = hash('sha256', $token);
      $expiresAt = date('Y-m-d H:i:s', strtotime('+2 days'));
      $expectedBranchType = role_branch_type($role);
      if ($expectedBranchType !== null) {
        if ($branchId <= 0 || (($branchTypeById[$branchId] ?? '') !== $expectedBranchType)) {
          throw new Exception('Role undangan harus sesuai tipe cabang.');
        }
      } else {
        $branchId = 0;
      }
      $stmt = db()->prepare("INSERT INTO user_invites (email, role, branch_id, token_hash, expires_at) VALUES (?,?,?,?,?)");
      $stmt->execute([$email, $role, $branchId > 0 ? $branchId : null, $tokenHash, $expiresAt]);

      if (!send_invite_email($email, $token, $role)) {
        throw new Exception('Gagal mengirim email undangan.');
      }

      $ok = 'Undangan berhasil dikirim.';
    }

    if ($action === 'save_email_settings') {
      if (($me['role'] ?? '') !== 'owner') {
        throw new Exception('Hanya owner yang bisa mengubah pengaturan email.');
      }
      $smtpHost = trim($_POST['smtp_host'] ?? '');
      $smtpPort = trim($_POST['smtp_port'] ?? '');
      $smtpSecure = strtolower(trim($_POST['smtp_secure'] ?? 'ssl'));
      $smtpUser = trim($_POST['smtp_user'] ?? '');
      $smtpPass = (string)($_POST['smtp_pass'] ?? '');
      $fromEmail = trim($_POST['smtp_from_email'] ?? '');
      $fromName = trim($_POST['smtp_from_name'] ?? '');
      if (!in_array($smtpSecure, ['ssl', 'tls', 'none'], true)) {
        $smtpSecure = 'ssl';
      }

      if ($smtpHost === '' || $smtpPort === '' || $smtpUser === '' || $smtpPass === '') {
        throw new Exception('Host, port, user, dan password SMTP wajib diisi.');
      }
      if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Email pengirim tidak valid.');
      }

      set_setting('smtp_host', $smtpHost);
      set_setting('smtp_port', $smtpPort);
      set_setting('smtp_secure', $smtpSecure);
      set_setting('smtp_user', $smtpUser);
      set_setting('smtp_pass', $smtpPass);
      set_setting('smtp_from_email', $fromEmail);
      set_setting('smtp_from_name', $fromName);

      $ok = 'Pengaturan email berhasil disimpan.';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$users = db()->query("SELECT u.id, u.username, u.name, u.role, u.branch_id, b.name AS branch_name, b.branch_type, u.attendance_geotagging_enabled, u.created_at FROM users u LEFT JOIN branches b ON b.id=u.branch_id ORDER BY u.id DESC")->fetchAll();
$customCss = setting('custom_css','');
$mailCfg = mail_settings();
?>
<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>User</title>
  <link rel="icon" href="<?php echo e(favicon_url()); ?>">
  <link rel="stylesheet" href="<?php echo e(asset_url('assets/app.css')); ?>">
  <style>
    .user-action-group{
      display:flex;
      flex-direction:column;
      gap:8px;
      align-items:flex-start;
      min-width:170px;
    }
    .user-action-row{
      display:flex;
      gap:8px;
      align-items:center;
      flex-wrap:wrap;
    }
    .user-action-row select{
      min-width:160px;
    }
    .user-action-row .btn{
      min-height:38px;
      padding:8px 14px;
    }
    .user-list-card{
      grid-column:1 / -1;
    }
    .user-list-table-wrap{
      overflow-x:auto;
    }

    <?php echo $customCss; ?>
  </style>
</head>
<body>
<div class="container">
  <?php include __DIR__ . '/partials_sidebar.php'; ?>
  <div class="main">
    <div class="topbar">
      <button class="btn" data-toggle-sidebar type="button">Menu</button>
      <div class="badge">User</div>
    </div>

    <div class="content">
      <div class="grid cols-2">
        <div class="card">
          <h3 style="margin-top:0">Undang User</h3>
          <?php if ($err): ?>
            <div class="card" style="border-color:rgba(251,113,133,.35);background:rgba(251,113,133,.10)"><?php echo e($err); ?></div>
          <?php endif; ?>
          <?php if ($ok): ?>
            <div class="card" style="border-color:rgba(52,211,153,.35);background:rgba(52,211,153,.10)"><?php echo e($ok); ?></div>
          <?php endif; ?>
          <?php if (!empty($manageableRoles)): ?>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="invite">
              <div class="row"><label>Email</label><input name="email" type="email" required></div>
              <div class="row">
                <label>Role</label>
                <select name="role">
                  <?php foreach ($manageableRoles as $r): ?>
                    <option value="<?php echo e($r); ?>" <?php echo $r === 'pegawai_pos' ? 'selected' : ''; ?>><?php echo e($r); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="row">
                <label>Cabang</label>
                <select name="branch_id">
                  <option value="0">-</option>
                  <?php foreach ($branches as $b): ?>
                    <option value="<?php echo e((string)$b['id']); ?>"><?php echo e($b['name'] . ' (' . $b['branch_type'] . ')'); ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn" type="submit">Kirim Undangan</button>
              <p><small>Link undangan berlaku 2 hari.</small></p>
            </form>
          <?php else: ?>
            <p><small>Anda tidak punya hak untuk mengundang user.</small></p>
          <?php endif; ?>
        </div>

        <div class="card user-list-card">
          <h3 style="margin-top:0">Daftar User</h3>
          <div class="user-list-table-wrap">
            <table class="table">
            <thead><tr><th>Username</th><th>Nama</th><th>Role</th><th>Cabang</th><th>Geotag Absen</th><th>Dibuat</th><th></th></tr></thead>
            <tbody>
              <?php foreach ($users as $u): ?>
                <?php
                  $roleLabels = [
                    'owner' => 'owner',
                    'superadmin' => 'owner',
                    'admin' => 'admin',
                    'adm' => 'admin',
                    'pegawai_pos' => 'pegawai_pos',
                    'pegawai_non_pos' => 'pegawai_non_pos',
                    'manager_toko' => 'manager_toko',
                    'pegawai_dapur' => 'pegawai_dapur',
                    'manager_dapur' => 'manager_dapur',
                  ];
                  $roleValue = (string)($u['role'] ?? '');
                  $roleValueNormalized = $roleValue === 'superadmin' ? 'owner' : ($roleValue === 'adm' ? 'admin' : $roleValue);
                  $roleLabel = $roleLabels[$roleValue] ?? ($roleValue !== '' ? $roleValue : 'pegawai_pos');
                ?>
                <tr>
                  <td><?php echo e($u['username']); ?></td>
                  <td><?php echo e($u['name']); ?></td>
                  <td><span class="badge"><?php echo e($roleLabel); ?></span></td>
                  <td><?php echo e((string)($u['branch_name'] ?? '-')); ?></td>
                  <td>
                    <span class="badge"><?php echo !empty($u['attendance_geotagging_enabled']) ? 'ON' : 'OFF'; ?></span>
                    <?php if (in_array(($me['role'] ?? ''), ['owner', 'admin'], true) && (int)$u['id'] !== (int)($me['id'] ?? 0)): ?>
                      <form method="post" style="display:inline;margin-left:6px">
                        <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                        <input type="hidden" name="action" value="toggle_attendance_geotagging">
                        <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                        <input type="hidden" name="attendance_geotagging_enabled" value="<?php echo !empty($u['attendance_geotagging_enabled']) ? '0' : '1'; ?>">
                        <button class="btn" type="submit"><?php echo !empty($u['attendance_geotagging_enabled']) ? 'Matikan' : 'Nyalakan'; ?></button>
                      </form>
                    <?php endif; ?>
                  </td>
                  <td><?php echo e($u['created_at']); ?></td>
                  <td>
                    <?php if (($me['role'] ?? '') === 'owner' && (int)$u['id'] !== (int)($me['id'] ?? 0)): ?>
                      <div class="user-action-group">
                        <form method="post" class="user-action-row">
                          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                          <input type="hidden" name="action" value="update_role">
                          <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                          <select name="role">
                            <option value="owner" <?php echo ($roleValueNormalized === 'owner') ? 'selected' : ''; ?>>owner</option>
                            <option value="admin" <?php echo ($roleValueNormalized === 'admin') ? 'selected' : ''; ?>>admin</option>
                            <option value="pegawai_pos" <?php echo ($roleValueNormalized === 'pegawai_pos') ? 'selected' : ''; ?>>pegawai_pos</option>
                            <option value="pegawai_non_pos" <?php echo ($roleValueNormalized === 'pegawai_non_pos') ? 'selected' : ''; ?>>pegawai_non_pos</option>
                            <option value="manager_toko" <?php echo ($roleValueNormalized === 'manager_toko') ? 'selected' : ''; ?>>manager_toko</option>
                            <option value="pegawai_dapur" <?php echo ($roleValueNormalized === 'pegawai_dapur') ? 'selected' : ''; ?>>pegawai_dapur</option>
                            <option value="manager_dapur" <?php echo ($roleValueNormalized === 'manager_dapur') ? 'selected' : ''; ?>>manager_dapur</option>
                          </select>
                          <select name="branch_id">
                            <option value="0">-</option>
                            <?php foreach ($branches as $b): ?>
                            <option value="<?php echo e((string)$b['id']); ?>" <?php echo ((int)($u['branch_id'] ?? 0) === (int)$b['id']) ? 'selected' : ''; ?>><?php echo e($b['name'] . ' (' . $b['branch_type'] . ')'); ?></option>
                            <?php endforeach; ?>
                          </select>
                          <button class="btn" type="submit">Simpan</button>
                        </form>
                        <form method="post" data-confirm="Hapus user ini?" class="user-action-row">
                          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                          <button class="btn" type="submit">Hapus</button>
                        </form>
                      </div>
                    <?php elseif (in_array(($me['role'] ?? ''), ['admin', 'manager_toko', 'manager_dapur'], true) && (int)$u['id'] !== (int)($me['id'] ?? 0) && in_array($roleValueNormalized, $manageableRoles, true)): ?>
                      <div class="user-action-group">
                        <form method="post" class="user-action-row">
                          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                          <input type="hidden" name="action" value="update_role">
                          <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                          <select name="role">
                            <?php foreach ($manageableRoles as $r): ?>
                              <?php if (($me['role'] ?? '') === 'admin' && in_array($r, ['owner', 'admin'], true)) continue; ?>
                              <option value="<?php echo e($r); ?>" <?php echo ($roleValueNormalized === $r) ? 'selected' : ''; ?>><?php echo e($r); ?></option>
                            <?php endforeach; ?>
                          </select>
                          <select name="branch_id">
                            <option value="0">-</option>
                            <?php foreach ($branches as $b): ?>
                            <option value="<?php echo e((string)$b['id']); ?>" <?php echo ((int)($u['branch_id'] ?? 0) === (int)$b['id']) ? 'selected' : ''; ?>><?php echo e($b['name'] . ' (' . $b['branch_type'] . ')'); ?></option>
                            <?php endforeach; ?>
                          </select>
                          <button class="btn" type="submit">Simpan</button>
                        </form>
                        <form method="post" data-confirm="Hapus user ini?" class="user-action-row">
                          <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="id" value="<?php echo e($u['id']); ?>">
                          <button class="btn" type="submit">Hapus</button>
                        </form>
                      </div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <h3 style="margin-top:0">Pengaturan Email</h3>
          <?php if (($me['role'] ?? '') === 'owner'): ?>
            <form method="post">
              <input type="hidden" name="_csrf" value="<?php echo e(csrf_token()); ?>">
              <input type="hidden" name="action" value="save_email_settings">
              <div class="row"><label>SMTP Host</label><input name="smtp_host" value="<?php echo e($mailCfg['host']); ?>" required></div>
              <div class="row"><label>SMTP Port</label><input name="smtp_port" value="<?php echo e($mailCfg['port']); ?>" required></div>
              <div class="row">
                <label>SMTP Security</label>
                <select name="smtp_secure">
                  <option value="ssl" <?php echo ($mailCfg['secure'] ?? '') === 'ssl' ? 'selected' : ''; ?>>SSL (465)</option>
                  <option value="tls" <?php echo ($mailCfg['secure'] ?? '') === 'tls' ? 'selected' : ''; ?>>TLS (STARTTLS)</option>
                  <option value="none" <?php echo ($mailCfg['secure'] ?? '') === 'none' ? 'selected' : ''; ?>>None</option>
                </select>
              </div>
              <div class="row"><label>SMTP User</label><input name="smtp_user" value="<?php echo e($mailCfg['user']); ?>" required></div>
              <div class="row"><label>SMTP Password</label><input type="password" name="smtp_pass" value="<?php echo e($mailCfg['pass']); ?>" required></div>
              <div class="row"><label>Email Pengirim</label><input name="smtp_from_email" value="<?php echo e($mailCfg['from_email']); ?>" required></div>
              <div class="row"><label>Nama Pengirim</label><input name="smtp_from_name" value="<?php echo e($mailCfg['from_name']); ?>" required></div>
              <button class="btn" type="submit">Simpan</button>
              <p><small>Default: admin@hopenoodles.my.id (SMTP 465).</small></p>
            </form>
          <?php else: ?>
            <p><small>Pengaturan email hanya tersedia untuk owner.</small></p>
          <?php endif; ?>
        </div>

      </div>
    </div>
  </div>
</div>
<script defer src="<?php echo e(asset_url('assets/app.js')); ?>"></script>
</body>
</html>
