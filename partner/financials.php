<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';
require_once PICT_ROOT . '/includes/financial.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$partner = partner_require_login();

$page_title = 'Funding / Commission';
$active_nav = 'financials';
$pid = (int) $partner['id'];

// Which bucket is selected? 'own', 'all', or a numeric sub-account id
$bucketRaw = (string) ($_GET['bucket'] ?? 'all');
$bucketType = 'all';
$bucketSubId = null;

if ($bucketRaw === 'own') {
    $bucketType = 'own';
} elseif (ctype_digit($bucketRaw) && (int) $bucketRaw > 0) {
    // Verify ownership of the sub-account
    $stmt = db()->prepare("SELECT id, full_name, status FROM partner_sub_accounts WHERE id = :id AND partner_id = :pid LIMIT 1");
    $stmt->execute([':id' => (int) $bucketRaw, ':pid' => $pid]);
    $sub = $stmt->fetch();
    if ($sub) {
        $bucketType = 'sub';
        $bucketSubId = (int) $sub['id'];
    }
}

// Load the partner's sub-accounts for the selector
$stmt = db()->prepare("
    SELECT id, full_name, status
    FROM partner_sub_accounts
    WHERE partner_id = :pid AND status IN ('ACTIVE','INACTIVE')
    ORDER BY full_name ASC
");
$stmt->execute([':pid' => $pid]);
$subAccounts = $stmt->fetchAll();

// Period filters (unchanged from before)
$period   = (string) ($_GET['period'] ?? 'all');
$fMonth   = (int) ($_GET['month'] ?? 0);
$fYear    = (int) ($_GET['year'] ?? 0);
$fFrom    = trim((string) ($_GET['from'] ?? ''));
$fTo      = trim((string) ($_GET['to'] ?? ''));

// Build WHERE clauses
$txWhere = ['t.partner_id = :pid'];
$txParams = [':pid' => $pid];

if ($bucketType === 'own') {
    $txWhere[] = 't.sub_account_id IS NULL';
} elseif ($bucketType === 'sub') {
    $txWhere[] = 't.sub_account_id = :bucket_sid';
    $txParams[':bucket_sid'] = $bucketSubId;
}
// 'all' → no bucket filter

if ($period === 'month' && $fMonth >= 1 && $fMonth <= 12 && $fYear >= 2000) {
    $txWhere[] = 't.period_year = :py';
    $txWhere[] = 't.period_month = :pm';
    $txParams[':py'] = $fYear; $txParams[':pm'] = $fMonth;
} elseif ($period === 'year' && $fYear >= 2000) {
    $txWhere[] = 't.period_year = :py';
    $txParams[':py'] = $fYear;
} elseif ($period === 'range') {
    if (preg_match('/^(\d{4})-(\d{2})$/', $fFrom, $m)) {
        $txWhere[] = '(t.period_year > :fy OR (t.period_year = :fy AND t.period_month >= :fm))';
        $txParams[':fy'] = (int) $m[1]; $txParams[':fm'] = (int) $m[2];
    }
    if (preg_match('/^(\d{4})-(\d{2})$/', $fTo, $m)) {
        $txWhere[] = '(t.period_year < :ty OR (t.period_year = :ty AND t.period_month <= :tm))';
        $txParams[':ty'] = (int) $m[1]; $txParams[':tm'] = (int) $m[2];
    }
}
$txWhereSql = implode(' AND ', $txWhere);

// Summary numbers, scoped to the selected bucket
if ($bucketType === 'own') {
    $stmt = db()->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN type = 'COMMISSION_EARNED' THEN amount ELSE 0 END), 0) AS earned,
          COALESCE(SUM(CASE WHEN type = 'WITHDRAWAL_PAID'  THEN -amount ELSE 0 END), 0) AS withdrawn
        FROM partner_financial_transactions
        WHERE partner_id = :pid AND sub_account_id IS NULL
    ");
    $stmt->execute([':pid' => $pid]);
} elseif ($bucketType === 'sub') {
    $stmt = db()->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN type = 'COMMISSION_EARNED' THEN amount ELSE 0 END), 0) AS earned,
          COALESCE(SUM(CASE WHEN type = 'WITHDRAWAL_PAID'  THEN -amount ELSE 0 END), 0) AS withdrawn
        FROM partner_financial_transactions
        WHERE partner_id = :pid AND sub_account_id = :sid
    ");
    $stmt->execute([':pid' => $pid, ':sid' => $bucketSubId]);
} else {
    $stmt = db()->prepare("
        SELECT
          COALESCE(SUM(CASE WHEN type = 'COMMISSION_EARNED' THEN amount ELSE 0 END), 0) AS earned,
          COALESCE(SUM(CASE WHEN type = 'WITHDRAWAL_PAID'  THEN -amount ELSE 0 END), 0) AS withdrawn
        FROM partner_financial_transactions
        WHERE partner_id = :pid
    ");
    $stmt->execute([':pid' => $pid]);
}
$totals = $stmt->fetch();

// Available balance for the current bucket
if ($bucketType === 'own') {
    $available = partner_bucket_balance($pid, null);
} elseif ($bucketType === 'sub') {
    $available = partner_bucket_balance($pid, $bucketSubId);
} else {
    $available = round((float) $totals['earned'] - (float) $totals['withdrawn'], 2);
    // safer: use aggregate
    $agg = partner_aggregate_bucket_stats($pid);
    $available = $agg['total_balance'];
}

// Reserved for the current bucket
if ($bucketType === 'own') {
    $stmt = db()->prepare("
        SELECT COALESCE(SUM(amount), 0)
        FROM withdrawals
        WHERE partner_id = :pid AND sub_account_id IS NULL AND status IN ('PENDING','APPROVED','PROCESSING')
    ");
    $stmt->execute([':pid' => $pid]);
    $reserved = round((float) $stmt->fetchColumn(), 2);
} elseif ($bucketType === 'sub') {
    $reserved = sub_account_total_reserved($bucketSubId);
} else {
    $reserved = (float) (partner_aggregate_bucket_stats($pid)['total_reserved'] ?? 0);
}

// Ledger list
$stmt = db()->prepare("
    SELECT t.*, s.full_name AS sub_name,
           e.total_nin_generated AS nin_qty,
           e.revenue_per_nin, e.commission_percentage
    FROM partner_financial_transactions t
    LEFT JOIN partner_sub_accounts s ON s.id = t.sub_account_id
    LEFT JOIN partner_enrolment_records e ON e.id = t.related_enrolment_id
    WHERE $txWhereSql
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT 200
");
$stmt->execute($txParams);
$txRows = $stmt->fetchAll();

require PICT_ROOT . '/includes/header-partner.php';
?>

<div class="page-head">
  <div>
    <h1>Funding / Commission</h1>
    <p>Select a bucket to see its ledger and balance.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-primary" href="<?= e(root_url('/partner/withdrawal-new.php')) ?>">Request Withdrawal</a>
    <a class="btn btn-outline" href="<?= e(root_url('/partner/withdrawals.php')) ?>">All Withdrawals</a>
  </div>
</div>

<!-- Bucket selector -->
<div class="filter-bar">
  <form method="get">
    <div class="form-group">
      <label class="form-label">Bucket</label>
      <select class="form-control" name="bucket" onchange="this.form.submit()">
        <option value="all"<?= $bucketType === 'all' ? ' selected' : '' ?>>All Buckets (Combined)</option>
        <option value="own"<?= $bucketType === 'own' ? ' selected' : '' ?>>My Own NIN — <?= e($partner['full_name']) ?></option>
        <?php foreach ($subAccounts as $s): ?>
          <option value="<?= (int) $s['id'] ?>"<?= $bucketType === 'sub' && $bucketSubId === (int) $s['id'] ? ' selected' : '' ?>>
            Sub-Account — <?= e($s['full_name']) ?><?= $s['status'] !== 'ACTIVE' ? ' (' . strtolower($s['status']) . ')' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($bucketType !== 'all'): ?>
      <input type="hidden" name="bucket" value="<?= $bucketType === 'own' ? 'own' : (int) $bucketSubId ?>">
    <?php endif; ?>
    <!-- Period filters -->
    <div class="form-group">
      <label class="form-label">Period</label>
      <select class="form-control" name="period" onchange="this.form.submit()">
        <option value="all"<?= $period === 'all' ? ' selected' : '' ?>>All Time</option>
        <option value="month"<?= $period === 'month' ? ' selected' : '' ?>>Month</option>
        <option value="year"<?= $period === 'year' ? ' selected' : '' ?>>Year</option>
        <option value="range"<?= $period === 'range' ? ' selected' : '' ?>>From / To Period</option>
      </select>
    </div>
    <?php if ($period === 'month'): ?>
      <div class="form-group">
        <label class="form-label">Month</label>
        <select class="form-control" name="month">
          <?php for ($i = 1; $i <= 12; $i++): ?>
            <option value="<?= $i ?>"<?= $fMonth === $i ? ' selected' : '' ?>><?= e(date('F', mktime(0,0,0,$i,1,2000))) ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Year</label>
        <input class="form-control" type="number" name="year" value="<?= $fYear ?: date('Y') ?>" min="2000" max="2100">
      </div>
    <?php elseif ($period === 'year'): ?>
      <div class="form-group">
        <label class="form-label">Year</label>
        <input class="form-control" type="number" name="year" value="<?= $fYear ?: date('Y') ?>" min="2000" max="2100">
      </div>
    <?php elseif ($period === 'range'): ?>
      <div class="form-group">
        <label class="form-label">From</label>
        <input class="form-control" type="month" name="from" value="<?= e($fFrom) ?>">
      </div>
      <div class="form-group">
        <label class="form-label">To</label>
        <input class="form-control" type="month" name="to" value="<?= e($fTo) ?>">
      </div>
    <?php endif; ?>
    <div class="form-group">
      <button class="btn btn-primary" type="submit">Apply</button>
      <a class="btn btn-outline" href="<?= e(root_url('/partner/financials.php')) ?>">Reset</a>
    </div>
  </form>
</div>

<div class="stat-grid">
  <div class="stat stat--money">
    <div class="stat__label">Total Amount Generated</div>
    <div class="stat__value"><?= e(format_money((float) $totals['earned'])) ?></div>
    <div class="stat__sub"><?= $bucketType === 'all' ? 'All buckets combined' : 'In selected bucket' ?></div>
  </div>
  <div class="stat stat--ok">
    <div class="stat__label">Total Withdrawn</div>
    <div class="stat__value"><?= e(format_money((float) $totals['withdrawn'])) ?></div>
  </div>
  <div class="stat stat--pending">
    <div class="stat__label">Reserved</div>
    <div class="stat__value"><?= e(format_money($reserved)) ?></div>
    <div class="stat__sub">Pending / approved requests</div>
  </div>
  <div class="stat stat--avail">
    <div class="stat__label">Balance Remaining</div>
    <div class="stat__value"><?= e(format_money($available)) ?></div>
    <div class="stat__sub"><?= $bucketType === 'all' ? 'Across all buckets' : 'Available in selected bucket' ?></div>
  </div>
</div>

<div class="card">
  <div class="flex-between mb-2">
    <h2 style="margin:0">Transaction Ledger</h2>
    <span class="muted" style="font-size:13px">
      <?= $bucketType === 'all' ? 'All buckets' : ($bucketType === 'own' ? 'Own NIN bucket' : 'Sub-account bucket') ?>
    </span>
  </div>

  <?php if (empty($txRows)): ?>
    <div class="empty">
      <h3>No transactions</h3>
      <p>Commission entries will appear after each import.</p>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Date</th>
            <?php if ($bucketType === 'all'): ?><th>Bucket</th><?php endif; ?>
            <th>Period</th>
            <th>Transaction ID</th>
            <th>Description</th>
            <th class="num">NIN Qty</th>
            <th class="num">Rev/NIN</th>
            <th class="num">Comm %</th>
            <th class="num">Amount</th>
            <th>Type</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($txRows as $t): ?>
            <tr>
              <td><?= e(date('d M Y', strtotime($t['created_at']))) ?></td>
              <?php if ($bucketType === 'all'): ?>
                <td>
                  <?php if ($t['sub_account_id'] !== null): ?>
                    <span class="badge" style="background:#e0e7ff;color:#4338ca">Sub</span>
                    <?= e((string) $t['sub_name']) ?>
                  <?php else: ?>
                    <span class="badge" style="background:#dbeafe;color:#1e40af">Own</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
              <td>
                <?php if ($t['period_year'] && $t['period_month']): ?>
                  <?= e(format_period((int) $t['period_year'], (int) $t['period_month'])) ?>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
              </td>
              <td><span class="masked"><?= e($t['transaction_id']) ?></span></td>
              <td><?= e((string) $t['description']) ?></td>
              <td class="num"><?= $t['nin_qty'] !== null ? number_format((int) $t['nin_qty']) : '<span class="muted">—</span>' ?></td>
              <td class="num"><?= $t['revenue_per_nin'] !== null ? e(format_money((float) $t['revenue_per_nin'])) : '<span class="muted">—</span>' ?></td>
              <td class="num"><?= $t['commission_percentage'] !== null ? e(number_format((float) $t['commission_percentage'], 2)) . '%' : '<span class="muted">—</span>' ?></td>
              <td class="num"><?= e(format_money((float) $t['amount'])) ?></td>
              <td>
                <?php
                  $cls = 'badge--adjustment';
                  if (strpos($t['type'], 'COMMISSION') === 0) $cls = 'badge--earned';
                  elseif (strpos($t['type'], 'RESERVED') !== false) $cls = 'badge--reserved';
                  elseif (strpos($t['type'], 'PAID') !== false) $cls = 'badge--paid';
                  elseif (strpos($t['type'], 'REVERS') !== false) $cls = 'badge--reversed';
                ?>
                <span class="badge <?= $cls ?>"><?= e(str_replace('_', ' ', $t['type'])) ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-partner.php'; ?>