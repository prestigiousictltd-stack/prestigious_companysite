<?php
/**
 * Super Admin daily report print renderer.
 * Standalone page — no chrome, suitable for print and Save as PDF.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$id = (int) ($_GET['id'] ?? 0);
$bundle = $id > 0 ? fetch_daily_report_bundle($id) : null;

if (!$bundle) {
    http_response_code(404);
    echo 'Report not found.';
    exit;
}

$companyName = (string) setting('company_name', 'Prestigious ICT Investment Limited');
$r    = $bundle['report'];
$html = render_daily_report($r, $bundle['children'], 'print');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($r['report_reference']) ?> — <?= e($companyName) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Times New Roman",Georgia,serif;color:#0f172a;font-size:12.5pt;line-height:1.5;background:#f5f5f5;padding:24px 0}
.page{background:#fff;max-width:800px;margin:0 auto;padding:42px 52px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
.dr-report__head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;border-bottom:2px solid #0f172a;padding-bottom:14px;margin-bottom:22px;flex-wrap:wrap}
.dr-report__head h1{margin:0 0 3px;font-size:17pt;letter-spacing:-.01em;font-family:Arial,sans-serif;font-weight:700}
.dr-report__head h2{margin:0;font-size:10pt;font-weight:600;letter-spacing:.18em;text-transform:uppercase;color:#666;font-family:Arial,sans-serif}
.dr-report__meta{border-collapse:collapse;font-size:10pt;font-family:Arial,sans-serif}
.dr-report__meta th,.dr-report__meta td{padding:1px 0;text-align:left;vertical-align:top}
.dr-report__meta th{color:#666;font-weight:600;padding-right:10px;white-space:nowrap}
.dr-report__section{margin-bottom:22px;page-break-inside:avoid}
.dr-report__section h3{font-size:10.5pt;letter-spacing:.06em;text-transform:uppercase;color:#0f172a;margin:0 0 10px;padding-bottom:4px;border-bottom:1px solid #cbd5e1;font-family:Arial,sans-serif;font-weight:700}
.dr-report__section h4{font-size:10pt;color:#0f172a;margin:10px 0 3px;font-weight:700;font-family:Arial,sans-serif}
.dr-report__narrative{white-space:pre-wrap;font-size:11pt;line-height:1.55}
.dr-report__empty{color:#888;font-style:italic;font-size:10.5pt}
.dr-report__tbl{width:100%;border-collapse:collapse;font-size:10.5pt;font-family:Arial,sans-serif}
.dr-report__tbl th,.dr-report__tbl td{padding:6px 8px;border:1px solid #cbd5e1;text-align:left;vertical-align:top}
.dr-report__tbl th{background:#f1f5f9;font-weight:700;font-size:9pt;letter-spacing:.05em;text-transform:uppercase;color:#475569}
.dr-report__tbl td.num,.dr-report__tbl th.num{text-align:right;font-variant-numeric:tabular-nums}
.dr-report__tbl tfoot td{background:#f1f5f9;font-weight:700}
.dr-report__tbl--fin{max-width:420px}
.dr-report__tbl--net td{background:#eff6ff}
.dr-report__chart{margin-top:10px;padding:8px;border:1px solid #e2e8f0;border-radius:6px;page-break-inside:avoid}
.dr-report__foot{border-top:1px solid #cbd5e1;margin-top:28px;padding-top:12px;font-size:9pt;color:#666;text-align:center;line-height:1.5;font-family:Arial,sans-serif}
.dr-report__foot-sign{margin-top:4px;font-style:italic}

.toolbar{max-width:800px;margin:0 auto 14px;display:flex;justify-content:space-between;gap:10px;font-family:Arial,sans-serif}
.toolbar .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;background:#7c3aed;color:#fff;text-decoration:none;font-size:13.5px;font-weight:600;border:0;cursor:pointer}
.toolbar .btn:hover{filter:brightness(1.05)}
.toolbar .btn-outline{background:#fff;color:#0f172a;border:1px solid #cbd5e1}
.toolbar .btn-outline:hover{background:#f1f5f9}

@page{margin:16mm}
@media print{
  body{background:#fff;padding:0}
  .page{box-shadow:none;padding:0;max-width:none;margin:0}
  .toolbar{display:none}
}
</style>
</head>
<body>

<div class="toolbar">
  <button class="btn" onclick="window.print()">&#128424; Print / Save as PDF</button>
  <a class="btn btn-outline" href="<?= e(root_url('/superadmin/daily-reports.php')) ?>?view=<?= (int) $r['id'] ?>">Close</a>
</div>

<div class="page">
  <?= $html ?>
</div>

</body>
</html>