<?php
/**
 * Shared header for unauthenticated auth pages (login, password reset).
 * Expected variables before include:
 *   $auth_page_title  (string)
 *   $auth_portal      (string)  'partner' | 'admin' | 'superadmin' | 'public'
 *   $auth_portal_label(string)
 *   $auth_portal_desc (string)  optional subtitle
 */

if (!isset($auth_page_title))    $auth_page_title    = 'Sign in';
if (!isset($auth_portal))        $auth_portal        = 'public';
if (!isset($auth_portal_label))  $auth_portal_label  = 'Sign in';
if (!isset($auth_portal_desc))   $auth_portal_desc   = '';
$companyName = (string) setting('company_name', 'Prestigious ICT Investment Limited');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($auth_page_title) ?> &middot; <?= e($companyName) ?></title>
<link rel="stylesheet" href="<?= e(url('/assets/vendor/bootstrap/bootstrap.min.css')) ?>">
<style>
:root{
  --brand:#0b5ed7; --brand-dark:#0847a8; --bg:#0f172a; --card:#ffffff;
}
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;
  background:#E8E8E8;
  color:#0f172a;
  display:flex;align-items:center;justify-content:center;padding:24px;
}
.auth-card{
  width:100%;max-width:460px;background:var(--card);border-radius:14px;
  box-shadow:0 20px 60px rgba(0,0,0,.35);overflow:hidden;
}
.auth-card__brand{
  background:var(--brand);color:#fff;padding:20px 28px;font-weight:600;font-size:17px;
  display:flex;align-items:center;gap:10px;
}
.auth-card__brand .dot{
  width:10px;height:10px;border-radius:50%;background:#7ee0a0;display:inline-block;
}
.auth-card__body{padding:28px}
.auth-card__title{margin:0 0 4px 0;font-size:22px;font-weight:700}
.auth-card__desc{margin:0 0 22px 0;color:#6b7280;font-size:14px}
.form-label{font-weight:600;font-size:13px;color:#374151;margin-bottom:4px;display:block}
.form-control{
  width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:15px;
  transition:border-color .15s,box-shadow .15s;background:#fff;
}
.form-control:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px rgba(11,94,215,.15)}
.form-group{margin-bottom:16px}
.btn{
  display:inline-block;width:100%;padding:11px 16px;border:0;border-radius:8px;
  font-weight:600;font-size:15px;cursor:pointer;transition:background .15s;text-align:center;
  text-decoration:none;
}
.btn-primary{background:var(--brand);color:#fff}
.btn-primary:hover{background:var(--brand-dark);color:#fff}
.btn-ghost{background:transparent;color:var(--brand);border:1px solid var(--brand)}
.alert{padding:10px 14px;border-radius:8px;font-size:14px;margin-bottom:16px}
.alert-danger{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.alert-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
.alert-info{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}
.auth-footer{margin-top:18px;font-size:13px;color:#6b7280;display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap}
.auth-footer a{color:var(--brand);text-decoration:none}
.auth-footer a:hover{text-decoration:underline}
.portal-pill{
  display:inline-block;font-size:11px;font-weight:700;letter-spacing:.06em;
  text-transform:uppercase;background:rgba(255,255,255,.18);color:#fff;
  padding:3px 8px;border-radius:999px;margin-left:auto;
}
@media print{.auth-card{box-shadow:none}}
</style>
</head>
<body>
<div class="auth-card">
  <div class="auth-card__brand">
    <span class="dot"></span>
    <?= e($companyName) ?>
    <span class="portal-pill"><?= e($auth_portal_label) ?></span>
  </div>
  <div class="auth-card__body">
    <h1 class="auth-card__title"><?= e($auth_page_title) ?></h1>
    <?php if ($auth_portal_desc !== ''): ?>
      <p class="auth-card__desc"><?= e($auth_portal_desc) ?></p>
    <?php endif; ?>
    <?php if ($msg = flash_get('error')): ?>
      <div class="alert alert-danger"><?= e($msg) ?></div>
    <?php endif; ?>
    <?php if ($msg = flash_get('success')): ?>
      <div class="alert alert-success"><?= e($msg) ?></div>
    <?php endif; ?>
    <?php if ($msg = flash_get('info')): ?>
      <div class="alert alert-info"><?= e($msg) ?></div>
    <?php endif; ?>