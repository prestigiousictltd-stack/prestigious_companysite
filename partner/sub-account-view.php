<?php
/**
 * Single sub-account view for the owning partner.
 * Read-only — the partner cannot change anything here.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$partner = partner_require_login();

$pid = (int) $partner['id'];
$id  = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Sub-account ID is required.');
    redirect(root_url('/partner/sub-accounts.php'));
}

// Load and verify ownership in one query
$stmt = db()->prepare("
    SELECT * FROM partner_sub_accounts
    WHERE id = :id AND partner_id = :pid
    LIMIT 1
");
$stmt->execute([':id' => $id, ':pid' => $pid]);
$sub = $stmt->fetch();
if (!$sub) {
    flash('error', 'Sub-account not found.');
    redirect(root_url('/partner/sub-accounts.php'));
}

$page_title = 'Sub-Account: ' . $sub['full_name'];
$active_nav = 'sub-accounts';

// Rate history
$stmt = db()->prepare("
    SELECT * FROM sub_account_commission_rates
    WHERE sub_account_id = :sid
    ORDER BY effective_from DESC, id DESC
");
$stmt->execute([':sid' => $id]);
$rates = $stmt->fetchAll();

// Balance and totals
$balance   = sub_account_balance($id);
$earned    = sub_account_total_commission($id);
$withdrawn = sub_account_total_withdrawn($id);
$reserved  = sub_account_total_reserved($id);

// Ledger
$stmt = db()->prepare("
    SELECT * FROM partner_financial_transactions
    WHERE sub_account_id = :sid
    ORDER BY created_at DESC, id DESC
    LIMIT 50
");
$stmt->execute([':sid' => $id]);
$ledger = $stmt->fetchAll();

// Enrolment records for this sub-account
$stmt = db()->prepare("
    SELECT * FROM partner_enrolment_records
    WHERE sub_account_id = :sid
    ORDER BY period_year DESC, period_month DESC, id DESC
    LIMIT 24
");
$stmt->execute([':sid' => $id]);
$enrolments = $stmt->fetchAll();

require PICT_ROOT . '/includes/header-partner.php';

$badge = function (string $s): string {
    $cls = match ($s) {
        'ACTIVE'     => 'badge--active',
        'PENDING'    => 'badge--pending',
        'INACTIVE'   => 'badge--inactive',
        'REJECTED'   => 'badge--rejected',
        'SUPERSEDED' => 'badge--inactive',
        default      => 'badge--inactive',
    };
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
};
?>

<div class="page-head">
  <div>
    <h1><?= e($sub['full_name']) ?> <?= $badge((string) $sub['status']) ?></h1>
    <p>
      NIN <span class="masked"><?= e(mask_nin($sub['nin_number'])) ?></span>
      &middot; Added <?= e(date('d M Y', strtotime($sub['created_at']))) ?>
      <?php if (!empty($sub['approved_at'])): ?>
        &middot; Approved <?= e(date('d M Y', strtotime($sub['approved_at']))) ?>
      <?php endif; ?>
    </p>
  </div>
   <div class="page-head__actions">
    <a class="btn btn-outline" href="<?= e(root_url('/partner/sub-accounts.php')) ?>">All Sub-Accounts</a>
    <?php if ($sub['status'] === 'ACTIVE' && $balance > 0): ?>
      <a class="btn btn-primary" href="<?= e(root_url('/partner/withdrawal-new.php')) ?>?bucket=<?= (int) $sub['id'] ?>">
        Withdraw from This Sub-Account
      </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($sub['status'] === 'PENDING'): ?>
  <div class="alert alert-info">
    This sub-account is awaiting Super Admin approval. Its NIN will start earning
    commission once it is approved and a rate is set.
  </div>
<?php elseif ($sub['status'] === 'REJECTED'): ?>
  <div class="alert alert-danger">
    <strong>This sub-account was rejected.</strong>
    <?php if (!empty($sub['status_reason'])): ?>
      <br>Reason: <?= e($sub['status_reason']) ?>
    <?php endif; ?>
  </div>
<?php elseif ($sub['status'] === 'INACTIVE'): ?>
  <div class="alert alert-warn">
    <strong>This sub-account is inactive.</strong> It will not earn on future imports.
    <?php if (!empty($sub['status_reason'])): ?>
      <br>Reason: <?= e($sub['status_reason']) ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat stat--purple">
    <div class="stat__label">Available Balance</div>
    <div class="stat__value"><?= e(format_money($balance)) ?></div>
    <div class="stat__sub">Withdrawable from the Withdrawals page</div>
  </div>
  <div class="stat stat--ok">
    <div class="stat__label">Total Earned</div>
    <div class="stat__value"><?= e(format_money($earned)) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Total Withdrawn</div>
    <div class="stat__value"><?= e(format_money($withdrawn)) ?></div>
  </div>
  <div class="stat stat--pending">
    <div class="stat__label">Reserved</div>
    <div class="stat__value"><?= e(format_money($reserved)) ?></div>
    <div class="stat__sub">Pending / approved withdrawal requests</div>
  </div>
</div>

<div class="card mb-3">
  <h2>Profile</h2>
  <table class="tbl tbl--compact">
    <tr><th style="width:220px">Full Name</th><td><?= e($sub['full_name']) ?></td></tr>
      <tr>
      <th>NIN</th>
      <td>
        <span id="sub-nin-display"
              class="masked"
              data-full="<?= e($sub['nin_number']) ?>"
              data-masked="<?= e(mask_nin($sub['nin_number'])) ?>">
          <?= e(mask_nin($sub['nin_number'])) ?>
        </span>
        <button type="button"
                class="btn btn-outline btn-sm"
                style="padding:2px 8px;font-size:11px;margin-left:6px"
                id="sub-nin-btn"
                onclick="pictReveal('sub-nin-display', 'sub-nin-btn')">
          Reveal
        </button>
      </td>
    </tr>
    <tr><th>Email</th><td><?= e((string) $sub['email']) ?: '<span class="muted">—</span>' ?></td></tr>
    <tr><th>Status</th><td><?= $badge((string) $sub['status']) ?></td></tr>
    <tr><th>Submitted</th><td><?= e(date('d M Y H:i', strtotime($sub['created_at']))) ?></td></tr>
    <?php if (!empty($sub['approved_at'])): ?>
      <tr><th>Approved</th><td><?= e(date('d M Y H:i', strtotime($sub['approved_at']))) ?></td></tr>
    <?php endif; ?>
    <?php if (!empty($sub['status_reason'])): ?>
      <tr><th>Status Note</th><td><?= e($sub['status_reason']) ?></td></tr>
    <?php endif; ?>
  </table>
</div>

<div class="card mb-3">
  <h2>Commission Rate History</h2>
  <?php if (empty($rates)): ?>
    <div class="empty"><p>A rate will be assigned by the Super Admin at approval.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr><th>Rate</th><th>Effective From</th><th>Effective To</th><th>Status</th><th>Notes</th></tr>
        </thead>
        <tbody>
          <?php foreach ($rates as $r): ?>
            <tr>
              <td><strong><?= e(number_format((float) $r['commission_percentage'], 2)) ?>%</strong></td>
              <td><?= e($r['effective_from']) ?></td>
              <td><?= e((string) $r['effective_to']) ?: '<span class="muted">Open</span>' ?></td>
              <td><?= $badge((string) $r['status']) ?></td>
              <td><?= e((string) $r['notes']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card mb-3">
  <h2>Enrolment Records</h2>
  <?php if (empty($enrolments)): ?>
    <div class="empty">
      <p>No enrolment records yet for this sub-account.</p>
      <p class="muted" style="font-size:13px">
        Records appear here when a CSV upload includes this NIN, or after a Super Admin
        approves the sub-account and historical records are attached.
      </p>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Period</th>
            <th>Station ID</th>
            <th>Station Name</th>
            <th class="num">NIN</th>
            <th class="num">ERNEW</th>
            <th class="num">CRNEW</th>
            <th class="num">Commission</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($enrolments as $e): ?>
            <tr>
              <td><strong><?= e(format_period((int) $e['period_year'], (int) $e['period_month'])) ?></strong></td>
              <td>
                <?= !empty($e['station_id_raw'])
                    ? '<span class="masked">' . e($e['station_id_raw']) . '</span>'
                    : '<span class="muted">—</span>' ?>
              </td>
              <td><?= !empty($e['station_name_raw']) ? e($e['station_name_raw']) : '<span class="muted">—</span>' ?></td>
              <td class="num"><?= number_format((int) $e['total_nin_generated']) ?></td>
              <td class="num"><?= number_format((int) $e['ernew_nin']) ?></td>
              <td class="num"><?= number_format((int) $e['crnew_nin']) ?></td>
              <td class="num"><strong><?= e(format_money((float) $e['commission_amount'])) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Transaction Ledger</h2>
  <?php if (empty($ledger)): ?>
    <div class="empty"><p>No transactions yet.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Date</th>
            <th>Transaction ID</th>
            <th>Description</th>
            <th>Type</th>
            <th class="num">Amount</th>
            <th class="num">Balance</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ledger as $t): ?>
            <tr>
              <td><?= e(date('d M Y', strtotime($t['created_at']))) ?></td>
              <td><span class="masked"><?= e($t['transaction_id']) ?></span></td>
              <td><?= e((string) $t['description']) ?></td>
              <td><span class="badge badge--inactive"><?= e(str_replace('_', ' ', $t['type'])) ?></span></td>
              <td class="num"><?= e(format_money((float) $t['amount'])) ?></td>
              <td class="num"><?= e(format_money((float) $t['new_balance'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-partner.php'; ?>