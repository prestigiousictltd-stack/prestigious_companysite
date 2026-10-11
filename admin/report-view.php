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

$page_title = 'Report ' . $bundle['report']['report_reference'];
$active_nav = 'reports';

$renderedHtml = render_daily_report($bundle['report'], $bundle['children'], 'web');

require PICT_ROOT . '/includes/header-admin.php';

$r = $bundle['report'];
$badge = function (string $s): string {
    $cls = match ($s) {
        'DRAFT'        => 'badge--draft',
        'SUBMITTED'    => 'badge--submitted',
        'UNDER_REVIEW' => 'badge--under_review',
        'APPROVED'     => 'badge--approved',
        'RETURNED'     => 'badge--returned',
        'ARCHIVED'     => 'badge--archived',
        default        => 'badge--draft',
    };
    return '<span class="badge ' . $cls . '">' . e(str_replace('_', ' ', $s)) . '</span>';
};
?>

<style>
.dr-report{background:#fff;border:1px solid var(--line);border-radius:var(--radius);padding:36px 40px;max-width:960px;margin:0 auto;box-shadow:var(--shadow-sm)}
.dr-report__head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;border-bottom:2px solid var(--ink);padding-bottom:18px;margin-bottom:26px;flex-wrap:wrap}
.dr-report__head h1{margin:0 0 4px;font-size:22px}
.dr-report__head h2{margin:0;font-size:14px;font-weight:600;letter-spacing:.16em;text-transform:uppercase;color:var(--muted)}
.dr-report__meta{border-collapse:collapse;font-size:13px}
.dr-report__meta th,.dr-report__meta td{padding:2px 0;text-align:left;vertical-align:top}
.dr-report__meta th{color:var(--muted);font-weight:600;padding-right:12px}
.dr-report__section{margin-bottom:28px}
.dr-report__section h3{font-size:15px;letter-spacing:.06em;text-transform:uppercase;color:var(--ink);margin:0 0 12px;padding-bottom:6px;border-bottom:1px solid var(--line)}
.dr-report__section h4{font-size:13.5px;color:var(--ink);margin:14px 0 4px;font-weight:600}
.dr-report__narrative{white-space:pre-wrap;font-size:14px;line-height:1.6}
.dr-report__empty{color:var(--muted);font-style:italic;font-size:13.5px}
.dr-report__tbl{width:100%;border-collapse:collapse;font-size:13.5px}
.dr-report__tbl th,.dr-report__tbl td{padding:8px 10px;border:1px solid var(--line);text-align:left;vertical-align:top}
.dr-report__tbl th{background:#f8fafc;font-weight:700;font-size:11.5px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted)}
.dr-report__tbl td.num,.dr-report__tbl th.num{text-align:right;font-variant-numeric:tabular-nums}
.dr-report__tbl tfoot td{background:#f8fafc}
.dr-report__tbl--fin{max-width:520px}
.dr-report__tbl--net td{background:#f0f7ff;font-weight:700}
.dr-report__chart{margin-top:14px;background:#fbfcfe;border:1px solid var(--line);border-radius:8px;padding:12px}
</style>

<div class="page-head">
  <div>
    <h1><?= e($r['report_reference']) ?></h1>
    <p>
      <?= e(date('d M Y', strtotime($r['report_date']))) ?>
      &middot; <?= $badge($r['status']) ?>
      <?php if ($r['submitted_at']): ?>
        &middot; Submitted <?= e(date('d M Y H:i', strtotime($r['submitted_at']))) ?>
      <?php endif; ?>
    </p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="./reports.php">All Reports</a>
    <?php if (in_array($r['status'], ['DRAFT','RETURNED'], true)): ?>
      <a class="btn btn-ghost" href="./daily-report.php?edit=<?= (int) $r['id'] ?>">Edit</a>
    <?php endif; ?>
    <a class="btn btn-primary" href="./daily-report-print.php?id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener">Print / Save as PDF</a>
  </div>
</div>

<?php if ($r['status'] === 'RETURNED' && !empty($r['review_comment'])): ?>
  <div class="alert alert-warn">
    <strong>Returned for correction.</strong><br>
    <em>Super Admin comment:</em> <?= e($r['review_comment']) ?>
  </div>
<?php endif; ?>

<?= $renderedHtml ?>

<?php require PICT_ROOT . '/includes/footer-admin.php'; ?>