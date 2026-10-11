<?php
/**
 * Partner login.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';

if (partner_logged_in()) {
    redirect('/partner/dashboard.php');
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
        $res = partner_attempt_login($email, $password);
        if ($res['ok']) {
            flash('success', 'Welcome back, ' . $res['partner']['full_name'] . '.');
            redirect('/partner/dashboard.php');
        }
        $errors['_global'] = $res['reason'] ?? 'Sign-in failed.';
    }
    flash_old($_POST, ['password']);
}

$auth_page_title    = 'Partner sign in';
$auth_portal        = 'partner';
$auth_portal_label  = 'Partner Portal';
$auth_portal_desc   = 'Sign in to view your NIN enrolment records, commissions and withdrawals.';
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
    <a href="../password-reset/request.php?role=PARTNER">Forgot your password?</a>
    <a href="./register.php">Create an account</a>
  </div>
  <div class="auth-footer" style="margin-top:6px">
    <a href="../">&larr; Back to website</a>
    <a href="../login.php">Change portal</a>
  </div>
</form>

<?php old_clear(); require PICT_ROOT . '/includes/footer-auth.php'; ?>