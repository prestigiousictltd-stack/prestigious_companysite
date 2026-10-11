<?php
/**
 * Password reset request.
 * Accepts ?role=PARTNER|ADMIN|SUPER_ADMIN
 * Always returns the same success message to prevent account enumeration.
 */
require_once __DIR__ . '/../bootstrap/init.php';

$role = strtoupper((string) ($_GET['role'] ?? 'PARTNER'));
if (!in_array($role, ['PARTNER', 'ADMIN', 'SUPER_ADMIN'], true)) {
    $role = 'PARTNER';
}

$errors = [];
$done = false;

if (is_post()) {
    csrf_require();

    $role  = strtoupper((string) ($_POST['role'] ?? $role));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    if (!in_array($role, ['PARTNER', 'ADMIN', 'SUPER_ADMIN'], true)) {
        $role = 'PARTNER';
    }

    $errors = validate(
        ['role' => $role, 'email' => $email],
        ['role' => ['required', 'in:PARTNER,ADMIN,SUPER_ADMIN'], 'email' => ['required', 'email', 'max:190']]
    );

    if (!$errors) {
        $table = match ($role) {
            'PARTNER'     => 'partners',
            'ADMIN'       => 'admins',
            'SUPER_ADMIN' => 'super_admins',
        };

        // Throttle by identifier so this endpoint cannot be used to spam
        $throttle = auth_check_throttle($role, 'reset:' . $email);
        if ($throttle['locked']) {
            $done = true;   // Still pretend success to prevent enumeration
        } else {
            $stmt = db()->prepare("SELECT id, full_name, email FROM `$table` WHERE email = :e AND status = 'ACTIVE' LIMIT 1");
            $stmt->execute([':e' => $email]);
            $account = $stmt->fetch();

            if ($account) {
                $plain = auth_create_reset_token($role, (int) $account['id']);
                $link  = absolute_url('/password-reset/reset.php?role=' . urlencode($role) . '&token=' . urlencode($plain));
                $name  = e((string) $account['full_name']);
                $intro = "<p>Hello $name,</p><p>We received a request to reset your password. Click the button below to choose a new password. This link expires in "
                       . (int) $GLOBALS['SEC_CONFIG']['reset_token']['ttl_minutes'] . " minutes.</p>";
                send_branded_mail(
                    (string) $account['email'],
                    'Reset your password',
                    $intro,
                    'Reset password',
                    $link
                );
                audit_log('PASSWORD_RESET_REQUESTED', $table, (int) $account['id'], 'Reset requested.', null, null,
                    ['role' => $role, 'id' => (int) $account['id'], 'label' => (string) $account['full_name']]);
            } else {
                // Log the attempt even for unknown emails (aggregate defence)
                auth_log_attempt($role, 'reset:' . $email, false, 'unknown_email');
            }
            $done = true;
        }
    }
    flash_old($_POST);
}

$auth_page_title    = 'Reset your password';
$auth_portal        = strtolower($role);
$auth_portal_label  = match ($role) {
    'PARTNER'     => 'Partner Portal',
    'ADMIN'       => 'Admin Portal',
    'SUPER_ADMIN' => 'Super Admin',
};
$auth_portal_desc   = 'Enter the email address associated with your account.';
require PICT_ROOT . '/includes/header-auth.php';
?>

<?php if ($done): ?>
  <div class="alert alert-success">
    If an account exists for that email address, a reset link has been sent.
  </div>
  <div class="auth-footer">
    <a href="<?= e(match($role){
        'PARTNER' => '/partner/login.php',
        'ADMIN' => '/admin/login.php',
        'SUPER_ADMIN' => '/superadmin/login.php',
    }) ?>">Return to sign in</a>
  </div>
<?php else: ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
      <?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="role" value="<?= e($role) ?>">

    <div class="form-group">
      <label class="form-label" for="email">Email address</label>
      <input class="form-control" type="email" id="email" name="email"
             value="<?= e(old('email')) ?>" required autofocus>
    </div>

    <button class="btn btn-primary" type="submit">Send reset link</button>

    <div class="auth-footer">
      <a href="/login.php">Change portal</a>
    </div>
  </form>
<?php endif; ?>

<?php old_clear(); require PICT_ROOT . '/includes/footer-auth.php'; ?>