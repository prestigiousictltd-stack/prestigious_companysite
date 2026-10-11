<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';
require_once PICT_ROOT . '/includes/financial.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$partner = partner_require_login();

$page_title = 'Dashboard';
$active_nav = 'dashboard';
$pid = (int) $partner['id'];

// Combined stats across all buckets
$aggregate = partner_aggregate_bucket_stats($pid);

// Enrolment totals (all buckets)
$stmt = db()->prepare("
    SELECT
      COALESCE(SUM(total_nin_generated), 0) AS total_nin,
      COALESCE(SUM(total_enrolments), 0)    AS total_enrolments,
      COALESCE(SUM(ernew_nin), 0)           AS ernew,
      COALESCE(SUM(crnew_nin), 0)           AS crnew,
      COALESCE(SUM(female_count), 0)        AS female,
      COALESCE(SUM(male_count), 0)          AS male
    FROM partner_enrolment_records
    WHERE partner_id = :pid
");
$stmt->execute([':pid' => $pid]);
$enrol = $stmt->fetch();

// Own NIN bucket
$ownBalance    = partner_bucket_balance($pid, null);
$ownCommission = 0.0;
$ownWithdrawn  = 0.0;
$ownReserved   = 0.0;

$stmt = db()->prepare("
    SELECT
      COALESCE(SUM(CASE WHEN type = 'COMMISSION_EARNED' THEN amount ELSE 0 END), 0) AS earned,
      COALESCE(SUM(CASE WHEN type = 'WITHDRAWAL_PAID'  THEN -amount ELSE 0 END), 0) AS withdrawn
    FROM partner_financial_transactions
    WHERE partner_id = :pid AND sub_account_id IS NULL
");
$stmt->execute([':pid' => $pid]);
$ownStats = $stmt->fetch();
$ownCommission = (float) ($ownStats['earned'] ?? 0);
$ownWithdrawn  = (float) ($ownStats['withdrawn'] ?? 0);

$stmt = db()->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM withdrawals
    WHERE partner_id = :pid AND sub_account_id IS NULL AND status IN ('PENDING','APPROVED','PROCESSING')
");
$stmt->execute([':pid' => $pid]);
$ownReserved = round((float) $stmt->fetchColumn(), 2);

// Sub-account buckets
$stmt = db()->prepare("
    SELECT * FROM partner_sub_accounts
    WHERE partner_id = :pid AND status IN ('ACTIVE','PENDING')
    ORDER BY status ASC, full_name ASC
");
$stmt->execute([':pid' => $pid]);
$subAccounts = $stmt->fetchAll();

$subBuckets = [];
foreach ($subAccounts as $s) {
    $sid = (int) $s['id'];
    $subBuckets[] = [
        'id'         => $sid,
        'name'       => (string) $s['full_name'],
        'nin'        => (string) $s['nin_number'],
        'status'     => (string) $s['status'],
        'balance'    => sub_account_balance($sid),
        'earned'     => sub_account_total_commission($sid),
        'withdrawn'  => sub_account_total_withdrawn($sid),
        'reserved'   => sub_account_total_reserved($sid),
        'url'        => root_url('/partner/sub-account-view.php') . '?id=' . $sid,
    ];
}

// Recent ledger entries (all buckets)
$stmt = db()->prepare("
    SELECT t.*, s.full_name AS sub_name
    FROM partner_financial_transactions t
    LEFT JOIN partner_sub_accounts s ON s.id = t.sub_account_id
    WHERE t.partner_id = :pid
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT 8
");
$stmt->execute([':pid' => $pid]);
$recentTx = $stmt->fetchAll();

// Recent enrolments (all buckets)
$stmt = db()->prepare("
    SELECT r.*, s.full_name AS sub_name
    FROM partner_enrolment_records r
    LEFT JOIN partner_sub_accounts s ON s.id = r.sub_account_id
    WHERE r.partner_id = :pid
    ORDER BY r.period_year DESC, r.period_month DESC, r.id DESC
    LIMIT 8
");
$stmt->execute([':pid' => $pid]);
$recentEnrolment = $stmt->fetchAll();

require PICT_ROOT . '/includes/header-partner.php';
?>

<div class="page-head">
  <div>
    <h1>Welcome, <?= e(strtok($partner['full_name'], ' ')) ?></h1>
    <p>
      Partner code <strong><?= e($partner['partner_code']) ?></strong>
      &middot; <span class="badge badge--active"><?= e($partner['status']) ?></span>
      &middot; <?= number_format((int) $aggregate['sub_count']) ?> active sub-account<?= (int) $aggregate['sub_count'] === 1 ? '' : 's' ?>
    </p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-primary" href="<?= e(root_url('/partner/withdrawal-new.php')) ?>">Request Withdrawal</a>
    <a class="btn btn-outline" href="<?= e(root_url('/partner/sub-accounts.php')) ?>">Manage Sub-Accounts</a>
  </div>
</div>

<!-- Combined totals across all buckets -->
<div class="stat-grid">
  <div class="stat">
    <div class="stat__label">Total NIN Generated</div>
    <div class="stat__value"><?= number_format((int) $enrol['total_nin']) ?></div>
    <div class="stat__sub">Across all your NINs</div>
  </div>
  <div class="stat">
    <div class="stat__label">Total Enrolments</div>
    <div class="stat__value"><?= number_format((int) $enrol['total_enrolments']) ?></div>
    <div class="stat__sub">ERNEW <?= number_format((int) $enrol['ernew']) ?> &middot; CRNEW <?= number_format((int) $enrol['crnew']) ?></div>
  </div>
  <div class="stat stat--money">
    <div class="stat__label">Total Commission</div>
    <div class="stat__value"><?= e(format_money((float) $aggregate['total_commission'])) ?></div>
    <div class="stat__sub">All-time earned across all buckets</div>
  </div>
  <div class="stat stat--ok">
    <div class="stat__label">Total Withdrawn</div>
    <div class="stat__value"><?= e(format_money((float) $aggregate['total_withdrawn'])) ?></div>
    <div class="stat__sub">Paid out across all buckets</div>
  </div>
  <div class="stat stat--avail">
    <div class="stat__label">Total Available Balance</div>
    <div class="stat__value"><?= e(format_money((float) $aggregate['total_balance'])) ?></div>
    <div class="stat__sub">
      <?= (float) $aggregate['total_reserved'] > 0
          ? 'Reserved: ' . e(format_money((float) $aggregate['total_reserved']))
          : 'No funds reserved' ?>
    </div>
  </div>
</div>

<!-- Buckets -->
<div class="card mb-3">
  <div class="flex-between mb-2">
    <h2 style="margin:0">Balance by Bucket</h2>
    <span class="muted" style="font-size:13px">Withdrawals are requested per bucket</span>
  </div>

  <div style="overflow-x:auto">
    <table class="tbl">
      <thead>
        <tr>
          <th>Bucket</th>
          <th>NIN</th>
          <th>Status</th>
          <th class="num">Earned</th>
          <th class="num">Withdrawn</th>
          <th class="num">Reserved</th>
          <th class="num">Available</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <!-- Own NIN bucket -->
        <tr>
          <td><strong>My own NIN</strong> <span class="muted" style="font-size:12px">(<?= e($partner['full_name']) ?>)</span></td>
          <td><span class="masked"><?= e(mask_nin($partner['nin_number'])) ?></span></td>
          <td><span class="badge badge--active">OWN</span></td>
          <td class="num"><?= e(format_money($ownCommission)) ?></td>
          <td class="num"><?= e(format_money($ownWithdrawn)) ?></td>
          <td class="num"><?= e(format_money($ownReserved)) ?></td>
          <td class="num"><strong><?= e(format_money($ownBalance)) ?></strong></td>
          <td class="text-right">
            <a class="btn btn-outline btn-sm" href="<?= e(root_url('/partner/financials.php')) ?>?bucket=own">View Ledger</a>
          </td>
        </tr>

        <!-- Sub-account buckets -->
        <?php foreach ($subBuckets as $sb): ?>
          <tr>
            <td><strong><?= e($sb['name']) ?></strong></td>
            <td><span class="masked"><?= e(mask_nin($sb['nin'])) ?></span></td>
            <td>
              <?php if ($sb['status'] === 'ACTIVE'): ?>
                <span class="badge badge--active">ACTIVE</span>
              <?php else: ?>
                <span class="badge badge--pending">PENDING</span>
              <?php endif; ?>
            </td>
            <td class="num"><?= e(format_money($sb['earned'])) ?></td>
            <td class="num"><?= e(format_money($sb['withdrawn'])) ?></td>
            <td class="num"><?= e(format_money($sb['reserved'])) ?></td>
            <td class="num">
              <?php if ($sb['status'] === 'ACTIVE'): ?>
                <strong><?= e(format_money($sb['balance'])) ?></strong>
              <?php else: ?>
                <span class="muted">— awaiting approval</span>
              <?php endif; ?>
            </td>
            <td class="text-right">
              <a class="btn btn-outline btn-sm" href="<?= e($sb['url']) ?>">View</a>
              <?php if ($sb['status'] === 'ACTIVE' && $sb['balance'] > 0): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(root_url('/partner/withdrawal-new.php')) ?>?bucket=<?= (int) $sb['id'] ?>">Withdraw</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (empty($subBuckets) && (int) $aggregate['pending_count'] === 0): ?>
    <p class="mt-2 muted" style="font-size:13.5px">
      You have no sub-accounts yet.
      <a href="<?= e(root_url('/partner/sub-account-add.php')) ?>">Add a sub-account</a>
      to register a NIN that earns under your account.
    </p>
  <?php endif; ?>
</div>

<!-- Recent Enrolments -->
<div class="card mb-3">
  <div class="flex-between mb-2">
    <h2 style="margin:0">Recent Enrolment Records</h2>
    <a class="btn btn-outline btn-sm" href="<?= e(root_url('/partner/enrolments.php')) ?>">See all</a>
  </div>
  <?php if (empty($recentEnrolment)): ?>
    <div class="empty">
      <h3>No records yet</h3>
      <p>Enrolment records will appear here after the first CSV import for one of your NINs.</p>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Period</th>
            <th>Bucket</th>
            <th>Station</th>
            <th class="num">NIN</th>
            <th class="num">Commission</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentEnrolment as $r): ?>
            <tr>
              <td><strong><?= e(format_period((int) $r['period_year'], (int) $r['period_month'])) ?></strong></td>
              <td>
                <?php if ($r['sub_account_id'] !== null): ?>
                  <span class="badge" style="background:#e0e7ff;color:#4338ca">Sub</span>
                  <?= e((string) $r['sub_name']) ?>
                <?php else: ?>
                  <span class="badge" style="background:#dbeafe;color:#1e40af">Own</span>
                  <?= e($partner['full_name']) ?>
                <?php endif; ?>
              </td>
              <td>
                <?= !empty($r['station_name_raw']) ? e($r['station_name_raw']) : '<span class="muted">—</span>' ?>
              </td>
              <td class="num"><?= number_format((int) $r['total_nin_generated']) ?></td>
              <td class="num"><strong><?= e(format_money((float) $r['commission_amount'])) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Recent Financial Activity -->
<div class="card">
  <div class="flex-between mb-2">
    <h2 style="margin:0">Recent Financial Activity</h2>
    <a class="btn btn-outline btn-sm" href="<?= e(root_url('/partner/financials.php')) ?>">See all</a>
  </div>
  <?php if (empty($recentTx)): ?>
    <div class="empty"><h3>No transactions yet</h3><p>Commission entries appear after each import.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Date</th>
            <th>Bucket</th>
            <th>Description</th>
            <th>Type</th>
            <th class="num">Amount</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($recentTx as $t): ?>
            <tr>
              <td><?= e(date('d M Y', strtotime($t['created_at']))) ?></td>
              <td>
                <?php if ($t['sub_account_id'] !== null): ?>
                  <span class="badge" style="background:#e0e7ff;color:#4338ca">Sub</span>
                  <?= e((string) $t['sub_name']) ?>
                <?php else: ?>
                  <span class="badge" style="background:#dbeafe;color:#1e40af">Own</span>
                <?php endif; ?>
              </td>
              <td><?= e((string) $t['description']) ?></td>
              <td><span class="badge badge--inactive"><?= e(str_replace('_', ' ', $t['type'])) ?></span></td>
              <td class="num"><?= e(format_money((float) $t['amount'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-partner.php'; ?>