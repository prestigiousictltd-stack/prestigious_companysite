<?php
/**
 * Super Admin login.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';

if (superadmin_logged_in()) {
    redirect('prestigious-ict/superadmin/dashboard.php');
}

$errors = [];
if (is_post()) {
    csrf_require();

    $email    = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $errors = validate(
        ['email' => $email, 'password' => $password],
        ['email' => ['required', 'email', 'max:190'], 'password' => ['required', 'min:1']]
    );

    if (!$errors) {
        $res = superadmin_attempt_login($email, $password);
        if ($res['ok']) {
            if ((int) $res['superadmin']['must_change_pwd'] === 1) {
                flash('info', 'Please change the initial password before continuing.');
                redirect('./superadmin/change-password.php');
            }
            flash('success', 'Welcome, ' . $res['superadmin']['full_name'] . '.');
            redirect('./superadmin/dashboard.php');
        }
        $errors['_global'] = $res['reason'] ?? 'Sign-in failed.';
    }
    flash_old($_POST, ['password']);
}

$auth_page_title    = 'Super Admin sign in';
$auth_portal        = 'superadmin';
$auth_portal_label  = 'Super Admin';
$auth_portal_desc   = 'Highest privilege access. All actions are audited.';
require PICT_ROOT . '/includes/header-auth.php';
?>

<?php if (!empty($errors['_global'])): ?>
  <div class="alert alert-danger"><?= e($errors['_global']) ?></div>
<?php endif; ?>

<form method="post" novalidate>
  <?= csrf_field() ?>

  <div class="form-group">
    <label class="form-label" for="email">Email address</label>
    <input class="form-control" type="email" id="email" name="email"
           value="<?= e(old('email')) ?>" autocomplete="email" required autofocus>
    <?php if (!empty($errors['email'])): ?>
      <div class="alert alert-danger" style="margin-top:6px"><?= e($errors['email']) ?></div>
    <?php endif; ?>
  </div>

  <div class="form-group">
    <label class="form-label" for="password">Password</label>
    <input class="form-control" type="password" id="password" name="password"
           autocomplete="current-password" required>
    <?php if (!empty($errors['password'])): ?>
      <div class="alert alert-danger" style="margin-top:6px"><?= e($errors['password']) ?></div>
    <?php endif; ?>
  </div>

  <button class="btn btn-primary" type="submit">Sign in</button>

  <div class="auth-footer">
    <a href="../password-reset/request.php?role=SUPER_ADMIN">Forgot your password?</a>
    <a href="../login.php">Change portal</a>
  </div>
</form>

<?php old_clear(); require PICT_ROOT . '/includes/footer-auth.php'; ?>