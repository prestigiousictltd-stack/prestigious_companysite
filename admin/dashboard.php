<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/admin-auth.php';
$admin = admin_require_login();

$page_title = 'Dashboard';
$active_nav = 'dashboard';

$adminId = (int) $admin['id'];

// Counts
$sql = "SELECT
    SUM(CASE WHEN status = 'DRAFT'     THEN 1 ELSE 0 END) AS drafts,
    SUM(CASE WHEN status = 'SUBMITTED' THEN 1 ELSE 0 END) AS submitted,
    SUM(CASE WHEN status = 'RETURNED'  THEN 1 ELSE 0 END) AS returned,
    SUM(CASE WHEN status = 'APPROVED'  THEN 1 ELSE 0 END) AS approved,
    COUNT(*) AS total
  FROM daily_reports WHERE created_by_admin_id = :id";
$stmt = db()->prepare($sql);
$stmt->execute([':id' => $adminId]);
$counts = $stmt->fetch() ?: ['drafts'=>0,'submitted'=>0,'returned'=>0,'approved'=>0,'total'=>0];

// Recent reports
$stmt = db()->prepare("
    SELECT id, report_reference, report_date, status, submitted_at, updated_at
    FROM daily_reports
    WHERE created_by_admin_id = :id
    ORDER BY report_date DESC, id DESC
    LIMIT 8
");
$stmt->execute([':id' => $adminId]);
$recent = $stmt->fetchAll();

// Returned reports needing correction
$stmt = db()->prepare("
    SELECT id, report_reference, report_date, review_comment
    FROM daily_reports
    WHERE created_by_admin_id = :id AND status = 'RETURNED'
    ORDER BY reviewed_at DESC
    LIMIT 5
");
$stmt->execute([':id' => $adminId]);
$returned = $stmt->fetchAll();

require PICT_ROOT . '/includes/header-admin.php';

$statusBadge = function (string $s): string {
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

<div class="page-head">
  <div>
    <h1>Welcome, <?= e(strtok($admin['full_name'], ' ')) ?></h1>
    <p>Snapshot of your report activity and any items awaiting your attention.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-primary" href="./daily-report.php">+ New Daily Report</a>
    <a class="btn btn-outline" href="./reports.php">View All Reports</a>
  </div>
</div>

<?php if (!empty($returned)): ?>
  <div class="alert alert-warn">
    <strong><?= count($returned) ?> report<?= count($returned) === 1 ? '' : 's' ?> returned for correction.</strong>
    Review the Super Admin comments and resubmit.
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat stat--draft">
    <div class="stat__label">Drafts</div>
    <div class="stat__value"><?= number_format((int) $counts['drafts']) ?></div>
    <div class="stat__sub">Saved but not submitted</div>
  </div>
  <div class="stat stat--pending">
    <div class="stat__label">Submitted</div>
    <div class="stat__value"><?= number_format((int) $counts['submitted']) ?></div>
    <div class="stat__sub">Awaiting Super Admin review</div>
  </div>
  <div class="stat stat--err">
    <div class="stat__label">Returned</div>
    <div class="stat__value"><?= number_format((int) $counts['returned']) ?></div>
    <div class="stat__sub">Needs your correction</div>
  </div>
  <div class="stat stat--ok">
    <div class="stat__label">Approved</div>
    <div class="stat__value"><?= number_format((int) $counts['approved']) ?></div>
    <div class="stat__sub">Locked and archived</div>
  </div>
</div>

<div class="card">
  <div class="flex-between mb-2">
    <h2 style="margin:0">Recent Reports</h2>
    <a class="btn btn-outline btn-sm" href="./reports.php">See all</a>
  </div>

  <?php if (empty($recent)): ?>
    <div class="empty">
      <h3>No reports yet</h3>
      <p>Start by filing your first daily report.</p>
      <a class="btn btn-primary" href="./daily-report.php">+ New Daily Report</a>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr>
            <th>Reference</th>
            <th>Report Date</th>
            <th>Status</th>
            <th>Submitted</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recent as $r): ?>
            <tr>
              <td><strong><?= e($r['report_reference']) ?></strong></td>
              <td><?= e(date('d M Y', strtotime($r['report_date']))) ?></td>
              <td><?= $statusBadge($r['status']) ?></td>
              <td><?= $r['submitted_at'] ? e(date('d M Y H:i', strtotime($r['submitted_at']))) : '<span class="muted">—</span>' ?></td>
              <td class="text-right">
                <a class="btn btn-outline btn-sm" href="report-view.php?id=<?= (int) $r['id'] ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-admin.php'; ?>