<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$page_title = 'My Profile';
$active_nav = 'profile';
$errors = [];

if (is_post()) {
    csrf_require();
    $name = trim((string) ($_POST['full_name'] ?? ''));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $errors = validate(['full_name' => $name, 'phone' => $phone],
        ['full_name' => ['required', 'string', 'min:2', 'max:150'], 'phone' => ['max:20']]);
    if (!$errors) {
        db()->prepare("UPDATE super_admins SET full_name=:n, phone=:p, updated_at=:ua WHERE id=:id")
            ->execute([':n' => $name, ':p' => $phone !== '' ? $phone : null, ':ua' => now_dt(), ':id' => (int) $sa['id']]);
        $_SESSION['superadmin_name'] = $name;
        audit_log('SUPERADMIN_PROFILE_UPDATE', 'super_admins', (int) $sa['id'], '');
        flash('success', 'Profile updated.');
        redirect('/superadmin/profile.php');
    }
}

require PICT_ROOT . '/includes/header-superadmin.php';
?>
<div class="page-head"><div><h1>My Profile</h1><p>Update your contact details.</p></div></div>
<?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="card card--pad-lg" style="max-width:640px">
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-group"><label class="form-label">Full Name</label>
      <input class="form-control" type="text" name="full_name" value="<?= e($sa['full_name']) ?>" required maxlength="150"></div>
    <div class="form-group"><label class="form-label">Email</label>
      <input class="form-control" type="email" value="<?= e($sa['email']) ?>" readonly>
      <div class="form-hint">Email changes require manual DB intervention for security.</div></div>
    <div class="form-group"><label class="form-label">Phone</label>
      <input class="form-control" type="tel" name="phone" value="<?= e((string) $sa['phone']) ?>" maxlength="20"></div>
    <button class="btn btn-grape" type="submit">Save Changes</button>
    <a class="btn btn-outline" href="/superadmin/change-password.php" style="margin-left:8px">Change Password</a>
  </form>
</div>

<div class="card card--pad-lg mt-3" style="max-width:640px">
  <h2>Account Information</h2>
  <table class="tbl tbl--compact">
    <tr><th style="width:180px">Account ID</th><td>#<?= (int) $sa['id'] ?></td></tr>
    <tr><th>Status</th><td><span class="badge badge--<?= strtolower($sa['status']) ?>"><?= e($sa['status']) ?></span></td></tr>
    <tr><th>Last Login</th><td><?= $sa['last_login_at'] ? e(date('d M Y H:i', strtotime($sa['last_login_at']))) : '—' ?></td></tr>
    <tr><th>Last Login IP</th><td><?= e((string) $sa['last_login_ip']) ?></td></tr>
    <tr><th>Created</th><td><?= e(date('d M Y', strtotime($sa['created_at']))) ?></td></tr>
  </table>
</div>
<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>