<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$page_title = 'Admin Accounts';
$active_nav = 'admins';
$errors = [];
$newPwd = null;

if (is_post()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $errors = validate([
            'full_name' => $name, 'email' => $email, 'phone' => $phone, 'password' => $password,
        ], [
            'full_name' => ['required', 'string', 'min_len:2', 'max_len:150'],
            'email' => ['required', 'email', 'max_len:190'],
            'phone' => ['max_len:20'],
            'password' => ['required', 'password'],
        ]);

        if (!$errors) {
            $chk = db()->prepare("SELECT 1 FROM admins WHERE email = :e");
            $chk->execute([':e' => $email]);
            if ($chk->fetchColumn()) { $errors['email'] = 'Email already in use.'; }
        }

        if (!$errors) {
            $stmt = db()->prepare("INSERT INTO admins (full_name, email, phone, password_hash, must_change_pwd, status, created_by_super_admin_id, created_at) VALUES (:n, :e, :p, :h, 1, 'ACTIVE', :by, :ca)");
            $stmt->execute([':n' => $name, ':e' => $email, ':p' => $phone !== '' ? $phone : null, ':h' => auth_hash($password), ':by' => (int) $sa['id'], ':ca' => now_dt()]);
            $newId = (int) db()->lastInsertId();
            audit_log('ADMIN_CREATED', 'admins', $newId, 'Admin ' . $name);
            notify('ADMIN', $newId, 'ACCOUNT_CREATED', 'Your admin account is ready',
                'Sign in with the credentials provided by the Super Admin.', '/admin/login.php');
            flash('success', 'Admin account created. They will be required to change the password on first login.');
            redirect('/superadmin/admins.php');
        }
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare("SELECT status FROM admins WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $cur = (string) $stmt->fetchColumn();
        $new = $cur === 'ACTIVE' ? 'DISABLED' : 'ACTIVE';
        $stmt = db()->prepare("UPDATE admins SET status = :s, updated_at = :ua WHERE id = :id");
        $stmt->execute([':s' => $new, ':ua' => now_dt(), ':id' => $id]);
        audit_log('ADMIN_STATUS_CHANGE', 'admins', $id, 'Status ' . $cur . ' → ' . $new);
        flash('success', 'Admin status updated.');
        redirect('/superadmin/admins.php');
    }

    if ($action === 'reset') {
        $id = (int) ($_POST['id'] ?? 0);
        $temp = 'Pict!' . bin2hex(random_bytes(4)) . 'a1';
        $stmt = db()->prepare("UPDATE admins SET password_hash = :h, must_change_pwd = 1, updated_at = :ua WHERE id = :id");
        $stmt->execute([':h' => auth_hash($temp), ':ua' => now_dt(), ':id' => $id]);
        $newPwd = $temp;
        notify('ADMIN', $id, 'PASSWORD_RESET_BY_ADMIN', 'Password reset', 'Contact the Super Admin for your temporary password.');
        audit_log('ADMIN_PASSWORD_RESET', 'admins', $id, 'Password reset.');
        flash('success', 'Password reset. Temporary password: ' . $temp);
        redirect('/superadmin/admins.php');
    }
}

$admins = db()->query("SELECT * FROM admins ORDER BY created_at DESC")->fetchAll();
require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head"><div><h1>Admin Accounts</h1><p>Manage operational Admin users who file daily reports.</p></div></div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card mb-3">
  <h2>Create New Admin</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-row">
      <div class="form-group"><label class="form-label">Full Name</label><input class="form-control" type="text" name="full_name" required maxlength="150"></div>
      <div class="form-group"><label class="form-label">Phone</label><input class="form-control" type="tel" name="phone" maxlength="20"></div>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required maxlength="190"></div>
      <div class="form-group"><label class="form-label">Initial Password</label><input class="form-control" type="password" name="password" required>
        <div class="form-hint">Admin will be forced to change this on first login.</div>
      </div>
    </div>
    <button class="btn btn-grape" type="submit">Create Admin</button>
  </form>
</div>

<div class="card">
  <h2>Existing Admins</h2>
  <?php if (empty($admins)): ?><div class="empty"><p>No admins yet.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Last Login</th><th>Created</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($admins as $a): ?>
            <tr>
              <td><strong><?= e($a['full_name']) ?></strong></td>
              <td><?= e($a['email']) ?></td>
              <td><span class="badge badge--<?= strtolower($a['status']) ?>"><?= e($a['status']) ?></span></td>
              <td><?= $a['last_login_at'] ? e(date('d M Y H:i', strtotime($a['last_login_at']))) : '<span class="muted">—</span>' ?></td>
              <td><?= e(date('d M Y', strtotime($a['created_at']))) ?></td>
              <td class="text-right">
                <div class="tbl__actions">
                  <form method="post" style="display:inline" onsubmit="return confirm('Reset password?');">
                    <?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn-outline btn-sm" type="submit">Reset Password</button>
                  </form>
                  <form method="post" style="display:inline" onsubmit="return confirm('Toggle status?');">
                    <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn btn-outline btn-sm" type="submit"><?= $a['status'] === 'ACTIVE' ? 'Disable' : 'Enable' ?></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>