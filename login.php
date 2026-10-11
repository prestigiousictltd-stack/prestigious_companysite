<?php
/**
 * Role chooser — lets a visitor pick which portal to sign in to.
 */
require_once __DIR__ . '/bootstrap/init.php';

$auth_page_title    = 'Choose a portal';
$auth_portal        = 'public';
$auth_portal_label  = 'Sign in';
$auth_portal_desc   = 'Select the portal you wish to sign in to.';
require PICT_ROOT . '/includes/header-auth.php';
?>
<div style="display:grid;gap:12px">
  <a class="btn btn-primary" href="./partner/login.php">Partner Portal</a>
  <a class="btn btn-ghost"   href="./admin/login.php">Admin Portal</a>
  <a class="btn btn-ghost"   href="./superadmin/login.php">Super Admin Portal</a>
</div>
<div class="auth-footer" style="margin-top:18px">
  <a href="./">&larr; Back to website</a>
</div>
<?php require PICT_ROOT . '/includes/footer-auth.php'; ?>