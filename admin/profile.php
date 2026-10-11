<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/admin-auth.php';
$admin = admin_require_login();

$page_title = 'My Profile';
$active_nav = 'profile';

$updated = false;
if (is_post()) {
    csrf_require();
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $phone    = trim((string) ($_POST['phone'] ?? ''));
    $errors = validate(
        ['full_name' => $fullName, 'phone' => $phone],
        ['full_name' => ['required', 'string', 'min:2', 'max:150'], 'phone' => ['max:30']]
    );
    if (!$errors) {
        $stmt = db()->prepare('UPDATE admins SET full_name = :n, phone = :p, updated_at = :ua WHERE id = :id');
        $stmt->execute([':n' => $fullName, ':p' => $phone !== '' ? $phone : null, ':ua' => now_dt(), ':id' => (int) $admin['id']]);
        audit_log('ADMIN_PROFILE_UPDATE', 'admins', (int) $admin['id'], 'Profile updated.');
        $_SESSION['admin_name'] = $fullName;
        $updated = true;
        flash('success', 'Profile updated.');
        redirect('/admin/profile.php');
    }
    flash('error', reset($errors));
}

require PICT_ROOT . '/includes/header-admin.php';
?>

<div class="page-head">
  <div>
    <h1>My Profile</h1>
    <p>Update your contact details. Email is managed by the Super Admin.</p>
  </div>
</div>

<div class="card card--pad-lg" style="max-width:640px">
  <form method="post">
    <?= csrf_field() ?>
    <div class="form-group">
      <label class="form-label" for="full_name">Full Name</label>
      <input class="form-control" type="text" id="full_name" name="full_name" value="<?= e($admin['full_name']) ?>" required maxlength="150">
    </div>
    <div class="form-group">
      <label class="form-label">Email Address</label>
      <input class="form-control" type="email" value="<?= e($admin['email']) ?>" readonly>
      <div class="form-hint">Contact the Super Admin if your email needs to change.</div>
    </div>
    <div class="form-group">
      <label class="form-label" for="phone">Phone</label>
      <input class="form-control" type="tel" id="phone" name="phone" value="<?= e((string) $admin['phone']) ?>" maxlength="30">
    </div>
    <button class="btn btn-primary" type="submit">Save Changes</button>
    <a class="btn btn-outline" href="/admin/change-password.php" style="margin-left:8px">Change Password</a>
  </form>
</div>

<div class="card card--pad-lg mt-3" style="max-width:640px">
  <h2>Account Information</h2>
  <table class="tbl tbl--compact">
    <tr><th style="width:180px">Account ID</th><td>#<?= (int) $admin['id'] ?></td></tr>
    <tr><th>Status</th><td><span class="badge badge--approved"><?= e($admin['status']) ?></span></td></tr>
    <tr><th>Last Login</th><td><?= $admin['last_login_at'] ? e(date('d M Y H:i', strtotime($admin['last_login_at']))) : '<span class="muted">—</span>' ?></td></tr>
    <tr><th>Last Login IP</th><td><?= e((string) $admin['last_login_ip']) ?: '<span class="muted">—</span>' ?></td></tr>
    <tr><th>Created</th><td><?= e(date('d M Y', strtotime($admin['created_at']))) ?></td></tr>
  </table>
</div>

<?php require PICT_ROOT . '/includes/footer-admin.php'; ?>