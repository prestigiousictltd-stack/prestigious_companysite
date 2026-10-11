<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/admin-auth.php';
$admin = admin_require_login();

$id = (int) ($_GET['id'] ?? 0);
$bundle = $id > 0 ? fetch_daily_report_bundle($id) : null;

if (!$bundle || (int) $bundle['report']['created_by_admin_id'] !== (int) $admin['id']) {
    flash('error', 'Report not found.');
    redirect('/admin/reports.php');
}

$page_title = 'Report Preview';
$active_nav = 'reports';

$renderedHtml = render_daily_report($bundle['report'], $bundle['children'], 'web');

$admin_scripts = [];
require PICT_ROOT . '/includes/header-admin.php';
?>

<style>
/* Report preview styling */
.dr-report{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:36px 40px;max-width:960px;margin:0 auto;box-shadow:var(--shadow-sm)}
.dr-report__head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;border-bottom:2px solid var(--ink);padding-bottom:18px;margin-bottom:26px;flex-wrap:wrap}
.dr-report__head h1{margin:0 0 4px;font-size:22px;letter-spacing:-.01em}
.dr-report__head h2{margin:0;font-size:14px;font-weight:600;letter-spacing:.16em;text-transform:uppercase;color:var(--muted)}
.dr-report__meta{border-collapse:collapse;font-size:13px}
.dr-report__meta th,.dr-report__meta td{padding:2px 0;text-align:left;vertical-align:top}
.dr-report__meta th{color:var(--muted);font-weight:600;padding-right:12px}
.dr-report__section{margin-bottom:28px}
.dr-report__section h3{font-size:15px;letter-spacing:.06em;text-transform:uppercase;color:var(--ink);margin:0 0 12px;padding-bottom:6px;border-bottom:1px solid var(--line)}
.dr-report__section h4{font-size:13.5px;color:var(--ink);margin:14px 0 4px;font-weight:600}
.dr-report__narrative{white-space:pre-wrap;font-size:14px;color:var(--text);line-height:1.6}
.dr-report__empty{color:var(--muted);font-style:italic;font-size:13.5px}
.dr-report__tbl{width:100%;border-collapse:collapse;font-size:13.5px}
.dr-report__tbl th,.dr-report__tbl td{padding:8px 10px;border:1px solid var(--line);text-align:left;vertical-align:top}
.dr-report__tbl th{background:#f8fafc;font-weight:700;font-size:11.5px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted)}
.dr-report__tbl td.num,.dr-report__tbl th.num{text-align:right;font-variant-numeric:tabular-nums}
.dr-report__tbl tfoot td{background:#f8fafc}
.dr-report__tbl--fin{max-width:520px}
.dr-report__tbl--net td{background:#f0f7ff;font-weight:700}
.dr-report__chart{margin-top:14px;background:#fbfcfe;border:1px solid var(--line);border-radius:8px;padding:12px}
.dr-report__foot{border-top:1px solid var(--line);margin-top:32px;padding-top:14px;font-size:11.5px;color:var(--muted);text-align:center;line-height:1.6}
.dr-report__foot-sign{margin-top:6px;font-style:italic}
@media print{
  .admin-sidebar,.admin-topbar,.page-head__actions,.save-bar{display:none!important}
  .admin-shell{grid-template-columns:1fr!important}
  .admin-content{padding:0!important}
  .dr-report{border:0;box-shadow:none;padding:0;max-width:none}
}
</style>

<div class="page-head">
  <div>
    <h1>Report Preview</h1>
    <p><?= e($bundle['report']['report_reference']) ?> &middot; <?= e(date('d M Y', strtotime($bundle['report']['report_date']))) ?></p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="/admin/reports.php">Back to History</a>
    <?php if (in_array($bundle['report']['status'], ['DRAFT','RETURNED'], true)): ?>
      <a class="btn btn-ghost" href="/admin/daily-report.php?edit=<?= (int) $bundle['report']['id'] ?>">Edit</a>
    <?php endif; ?>
    <a class="btn btn-primary" href="/admin/daily-report-print.php?id=<?= (int) $bundle['report']['id'] ?>" target="_blank" rel="noopener">Print / Save as PDF</a>
  </div>
</div>

<?= $renderedHtml ?>

<?php require PICT_ROOT . '/includes/footer-admin.php'; ?>