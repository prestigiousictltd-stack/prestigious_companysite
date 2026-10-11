<?php
/**
 * Full detail view for one sub-account, plus action forms.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$sa = superadmin_require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Sub-account ID is required.');
    redirect(root_url('/superadmin/sub-accounts.php'));
}

$stmt = db()->prepare("
    SELECT s.*,
           p.full_name AS partner_name, p.partner_code, p.nin_number AS partner_nin,
           p.state_id, p.lga_id,
           appr.full_name AS approver_name
    FROM partner_sub_accounts s
    JOIN partners p ON p.id = s.partner_id
    LEFT JOIN super_admins appr ON appr.id = s.approved_by_super_admin_id
    WHERE s.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$sub = $stmt->fetch();
if (!$sub) {
    flash('error', 'Sub-account not found.');
    redirect(root_url('/superadmin/sub-accounts.php'));
}

$page_title = 'Sub-Account: ' . $sub['full_name'];
$active_nav = 'sub-accounts';

// Rate history
$stmt = db()->prepare("
    SELECT r.*, sa.full_name AS creator_name
    FROM sub_account_commission_rates r
    LEFT JOIN super_admins sa ON sa.id = r.created_by_super_admin_id
    WHERE r.sub_account_id = :sid
    ORDER BY r.effective_from DESC, r.id DESC
");
$stmt->execute([':sid' => $id]);
$rates = $stmt->fetchAll();

$balance   = sub_account_balance($id);
$earned    = sub_account_total_commission($id);
$withdrawn = sub_account_total_withdrawn($id);
$reserved  = sub_account_total_reserved($id);

// Enrolment records
$stmt = db()->prepare("
    SELECT * FROM partner_enrolment_records
    WHERE sub_account_id = :sid
    ORDER BY period_year DESC, period_month DESC, id DESC
    LIMIT 24
");
$stmt->execute([':sid' => $id]);
$enrolments = $stmt->fetchAll();

// Ledger
$stmt = db()->prepare("
    SELECT * FROM partner_financial_transactions
    WHERE sub_account_id = :sid
    ORDER BY created_at DESC, id DESC
    LIMIT 50
");
$stmt->execute([':sid' => $id]);
$ledger = $stmt->fetchAll();

// Pending backfill count: matching enrolment rows that are still null or
// attached to the parent partner instead of this sub-account
$stmt = db()->prepare("
    SELECT COUNT(*) FROM partner_enrolment_records
    WHERE partner_nin_raw = :n
      AND (sub_account_id IS NULL OR sub_account_id <> :sid)
      AND commission_amount >= 0
");
$stmt->execute([':n' => $sub['nin_number'], ':sid' => $id]);
$pendingBackfill = (int) $stmt->fetchColumn();

require PICT_ROOT . '/includes/header-superadmin.php';

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
      &middot; Parent partner
      <a href="<?= e(root_url('/superadmin/partner-view.php')) ?>?id=<?= (int) $sub['partner_id'] ?>">
        <?= e($sub['partner_name']) ?>
      </a>
      (<?= e($sub['partner_code']) ?>)
      &middot; Submitted <?= e(date('d M Y', strtotime($sub['created_at']))) ?>
    </p>
  </div>
    <div class="page-head__actions">
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/sub-accounts.php')) ?>">All Sub-Accounts</a>
    <?php if (in_array($sub['status'], ['ACTIVE','INACTIVE'], true)): ?>
      <a class="btn btn-outline"
         href="<?= e(root_url('/superadmin/sub-account-reassign.php')) ?>?sub_id=<?= (int) $sub['id'] ?>">
        Reassign to Another Partner
      </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($sub['status'] === 'PENDING'): ?>
  <div class="alert alert-info">
    <strong>Pending review.</strong> Approving creates the initial commission rate
    and, unless disabled below, backfills any historical CSV records already imported for this NIN.
  </div>
<?php endif; ?>

<?php if ($sub['status'] === 'ACTIVE' && $pendingBackfill > 0): ?>
  <div class="alert alert-warn" style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
    <div>
      <strong><?= number_format($pendingBackfill) ?></strong>
      matching historical enrolment record<?= $pendingBackfill === 1 ? '' : 's' ?>
      for this NIN are still unattached or credited to the partner's own bucket.
    </div>
    <form method="post" action="<?= e(root_url('/superadmin/sub-account-action.php')) ?>" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="run_backfill">
      <button class="btn btn-warn btn-sm" type="submit"
              onclick="return confirm('Attach <?= $pendingBackfill ?> historical record(s) to this sub-account?');">
        Run Backfill Now
      </button>
    </form>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat stat--purple">
    <div class="stat__label">Available Balance</div>
    <div class="stat__value"><?= e(format_money($balance)) ?></div>
    <div class="stat__sub">Withdrawable by the parent partner</div>
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
  </div>
</div>

<!-- Approve / Reject (PENDING only) -->
<?php if ($sub['status'] === 'PENDING'): ?>
<div class="card mb-3 card--pad-lg">
  <h2>Review Decision</h2>

  <form method="post" action="<?= e(root_url('/superadmin/sub-account-action.php')) ?>" class="mb-3">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="action" value="approve">

    <div class="form-row--3 form-row">
      <div class="form-group">
        <label class="form-label">Commission % <span class="req">*</span></label>
        <input class="form-control" type="number" step="0.01" min="0" max="100"
               name="commission_percentage" value="40.00" required>
        <div class="form-hint">Applied to the gross revenue of this NIN's enrolments.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Effective From <span class="req">*</span></label>
        <input class="form-control" type="date" name="effective_from" value="<?= e(today_date()) ?>" required>
        <div class="form-hint">Historical settlements use the rate in force on each record's period date.</div>
      </div>
      <div class="form-group">
        <label class="form-label">Backfill</label>
        <select class="form-control" name="run_backfill">
          <option value="1" selected>Yes — attach historical records</option>
          <option value="0">No — future imports only</option>
        </select>
      </div>
    </div>

    <button class="btn btn-ok btn-lg" type="submit"
            onclick="return confirm('Approve this sub-account?');">
      Approve Sub-Account
    </button>
  </form>

  <form method="post" action="<?= e(root_url('/superadmin/sub-account-action.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="action" value="reject">
    <div class="form-group">
      <label class="form-label">Rejection Reason</label>
      <input class="form-control" type="text" name="reason" maxlength="500"
             placeholder="Optional — shown to the partner">
    </div>
    <button class="btn btn-danger" type="submit"
            onclick="return confirm('Reject this sub-account?');">
      Reject Sub-Account
    </button>
  </form>
</div>
<?php endif; ?>

<!-- Actions for ACTIVE / INACTIVE -->
<?php if (in_array($sub['status'], ['ACTIVE','INACTIVE'], true)): ?>
<div class="card mb-3 card--pad-lg">
  <h2>Status Actions</h2>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <?php if ($sub['status'] === 'ACTIVE'): ?>
      <form method="post" action="<?= e(root_url('/superadmin/sub-account-action.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="deactivate">
        <input class="form-control" type="text" name="reason" maxlength="500"
               placeholder="Optional reason" style="display:inline-block;width:260px;margin-right:8px">
        <button class="btn btn-outline" type="submit"
                onclick="return confirm('Deactivate this sub-account? It will stop earning from this point forward.');">
          Deactivate
        </button>
      </form>
    <?php else: ?>
      <form method="post" action="<?= e(root_url('/superadmin/sub-account-action.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="reactivate">
        <input class="form-control" type="text" name="reason" maxlength="500"
               placeholder="Optional reason" style="display:inline-block;width:260px;margin-right:8px">
        <button class="btn btn-ok" type="submit"
                onclick="return confirm('Reactivate this sub-account?');">
          Reactivate
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- Edit fields (name + email only; NIN is identity) -->
<div class="card mb-3 card--pad-lg">
  <h2>Edit Details</h2>
  <form method="post" action="<?= e(root_url('/superadmin/sub-account-action.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="action" value="edit">

    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Full Name</label>
        <input class="form-control" type="text" name="full_name" value="<?= e($sub['full_name']) ?>" maxlength="150" required>
      </div>
      <div class="form-group">
        <label class="form-label">Email</label>
        <input class="form-control" type="email" name="email" value="<?= e((string) $sub['email']) ?>" maxlength="190">
      </div>
    </div>

        <div class="form-group">
      <label class="form-label">NIN</label>
      <div style="display:flex;align-items:center;gap:8px">
        <span id="sub-nin-display"
              class="masked"
              data-full="<?= e($sub['nin_number']) ?>"
              data-masked="<?= e(mask_nin($sub['nin_number'])) ?>"
              style="font-family:'SF Mono',Menlo,Consolas,monospace;letter-spacing:.5px;font-size:14.5px;color:var(--ink)">
          <?= e(mask_nin($sub['nin_number'])) ?>
        </span>
        <button type="button"
                class="btn btn-outline btn-sm"
                style="padding:2px 10px;font-size:11.5px"
                id="sub-nin-btn"
                onclick="pictReveal('sub-nin-display', 'sub-nin-btn', 'sub_account_nin', <?= (int) $sub['id'] ?>)">
          Reveal
        </button>
      </div>
      <div class="form-hint">NIN is identity. It cannot be changed after submission.</div>
    </div>

    <button class="btn btn-grape" type="submit">Save Changes</button>
  </form>
</div>

<!-- Profile details -->
<div class="card mb-3">
  <h2>Overview</h2>
  <table class="tbl tbl--compact">
    <tr><th style="width:220px">Status</th><td><?= $badge((string) $sub['status']) ?></td></tr>
    <tr><th>Submitted</th><td><?= e(date('d M Y H:i', strtotime($sub['created_at']))) ?></td></tr>
    <?php if (!empty($sub['approved_at'])): ?>
      <tr><th>Approved</th><td><?= e(date('d M Y H:i', strtotime($sub['approved_at']))) ?>
        <?php if (!empty($sub['approver_name'])): ?>by <?= e($sub['approver_name']) ?><?php endif; ?></td></tr>
    <?php endif; ?>
    <?php if (!empty($sub['status_changed_at']) && $sub['status_changed_at'] !== $sub['approved_at']): ?>
      <tr><th>Last Status Change</th><td><?= e(date('d M Y H:i', strtotime($sub['status_changed_at']))) ?></td></tr>
    <?php endif; ?>
    <?php if (!empty($sub['status_reason'])): ?>
      <tr><th>Status Note</th><td><?= e($sub['status_reason']) ?></td></tr>
    <?php endif; ?>
  </table>
</div>

<!-- Rate history -->
<div class="card mb-3">
  <h2>Commission Rate History</h2>
  <?php if (empty($rates)): ?>
    <div class="empty"><p>No rate yet. Rate is created on approval.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr><th>Rate</th><th>Effective From</th><th>Effective To</th><th>Status</th><th>Set By</th><th>Notes</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($rates as $r): ?>
            <tr>
              <td><strong><?= e(number_format((float) $r['commission_percentage'], 2)) ?>%</strong></td>
              <td><?= e($r['effective_from']) ?></td>
              <td><?= e((string) $r['effective_to']) ?: '<span class="muted">Open</span>' ?></td>
              <td><?= $badge((string) $r['status']) ?></td>
              <td><?= e((string) $r['creator_name']) ?: '<span class="muted">—</span>' ?></td>
              <td><?= e((string) $r['notes']) ?></td>
              <td class="text-right">
                <?php if ($r['status'] === 'ACTIVE'): ?>
                  <form method="post" action="<?= e(root_url('/superadmin/sub-account-action.php')) ?>" style="display:inline" onsubmit="return confirm('Supersede this rate?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="supersede_rate">
                    <input type="hidden" name="rate_id" value="<?= (int) $r['id'] ?>">
                    <button class="btn btn-outline btn-sm" type="submit">Supersede</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if (in_array($sub['status'], ['ACTIVE','INACTIVE'], true)): ?>
    <h3 style="margin-top:22px">Add New Rate</h3>
    <form method="post" action="<?= e(root_url('/superadmin/sub-account-action.php')) ?>" class="form-row--3 form-row">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="add_rate">
      <div class="form-group">
        <label class="form-label">Commission %</label>
        <input class="form-control" type="number" step="0.01" min="0" max="100" name="commission_percentage" value="40.00" required>
      </div>
      <div class="form-group">
        <label class="form-label">Effective From</label>
        <input class="form-control" type="date" name="effective_from" value="<?= e(today_date()) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Effective To (optional)</label>
        <input class="form-control" type="date" name="effective_to">
      </div>
      <div class="form-group" style="grid-column:1 / -1">
        <label class="form-label">Notes</label>
        <input class="form-control" type="text" name="rate_notes" maxlength="255">
      </div>
      <div class="form-group">
        <button class="btn btn-grape" type="submit">Add Rate</button>
      </div>
    </form>
  <?php endif; ?>
</div>

<!-- Enrolment records -->
<div class="card mb-3">
  <h2>Enrolment Records</h2>
  <?php if (empty($enrolments)): ?>
    <div class="empty"><p>No enrolment records attached to this sub-account yet.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Period</th>
            <th>Station ID</th>
            <th>Station Name</th>
            <th class="num">NIN</th>
            <th class="num">Rate</th>
            <th class="num">Commission</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($enrolments as $e): ?>
            <tr>
              <td><strong><?= e(format_period((int) $e['period_year'], (int) $e['period_month'])) ?></strong></td>
              <td><?= !empty($e['station_id_raw']) ? '<span class="masked">' . e($e['station_id_raw']) . '</span>' : '<span class="muted">—</span>' ?></td>
              <td><?= !empty($e['station_name_raw']) ? e($e['station_name_raw']) : '<span class="muted">—</span>' ?></td>
              <td class="num"><?= number_format((int) $e['total_nin_generated']) ?></td>
              <td class="num"><?= e(number_format((float) $e['commission_percentage'], 2)) ?>%</td>
              <td class="num"><strong><?= e(format_money((float) $e['commission_amount'])) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- Ledger -->
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

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>