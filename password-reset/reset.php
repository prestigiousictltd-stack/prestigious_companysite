<?php
/**
 * Password reset — set new password.
 * Accepts ?role=...&token=...
 */
require_once __DIR__ . '/../bootstrap/init.php';

$role  = strtoupper((string) ($_GET['role'] ?? ($_POST['role'] ?? 'PARTNER')));
$token = (string) ($_GET['token'] ?? ($_POST['token'] ?? ''));

if (!in_array($role, ['PARTNER', 'ADMIN', 'SUPER_ADMIN'], true)) {
    $role = 'PARTNER';
}

$record = $token !== '' ? auth_lookup_reset_token($token, $role) : null;

$errors = [];
$done = false;

if (is_post()) {
    csrf_require();
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['password_confirmation'] ?? '');

    if (!$record) {
        $errors['_global'] = 'This reset link is invalid or has expired.';
    } else {
        $errors = validate(
            ['password' => $password, 'password_confirmation' => $confirm],
            [
                'password'              => ['required', 'password', 'confirmed:password_confirmation'],
                'password_confirmation' => ['required'],
            ]
        );

        if (!$errors) {
            $table = match ($role) {
                'PARTNER'     => 'partners',
                'ADMIN'       => 'admins',
                'SUPER_ADMIN' => 'super_admins',
            };
            db()->beginTransaction();
            try {
                $hash = auth_hash($password);
                $stmt = db()->prepare("UPDATE `$table` SET password_hash = ?, must_change_pwd = 0, updated_at = ? WHERE id = ?");
                $stmt->execute([$hash, now_dt(), $record['account_id']]);
                auth_consume_reset_token($record['id']);
                db()->commit();
                audit_log('PASSWORD_RESET_COMPLETED', $table, $record['account_id'], 'Password reset via token.');
                $done = true;
            } catch (Throwable $e) {
                db()->rollBack();
                error_log('[password reset] failed: ' . $e->getMessage());
                $errors['_global'] = 'Could not reset password. Please try again.';
            }
        }
    }
}

$auth_page_title    = 'Set a new password';
$auth_portal        = strtolower($role);
$auth_portal_label  = match ($role) {
    'PARTNER'     => 'Partner Portal',
    'ADMIN'       => 'Admin Portal',
    'SUPER_ADMIN' => 'Super Admin',
};
$auth_portal_desc   = 'Choose a strong password.';
require PICT_ROOT . '/includes/header-auth.php';
?>

<?php if ($done): ?>
  <div class="alert alert-success">Your password has been updated. You may now sign in.</div>
  <div class="auth-footer">
    <a href="<?= e(match($role){
        'PARTNER' => '/partner/login.php',
        'ADMIN' => '/admin/login.php',
        'SUPER_ADMIN' => '/superadmin/login.php',
    }) ?>">Continue to sign in</a>
  </div>
<?php elseif (!$record): ?>
  <div class="alert alert-danger">This reset link is invalid or has expired.</div>
  <div class="auth-footer">
    <a href="/public/password-reset/request.php?role=<?= e($role) ?>">Request a new link</a>
  </div>
<?php else: ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="role"  value="<?= e($role) ?>">
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <div class="form-group">
      <label class="form-label" for="password">New password</label>
      <input class="form-control" type="password" id="password" name="password" required autofocus>
      <small style="color:#6b7280;font-size:12px">
        Minimum <?= (int) setting('security_password_min_length', 8) ?> characters, with upper case, lower case and a number.
      </small>
    </div>

    <div class="form-group">
      <label class="form-label" for="password_confirmation">Confirm new password</label>
      <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" required>
    </div>

    <button class="btn btn-primary" type="submit">Update password</button>
  </form>
<?php endif; ?>

<?php require PICT_ROOT . '/includes/footer-auth.php'; ?>