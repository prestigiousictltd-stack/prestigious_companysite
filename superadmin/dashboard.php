<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$page_title = 'Dashboard';
$active_nav = 'dashboard';

$q = fn(string $sql) => db()->query($sql)->fetch();

$partners = $q("SELECT
  COUNT(*) AS total,
  SUM(status='PENDING') AS pending,
  SUM(status='ACTIVE') AS active,
  SUM(status='INACTIVE') AS inactive,
  SUM(status='SUSPENDED') AS suspended,
  SUM(status='REJECTED') AS rejected
  FROM partners");

$subAccounts = $q("SELECT
  COUNT(*) AS total,
  SUM(status='PENDING')  AS pending,
  SUM(status='ACTIVE')   AS active,
  SUM(status='INACTIVE') AS inactive,
  SUM(status='REJECTED') AS rejected
  FROM partner_sub_accounts");

$subAccountsActivePartners = (int) db()->query("
  SELECT COUNT(DISTINCT partner_id) FROM partner_sub_accounts WHERE status = 'ACTIVE'
")->fetchColumn();

$nin = $q("SELECT
  COALESCE(SUM(total_enrolments),0) AS enrol,
  COALESCE(SUM(total_nin_generated),0) AS nin,
  COALESCE(SUM(ernew_nin),0) AS ernew,
  COALESCE(SUM(crnew_nin),0) AS crnew,
  COALESCE(SUM(female_count),0) AS female,
  COALESCE(SUM(male_count),0) AS male
  FROM partner_enrolment_records");

$fin = $q("SELECT
  COALESCE((SELECT SUM(gross_revenue) FROM partner_enrolment_records),0) AS revenue,
  COALESCE((SELECT SUM(amount) FROM partner_financial_transactions WHERE type='COMMISSION_EARNED' AND sub_account_id IS NULL),0) AS own_commission,
  COALESCE((SELECT SUM(amount) FROM partner_financial_transactions WHERE type='COMMISSION_EARNED' AND sub_account_id IS NOT NULL),0) AS sub_commission,
  COALESCE((SELECT SUM(amount) FROM partner_financial_transactions WHERE type='COMMISSION_EARNED'),0) AS commission,
  COALESCE((SELECT SUM(amount) FROM partner_financial_transactions WHERE type='WITHDRAWAL_PAID'),0) AS withdrawn_neg,
  COALESCE((SELECT SUM(amount) FROM partner_financial_transactions),0) AS available,
  COALESCE((SELECT SUM(amount) FROM aggregator_financial_transactions WHERE type='COMMISSION_EARNED'),0) AS aggregator_commission,
  COALESCE((SELECT -SUM(amount) FROM aggregator_financial_transactions WHERE type='REVERSAL' AND amount < 0),0) AS aggregator_reversals,
  COALESCE((SELECT SUM(amount) FROM aggregator_financial_transactions),0) AS aggregator_balance,
  COALESCE((SELECT SUM(-amount) FROM aggregator_financial_transactions WHERE type='PAYOUT'),0) AS aggregator_paid
");
$totalWithdrawn = abs((float) $fin['withdrawn_neg']);

$withPending = (int) db()->query("SELECT COUNT(*) FROM withdrawals WHERE status='PENDING'")->fetchColumn();
$outstanding = round((float) $fin['commission'] - $totalWithdrawn, 2);

// ---- Aggregator KPIs and breakdown ----
$aggregatorCount = (int) db()->query("SELECT COUNT(*) FROM aggregators WHERE status = 'ACTIVE'")->fetchColumn();

$aggregatorRows = db()->query("
    SELECT a.id, a.full_name, a.status,
           COALESCE(SUM(t.amount), 0) AS earned,
           COALESCE((
             SELECT SUM(-p.amount) FROM aggregator_financial_transactions p
             WHERE p.aggregator_id = a.id AND p.type = 'PAYOUT'
           ), 0) AS paid
    FROM aggregators a
    LEFT JOIN aggregator_financial_transactions t
           ON t.aggregator_id = a.id AND t.type = 'COMMISSION_EARNED'
    WHERE a.status = 'ACTIVE'
    GROUP BY a.id, a.full_name, a.status
    ORDER BY earned DESC, a.full_name ASC
    LIMIT 10
")->fetchAll();

$rep = $q("SELECT
  COUNT(*) AS total,
  SUM(status='SUBMITTED') AS submitted,
  SUM(status='APPROVED') AS approved,
  SUM(status='RETURNED') AS returned
  FROM daily_reports");

// Monthly NIN chart data (last 12 months)
$stmt = db()->query("
  SELECT period_year, period_month, SUM(total_nin_generated) AS nin
  FROM partner_enrolment_records
  GROUP BY period_year, period_month
  ORDER BY period_year DESC, period_month DESC
  LIMIT 12
");
$monthly = array_reverse($stmt->fetchAll());

// Pending queues
$pendingPartners = db()->query("SELECT id, partner_code, full_name, email, created_at FROM partners WHERE status='PENDING' ORDER BY created_at DESC LIMIT 5")->fetchAll();
$pendingWithdrawals = db()->query("SELECT w.id, w.withdrawal_reference, w.amount, w.created_at, p.full_name, p.partner_code FROM withdrawals w JOIN partners p ON p.id=w.partner_id WHERE w.status='PENDING' ORDER BY w.created_at ASC LIMIT 5")->fetchAll();

require PICT_ROOT . '/includes/header-superadmin.php';

$bars = [];
foreach ($monthly as $m) {
    $bars[] = ['label' => format_period((int) $m['period_year'], (int) $m['period_month']), 'value' => (float) $m['nin']];
}

$renderBarChart = function (array $pairs, int $w = 640, int $h = 240) {
    $padL = 50; $padR = 20; $padT = 20; $padB = 46;
    $chartW = $w - $padL - $padR; $chartH = $h - $padT - $padB;
    $max = 1.0; foreach ($pairs as $p) { $max = max($max, (float) $p['value']); }
    $n = max(1, count($pairs));
    $barW = (int) max(10, (($chartW - ($n - 1) * 6) / $n));
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="xMidYMid meet" font-family="inherit">';
    if (empty($pairs)) { $svg .= '<text x="' . ($w/2) . '" y="' . ($h/2) . '" text-anchor="middle" fill="#9ca3af" font-size="13">No data yet</text>'; }
    else {
        foreach ($pairs as $i => $p) {
            $x = $padL + $i * ($barW + 6);
            $bh = ((float) $p['value'] / $max) * $chartH;
            $y = $padT + ($chartH - $bh);
            $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $barW . '" height="' . max(1, $bh) . '" rx="3" fill="#0b5ed7"/>';
            if ((float) $p['value'] > 0) {
                $svg .= '<text x="' . ($x + $barW/2) . '" y="' . ($y - 5) . '" text-anchor="middle" font-size="10" font-weight="600" fill="#0f172a">' . number_format((float) $p['value'], 0) . '</text>';
            }
            $label = explode(' ', $p['label'])[0];
            $svg .= '<text x="' . ($x + $barW/2) . '" y="' . ($h - $padB + 16) . '" text-anchor="middle" font-size="10" fill="#6b7280">' . e($label) . '</text>';
        }
    }
    return $svg . '</svg>';
};
?>

<div class="page-head">
  <div><h1>Super Admin Dashboard</h1><p>System-wide snapshot across partners, NIN, finances and reports.</p></div>
  <div class="page-head__actions">
    <a class="btn btn-grape" href="import-upload.php">+ Upload CSV</a>
    <a class="btn btn-outline" href="analytics.php">Analytics</a>
  </div>
</div>

<h2 style="font-size:15px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);margin-bottom:10px">Partners</h2>
<div class="stat-grid">
  <div class="stat"><div class="stat__label">Total</div><div class="stat__value"><?= number_format((int) $partners['total']) ?></div></div>
  <div class="stat stat--pending"><div class="stat__label">Pending</div><div class="stat__value"><?= number_format((int) $partners['pending']) ?></div></div>
  <div class="stat stat--ok"><div class="stat__label">Active</div><div class="stat__value"><?= number_format((int) $partners['active']) ?></div></div>
  <div class="stat"><div class="stat__label">Inactive</div><div class="stat__value"><?= number_format((int) $partners['inactive']) ?></div></div>
  <div class="stat stat--err"><div class="stat__label">Suspended</div><div class="stat__value"><?= number_format((int) $partners['suspended']) ?></div></div>
  <div class="stat stat--err"><div class="stat__label">Rejected</div><div class="stat__value"><?= number_format((int) $partners['rejected']) ?></div></div>
</div>

<h2 style="font-size:15px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);margin:22px 0 10px">Sub-Accounts</h2>
<div class="stat-grid">
  <div class="stat"><div class="stat__label">Total</div><div class="stat__value"><?= number_format((int) $subAccounts['total']) ?></div></div>
  <div class="stat stat--pending"><div class="stat__label">Pending</div><div class="stat__value"><?= number_format((int) $subAccounts['pending']) ?></div></div>
  <div class="stat stat--ok"><div class="stat__label">Active</div><div class="stat__value"><?= number_format((int) $subAccounts['active']) ?></div></div>
  <div class="stat"><div class="stat__label">Inactive</div><div class="stat__value"><?= number_format((int) $subAccounts['inactive']) ?></div></div>
  <div class="stat stat--err"><div class="stat__label">Rejected</div><div class="stat__value"><?= number_format((int) $subAccounts['rejected']) ?></div></div>
  <div class="stat">
    <div class="stat__label">Partners with Sub-Accounts</div>
    <div class="stat__value"><?= number_format($subAccountsActivePartners) ?></div>
    <div class="stat__sub">Of <?= number_format((int) $partners['active']) ?> active partners</div>
  </div>
</div>

<h2 style="font-size:15px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);margin:22px 0 10px">NIN</h2>
<div class="stat-grid">
  <div class="stat"><div class="stat__label">Total Enrolments</div><div class="stat__value"><?= number_format((int) $nin['enrol']) ?></div></div>
  <div class="stat stat--purple"><div class="stat__label">Total NIN Generated</div><div class="stat__value"><?= number_format((int) $nin['nin']) ?></div></div>
  <div class="stat"><div class="stat__label">ERNEW</div><div class="stat__value"><?= number_format((int) $nin['ernew']) ?></div></div>
  <div class="stat"><div class="stat__label">CRNEW</div><div class="stat__value"><?= number_format((int) $nin['crnew']) ?></div></div>
  <div class="stat"><div class="stat__label">Female</div><div class="stat__value"><?= number_format((int) $nin['female']) ?></div></div>
  <div class="stat"><div class="stat__label">Male</div><div class="stat__value"><?= number_format((int) $nin['male']) ?></div></div>
</div>


<?php
// Net Revenue = gross revenue − partner commission − aggregator commission
//               + aggregator reversals (clawbacks returned to the organisation)

$netRevenue = round(
    (float) $fin['revenue']
    - (float) $fin['commission']
    - (float) $fin['aggregator_commission']
    + (float) $fin['aggregator_reversals'],
    2
);
$netMargin = (float) $fin['revenue'] > 0
    ? round($netRevenue / (float) $fin['revenue'] * 100, 1)
    : 0.0;
?>
<h2 style="font-size:15px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted);margin:22px 0 10px">Financials</h2>
<div class="stat-grid">
  <div class="stat stat--ok">
    <div class="stat__label">Total Revenue</div>
    <div class="stat__value"><?= e(format_money((float) $fin['revenue'])) ?></div>
    <div class="stat__sub">Gross before deductions</div>
  </div>
  <div class="stat stat--purple">
    <div class="stat__label">Partner-Own Commission</div>
    <div class="stat__value"><?= e(format_money((float) $fin['own_commission'])) ?></div>
    <div class="stat__sub">Credited to partners' own NINs</div>
  </div>
  <div class="stat" style="--brand:#7c3aed">
    <div class="stat__label" style="color:#7c3aed">Sub-Account Commission</div>
    <div class="stat__value" style="color:#7c3aed"><?= e(format_money((float) $fin['sub_commission'])) ?></div>
    <div class="stat__sub">Credited to sub-account buckets</div>
  </div>
  <div class="stat">
    <div class="stat__label">Aggregator Commission</div>
    <div class="stat__value"><?= e(format_money((float) $fin['aggregator_commission'])) ?></div>
    <div class="stat__sub">Accrued to aggregators</div>
  </div>
   <div class="stat" style="--brand:#dc2626">
    <div class="stat__label" style="color:#dc2626">Less: Clawbacks</div>
    <div class="stat__value" style="color:#dc2626">−<?= e(format_money((float) $fin['aggregator_reversals'])) ?></div>
    <div class="stat__sub">Returned from deactivations</div>
  </div>
  <div class="stat" style="--brand:#0f766e">
    <div class="stat__label" style="color:#0f766e">Net Revenue</div>
    <div class="stat__value" style="color:#0f766e"><?= e(format_money($netRevenue)) ?></div>
    <div class="stat__sub">Retained after all deductions</div>
  </div>
  <div class="stat">
    <div class="stat__label">Net Margin</div>
    <div class="stat__value"><?= e(number_format($netMargin, 1)) ?>%</div>
    <div class="stat__sub">Of gross revenue</div>
  </div>
</div>

<div class="stat-grid">
  <div class="stat"><div class="stat__label">Total Withdrawn</div><div class="stat__value"><?= e(format_money($totalWithdrawn)) ?></div></div>
  <div class="stat stat--pending"><div class="stat__label">Pending Withdrawals</div><div class="stat__value"><?= number_format($withPending) ?></div></div>
  <div class="stat stat--err"><div class="stat__label">Outstanding Partner Balance</div><div class="stat__value"><?= e(format_money($outstanding)) ?></div></div>
  <div class="stat stat--purple"><div class="stat__label">Outstanding Aggregator Balance</div><div class="stat__value"><?= e(format_money((float) $fin['aggregator_balance'])) ?></div></div>
</div>

<!-- Aggregator commission breakdown -->
<div class="card mb-3">
  <div class="flex-between mb-2">
    <h2 style="margin:0">Aggregator Commission Summary</h2>
    <div>
      <span class="muted" style="font-size:13px;margin-right:12px"><?= number_format($aggregatorCount) ?> active aggregator<?= $aggregatorCount === 1 ? '' : 's' ?></span>
      <a class="btn btn-outline btn-sm" href="<?= e(root_url('/superadmin/aggregators.php')) ?>">Manage</a>
    </div>
  </div>

  <?php if (empty($aggregatorRows)): ?>
    <div class="empty">
      <p>No aggregator commission recorded yet.</p>
      <?php if ($aggregatorCount === 0): ?>
        <a class="btn btn-grape btn-sm" href="<?= e(root_url('/superadmin/aggregator-add.php')) ?>">Add the first aggregator</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Aggregator</th>
            <th>Status</th>
            <th class="num">Commission Earned</th>
            <th class="num">Paid Out</th>
            <th class="num">Outstanding</th>
            <th class="num">Share of Aggregator Cost</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php
          $aggTotalEarned = (float) $fin['aggregator_commission'];
          foreach ($aggregatorRows as $r):
              $earned = (float) $r['earned'];
              $paid   = (float) $r['paid'];
              $owed   = round($earned - $paid, 2);
              $share  = $aggTotalEarned > 0 ? round($earned / $aggTotalEarned * 100, 1) : 0.0;
          ?>
            <tr>
              <td>
                <a href="<?= e(root_url('/superadmin/aggregator-view.php')) ?>?id=<?= (int) $r['id'] ?>">
                  <strong><?= e($r['full_name']) ?></strong>
                </a>
              </td>
              <td>
                <span class="badge badge--<?= $r['status'] === 'ACTIVE' ? 'active' : 'inactive' ?>">
                  <?= e($r['status']) ?>
                </span>
              </td>
              <td class="num"><?= e(format_money($earned)) ?></td>
              <td class="num"><?= e(format_money($paid)) ?></td>
              <td class="num"><strong><?= e(format_money($owed)) ?></strong></td>
              <td class="num"><?= e(number_format($share, 1)) ?>%</td>
              <td class="text-right">
                <a class="btn btn-outline btn-sm" href="<?= e(root_url('/superadmin/aggregator-view.php')) ?>?id=<?= (int) $r['id'] ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2"><strong>All Aggregators</strong></td>
            <td class="num"><strong><?= e(format_money((float) $fin['aggregator_commission'])) ?></strong></td>
            <td class="num"><strong><?= e(format_money((float) $fin['aggregator_paid'])) ?></strong></td>
            <td class="num"><strong><?= e(format_money((float) $fin['aggregator_balance'])) ?></strong></td>
            <td class="num"><strong>100.0%</strong></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="chart-grid">
  <div class="chart-host">
    <div class="chart-host__title">Monthly NIN Generation (last 12 months)</div>
    <?= $renderBarChart($bars) ?>
  </div>
  <div class="chart-host">
    <div class="chart-host__title">Daily Reports</div>
    <div class="stat-grid" style="grid-template-columns:1fr 1fr;margin:0">
      <div class="stat"><div class="stat__label">Submitted</div><div class="stat__value"><?= number_format((int) $rep['submitted']) ?></div></div>
      <div class="stat stat--ok"><div class="stat__label">Approved</div><div class="stat__value"><?= number_format((int) $rep['approved']) ?></div></div>
      <div class="stat stat--err"><div class="stat__label">Returned</div><div class="stat__value"><?= number_format((int) $rep['returned']) ?></div></div>
      <div class="stat"><div class="stat__label">Total</div><div class="stat__value"><?= number_format((int) $rep['total']) ?></div></div>
    </div>
    <a class="btn btn-outline btn-sm mt-2" href="daily-reports.php?status=SUBMITTED">Review submitted reports</a>
  </div>
</div>

<div class="card mt-3">
  <div class="flex-between mb-2"><h2 style="margin:0">Pending Partner Applications</h2>
    <a class="btn btn-outline btn-sm" href="partners.php?status=PENDING">See all</a></div>
  <?php if (empty($pendingPartners)): ?><div class="empty"><p>No pending applications.</p></div>
  <?php else: ?>
    <table class="tbl tbl--compact">
      <thead><tr><th>Code</th><th>Name</th><th>Email</th><th>Applied</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pendingPartners as $p): ?>
          <tr>
            <td><?= e($p['partner_code']) ?></td>
            <td><strong><?= e($p['full_name']) ?></strong></td>
            <td><?= e($p['email']) ?></td>
            <td><?= e(date('d M Y', strtotime($p['created_at']))) ?></td>
            <td class="text-right"><a class="btn btn-outline btn-sm" href="partner-view.php?id=<?= (int) $p['id'] ?>">Review</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card mt-3">
  <div class="flex-between mb-2"><h2 style="margin:0">Pending Withdrawals</h2>
    <a class="btn btn-outline btn-sm" href="withdrawals.php?status=PENDING">See all</a></div>
  <?php if (empty($pendingWithdrawals)): ?><div class="empty"><p>No pending withdrawals.</p></div>
  <?php else: ?>
    <table class="tbl tbl--compact">
      <thead><tr><th>Reference</th><th>Partner</th><th class="num">Amount</th><th>Requested</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($pendingWithdrawals as $w): ?>
          <tr>
            <td><span class="masked"><?= e($w['withdrawal_reference']) ?></span></td>
            <td><?= e($w['full_name']) ?> <span class="muted">(<?= e($w['partner_code']) ?>)</span></td>
            <td class="num"><?= e(format_money((float) $w['amount'])) ?></td>
            <td><?= e(date('d M Y H:i', strtotime($w['created_at']))) ?></td>
            <td class="text-right"><a class="btn btn-outline btn-sm" href="withdrawals.php?view=<?= (int) $w['id'] ?>">Process</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>