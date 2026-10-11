<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/financial.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$sa = superadmin_require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("
    SELECT p.*, s.name AS state_name, l.name AS lga_name,
           apr.full_name AS approved_by_name, scb.full_name AS status_by_name
    FROM partners p
    LEFT JOIN states s ON s.id = p.state_id
    LEFT JOIN lgas l ON l.id = p.lga_id
    LEFT JOIN super_admins apr ON apr.id = p.approved_by_id
    LEFT JOIN super_admins scb ON scb.id = p.status_changed_by_id
    WHERE p.id = :id LIMIT 1
");
$stmt->execute([':id' => $id]);
$p = $stmt->fetch();
if (!$p) { flash('error', 'Partner not found.'); redirect('/superadmin/partners.php'); }

$page_title = $p['full_name'];
$active_nav = 'partners';

// Related data
$stmt = db()->prepare("SELECT id, station_code, station_name, state_id, lga_id, status FROM stations WHERE partner_id = :pid ORDER BY station_name");
$stmt->execute([':pid' => $id]);
$stations = $stmt->fetchAll();

$stmt = db()->prepare("SELECT id, bank_name, account_name, account_number, is_primary, status FROM partner_bank_accounts WHERE partner_id = :pid ORDER BY is_primary DESC, id DESC");
$stmt->execute([':pid' => $id]);
$banks = $stmt->fetchAll();

$stmt = db()->prepare("SELECT * FROM partner_commission_rates WHERE partner_id = :pid ORDER BY effective_from DESC");
$stmt->execute([':pid' => $id]);
$rates = $stmt->fetchAll();

$stmt = db()->prepare("
    SELECT period_year, period_month,
           SUM(total_enrolments) AS total_enrolments,
           SUM(total_nin_generated) AS total_nin,
           SUM(ernew_nin) AS ernew, SUM(crnew_nin) AS crnew,
           SUM(female_count) AS female, SUM(male_count) AS male,
           SUM(commission_amount) AS commission
    FROM partner_enrolment_records
    WHERE partner_id = :pid
    GROUP BY period_year, period_month
    ORDER BY period_year DESC, period_month DESC
    LIMIT 12
");
$stmt->execute([':pid' => $id]);
$periods = $stmt->fetchAll();

$stmt = db()->prepare("
    SELECT transaction_id, type, amount, description, created_at, period_year, period_month
    FROM partner_financial_transactions WHERE partner_id = :pid
    ORDER BY created_at DESC LIMIT 15
");
$stmt->execute([':pid' => $id]);
$ledger = $stmt->fetchAll();

$stmt = db()->prepare("
    SELECT id, withdrawal_reference, amount, status, bank_name_snapshot, created_at
    FROM withdrawals WHERE partner_id = :pid
    ORDER BY created_at DESC LIMIT 10
");
$stmt->execute([':pid' => $id]);
$withdrawals = $stmt->fetchAll();

$balance    = partner_available_balance($id);
$commission = partner_total_commission($id);
$withdrawn  = partner_total_withdrawn($id);
$reserved   = partner_total_reserved($id);

require PICT_ROOT . '/includes/header-superadmin.php';

$badge = function (string $s): string {
    $cls = match ($s) {
        'PENDING'   => 'badge--pending', 'ACTIVE' => 'badge--active',
        'INACTIVE'  => 'badge--inactive', 'SUSPENDED' => 'badge--suspended',
        'REJECTED'  => 'badge--rejected', default => 'badge--inactive',
    };
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
};
?>

<div class="page-head">
  <div>
    <h1><?= e($p['full_name']) ?> <?= $badge($p['status']) ?></h1>
    <p>Partner code <span class="masked"><?= e($p['partner_code']) ?></span> &middot; <?= e((string) $p['email']) ?></p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="./partners.php">Back</a>
    <a class="btn btn-outline" href="./partner-edit.php?id=<?= (int) $p['id'] ?>">Edit</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--purple"><div class="stat__label">Available Balance</div><div class="stat__value"><?= e(format_money($balance)) ?></div>
    <div class="stat__sub">Reserved: <?= e(format_money($reserved)) ?></div></div>
  <div class="stat stat--ok"><div class="stat__label">Commission Earned</div><div class="stat__value"><?= e(format_money($commission)) ?></div></div>
  <div class="stat"><div class="stat__label">Withdrawn</div><div class="stat__value"><?= e(format_money($withdrawn)) ?></div></div>
</div>

<div class="card mb-3">
  <h2>Action</h2>
  <form method="post" action="./partner-action.php" class="flex-between" style="gap:10px;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="partner_id" value="<?= (int) $p['id'] ?>">
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:end">
      <div class="form-group" style="margin:0;min-width:180px">
        <label class="form-label">Action</label>
        <select class="form-control" name="action" required>
          <option value="">— Select —</option>
          <?php if ($p['status'] === 'PENDING'): ?>
            <option value="approve">Approve</option>
            <option value="reject">Reject</option>
          <?php elseif ($p['status'] === 'ACTIVE'): ?>
            <option value="suspend">Suspend</option>
            <option value="deactivate">Deactivate</option>
          <?php elseif (in_array($p['status'], ['SUSPENDED','INACTIVE'], true)): ?>
            <option value="reactivate">Reactivate</option>
          <?php elseif ($p['status'] === 'REJECTED'): ?>
            <option value="approve">Approve (override)</option>
          <?php endif; ?>
          <option value="reset_password">Reset Password</option>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:220px">
        <label class="form-label">Reason / Comment (optional)</label>
        <input class="form-control" type="text" name="reason" maxlength="500">
      </div>
      <button class="btn btn-grape" type="submit" onclick="return confirm('Confirm this action?');">Apply</button>
    </div>
  </form>
</div>

<?php
$hasPhoto = !empty($p['profile_image_path']);
$photoUrl = root_url('/superadmin/partner-photo.php') . '?partner_id=' . (int) $p['id']
          . '&v=' . urlencode(basename((string) $p['profile_image_path']));
$pInitials = strtoupper(
    mb_substr($p['full_name'], 0, 1)
    . (mb_strpos($p['full_name'], ' ') !== false
        ? mb_substr($p['full_name'], mb_strpos($p['full_name'], ' ') + 1, 1)
        : '')
);
?>

<div class="card mb-3" style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">
  <div style="flex-shrink:0">
    <div style="width:180px;height:180px;border-radius:14px;overflow:hidden;background:#eef2f8;border:1px solid var(--line);display:flex;align-items:center;justify-content:center">
      <?php if ($hasPhoto): ?>
        <img src="<?= e($photoUrl) ?>" alt="Partner photo"
             style="width:100%;height:100%;object-fit:cover;display:block"
             onerror="this.style.display='none';this.parentNode.innerHTML='<span style=&quot;font-size:60px;font-weight:700;color:#6b7280&quot;><?= e($pInitials) ?></span>';">
      <?php else: ?>
        <span style="font-size:60px;font-weight:700;color:#6b7280"><?= e($pInitials) ?></span>
      <?php endif; ?>
    </div>
    <?php if (!$hasPhoto): ?>
      <p class="muted" style="margin:8px 0 0;font-size:12.5px;text-align:center">No photo on file</p>
    <?php endif; ?>
    <p style="margin:8px 0 0;font-size:12.5px;text-align:center">
      <a href="<?= e($photoUrl) ?>" target="_blank" rel="noopener" style="font-size:12.5px">Open full size</a>
    </p>
  </div>

  <div style="flex:1;min-width:320px">
    <h2 style="margin-top:0">Profile</h2>
    <table class="tbl tbl--compact">
      <tr><th style="width:220px">Full Name</th><td><?= e($p['full_name']) ?></td></tr>
      <tr><th>Email</th><td><?= e($p['email']) ?></td></tr>
      <tr><th>Phone</th><td><?= e($p['phone']) ?></td></tr>
            <tr>
        <th>NIN</th>
        <td>
          <span id="nin-display"
                class="masked"
                data-full="<?= e($p['nin_number']) ?>"
                data-masked="<?= e(mask_nin($p['nin_number'])) ?>">
            <?= e(mask_nin($p['nin_number'])) ?>
          </span>
          <button type="button"
                  class="btn btn-outline btn-sm"
                  style="padding:2px 8px;font-size:11px;margin-left:6px"
                  id="nin-reveal-btn"
                  onclick="pictRevealNin(<?= (int) $p['id'] ?>)">
            Reveal
          </button>
        </td>
      </tr>
      <tr><th>Partner Type</th><td><?= e($p['partner_type']) ?><?= $p['business_name'] ? ' — ' . e($p['business_name']) : '' ?></td></tr>
      <tr><th>Address</th><td><?= e((string) $p['address']) ?: '<span class="muted">—</span>' ?></td></tr>
      <tr><th>State / LGA</th><td><?= e((string) $p['state_name']) ?> / <?= e((string) $p['lga_name']) ?></td></tr>
      <tr><th>Joined</th><td><?= e(date('d M Y H:i', strtotime($p['created_at']))) ?></td></tr>
      <?php if ($p['approved_at']): ?><tr><th>Approved</th><td><?= e(date('d M Y H:i', strtotime($p['approved_at']))) ?> by <?= e((string) $p['approved_by_name']) ?></td></tr><?php endif; ?>
      <?php if ($p['status_reason']): ?><tr><th>Status Note</th><td><?= e($p['status_reason']) ?></td></tr><?php endif; ?>
    </table>
  </div>
</div>

<div class="card mb-3">
  <h2>Commission Rates</h2>
  <?php if (empty($rates)): ?>
    <div class="alert alert-warn">No commission rate is configured for this partner. They cannot be credited until one exists.</div>
    <a class="btn btn-grape btn-sm" href="commission-rates.php?partner_id=<?= (int) $p['id'] ?>">Set Rate</a>
  <?php else: ?>
    <table class="tbl tbl--compact">
      <thead><tr><th>Rate</th><th>Effective From</th><th>Effective To</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($rates as $r): ?>
          <tr>
            <td><strong><?= e(number_format((float) $r['commission_percentage'], 2)) ?>%</strong></td>
            <td><?= e($r['effective_from']) ?></td>
            <td><?= e((string) $r['effective_to']) ?: '<span class="muted">Open</span>' ?></td>
            <td><span class="badge badge--<?= strtolower($r['status']) ?>"><?= e($r['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="mt-2"><a class="btn btn-outline btn-sm" href="commission-rates.php?partner_id=<?= (int) $p['id'] ?>">Manage Rates</a></p>
  <?php endif; ?>
</div>


<?php
$stmt = db()->prepare("
    SELECT * FROM partner_sub_accounts
    WHERE partner_id = :pid
    ORDER BY
      CASE status
        WHEN 'PENDING'  THEN 1
        WHEN 'ACTIVE'   THEN 2
        WHEN 'INACTIVE' THEN 3
        WHEN 'REJECTED' THEN 4
      END,
      full_name ASC
");
$stmt->execute([':pid' => $id]);
$partnerSubAccounts = $stmt->fetchAll();
?>
<div class="card mb-3">
  <div class="flex-between mb-2">
    <h2 style="margin:0">Sub-Accounts</h2>
    <a class="btn btn-outline btn-sm" href="<?= e(root_url('/superadmin/sub-accounts.php')) ?>?partner=<?= (int) $p['id'] ?>">
      View in Sub-Accounts List
    </a>
  </div>

  <?php if (empty($partnerSubAccounts)): ?>
    <div class="empty">
      <p>No sub-accounts registered under this partner.</p>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Name</th>
            <th>NIN</th>
            <th>Status</th>
            <th class="num">Rate</th>
            <th class="num">Balance</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($partnerSubAccounts as $sa):
              $saId = (int) $sa['id'];
              $saBal = sub_account_balance($saId);
              $saRate = sub_account_current_rate($saId);
          ?>
            <tr>
              <td><strong><?= e($sa['full_name']) ?></strong></td>
                            <td>
                <span id="pv-sub-nin-<?= (int) $sa['id'] ?>"
                      class="masked"
                      data-full="<?= e($sa['nin_number']) ?>"
                      data-masked="<?= e(mask_nin($sa['nin_number'])) ?>">
                  <?= e(mask_nin($sa['nin_number'])) ?>
                </span>
                <button type="button"
                        class="btn btn-outline btn-sm"
                        style="padding:2px 8px;font-size:11px;margin-left:6px"
                        id="pv-sub-nin-btn-<?= (int) $sa['id'] ?>"
                        onclick="pictReveal('pv-sub-nin-<?= (int) $sa['id'] ?>', 'pv-sub-nin-btn-<?= (int) $sa['id'] ?>', 'sub_account_nin', <?= (int) $sa['id'] ?>)">
                  Reveal
                </button>
              </td>
              <td>
                <span class="badge badge--<?= match($sa['status']) {
                  'ACTIVE' => 'active',
                  'PENDING' => 'pending',
                  'INACTIVE' => 'inactive',
                  'REJECTED' => 'rejected',
                  default => 'inactive'
                } ?>"><?= e($sa['status']) ?></span>
              </td>
              <td class="num"><?= $saRate ? e(number_format((float) $saRate['commission_percentage'], 2)) . '%' : '<span class="muted">—</span>' ?></td>
              <td class="num"><?= e(format_money($saBal)) ?></td>
              <td class="text-right">
                <a class="btn btn-outline btn-sm" href="<?= e(root_url('/superadmin/sub-account-view.php')) ?>?id=<?= $saId ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>


<div class="card mb-3">
  <h2>Stations</h2>
  <?php if (empty($stations)): ?><div class="empty"><p>No stations registered.</p></div>
  <?php else: ?>
    <table class="tbl tbl--compact">
      <thead><tr><th>Code</th><th>Name</th><th>Status</th></tr></thead>
      <tbody>
        <?php foreach ($stations as $st): ?>
          <tr>
            <td><span class="masked"><?= e($st['station_code']) ?></span></td>
            <td><?= e($st['station_name']) ?></td>
            <td><span class="badge badge--<?= strtolower($st['status']) ?>"><?= e($st['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card mb-3">
  <h2>Bank Accounts</h2>
  <?php if (empty($banks)): ?>
    <div class="empty"><p>No bank accounts on file.</p></div>
  <?php else: ?>
    <table class="tbl tbl--compact">
      <thead>
        <tr>
          <th>Bank</th>
          <th>Account Name</th>
          <th>Account Number</th>
          <th>Primary</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($banks as $b): ?>
          <?php
            $bankId = (int) $b['id'];
            $masked = mask_account($b['account_number']);
          ?>
          <tr>
            <td><?= e($b['bank_name']) ?></td>
            <td><?= e($b['account_name']) ?></td>
            <td>
                           <span id="acct-<?= $bankId ?>" class="masked"
                    data-full="<?= e($b['account_number']) ?>"
                    data-masked="<?= e($masked) ?>">
                <?= e($masked) ?>
              </span>
              <button type="button"
                      class="btn btn-outline btn-sm"
                      style="padding:2px 8px;font-size:11px;margin-left:6px"
                      id="reveal-btn-<?= $bankId ?>"
                      onclick="pictRevealAcct(<?= $bankId ?>)">
                Reveal
              </button>
            </td>
            <td><?= $b['is_primary'] ? '<span class="badge badge--active">Primary</span>' : '<span class="muted">—</span>' ?></td>
            <td><span class="badge badge--<?= strtolower($b['status']) ?>"><?= e($b['status']) ?></span></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

   
  <?php endif; ?>

</div>

<div class="card mb-3">
  <h2>Enrolment by Period (last 12)</h2>
  <?php if (empty($periods)): ?><div class="empty"><p>No enrolment data yet.</p></div>
  <?php else: ?>
    <table class="tbl tbl--compact">
      <thead><tr><th>Period</th><th class="num">Enrolments</th><th class="num">NIN</th><th class="num">ERNEW</th><th class="num">CRNEW</th><th class="num">Female</th><th class="num">Male</th><th class="num">Commission</th></tr></thead>
      <tbody>
        <?php foreach ($periods as $pr): ?>
          <tr>
            <td><?= e(format_period((int) $pr['period_year'], (int) $pr['period_month'])) ?></td>
            <td class="num"><?= number_format((int) $pr['total_enrolments']) ?></td>
            <td class="num"><?= number_format((int) $pr['total_nin']) ?></td>
            <td class="num"><?= number_format((int) $pr['ernew']) ?></td>
            <td class="num"><?= number_format((int) $pr['crnew']) ?></td>
            <td class="num"><?= number_format((int) $pr['female']) ?></td>
            <td class="num"><?= number_format((int) $pr['male']) ?></td>
            <td class="num"><?= e(format_money((float) $pr['commission'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card mb-3">
  <h2>Recent Ledger</h2>
  <?php if (empty($ledger)): ?><div class="empty"><p>No transactions.</p></div>
  <?php else: ?>
    <table class="tbl tbl--compact">
      <thead><tr><th>Date</th><th>Transaction ID</th><th>Description</th><th>Type</th><th class="num">Amount</th></tr></thead>
      <tbody>
        <?php foreach ($ledger as $l): ?>
          <tr>
            <td><?= e(date('d M Y', strtotime($l['created_at']))) ?></td>
            <td><span class="masked"><?= e($l['transaction_id']) ?></span></td>
            <td><?= e((string) $l['description']) ?></td>
            <td><span class="badge badge--inactive"><?= e(str_replace('_',' ', $l['type'])) ?></span></td>
            <td class="num"><?= e(format_money((float) $l['amount'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Recent Withdrawals</h2>
  <?php if (empty($withdrawals)): ?><div class="empty"><p>No withdrawals.</p></div>
  <?php else: ?>
    <table class="tbl tbl--compact">
      <thead><tr><th>Reference</th><th class="num">Amount</th><th>Status</th><th>Bank</th><th>Requested</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($withdrawals as $w): ?>
          <tr>
            <td><span class="masked"><?= e($w['withdrawal_reference']) ?></span></td>
            <td class="num"><?= e(format_money((float) $w['amount'])) ?></td>
            <td><span class="badge badge--<?= strtolower($w['status']) ?>"><?= e($w['status']) ?></span></td>
            <td><?= e($w['bank_name_snapshot']) ?></td>
            <td><?= e(date('d M Y', strtotime($w['created_at']))) ?></td>
            <td class="text-right"><a class="btn btn-outline btn-sm" href="withdrawals.php?view=<?= (int) $w['id'] ?>">View</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
 <script>
   function pictRevealAcct(id) {
  var span = document.getElementById('acct-' + id);
  var btn  = document.getElementById('reveal-btn-' + id);
  if (!span || !btn) return;

  var full   = span.getAttribute('data-full')   || '';
  var masked = span.getAttribute('data-masked') || '';
  var shown  = span.getAttribute('data-revealed') === '1';

  if (!shown) {
    span.textContent = full;
    span.setAttribute('data-revealed', '1');
    btn.textContent = 'Hide';

    fetch(<?= json_encode(root_url('/superadmin/audit-reveal.php')) ?>, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': <?= json_encode(csrf_token()) ?>
      },
      body: 'entity=partner_bank_account&id=' + encodeURIComponent(id)
    }).catch(function () { /* silent */ });
  } else {
    span.textContent = masked;
    span.removeAttribute('data-revealed');
    btn.textContent = 'Reveal';
  }
}
    function pictRevealNin(partnerId) {
      var span = document.getElementById('nin-display');
      var btn  = document.getElementById('nin-reveal-btn');
      if (!span || !btn) return;

      var full   = span.getAttribute('data-full')   || '';
      var masked = span.getAttribute('data-masked') || '';
      var shown  = span.getAttribute('data-revealed') === '1';

      if (!shown) {
        span.textContent = full;
        span.setAttribute('data-revealed', '1');
        btn.textContent = 'Hide';

        // Log the reveal; the UI does not wait for the response.
        fetch(<?= json_encode(root_url('/superadmin/audit-reveal.php')) ?>, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': <?= json_encode(csrf_token()) ?>
          },
          body: 'entity=partner_nin&id=' + encodeURIComponent(partnerId)
        }).catch(function () { /* silent */ });
      } else {
        span.textContent = masked;
        span.removeAttribute('data-revealed');
        btn.textContent = 'Reveal';
      }
    }
    </script>
<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>