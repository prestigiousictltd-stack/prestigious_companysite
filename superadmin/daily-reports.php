<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/daily-report-render.php';
$sa = superadmin_require_login();

$page_title = 'Daily Reports';
$active_nav = 'daily-reports';

$fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
$fFrom = trim((string) ($_GET['from'] ?? ''));
$fTo = trim((string) ($_GET['to'] ?? ''));
$viewId = (int) ($_GET['view'] ?? 0);

$where = ['1=1']; $params = [];
if (in_array($fStatus, ['DRAFT','SUBMITTED','UNDER_REVIEW','APPROVED','RETURNED','ARCHIVED'], true)) {
    $where[] = 'r.status = :st'; $params[':st'] = $fStatus;
}
if ($fFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) { $where[] = 'r.report_date >= :f'; $params[':f'] = $fFrom; }
if ($fTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo)) { $where[] = 'r.report_date <= :t'; $params[':t'] = $fTo; }
$whereSql = implode(' AND ', $where);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25; $offset = ($page - 1) * $perPage;

$c = db()->prepare("SELECT COUNT(*) FROM daily_reports r WHERE $whereSql");
$c->execute($params);
$total = (int) $c->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare("
    SELECT r.*, a.full_name AS author
    FROM daily_reports r LEFT JOIN admins a ON a.id = r.created_by_admin_id
    WHERE $whereSql
    ORDER BY r.report_date DESC, r.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$detail = null;
if ($viewId > 0) {
    $detail = fetch_daily_report_bundle($viewId);
}

require PICT_ROOT . '/includes/header-superadmin.php';

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
    return '<span class="badge ' . $cls . '">' . e(str_replace('_',' ', $s)) . '</span>';
};
?>

<div class="page-head">
  <div><h1>Daily Reports</h1><p><?= number_format($total) ?> report<?= $total === 1 ? '' : 's' ?>.</p></div>
</div>

<?php if ($detail): $r = $detail['report']; ?>
<div class="card mb-3">
  <div class="flex-between mb-2">
    <h2 style="margin:0">Report <span class="masked"><?= e($r['report_reference']) ?></span> <?= $badge($r['status']) ?></h2>
    <div class="tbl__actions">
      <a class="btn btn-outline btn-sm" href="./daily-reports.php">Close</a>
    </div>
  </div>

  <form method="post" action="./daily-report-review.php" class="flex-between mt-2" style="gap:10px;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="report_id" value="<?= (int) $r['id'] ?>">
    <div class="form-group" style="margin:0;flex:1;min-width:260px">
      <label class="form-label">Review Comment (optional unless returning)</label>
      <input class="form-control" type="text" name="comment" maxlength="1000" value="<?= e((string) $r['review_comment']) ?>">
    </div>
    <div class="form-group" style="margin:0">
      <label class="form-label">&nbsp;</label>
      <?php if ($r['status'] === 'SUBMITTED' || $r['status'] === 'UNDER_REVIEW'): ?>
        <button class="btn btn-ok" name="decision" value="approve" onclick="return confirm('Approve this report? It will become immutable.');">Approve</button>
        <button class="btn btn-danger" name="decision" value="return" onclick="return confirm('Return for correction?');" style="margin-left:8px">Return</button>
        <button class="btn btn-outline" name="decision" value="archive" style="margin-left:8px">Archive</button>
      <?php elseif ($r['status'] === 'APPROVED' || $r['status'] === 'RETURNED'): ?>
        <button class="btn btn-outline" name="decision" value="archive">Archive</button>
      <?php endif; ?>
    </div>
  </form>

  <div class="mt-3" style="background:#fbfcfe;border:1px solid var(--line);border-radius:10px;padding:20px;max-height:70vh;overflow:auto">
    <?= render_daily_report($r, $detail['children'], 'web') ?>
  </div>

   <p class="mt-2">
    <a class="btn btn-outline btn-sm"
       href="<?= e(root_url('/superadmin/daily-report-print.php')) ?>?id=<?= (int) $r['id'] ?>"
       target="_blank" rel="noopener">Print / Save as PDF</a>
  </p></div>
<?php endif; ?>

<div class="filter-bar">
  <form method="get">
    <div class="form-group"><label class="form-label">Status</label>
      <select class="form-control" name="status">
        <option value="">All</option>
        <?php foreach (['DRAFT','SUBMITTED','UNDER_REVIEW','APPROVED','RETURNED','ARCHIVED'] as $s): ?>
          <option value="<?= e($s) ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= e(str_replace('_',' ', $s)) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= e($fFrom) ?>"></div>
    <div class="form-group"><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= e($fTo) ?>"></div>
    <div class="form-group"><button class="btn btn-grape" type="submit">Filter</button>
      <a class="btn btn-outline" href="daily-reports.php">Reset</a></div>
  </form>
</div>

<div class="card">
  <?php if (empty($rows)): ?><div class="empty"><p>No reports.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead><tr><th>Reference</th><th>Date</th><th>Author</th><th>Status</th><th class="num">Net Income</th><th>Submitted</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><span class="masked"><?= e($r['report_reference']) ?></span></td>
              <td><?= e(date('d M Y', strtotime($r['report_date']))) ?></td>
              <td><?= e((string) $r['author']) ?></td>
              <td><?= $badge($r['status']) ?></td>
              <td class="num"><?= e(format_money((float) $r['net_income'])) ?></td>
              <td><?= $r['submitted_at'] ? e(date('d M Y H:i', strtotime($r['submitted_at']))) : '<span class="muted">—</span>' ?></td>
              <td class="text-right">
                <a class="btn btn-outline btn-sm" href="?view=<?= (int) $r['id'] ?>">Review</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
      <ul class="pagination">
        <?php $qs = $_GET; unset($qs['page'], $qs['view']); $base = http_build_query($qs); ?>
        <?php if ($page > 1): ?><li><a href="?<?= e($base) ?>&page=<?= $page - 1 ?>">Previous</a></li><?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <li><?= $i === $page ? '<span class="active">' . $i . '</span>' : '<a href="?' . e($base) . '&page=' . $i . '">' . $i . '</a>' ?></li>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><li><a href="?<?= e($base) ?>&page=<?= $page + 1 ?>">Next</a></li><?php endif; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>