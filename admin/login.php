<?php
/**
 * Admin login.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/admin-auth.php';

if (admin_logged_in()) {
    redirect('admin/dashboard.php');
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
        $res = admin_attempt_login($email, $password);
        if ($res['ok']) {
            if ((int) $res['admin']['must_change_pwd'] === 1) {
                flash('info', 'Please change your initial password before continuing.');
                redirect('/admin/change-password.php');
            }
            flash('success', 'Welcome, ' . $res['admin']['full_name'] . '.');
            redirect('admin/dashboard.php');
        }
        $errors['_global'] = $res['reason'] ?? 'Sign-in failed.';
    }
    flash_old($_POST, ['password']);
}

$auth_page_title    = 'Admin sign in';
$auth_portal        = 'admin';
$auth_portal_label  = 'Admin Portal';
$auth_portal_desc   = 'Sign in to file the daily operational report and view your submissions.';
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
    <a href="../password-reset/request.php?role=ADMIN">Forgot your password?</a>
    <a href="../login.php">Change portal</a>
  </div>
</form>

<?php old_clear(); require PICT_ROOT . '/includes/footer-auth.php'; ?>