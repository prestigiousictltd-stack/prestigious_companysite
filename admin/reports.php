<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/admin-auth.php';
$admin = admin_require_login();

$page_title = 'Report History';
$active_nav = 'reports';

// Filters
$fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
$fFrom   = trim((string) ($_GET['from'] ?? ''));
$fTo     = trim((string) ($_GET['to'] ?? ''));

$where = ['created_by_admin_id = :aid'];
$params = [':aid' => (int) $admin['id']];

if (in_array($fStatus, ['DRAFT','SUBMITTED','UNDER_REVIEW','APPROVED','RETURNED','ARCHIVED'], true)) {
    $where[] = 'status = :st';
    $params[':st'] = $fStatus;
}
if ($fFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) {
    $where[] = 'report_date >= :f';
    $params[':f'] = $fFrom;
}
if ($fTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo)) {
    $where[] = 'report_date <= :t';
    $params[':t'] = $fTo;
}

$whereSql = implode(' AND ', $where);

// Pagination
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$countStmt = db()->prepare("SELECT COUNT(*) FROM daily_reports WHERE $whereSql");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare("
    SELECT id, report_reference, report_date, status, submitted_at, reviewed_at, updated_at, net_income
    FROM daily_reports
    WHERE $whereSql
    ORDER BY report_date DESC, id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$reports = $stmt->fetchAll();

require PICT_ROOT . '/includes/header-admin.php';

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

<div class="page-head">
  <div>
    <h1>Report History</h1>
    <p><?= number_format($total) ?> report<?= $total === 1 ? '' : 's' ?> found.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-primary" href="./daily-report.php">+ New Daily Report</a>
  </div>
</div>

<div class="filter-bar">
  <form method="get">
    <div class="form-group">
      <label class="form-label">Status</label>
      <select class="form-control" name="status">
        <option value="">All statuses</option>
        <?php foreach (['DRAFT','SUBMITTED','UNDER_REVIEW','APPROVED','RETURNED','ARCHIVED'] as $s): ?>
          <option value="<?= e($s) ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= e(str_replace('_',' ', $s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">From</label>
      <input type="date" class="form-control" name="from" value="<?= e($fFrom) ?>">
    </div>
    <div class="form-group">
      <label class="form-label">To</label>
      <input type="date" class="form-control" name="to" value="<?= e($fTo) ?>">
    </div>
    <div class="form-group" style="grid-column:span 2">
      <button class="btn btn-primary" type="submit">Filter</button>
      <a class="btn btn-outline" href="./reports.php">Reset</a>
    </div>
  </form>
</div>

<div class="card">
  <?php if (empty($reports)): ?>
    <div class="empty">
      <h3>No reports found</h3>
      <p>Try adjusting the filters or file a new report.</p>
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
            <th>Net Income</th>
            <th>Submitted</th>
            <th>Last Updated</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reports as $r): ?>
            <tr>
              <td><strong><?= e($r['report_reference']) ?></strong></td>
              <td><?= e(date('d M Y', strtotime($r['report_date']))) ?></td>
              <td><?= $badge($r['status']) ?></td>
              <td class="text-right" style="font-variant-numeric:tabular-nums">
                <?= e(format_money($r['net_income'])) ?>
              </td>
              <td><?= $r['submitted_at'] ? e(date('d M Y H:i', strtotime($r['submitted_at']))) : '<span class="muted">—</span>' ?></td>
              <td><?= e(date('d M Y H:i', strtotime($r['updated_at'] ?? $r['submitted_at'] ?? $r['report_date']))) ?></td>
              <td class="text-right">
                <div class="tbl__actions">
                  <a class="btn btn-outline btn-sm" href="./report-view.php?id=<?= (int) $r['id'] ?>">View</a>
                  <?php if (in_array($r['status'], ['DRAFT','RETURNED'], true)): ?>
                    <a class="btn btn-ghost btn-sm" href="./daily-report.php?edit=<?= (int) $r['id'] ?>">Edit</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <ul class="pagination">
        <?php if ($page > 1): ?>
          <li><a href="?page=<?= $page - 1 ?>&status=<?= urlencode($fStatus) ?>&from=<?= urlencode($fFrom) ?>&to=<?= urlencode($fTo) ?>">Previous</a></li>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <li><?= $i === $page ? '<span class="active">' . $i . '</span>' : '<a href="?page=' . $i . '&status=' . urlencode($fStatus) . '&from=' . urlencode($fFrom) . '&to=' . urlencode($fTo) . '">' . $i . '</a>' ?></li>
        <?php endfor; ?>
        <?php if ($page < $pages): ?>
          <li><a href="?page=<?= $page + 1 ?>&status=<?= urlencode($fStatus) ?>&from=<?= urlencode($fFrom) ?>&to=<?= urlencode($fTo) ?>">Next</a></li>
        <?php endif; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-admin.php'; ?>