<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/aggregator.php';
$sa = superadmin_require_login();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Aggregator ID is required.');
    redirect(root_url('/superadmin/aggregators.php'));
}

$stmt = db()->prepare("
    SELECT a.*, sa.full_name AS creator_name
    FROM aggregators a
    LEFT JOIN super_admins sa ON sa.id = a.created_by_super_admin_id
    WHERE a.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$a = $stmt->fetch();
if (!$a) {
    flash('error', 'Aggregator not found.');
    redirect(root_url('/superadmin/aggregators.php'));
}

$page_title = $a['full_name'];
$active_nav = 'aggregators';

$currentRate = aggregator_current_rate((int) $a['id']);

$stmt = db()->prepare("SELECT * FROM aggregator_commission_rates WHERE aggregator_id = :aid ORDER BY effective_from DESC, id DESC");
$stmt->execute([':aid' => $id]);
$rates = $stmt->fetchAll();

$linkedNins = aggregator_list_linked_nins($id, false);

$stmt = db()->prepare("
    SELECT * FROM aggregator_financial_transactions
    WHERE aggregator_id = :aid
    ORDER BY created_at DESC, id DESC
    LIMIT 50
");
$stmt->execute([':aid' => $id]);
$ledger = $stmt->fetchAll();

$stmt = db()->prepare("
    SELECT period_year, period_month,
           SUM(partner_commission_amount)    AS partner_commission_total,
           SUM(aggregator_commission_amount) AS aggregator_commission_total
    FROM aggregator_enrolment_records
    WHERE aggregator_id = :aid
    GROUP BY period_year, period_month
    ORDER BY period_year DESC, period_month DESC
    LIMIT 24
");
$stmt->execute([':aid' => $id]);
$periods = $stmt->fetchAll();

$balance = aggregator_available_balance((int) $a['id']);
$earned  = aggregator_total_earned((int) $a['id']);
$paid    = aggregator_total_paid((int) $a['id']);

$partnerOwnCount = 0;
$subAccountCount = 0;
foreach ($linkedNins as $n) {
    if ($n['type'] === 'sub_account') { $subAccountCount++; }
    else { $partnerOwnCount++; }
}

require PICT_ROOT . '/includes/header-superadmin.php';

$statusBadge = function (string $s): string {
    $cls = match (strtoupper($s)) {
        'ACTIVE'     => 'badge--active',
        'INACTIVE'   => 'badge--inactive',
        'SUPERSEDED' => 'badge--inactive',
        'PENDING'    => 'badge--pending',
        'SUSPENDED'  => 'badge--rejected',
        default      => 'badge--inactive',
    };
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
};
?>

<div class="page-head">
  <div>
    <h1><?= e($a['full_name']) ?> <?= $statusBadge((string) $a['status']) ?></h1>
    <p>
      Added <?= e(date('d M Y', strtotime($a['created_at']))) ?>
      <?php if (!empty($a['creator_name'])): ?> by <?= e($a['creator_name']) ?><?php endif; ?>
      &middot; <?= number_format(count($linkedNins)) ?> active NIN link<?= count($linkedNins) === 1 ? '' : 's' ?>
      <?php if ($currentRate): ?> &middot; current rate <?= e(number_format((float) $currentRate['commission_percentage'], 2)) ?>%<?php endif; ?>
    </p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregators.php')) ?>">All Aggregators</a>
    <a class="btn btn-ghost" href="<?= e(root_url('/superadmin/aggregator-edit.php')) ?>?id=<?= (int) $a['id'] ?>">Edit</a>
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregator-print.php')) ?>?id=<?= (int) $a['id'] ?>" target="_blank" rel="noopener">Print Statement</a>
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregator-backfill.php')) ?>">Backfill</a>
    <a class="btn btn-grape" href="<?= e(root_url('/superadmin/aggregator-payout.php')) ?>?id=<?= (int) $a['id'] ?>">Record Payout</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--purple">
    <div class="stat__label">Available Balance</div>
    <div class="stat__value"><?= e(format_money($balance)) ?></div>
    <div class="stat__sub">Unpaid commission owed</div>
  </div>
  <div class="stat stat--ok">
    <div class="stat__label">Total Earned</div>
    <div class="stat__value"><?= e(format_money($earned)) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Total Paid Out</div>
    <div class="stat__value"><?= e(format_money($paid)) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Linked NINs</div>
    <div class="stat__value"><?= number_format(count($linkedNins)) ?></div>
    <div class="stat__sub">
      <?= number_format($partnerOwnCount) ?> partner &middot; <?= number_format($subAccountCount) ?> sub-account
    </div>
  </div>
</div>

<div class="card mb-3">
  <h2>Profile</h2>
  <table class="tbl tbl--compact">
    <tr><th style="width:220px">Full Name</th><td><?= e($a['full_name']) ?></td></tr>
    <tr><th>Phone</th><td><?= e((string) $a['phone']) ?: '<span class="muted">—</span>' ?></td></tr>
    <tr><th>Email</th><td><?= e((string) $a['email']) ?: '<span class="muted">—</span>' ?></td></tr>
    <tr><th>Bank Name</th><td><?= e((string) $a['bank_name']) ?: '<span class="muted">—</span>' ?></td></tr>
    <tr><th>Account Name</th><td><?= e((string) $a['bank_account_name']) ?: '<span class="muted">—</span>' ?></td></tr>
    <tr><th>Account Number</th><td><span class="masked"><?= e((string) $a['bank_account_number']) ?: '<span class="muted">—</span>' ?></span></td></tr>
    <tr><th>Status</th><td><?= $statusBadge((string) $a['status']) ?></td></tr>
    <tr><th>Notes</th><td><?= nl2br(e((string) $a['notes'])) ?: '<span class="muted">—</span>' ?></td></tr>
  </table>
</div>

<div class="card mb-3">
  <h2>Commission Rate History</h2>
  <?php if (empty($rates)): ?>
    <div class="empty"><p>No rate configured.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead><tr><th>Rate</th><th>Effective From</th><th>Effective To</th><th>Status</th><th>Notes</th></tr></thead>
        <tbody>
          <?php foreach ($rates as $r): ?>
            <tr>
              <td><strong><?= e(number_format((float) $r['commission_percentage'], 2)) ?>%</strong></td>
              <td><?= e($r['effective_from']) ?></td>
              <td><?= e((string) $r['effective_to']) ?: '<span class="muted">Open</span>' ?></td>
              <td><?= $statusBadge((string) $r['status']) ?></td>
              <td><?= e((string) $r['notes']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card mb-3">
  <h2>Linked NINs</h2>
  <div class="form-hint" style="margin-bottom:12px">
    Only these NINs earn aggregator commission. A partner's own NIN does not carry over to their sub-accounts, and vice versa.
  </div>
  <?php if (empty($linkedNins)): ?>
    <div class="empty"><p>No NINs linked. Use Edit to link one.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Owner</th>
            <th>Type</th>
            <th>NIN</th>
            <th>Parent Partner</th>
            <th>Since</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($linkedNins as $n): ?>
            <tr>
              <td><strong><?= e($n['display_name']) ?></strong></td>
              <td>
                <?php if ($n['type'] === 'sub_account'): ?>
                  <span class="badge" style="background:#e0e7ff;color:#4338ca">Sub-Account</span>
                <?php else: ?>
                  <span class="badge" style="background:#dbeafe;color:#1e40af">Partner Own</span>
                <?php endif; ?>
              </td>
                            <td>
                <span id="agg-nin-<?= (int) $n['assignment_id'] ?>"
                      class="masked"
                      data-full="<?= e($n['nin']) ?>"
                      data-masked="<?= e(mask_nin($n['nin'])) ?>">
                  <?= e(mask_nin($n['nin'])) ?>
                </span>
                <button type="button"
                        class="btn btn-outline btn-sm"
                        style="padding:2px 8px;font-size:11px;margin-left:6px"
                        id="agg-nin-btn-<?= (int) $n['assignment_id'] ?>"
                        onclick="pictReveal('agg-nin-<?= (int) $n['assignment_id'] ?>', 'agg-nin-btn-<?= (int) $n['assignment_id'] ?>', 'aggregator_linked_nin', <?= (int) $n['assignment_id'] ?>)">
                  Reveal
                </button>
              </td>
              <td>
                <?php if ($n['type'] === 'sub_account'): ?>
                  <a href="<?= e(root_url('/superadmin/partner-view.php')) ?>?id=<?= (int) $n['partner_id'] ?>">
                    <?= e($n['partner_name']) ?>
                  </a>
                <?php else: ?>
                  <a href="<?= e(root_url('/superadmin/partner-view.php')) ?>?id=<?= (int) $n['partner_id'] ?>">
                    <?= e($n['partner_name']) ?>
                  </a>
                <?php endif; ?>
              </td>
              <td><?= e($n['effective_from']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card mb-3">
  <h2>Commission by Period</h2>
  <?php if (empty($periods)): ?>
    <div class="empty"><p>No settlements yet. Aggregator commission accrues when CSV imports are confirmed.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Period</th>
            <th class="num">Linked NINs' Commission</th>
            <th class="num">Aggregator Commission</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($periods as $p): ?>
            <tr>
              <td><strong><?= e(format_period((int) $p['period_year'], (int) $p['period_month'])) ?></strong></td>
              <td class="num"><?= e(format_money((float) $p['partner_commission_total'])) ?></td>
              <td class="num"><strong><?= e(format_money((float) $p['aggregator_commission_total'])) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Ledger</h2>
  <?php if (empty($ledger)): ?>
    <div class="empty"><p>No transactions yet.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Date</th>
            <th>Transaction ID</th>
            <th>Type</th>
            <th>Description</th>
            <th class="num">Amount</th>
            <th class="num">New Balance</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($ledger as $t): ?>
            <tr>
              <td><?= e(date('d M Y', strtotime($t['created_at']))) ?></td>
              <td><span class="masked"><?= e($t['transaction_id']) ?></span></td>
              <td><span class="badge badge--inactive"><?= e(str_replace('_', ' ', $t['type'])) ?></span></td>
              <td><?= e((string) $t['description']) ?></td>
              <td class="num"><?= e(format_money((float) $t['amount'])) ?></td>
              <td class="num"><?= e(format_money((float) $t['new_balance'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
</div>

<!-- Danger zone: status changes and deletion -->
<?php
$outstandingBalance = aggregator_available_balance((int) $a['id']);
$hasLedgerEntries   = (int) db()->query(
    "SELECT COUNT(*) FROM aggregator_financial_transactions WHERE aggregator_id = " . (int) $a['id']
)->fetchColumn();
$hasSettlements     = (int) db()->query(
    "SELECT COUNT(*) FROM aggregator_enrolment_records WHERE aggregator_id = " . (int) $a['id']
)->fetchColumn();
$hasAssignments     = (int) db()->query(
    "SELECT COUNT(*) FROM partner_aggregator_assignments WHERE aggregator_id = " . (int) $a['id']
)->fetchColumn();
$canDelete = ($a['status'] === 'INACTIVE'
              && $hasLedgerEntries === 0
              && $hasSettlements === 0
              && $hasAssignments === 0);
?>

<div class="card mb-3" style="border-color:#fecaca">
  <h2 style="color:#991b1b">Status Actions</h2>

  <?php if ($a['status'] === 'ACTIVE'): ?>
    <p style="font-size:14px;color:var(--muted)">
      Deactivating stops the aggregator from earning on future imports.
      The current outstanding balance of
      <strong style="color:var(--ink)"><?= e(format_money($outstandingBalance)) ?></strong>
      will be reclaimed to organisation revenue via a reversal entry.
      Historical settlements and paid amounts remain in the ledger for audit.
    </p>

    <form method="post" action="<?= e(root_url('/superadmin/aggregator-action.php')) ?>" style="max-width:640px">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
      <input type="hidden" name="action" value="deactivate">
      <div class="form-group">
        <label class="form-label">Reason (optional)</label>
        <input class="form-control" type="text" name="reason" maxlength="500"
               placeholder="Recorded in the audit log and the aggregator's notes">
      </div>
      <button class="btn btn-danger" type="submit"
              onclick="return confirm('Deactivate this aggregator?\n\nOutstanding balance of <?= e(number_format($outstandingBalance, 2, '.', '')) ?> will be reclaimed to organisation revenue.\n\nThis action is logged. You can reactivate later if needed.');">
        Deactivate Aggregator
      </button>
    </form>

  <?php elseif ($a['status'] === 'INACTIVE'): ?>
    <p style="font-size:14px;color:var(--muted)">
      This aggregator is inactive. It does not earn on new imports.
      Reactivating will allow it to resume earning on future batches.
    </p>

    <form method="post" action="<?= e(root_url('/superadmin/aggregator-action.php')) ?>" style="max-width:640px;margin-bottom:22px">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
      <input type="hidden" name="action" value="reactivate">
      <div class="form-group">
        <label class="form-label">Reason (optional)</label>
        <input class="form-control" type="text" name="reason" maxlength="500">
      </div>
      <button class="btn btn-ok" type="submit"
              onclick="return confirm('Reactivate this aggregator?');">
        Reactivate Aggregator
      </button>
    </form>

    <div style="border-top:1px solid var(--line);padding-top:18px">
      <h3 style="font-size:15px;color:#991b1b;margin-bottom:8px">Delete</h3>
      <?php if ($canDelete): ?>
        <p style="font-size:13.5px;color:var(--muted);margin-bottom:12px">
          This aggregator has no ledger entries, no settlements and no linked NINs.
          It can be permanently deleted.
        </p>
        <form method="post" action="<?= e(root_url('/superadmin/aggregator-action.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
          <input type="hidden" name="action" value="delete">
          <button class="btn btn-danger" type="submit"
                  onclick="return confirm('Permanently delete this aggregator? This cannot be undone.');">
            Delete Aggregator
          </button>
        </form>
      <?php else: ?>
        <p style="font-size:13.5px;color:var(--muted)">
          This aggregator cannot be deleted because it has
          <?php if ($hasLedgerEntries > 0): ?>ledger entries<?php endif; ?>
          <?php if ($hasSettlements > 0): ?><?= $hasLedgerEntries > 0 ? ', ' : '' ?>settlement records<?php endif; ?>
          <?php if ($hasAssignments > 0): ?><?= ($hasLedgerEntries > 0 || $hasSettlements > 0) ? ' and ' : '' ?>linked NINs<?php endif; ?>.
          Deletion is only available for aggregators that were created by mistake and never used.
        </p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>
<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>