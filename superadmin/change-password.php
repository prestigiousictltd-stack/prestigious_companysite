<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$page_title = 'Change Password';
$active_nav = 'change-password';
$mustChange = (int) $sa['must_change_pwd'] === 1;
$errors = [];

if (is_post()) {
    csrf_require();
    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $errors = validate(
        ['current_password' => $current, 'new_password' => $new, 'confirm_password' => $confirm],
        [
            'current_password' => ['required'],
            'new_password'     => ['required', 'password', 'same:confirm_password'],
            'confirm_password' => ['required'],
        ],
        ['current_password' => 'Current password', 'new_password' => 'New password', 'confirm_password' => 'Password confirmation']
    );

    if (!$errors) {
        if (!auth_verify($current, (string) $sa['password_hash'])) {
            $errors['current_password'] = 'Current password is incorrect.';
        } elseif ($current === $new) {
            $errors['new_password'] = 'New password must differ from the current one.';
        }
    }

    if (!$errors) {
        db()->beginTransaction();
        try {
            auth_change_password('super_admins', (int) $sa['id'], auth_hash($new), 'SUPER_ADMIN');
            db()->commit();
            flash('success', 'Password changed successfully.');
            redirect('/superadmin/dashboard.php');
        } catch (Throwable $e) {
            db()->rollBack();
            $errors['_global'] = 'Could not change the password.';
        }
    }
}

require PICT_ROOT . '/includes/header-superadmin.php';
?>
<div class="page-head"><div><h1>Change Password</h1>
<?php if ($mustChange): ?><p>You must change your initial password before continuing.</p><?php endif; ?>
</div></div>
<?php if ($mustChange): ?><div class="alert alert-warn">Your account is using an initial password. Please set a new password now.</div><?php endif; ?>
<?php if (!empty($errors['_global'])): ?><div class="alert alert-danger"><?= e($errors['_global']) ?></div><?php endif; ?>
<div class="card card--pad-lg" style="max-width:520px">
<form method="post" novalidate>
  <?= csrf_field() ?>
  <div class="form-group"><label class="form-label" for="current_password">Current Password</label>
    <input class="form-control" type="password" id="current_password" name="current_password" required autocomplete="current-password">
    <?php if (!empty($errors['current_password'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['current_password']) ?></div><?php endif; ?>
  </div>
  <div class="form-group"><label class="form-label" for="new_password">New Password</label>
    <input class="form-control" type="password" id="new_password" name="new_password" required autocomplete="new-password">
    <div class="form-hint">Minimum <?= (int) setting('security_password_min_length', 8) ?> characters, upper + lower + number.</div>
    <?php if (!empty($errors['new_password'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['new_password']) ?></div><?php endif; ?>
  </div>
  <div class="form-group"><label class="form-label" for="confirm_password">Confirm New Password</label>
    <input class="form-control" type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
    <?php if (!empty($errors['confirm_password'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['confirm_password']) ?></div><?php endif; ?>
  </div>
  <button class="btn btn-grape" type="submit">Change Password</button>
  <?php if (!$mustChange): ?><a class="btn btn-outline" href="/superadmin/dashboard.php" style="margin-left:8px">Cancel</a><?php endif; ?>
</form>
</div>
<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>