<?php
/**
 * Maintenance mode page.
 * Included from other public entry points when maintenance_mode=1.
 */

if (!defined('PICT_BOOTSTRAPPED')) {
   require_once __DIR__ . '/bootstrap/init.php';
}

$companyName = (string) setting('company_name', 'Prestigious ICT Investment Limited');
$message     = (string) setting('maintenance_message', 'We are performing scheduled maintenance. Please check back shortly.');
if (!headers_sent()) {
    http_response_code(503);
    header('Retry-After: 3600');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Maintenance — <?= e($companyName) ?></title>
<link rel="stylesheet" href="/assets/vendor/bootstrap/bootstrap.min.css">
<style>
body{
  margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
  background:linear-gradient(140deg,#0b5ed7 0%,#062b6c 60%,#041c4a 100%);
  color:#fff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;
  padding:24px;text-align:center;
}
.box{max-width:560px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.15);
  border-radius:16px;padding:44px 36px;backdrop-filter:blur(8px)}
h1{font-size:26px;margin:0 0 12px;color:#fff}
p{color:rgba(255,255,255,.82);margin:0 0 24px;font-size:15.5px;line-height:1.7}
a.btn{display:inline-block;background:#fff;color:#0b5ed7;padding:11px 22px;border-radius:10px;
  font-weight:600;text-decoration:none}
a.btn:hover{background:rgba(255,255,255,.92);text-decoration:none}
.brand{font-size:13px;letter-spacing:.12em;text-transform:uppercase;opacity:.75;margin-bottom:12px}
</style>
</head>
<body>
  <div class="box">
    <div class="brand"><?= e($companyName) ?></div>
    <h1>We&rsquo;ll be right back</h1>
    <p><?= nl2br(e($message)) ?></p>
    <a class="btn" href="/">Try again</a>
  </div>
</body>
</html>