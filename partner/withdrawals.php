<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';
require_once PICT_ROOT . '/includes/financial.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$partner = partner_require_login();

$page_title = 'Withdrawals';
$active_nav = 'withdrawals';
$pid = (int) $partner['id'];

// Combined stats
$aggregate = partner_aggregate_bucket_stats($pid);

// Bucket rows for the summary
$ownBalance  = partner_bucket_balance($pid, null);
$ownReserved = partner_bucket_reserved($pid, null);

$stmt = db()->prepare("
    SELECT id, full_name, nin_number, status
    FROM partner_sub_accounts
    WHERE partner_id = :pid AND status = 'ACTIVE'
    ORDER BY full_name ASC
");
$stmt->execute([':pid' => $pid]);
$subRows = $stmt->fetchAll();

$subBuckets = [];
foreach ($subRows as $s) {
    $sid = (int) $s['id'];
    $subBuckets[] = [
        'id'       => $sid,
        'name'     => (string) $s['full_name'],
        'nin'      => (string) $s['nin_number'],
        'balance'  => sub_account_balance($sid),
        'reserved' => sub_account_total_reserved($sid),
    ];
}

// Withdrawal list — join sub-account to show its name
$stmt = db()->prepare("
    SELECT w.*, h.from_status, h.to_status, h.created_at AS hist_at,
           s.full_name AS sub_name
    FROM withdrawals w
    LEFT JOIN withdrawal_status_history h
      ON h.id = (SELECT MAX(id) FROM withdrawal_status_history WHERE withdrawal_id = w.id)
    LEFT JOIN partner_sub_accounts s ON s.id = w.sub_account_id
    WHERE w.partner_id = :pid
    ORDER BY w.created_at DESC
    LIMIT 100
");
$stmt->execute([':pid' => $pid]);
$rows = $stmt->fetchAll();

require PICT_ROOT . '/includes/header-partner.php';

$badge = function (string $s): string {
    $cls = match ($s) {
        'PENDING'    => 'badge--pending',
        'APPROVED'   => 'badge--approved',
        'PROCESSING' => 'badge--processing',
        'PAID'       => 'badge--paid',
        'REJECTED'   => 'badge--rejected',
        'REVERSED'   => 'badge--reversed',
        'CANCELLED'  => 'badge--cancelled',
        default      => 'badge--pending',
    };
    return '<span class="badge ' . $cls . '">' . e(str_replace('_', ' ', $s)) . '</span>';
};
?>

<div class="page-head">
  <div>
    <h1>Withdrawals</h1>
    <p>Every request is drawn from a specific bucket — your own NIN or a sub-account.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-primary" href="<?= e(root_url('/partner/withdrawal-new.php')) ?>">New Request</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--avail">
    <div class="stat__label">Total Available Balance</div>
    <div class="stat__value"><?= e(format_money((float) $aggregate['total_balance'])) ?></div>
    <div class="stat__sub">Across all buckets</div>
  </div>
  <div class="stat stat--pending">
    <div class="stat__label">Total Reserved</div>
    <div class="stat__value"><?= e(format_money((float) $aggregate['total_reserved'])) ?></div>
    <div class="stat__sub">Held for pending requests</div>
  </div>
</div>

<!-- Balance by bucket -->
<div class="card mb-3">
  <h2>Balance by Bucket</h2>
  <div style="overflow-x:auto">
    <table class="tbl tbl--compact">
      <thead>
        <tr>
          <th>Bucket</th>
          <th>NIN</th>
          <th class="num">Available</th>
          <th class="num">Reserved</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>My own NIN</strong> <span class="muted" style="font-size:12px">(<?= e($partner['full_name']) ?>)</span></td>
          <td><span class="masked"><?= e(mask_nin($partner['nin_number'])) ?></span></td>
          <td class="num"><strong><?= e(format_money($ownBalance)) ?></strong></td>
          <td class="num"><?= e(format_money($ownReserved)) ?></td>
          <td class="text-right">
            <?php if ($ownBalance > 0): ?>
              <a class="btn btn-ghost btn-sm" href="<?= e(root_url('/partner/withdrawal-new.php')) ?>?bucket=own">Withdraw</a>
            <?php endif; ?>
          </td>
        </tr>
        <?php foreach ($subBuckets as $sb): ?>
          <tr>
            <td><strong><?= e($sb['name']) ?></strong></td>
            <td><span class="masked"><?= e(mask_nin($sb['nin'])) ?></span></td>
            <td class="num"><strong><?= e(format_money($sb['balance'])) ?></strong></td>
            <td class="num"><?= e(format_money($sb['reserved'])) ?></td>
            <td class="text-right">
              <?php if ($sb['balance'] > 0): ?>
                <a class="btn btn-ghost btn-sm" href="<?= e(root_url('/partner/withdrawal-new.php')) ?>?bucket=<?= (int) $sb['id'] ?>">Withdraw</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card">
  <h2>Request History</h2>
  <?php if (empty($rows)): ?>
    <div class="empty">
      <h3>No withdrawal requests yet</h3>
      <p>Once you have commission available in a bucket, you can request a withdrawal.</p>
      <a class="btn btn-primary" href="<?= e(root_url('/partner/withdrawal-new.php')) ?>">Request Withdrawal</a>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr>
            <th>Reference</th>
            <th>Source Bucket</th>
            <th class="num">Amount</th>
            <th>Status</th>
            <th>Bank</th>
            <th>Requested</th>
            <th>Updated</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $w): ?>
            <tr>
              <td><span class="masked"><?= e($w['withdrawal_reference']) ?></span></td>
              <td>
                <?php if (!empty($w['sub_account_id'])): ?>
                  <span class="badge" style="background:#e0e7ff;color:#4338ca">Sub</span>
                  <?= e((string) $w['sub_name']) ?>
                <?php else: ?>
                  <span class="badge" style="background:#dbeafe;color:#1e40af">Own</span>
                  My NIN
                <?php endif; ?>
              </td>
              <td class="num"><?= e(format_money((float) $w['amount'])) ?></td>
              <td><?= $badge($w['status']) ?></td>
              <td>
                <?= e($w['bank_name_snapshot']) ?><br>
                <span class="muted" style="font-size:12.5px">
                  <?= e($w['account_name_snapshot']) ?> &middot; <?= e($w['account_number_snapshot']) ?>
                </span>
              </td>
              <td><?= e(date('d M Y H:i', strtotime($w['created_at']))) ?></td>
              <td><?= e(date('d M Y H:i', strtotime($w['updated_at'] ?? $w['created_at']))) ?></td>
              <td class="text-right">
                <a class="btn btn-outline btn-sm" href="<?= e(root_url('/partner/withdrawals.php')) ?>?view=<?= (int) $w['id'] ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php
// Detail view
$viewId = (int) ($_GET['view'] ?? 0);
if ($viewId > 0):
    $stmt = db()->prepare("
        SELECT w.*, s.full_name AS sub_name
        FROM withdrawals w
        LEFT JOIN partner_sub_accounts s ON s.id = w.sub_account_id
        WHERE w.id = :id AND w.partner_id = :pid
        LIMIT 1
    ");
    $stmt->execute([':id' => $viewId, ':pid' => $pid]);
    $w = $stmt->fetch();
    if ($w):
        $stmt = db()->prepare("SELECT * FROM withdrawal_status_history WHERE withdrawal_id = :id ORDER BY created_at ASC, id ASC");
        $stmt->execute([':id' => $viewId]);
        $history = $stmt->fetchAll();
?>
<div class="card mt-3">
  <div class="flex-between mb-2">
    <h2 style="margin:0">Withdrawal Detail</h2>
    <a class="btn btn-outline btn-sm" href="<?= e(root_url('/partner/withdrawals.php')) ?>">Close</a>
  </div>
  <table class="tbl tbl--compact" style="margin-bottom:16px">
    <tr><th style="width:200px">Reference</th><td><span class="masked"><?= e($w['withdrawal_reference']) ?></span></td></tr>
    <tr><th>Source Bucket</th>
      <td>
        <?php if (!empty($w['sub_account_id'])): ?>
          <span class="badge" style="background:#e0e7ff;color:#4338ca">Sub</span>
          <?= e((string) $w['sub_name']) ?>
        <?php else: ?>
          <span class="badge" style="background:#dbeafe;color:#1e40af">Own</span>
          My own NIN — <?= e($partner['full_name']) ?>
        <?php endif; ?>
      </td>
    </tr>
    <tr><th>Amount</th><td><strong><?= e(format_money((float) $w['amount'])) ?></strong></td></tr>
    <tr><th>Status</th><td><?= $badge($w['status']) ?></td></tr>
    <tr><th>Bank</th><td><?= e($w['bank_name_snapshot']) ?></td></tr>
    <tr><th>Account Name</th><td><?= e($w['account_name_snapshot']) ?></td></tr>
    <tr><th>Account Number</th><td><span class="masked"><?= e($w['account_number_snapshot']) ?></span></td></tr>
    <?php if (!empty($w['partner_remark'])): ?><tr><th>Your Remark</th><td><?= e($w['partner_remark']) ?></td></tr><?php endif; ?>
    <?php if (!empty($w['admin_comment'])): ?><tr><th>Admin Comment</th><td><?= e($w['admin_comment']) ?></td></tr><?php endif; ?>
    <?php if (!empty($w['rejection_reason'])): ?><tr><th>Rejection Reason</th><td><?= e($w['rejection_reason']) ?></td></tr><?php endif; ?>
    <?php if (!empty($w['payment_reference'])): ?><tr><th>Payment Reference</th><td><span class="masked"><?= e($w['payment_reference']) ?></span></td></tr><?php endif; ?>
    <tr><th>Requested</th><td><?= e(date('d M Y H:i', strtotime($w['created_at']))) ?></td></tr>
    <?php if ($w['paid_at']): ?><tr><th>Paid</th><td><?= e(date('d M Y H:i', strtotime($w['paid_at']))) ?></td></tr><?php endif; ?>
  </table>

  <h3 style="font-size:14px;letter-spacing:.05em;text-transform:uppercase;color:var(--muted)">Status History</h3>
  <table class="tbl tbl--compact">
    <thead><tr><th>When</th><th>From</th><th>To</th><th>By</th><th>Comment</th></tr></thead>
    <tbody>
      <?php foreach ($history as $h): ?>
        <tr>
          <td><?= e(date('d M Y H:i', strtotime($h['created_at']))) ?></td>
          <td><?= e((string) $h['from_status']) ?: '<span class="muted">—</span>' ?></td>
          <td><?= e($h['to_status']) ?></td>
          <td><?= e($h['changed_by_role']) ?> <?= $h['changed_by_id'] ? '#' . (int) $h['changed_by_id'] : '' ?></td>
          <td><?= e((string) $h['comment']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; endif; ?>

<?php require PICT_ROOT . '/includes/footer-partner.php'; ?>