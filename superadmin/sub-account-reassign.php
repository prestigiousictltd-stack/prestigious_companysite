<?php
/**
 * Reassign a sub-account from one parent partner to another.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$sa = superadmin_require_login();

$subId = (int) ($_GET['sub_id'] ?? $_POST['sub_id'] ?? 0);
if ($subId <= 0) {
    flash('error', 'Sub-account ID is required.');
    redirect(root_url('/superadmin/sub-accounts.php'));
}

$stmt = db()->prepare("
    SELECT s.*, p.full_name AS partner_name, p.partner_code
    FROM partner_sub_accounts s
    JOIN partners p ON p.id = s.partner_id
    WHERE s.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $subId]);
$sub = $stmt->fetch();
if (!$sub) {
    flash('error', 'Sub-account not found.');
    redirect(root_url('/superadmin/sub-accounts.php'));
}

$page_title = 'Reassign Sub-Account';
$active_nav = 'sub-accounts';

// Compute what will move
$balanceNow      = sub_account_balance($subId);
$totalEarned     = sub_account_total_commission($subId);
$totalWithdrawn  = sub_account_total_withdrawn($subId);

$stmt = db()->prepare("SELECT COUNT(*) FROM partner_enrolment_records WHERE sub_account_id = :sid");
$stmt->execute([':sid' => $subId]);
$enrolCount = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM partner_financial_transactions WHERE sub_account_id = :sid");
$stmt->execute([':sid' => $subId]);
$ledgerCount = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM withdrawals WHERE sub_account_id = :sid");
$stmt->execute([':sid' => $subId]);
$withdrawalCount = (int) $stmt->fetchColumn();

$stmt = db()->prepare("SELECT COUNT(*) FROM withdrawals WHERE sub_account_id = :sid AND status IN ('PENDING','APPROVED','PROCESSING')");
$stmt->execute([':sid' => $subId]);
$pendingWithdrawalCount = (int) $stmt->fetchColumn();

// Load destination partner options (ACTIVE only, exclude current parent)
$stmt = db()->prepare("
    SELECT id, full_name, partner_code
    FROM partners
    WHERE status = 'ACTIVE' AND id <> :current
    ORDER BY full_name
");
$stmt->execute([':current' => (int) $sub['partner_id']]);
$destinations = $stmt->fetchAll();

// Determine whether the reassign button should be enabled
$blockReasons = [];
if (!in_array($sub['status'], ['ACTIVE','INACTIVE'], true)) {
    $blockReasons[] = 'The sub-account is ' . strtolower($sub['status']) . '. Only active or inactive sub-accounts can be reassigned.';
}
if ($pendingWithdrawalCount > 0) {
    $blockReasons[] = $pendingWithdrawalCount . ' withdrawal(s) are still in PENDING/APPROVED/PROCESSING. Resolve them first.';
}
if (empty($destinations)) {
    $blockReasons[] = 'There is no other active partner to reassign to.';
}

require PICT_ROOT . '/includes/header-superadmin.php';

$badge = function (string $s): string {
    $cls = match ($s) {
        'ACTIVE'   => 'badge--active',
        'PENDING'  => 'badge--pending',
        'INACTIVE' => 'badge--inactive',
        'REJECTED' => 'badge--rejected',
        default    => 'badge--inactive',
    };
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
};
?>

<div class="page-head">
  <div>
    <h1>Reassign Sub-Account</h1>
    <p>Move a sub-account and all of its history to a different parent partner.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/sub-account-view.php')) ?>?id=<?= $subId ?>">Back</a>
  </div>
</div>

<?php if (!empty($blockReasons)): ?>
  <div class="alert alert-warn">
    <strong>Reassignment is blocked.</strong>
    <ul style="margin:8px 0 0 18px;padding:0">
      <?php foreach ($blockReasons as $r): ?>
        <li><?= e($r) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card mb-3">
  <h2>Sub-Account</h2>
  <table class="tbl tbl--compact">
    <tr><th style="width:220px">Name</th><td><strong><?= e($sub['full_name']) ?></strong></td></tr>
    <tr><th>NIN</th><td><span class="masked"><?= e(mask_nin($sub['nin_number'])) ?></span></td></tr>
    <tr><th>Status</th><td><?= $badge((string) $sub['status']) ?></td></tr>
    <tr>
      <th>Current Parent Partner</th>
      <td>
        <a href="<?= e(root_url('/superadmin/partner-view.php')) ?>?id=<?= (int) $sub['partner_id'] ?>">
          <?= e($sub['partner_name']) ?>
        </a>
        <span class="muted">(<?= e($sub['partner_code']) ?>)</span>
      </td>
    </tr>
  </table>
</div>

<div class="card mb-3">
  <h2>What Will Move</h2>
  <div class="stat-grid">
    <div class="stat stat--purple">
      <div class="stat__label">Current Bucket Balance</div>
      <div class="stat__value"><?= e(format_money($balanceNow)) ?></div>
      <div class="stat__sub">Transfers to the new partner's ledger</div>
    </div>
    <div class="stat stat--ok">
      <div class="stat__label">Lifetime Commission</div>
      <div class="stat__value"><?= e(format_money($totalEarned)) ?></div>
    </div>
    <div class="stat">
      <div class="stat__label">Lifetime Withdrawn</div>
      <div class="stat__value"><?= e(format_money($totalWithdrawn)) ?></div>
    </div>
  </div>
  <table class="tbl tbl--compact">
    <tr><th style="width:220px">Enrolment Records</th><td><?= number_format($enrolCount) ?></td></tr>
    <tr><th>Ledger Entries</th><td><?= number_format($ledgerCount) ?></td></tr>
    <tr><th>Historical Withdrawals</th><td><?= number_format($withdrawalCount) ?></td></tr>
    <tr><th>Pending Withdrawals</th><td>
      <?= $pendingWithdrawalCount > 0
          ? '<span class="badge badge--pending">' . number_format($pendingWithdrawalCount) . ' blocking</span>'
          : '<span class="badge badge--active">None</span>' ?>
    </td></tr>
  </table>
</div>

<div class="alert alert-info">
  <strong>What happens on reassignment.</strong>
  <ul style="margin:8px 0 0 18px;padding:0">
    <li>The sub-account's parent partner changes.</li>
    <li>Every enrolment record, ledger entry, historical withdrawal, and aggregator link tied to this sub-account is reattached to the new partner in one transaction.</li>
    <li>The old partner's ledger balance drops by the current bucket balance; the new partner's rises by the same amount.</li>
    <li>Historical <code>previous_balance</code> / <code>new_balance</code> figures on the moved ledger rows are left unchanged — they are the running figures as of the moment of each original entry.</li>
    <li>The action is audit-logged with old partner, new partner, and balance moved.</li>
  </ul>
</div>

<?php if (empty($blockReasons)): ?>
<div class="card card--pad-lg" style="max-width:680px">
  <form method="post" action="<?= e(root_url('/superadmin/sub-account-action.php')) ?>" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="reassign">
    <input type="hidden" name="id" value="<?= $subId ?>">

    <div class="form-group">
      <label class="form-label">Destination Parent Partner <span class="req">*</span></label>
      <select class="form-control" name="new_partner_id" required>
        <option value="">— Select partner —</option>
        <?php foreach ($destinations as $d): ?>
          <option value="<?= (int) $d['id'] ?>">
            <?= e($d['full_name']) ?> (<?= e($d['partner_code']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <div class="form-hint">
        Only ACTIVE partners are listed. The sub-account's NIN must not collide with the destination's own NIN.
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Reason (optional)</label>
      <input class="form-control" type="text" name="reason" maxlength="500"
             placeholder="Internal note recorded in the audit log">
    </div>

    <button class="btn btn-warn btn-lg" type="submit"
            onclick="return confirm('Reassign this sub-account? This will move all its records, ledger entries and history to the destination partner. The action is logged.');">
      Reassign Sub-Account
    </button>
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/sub-account-view.php')) ?>?id=<?= $subId ?>" style="margin-left:8px">Cancel</a>
  </form>
</div>
<?php else: ?>
  <div class="card card--pad-lg">
    <p class="muted">Reassignment is not available until the blocking conditions above are resolved.</p>
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/sub-account-view.php')) ?>?id=<?= $subId ?>">Back to Sub-Account</a>
  </div>
<?php endif; ?>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>