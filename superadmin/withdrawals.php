<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/financial.php';
$sa = superadmin_require_login();

$page_title = 'Withdrawals';
$active_nav = 'withdrawals';
$errors = [];

if (is_post()) {
    csrf_require();
    $action  = (string) ($_POST['action'] ?? '');
    $wid     = (int) ($_POST['id'] ?? 0);
    $comment = trim((string) ($_POST['comment'] ?? ''));

        $stmt = db()->prepare("
        SELECT w.*, p.full_name, p.partner_code,
               s.full_name AS sub_account_name
        FROM withdrawals w
        JOIN partners p ON p.id = w.partner_id
        LEFT JOIN partner_sub_accounts s ON s.id = w.sub_account_id
        WHERE w.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $wid]);
    $w = $stmt->fetch();
    if (!$w) { flash('error', 'Withdrawal not found.'); redirect('/superadmin/withdrawals.php'); }

    // All UPDATE statements below use DISTINCT placeholder names because
    // PDO::ATTR_EMULATE_PREPARES = false does not allow a placeholder
    // to be reused within a single statement.
    $now = now_dt();

    try {
        db()->beginTransaction();

        if ($action === 'approve') {
            if ($w['status'] !== 'PENDING') {
                throw new RuntimeException('Only pending withdrawals can be approved.');
            }
            db()->prepare("
                UPDATE withdrawals SET
                    status                  = 'APPROVED',
                    admin_comment           = :admin_comment,
                    approved_by_super_admin_id = :approved_by,
                    approved_at             = :approved_at,
                    updated_at              = :updated_at
                WHERE id = :id
            ")->execute([
                ':admin_comment' => $comment !== '' ? $comment : null,
                ':approved_by'   => (int) $sa['id'],
                ':approved_at'   => $now,
                ':updated_at'    => $now,
                ':id'            => $wid,
            ]);

            db()->prepare("
                INSERT INTO withdrawal_status_history
                  (withdrawal_id, from_status, to_status, changed_by_role, changed_by_id, comment, created_at)
                VALUES
                  (:wid, 'PENDING', 'APPROVED', 'SUPER_ADMIN', :by, :comment, :created_at)
            ")->execute([
                ':wid'        => $wid,
                ':by'         => (int) $sa['id'],
                ':comment'    => $comment !== '' ? $comment : null,
                ':created_at' => $now,
            ]);

            notify('PARTNER', (int) $w['partner_id'], 'WITHDRAWAL_APPROVED', 'Withdrawal approved',
                'Your withdrawal of ' . format_money((float) $w['amount']) . ' has been approved and is being processed.',
                '/partner/withdrawals.php?view=' . $wid);

            audit_log('WITHDRAWAL_APPROVED', 'withdrawals', $wid, $w['withdrawal_reference']);
            flash('success', 'Withdrawal approved.');
        }

        elseif ($action === 'reject') {
            if (!in_array($w['status'], ['PENDING','APPROVED','PROCESSING'], true)) {
                throw new RuntimeException('Cannot reject a withdrawal in this state.');
            }

            $lock = db()->prepare("SELECT id FROM partners WHERE id = :pid FOR UPDATE");
            $lock->execute([':pid' => (int) $w['partner_id']]);

            db()->prepare("
                UPDATE withdrawals SET
                    status                     = 'REJECTED',
                    rejection_reason           = :reason,
                    rejected_by_super_admin_id = :rejected_by,
                    rejected_at                = :rejected_at,
                    updated_at                 = :updated_at
                WHERE id = :id
            ")->execute([
                ':reason'      => $comment !== '' ? $comment : null,
                ':rejected_by' => (int) $sa['id'],
                ':rejected_at' => $now,
                ':updated_at'  => $now,
                ':id'          => $wid,
            ]);

            db()->prepare("
                INSERT INTO withdrawal_status_history
                  (withdrawal_id, from_status, to_status, changed_by_role, changed_by_id, comment, created_at)
                VALUES
                  (:wid, :from_status, 'REJECTED', 'SUPER_ADMIN', :by, :comment, :created_at)
            ")->execute([
                ':wid'         => $wid,
                ':from_status' => (string) $w['status'],
                ':by'          => (int) $sa['id'],
                ':comment'     => $comment !== '' ? $comment : null,
                ':created_at'  => $now,
            ]);

            // Release the reservation: positive amount restores available balance
            ledger_record(
                (int) $w['partner_id'],
                'WITHDRAWAL_REVERSED',
                (float) $w['amount'],
                (string) $w['withdrawal_reference'],
                'Withdrawal rejected — reservation released',
                [],
                ['withdrawal_id' => $wid],
                'SUPER_ADMIN',
                (int) $sa['id']
            );

            notify('PARTNER', (int) $w['partner_id'], 'WITHDRAWAL_REJECTED', 'Withdrawal rejected',
                $comment !== '' ? $comment : 'Your withdrawal request was rejected.',
                '/partner/withdrawals.php?view=' . $wid);

            audit_log('WITHDRAWAL_REJECTED', 'withdrawals', $wid, $comment);
            flash('success', 'Withdrawal rejected and funds returned to the partner balance.');
        }

        elseif ($action === 'mark_processing') {
            if ($w['status'] !== 'APPROVED') {
                throw new RuntimeException('Only approved withdrawals can move to processing.');
            }

            db()->prepare("
                UPDATE withdrawals SET
                    status     = 'PROCESSING',
                    updated_at = :updated_at
                WHERE id = :id
            ")->execute([
                ':updated_at' => $now,
                ':id'         => $wid,
            ]);

            db()->prepare("
                INSERT INTO withdrawal_status_history
                  (withdrawal_id, from_status, to_status, changed_by_role, changed_by_id, comment, created_at)
                VALUES
                  (:wid, 'APPROVED', 'PROCESSING', 'SUPER_ADMIN', :by, :comment, :created_at)
            ")->execute([
                ':wid'        => $wid,
                ':by'         => (int) $sa['id'],
                ':comment'    => $comment !== '' ? $comment : null,
                ':created_at' => $now,
            ]);

            audit_log('WITHDRAWAL_PROCESSING', 'withdrawals', $wid, '');
            flash('success', 'Marked as processing.');
        }

        elseif ($action === 'mark_paid') {
            if (!in_array($w['status'], ['APPROVED','PROCESSING'], true)) {
                throw new RuntimeException('Cannot mark paid in this state.');
            }
            $paymentRef = trim((string) ($_POST['payment_reference'] ?? ''));

            $lock = db()->prepare("SELECT id FROM partners WHERE id = :pid FOR UPDATE");
            $lock->execute([':pid' => (int) $w['partner_id']]);

            db()->prepare("
                UPDATE withdrawals SET
                    status                 = 'PAID',
                    paid_by_super_admin_id = :paid_by,
                    paid_at                = :paid_at,
                    payment_reference      = :payment_reference,
                    admin_comment          = :admin_comment,
                    updated_at             = :updated_at
                WHERE id = :id
            ")->execute([
                ':paid_by'           => (int) $sa['id'],
                ':paid_at'           => $now,
                ':payment_reference' => $paymentRef !== '' ? $paymentRef : null,
                ':admin_comment'     => $comment !== '' ? $comment : null,
                ':updated_at'        => $now,
                ':id'                => $wid,
            ]);

            db()->prepare("
                INSERT INTO withdrawal_status_history
                  (withdrawal_id, from_status, to_status, changed_by_role, changed_by_id, comment, created_at)
                VALUES
                  (:wid, :from_status, 'PAID', 'SUPER_ADMIN', :by, :comment, :created_at)
            ")->execute([
                ':wid'         => $wid,
                ':from_status' => (string) $w['status'],
                ':by'          => (int) $sa['id'],
                ':comment'     => $comment !== '' ? $comment : null,
                ':created_at'  => $now,
            ]);

            // Two ledger entries: cash out, and release of the earlier reservation.
            ledger_record(
                (int) $w['partner_id'],
                'WITHDRAWAL_PAID',
                -(float) $w['amount'],
                (string) $w['withdrawal_reference'],
                'Withdrawal paid' . ($paymentRef !== '' ? ' — ref ' . $paymentRef : ''),
                [],
                ['withdrawal_id' => $wid],
                'SUPER_ADMIN',
                (int) $sa['id']
            );
            ledger_record(
                (int) $w['partner_id'],
                'WITHDRAWAL_REVERSED',
                (float) $w['amount'],
                (string) $w['withdrawal_reference'],
                'Reservation released on payment',
                [],
                ['withdrawal_id' => $wid],
                'SUPER_ADMIN',
                (int) $sa['id']
            );

            notify('PARTNER', (int) $w['partner_id'], 'WITHDRAWAL_PAID', 'Withdrawal paid',
                format_money((float) $w['amount']) . ' has been sent to your bank account.',
                '/partner/withdrawals.php?view=' . $wid);

            audit_log('WITHDRAWAL_PAID', 'withdrawals', $wid, 'Paid ' . format_money((float) $w['amount']));
            flash('success', 'Withdrawal marked as paid. Ledger updated.');
        }

        else {
            throw new RuntimeException('Unknown action.');
        }

        db()->commit();
    } catch (RuntimeException $e) {
        if (db()->inTransaction()) db()->rollBack();
        flash('error', $e->getMessage());
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[withdrawals] ' . $e->getMessage());
        flash('error', 'Action failed. Please try again.');
    }

    redirect('/superadmin/withdrawals.php?view=' . $wid);
}

// ----- GET: list and detail -----
$fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
$fSort   = strtolower(trim((string) ($_GET['sort'] ?? 'newest')));
$viewId  = (int) ($_GET['view'] ?? 0);

// Whitelist of allowed sort orders. Anything else falls back to 'newest'.
$allowedSorts = [
    'newest'          => ['label' => 'Newest first',              'sql' => 'w.created_at DESC, w.id DESC'],
    'oldest'          => ['label' => 'Oldest first',              'sql' => 'w.created_at ASC, w.id ASC'],
    'partner_asc'     => ['label' => 'Partner A → Z',             'sql' => 'p.full_name ASC, w.created_at DESC'],
    'partner_desc'    => ['label' => 'Partner Z → A',             'sql' => 'p.full_name DESC, w.created_at DESC'],
    'partner_status'  => ['label' => 'Partner A → Z (pending first)', 'sql' => "FIELD(w.status,'PENDING','APPROVED','PROCESSING','PAID','REJECTED','REVERSED','CANCELLED') ASC, p.full_name ASC, w.created_at DESC"],
    'amount_high'     => ['label' => 'Amount: highest → lowest',  'sql' => 'w.amount DESC, w.created_at DESC'],
    'amount_low'      => ['label' => 'Amount: lowest → highest',  'sql' => 'w.amount ASC, w.created_at DESC'],
];

if (!isset($allowedSorts[$fSort])) {
    $fSort = 'newest';
}
$orderSql = $allowedSorts[$fSort]['sql'];

$where = ['1=1']; $params = [];
if (in_array($fStatus, ['PENDING','APPROVED','PROCESSING','PAID','REJECTED','REVERSED','CANCELLED'], true)) {
    $where[] = 'w.status = :st';
    $params[':st'] = $fStatus;
}
$whereSql = implode(' AND ', $where);

$stmt = db()->prepare("
    SELECT w.*, p.full_name, p.partner_code,
           s.full_name AS sub_account_name
    FROM withdrawals w
    JOIN partners p ON p.id = w.partner_id
    LEFT JOIN partner_sub_accounts s ON s.id = w.sub_account_id
    WHERE $whereSql
    ORDER BY $orderSql
    LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$detail = null; $history = [];
if ($viewId > 0) {
    $stmt = db()->prepare("
        SELECT w.*, p.full_name, p.partner_code
        FROM withdrawals w
        JOIN partners p ON p.id = w.partner_id
        WHERE w.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $viewId]);
    $detail = $stmt->fetch();
    if ($detail) {
        $stmt = db()->prepare("SELECT * FROM withdrawal_status_history WHERE withdrawal_id = :id ORDER BY created_at ASC, id ASC");
        $stmt->execute([':id' => $viewId]);
        $history = $stmt->fetchAll();
    }
}

require PICT_ROOT . '/includes/header-superadmin.php';

$badge = function (string $s): string {
    $cls = match ($s) {
        'PENDING'    => 'badge--pending',
        'APPROVED'   => 'badge--approved',
        'PROCESSING' => 'badge--submitted',
        'PAID'       => 'badge--active',
        'REJECTED'   => 'badge--rejected',
        'REVERSED'   => 'badge--inactive',
        'CANCELLED'  => 'badge--inactive',
        default      => 'badge--pending',
    };
    return '<span class="badge ' . $cls . '">' . e(str_replace('_', ' ', $s)) . '</span>';
};
?>

<div class="page-head"><div><h1>Withdrawals</h1><p>Process partner withdrawal requests.</p></div></div>

<?php if ($detail): $d = $detail; ?>
<div class="card mb-3">
  <div class="flex-between mb-2">
    <h2 style="margin:0">Withdrawal Detail — <span class="masked"><?= e($d['withdrawal_reference']) ?></span></h2>
    <a class="btn btn-outline btn-sm" href="withdrawals.php">Close</a>
  </div>

  <table class="tbl tbl--compact mb-3">
        <tr><th style="width:220px">Partner</th><td><?= e($d['full_name']) ?> <span class="muted">(<?= e($d['partner_code']) ?>)</span></td></tr>
    <tr>
      <th>Source Bucket</th>
      <td>
        <?php if (!empty($d['sub_account_id'])): ?>
          <span class="badge" style="background:#e0e7ff;color:#4338ca">Sub</span>
          <?= e((string) ($d['sub_account_name'] ?? ('Sub-account #' . (int) $d['sub_account_id']))) ?>
        <?php else: ?>
          <span class="badge" style="background:#dbeafe;color:#1e40af">Own</span>
          Partner's own NIN
        <?php endif; ?>
      </td>
    </tr>
    <tr><th>Amount</th><td><strong><?= e(format_money((float) $d['amount'])) ?></strong></td></tr>
    <tr><th>Status</th><td><?= $badge((string) $d['status']) ?></td></tr>
    <tr><th>Bank</th><td><?= e($d['bank_name_snapshot']) ?></td></tr>
    <tr><th>Account Name</th><td><?= e($d['account_name_snapshot']) ?></td></tr>
       <tr>
      <th>Account Number</th>
      <td>
        <span id="wd-acct" class="masked"
              data-full="<?= e($d['account_number_snapshot']) ?>"
              data-masked="<?= e(mask_account($d['account_number_snapshot'])) ?>">
          <?= e(mask_account($d['account_number_snapshot'])) ?>
        </span>
        <button type="button"
                class="btn btn-outline btn-sm"
                style="padding:2px 8px;font-size:11px;margin-left:6px"
                id="wd-reveal-btn"
                onclick="pictRevealWdAcct()">
          Reveal
        </button>
      </td>
    </tr>
    <tr><th>Requested</th><td><?= e(date('d M Y H:i', strtotime($d['created_at']))) ?></td></tr>
    <?php if (!empty($d['partner_remark'])): ?><tr><th>Partner Remark</th><td><?= e($d['partner_remark']) ?></td></tr><?php endif; ?>
    <?php if (!empty($d['payment_reference'])): ?><tr><th>Payment Reference</th><td><span class="masked"><?= e($d['payment_reference']) ?></span></td></tr><?php endif; ?>
    <?php if (!empty($d['admin_comment'])): ?><tr><th>Admin Comment</th><td><?= e($d['admin_comment']) ?></td></tr><?php endif; ?>
  </table>

  <h3 style="font-size:14px;text-transform:uppercase;letter-spacing:.05em;color:var(--muted)">Take Action</h3>
  <form method="post" class="flex-between" style="gap:10px;flex-wrap:wrap">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
    <div class="form-group" style="margin:0;flex:1;min-width:220px">
      <label class="form-label">Comment</label>
      <input class="form-control" type="text" name="comment" maxlength="500" value="<?= e((string) $d['admin_comment']) ?>">
    </div>

    <?php if ($d['status'] === 'PENDING'): ?>
      <div class="form-group" style="margin:0">
        <label class="form-label">&nbsp;</label>
        <button class="btn btn-ok" name="action" value="approve" onclick="return confirm('Approve this request?');">Approve</button>
        <button class="btn btn-danger" name="action" value="reject" onclick="return confirm('Reject and return funds to partner?');" style="margin-left:8px">Reject</button>
      </div>
    <?php elseif ($d['status'] === 'APPROVED'): ?>
      <div class="form-group" style="margin:0">
        <label class="form-label">&nbsp;</label>
        <button class="btn btn-grape" name="action" value="mark_processing">Mark Processing</button>
        <button class="btn btn-danger" name="action" value="reject" style="margin-left:8px">Reject</button>
      </div>
    <?php elseif ($d['status'] === 'PROCESSING'): ?>
      <div class="form-group" style="margin:0">
        <label class="form-label">Payment Reference</label>
        <input class="form-control" type="text" name="payment_reference" maxlength="80" placeholder="Optional bank reference">
      </div>
      <div class="form-group" style="margin:0">
        <label class="form-label">&nbsp;</label>
        <button class="btn btn-ok" name="action" value="mark_paid" onclick="return confirm('Mark as PAID and record ledger entries?');">Mark Paid</button>
      </div>
    <?php endif; ?>
  </form>
  <script>
function pictRevealWdAcct() {
  var span = document.getElementById('wd-acct');
  var btn  = document.getElementById('wd-reveal-btn');
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
      body: 'entity=withdrawal&id=<?= (int) $d['id'] ?>'
    }).catch(function () { /* silent */ });
  } else {
    span.textContent = masked;
    span.removeAttribute('data-revealed');
    btn.textContent = 'Reveal';
  }
}
</script>
</div>

<div class="card mb-3">
  <h2>Status History</h2>
  <?php if (empty($history)): ?>
    <div class="empty"><p>No history.</p></div>
  <?php else: ?>
    <table class="tbl tbl--compact">
      <thead><tr><th>When</th><th>From</th><th>To</th><th>By</th><th>Comment</th></tr></thead>
      <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td><?= e(date('d M Y H:i', strtotime($h['created_at']))) ?></td>
            <td><?= e((string) $h['from_status']) ?: '—' ?></td>
            <td><?= e($h['to_status']) ?></td>
            <td><?= e($h['changed_by_role']) ?><?= $h['changed_by_id'] ? ' #' . (int) $h['changed_by_id'] : '' ?></td>
            <td><?= e((string) $h['comment']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="filter-bar">
  <form method="get">
    <div class="form-group"><label class="form-label">Status</label>
      <select class="form-control" name="status">
        <option value="">All statuses</option>
        <?php foreach (['PENDING','APPROVED','PROCESSING','PAID','REJECTED','REVERSED'] as $s): ?>
          <option value="<?= $s ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= str_replace('_', ' ', $s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Sort by</label>
      <select class="form-control" name="sort">
        <?php foreach ($allowedSorts as $key => $opt): ?>
          <option value="<?= e($key) ?>"<?= $fSort === $key ? ' selected' : '' ?>><?= e($opt['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <button class="btn btn-grape" type="submit">Apply</button>
      <a class="btn btn-outline" href="<?= e(root_url('/superadmin/withdrawals.php')) ?>">Reset</a>
    </div>
  </form>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="empty"><p>No withdrawals.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
               <thead><tr><th>Reference</th><th>Partner</th><th>Source</th><th class="num">Amount</th><th>Status</th><th>Bank</th><th>Requested</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><span class="masked"><?= e($r['withdrawal_reference']) ?></span></td>
                            <td><?= e($r['full_name']) ?> <span class="muted">(<?= e($r['partner_code']) ?>)</span></td>
              <td>
                <?php if (!empty($r['sub_account_id'])): ?>
                  <span class="badge" style="background:#e0e7ff;color:#4338ca">Sub</span>
                  <?= e((string) $r['sub_account_name']) ?>
                <?php else: ?>
                  <span class="badge" style="background:#dbeafe;color:#1e40af">Own</span>
                <?php endif; ?>
              </td>
              <td class="num"><?= e(format_money((float) $r['amount'])) ?></td>
              <td><?= $badge((string) $r['status']) ?></td>
              <td><?= e($r['bank_name_snapshot']) ?></td>
              <td><?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
              <td class="text-right"><a class="btn btn-outline btn-sm" href="?view=<?= (int) $r['id'] ?>">Open</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>