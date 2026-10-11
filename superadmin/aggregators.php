<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/aggregator.php';
$sa = superadmin_require_login();

$page_title = 'Aggregators';
$active_nav = 'aggregators';

$fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
$fSearch = trim((string) ($_GET['q'] ?? ''));

$where  = ['1=1'];
$params = [];
if (in_array($fStatus, ['ACTIVE','INACTIVE'], true)) {
    $where[] = 'a.status = :st';
    $params[':st'] = $fStatus;
}
if ($fSearch !== '') {
    $like = '%' . $fSearch . '%';
    $where[] = '(a.full_name LIKE :q_name
                  OR a.email     LIKE :q_email
                  OR a.phone     LIKE :q_phone)';
    $params[':q_name']  = $like;
    $params[':q_email'] = $like;
    $params[':q_phone'] = $like;
}
$whereSql = implode(' AND ', $where);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$cStmt = db()->prepare("SELECT COUNT(*) FROM aggregators a WHERE $whereSql");
$cStmt->execute($params);
$total = (int) $cStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare("
    SELECT a.*,
           (SELECT COUNT(DISTINCT partner_id) FROM partner_aggregator_assignments
             WHERE aggregator_id = a.id AND status = 'ACTIVE') AS partner_count,
           (SELECT commission_percentage FROM aggregator_commission_rates
             WHERE aggregator_id = a.id AND status = 'ACTIVE'
               AND effective_from <= CURDATE()
               AND (effective_to IS NULL OR effective_to >= CURDATE())
             ORDER BY effective_from DESC, id DESC LIMIT 1) AS current_pct
    FROM aggregators a
    WHERE $whereSql
    ORDER BY a.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Totals for the summary cards (across the whole table, not the filtered set)
$globalStats = db()->query("
    SELECT
      (SELECT COUNT(*) FROM aggregators WHERE status = 'ACTIVE')   AS active_count,
      (SELECT COUNT(*) FROM aggregators WHERE status = 'INACTIVE') AS inactive_count,
      (SELECT COALESCE(SUM(amount), 0) FROM aggregator_financial_transactions WHERE type = 'COMMISSION_EARNED') AS earned_total,
      (SELECT COALESCE(SUM(-amount), 0) FROM aggregator_financial_transactions WHERE type = 'PAYOUT') AS paid_total,
      (SELECT COALESCE(SUM(amount), 0) FROM aggregator_financial_transactions) AS balance_total
")->fetch();
// Detect historical partner enrolment records that could be backfilled:
// partner records with no settlement yet, whose partner was already
// attached to an aggregator at the time of the record.
$unsettledCount = (int) db()->query("
    SELECT COUNT(*)
    FROM partner_enrolment_records r
    WHERE r.partner_id IS NOT NULL
      AND r.commission_amount > 0
      AND NOT EXISTS (
          SELECT 1 FROM aggregator_enrolment_records a
          WHERE a.partner_enrolment_id = r.id
      )
      AND EXISTS (
          SELECT 1 FROM partner_aggregator_assignments pa
          WHERE pa.partner_id = r.partner_id
            AND pa.status = 'ACTIVE'
            AND pa.effective_from <= r.effective_date_used
            AND (pa.effective_to IS NULL OR pa.effective_to >= r.effective_date_used)
      )
")->fetchColumn();
require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head">
  <div>
    <h1>Aggregators</h1>
    <p><?= number_format($total) ?> aggregator<?= $total === 1 ? '' : 's' ?> registered.</p>
  </div>
   <div class="page-head__actions">
    <?php if ($unsettledCount > 0): ?>
      <a class="btn btn-warn" href="<?= e(root_url('/superadmin/aggregator-backfill.php')) ?>">
        Run Backfill (<?= number_format($unsettledCount) ?>)
      </a>
    <?php else: ?>
      <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregator-backfill.php')) ?>">
        Backfill Historical
      </a>
    <?php endif; ?>
    <a class="btn btn-grape" href="<?= e(root_url('/superadmin/aggregator-add.php')) ?>">+ Add Aggregator</a>
  </div>
</div>

<?php if ($unsettledCount > 0): ?>
  <div class="alert alert-warn" style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
    <div>
      <strong><?= number_format($unsettledCount) ?></strong>
      historical partner enrolment record<?= $unsettledCount === 1 ? '' : 's' ?>
      can be credited to aggregators but <?= $unsettledCount === 1 ? 'has' : 'have' ?> no settlement yet.
      This typically happens when a partner was attached to an aggregator after their enrolment records were imported.
    </div>
    <a class="btn btn-warn btn-sm" href="<?= e(root_url('/superadmin/aggregator-backfill.php')) ?>">
      Review &amp; Run Backfill
    </a>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat stat--ok">
    <div class="stat__label">Active Aggregators</div>
    <div class="stat__value"><?= number_format((int) $globalStats['active_count']) ?></div>
  </div>
  <div class="stat stat--inactive">
    <div class="stat__label">Inactive</div>
    <div class="stat__value"><?= number_format((int) $globalStats['inactive_count']) ?></div>
  </div>
  <div class="stat stat--purple">
    <div class="stat__label">Total Earned (All-Time)</div>
    <div class="stat__value"><?= e(format_money((float) $globalStats['earned_total'])) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Total Paid Out</div>
    <div class="stat__value"><?= e(format_money((float) $globalStats['paid_total'])) ?></div>
  </div>
  <div class="stat stat--pending">
    <div class="stat__label">Outstanding Balance</div>
    <div class="stat__value"><?= e(format_money((float) $globalStats['balance_total'])) ?></div>
  </div>
</div>

<div class="filter-bar">
  <form method="get">
    <div class="form-group">
      <label class="form-label">Search</label>
      <input class="form-control" type="text" name="q" value="<?= e($fSearch) ?>" placeholder="Name, email or phone">
    </div>
    <div class="form-group">
      <label class="form-label">Status</label>
      <select class="form-control" name="status">
        <option value="">All</option>
        <option value="ACTIVE"<?= $fStatus === 'ACTIVE' ? ' selected' : '' ?>>Active</option>
        <option value="INACTIVE"<?= $fStatus === 'INACTIVE' ? ' selected' : '' ?>>Inactive</option>
      </select>
    </div>
    <div class="form-group">
      <button class="btn btn-grape" type="submit">Filter</button>
      <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregators.php')) ?>">Reset</a>
    </div>
  </form>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="empty">
      <h3>No aggregators yet</h3>
      <p>Add the first aggregator to start tracking their commission.</p>
      <a class="btn btn-grape" href="<?= e(root_url('/superadmin/aggregator-add.php')) ?>">+ Add Aggregator</a>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr>
            <th>Name</th>
            <th>Contact</th>
            <th class="num">Rate</th>
            <th class="num">Partners</th>
            <th class="num">Balance</th>
            <th>Status</th>
            <th>Added</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $a):
              $balance = aggregator_available_balance((int) $a['id']);
              $earned  = aggregator_total_earned((int) $a['id']);
              $paid    = aggregator_total_paid((int) $a['id']);
          ?>
            <tr>
              <td><strong><?= e($a['full_name']) ?></strong></td>
              <td>
                <?php if (!empty($a['phone'])): ?><div><?= e($a['phone']) ?></div><?php endif; ?>
                <?php if (!empty($a['email'])): ?><div class="muted" style="font-size:12.5px"><?= e($a['email']) ?></div><?php endif; ?>
                <?php if (empty($a['phone']) && empty($a['email'])): ?><span class="muted">—</span><?php endif; ?>
              </td>
              <td class="num"><?= $a['current_pct'] !== null ? e(number_format((float) $a['current_pct'], 2)) . '%' : '<span class="muted">—</span>' ?></td>
              <td class="num"><?= number_format((int) $a['partner_count']) ?></td>
              <td class="num">
                <strong><?= e(format_money($balance)) ?></strong><br>
                <span class="muted" style="font-size:11.5px">Earned <?= e(format_money($earned)) ?> &middot; Paid <?= e(format_money($paid)) ?></span>
              </td>
              <td>
                <span class="badge badge--<?= $a['status'] === 'ACTIVE' ? 'active' : 'inactive' ?>"><?= e($a['status']) ?></span>
              </td>
              <td><?= e(date('d M Y', strtotime($a['created_at']))) ?></td>
              <td class="text-right">
                <div class="tbl__actions" style="justify-content:flex-end">
                  <a class="btn btn-outline btn-sm" href="<?= e(root_url('/superadmin/aggregator-view.php')) ?>?id=<?= (int) $a['id'] ?>">View</a>
                  <a class="btn btn-ghost btn-sm" href="<?= e(root_url('/superadmin/aggregator-edit.php')) ?>?id=<?= (int) $a['id'] ?>">Edit</a>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <ul class="pagination">
        <?php $qs = http_build_query(['status' => $fStatus, 'q' => $fSearch]); ?>
        <?php if ($page > 1): ?><li><a href="?<?= e($qs) ?>&page=<?= $page - 1 ?>">Previous</a></li><?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <li><?= $i === $page ? '<span class="active">' . $i . '</span>' : '<a href="?' . e($qs) . '&page=' . $i . '">' . $i . '</a>' ?></li>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><li><a href="?<?= e($qs) ?>&page=<?= $page + 1 ?>">Next</a></li><?php endif; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>