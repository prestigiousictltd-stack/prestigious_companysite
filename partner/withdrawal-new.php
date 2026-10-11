<?php
/**
 * Request a withdrawal from a specific bucket.
 * Query: ?bucket=own | ?bucket=<sub_account_id>
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';
require_once PICT_ROOT . '/includes/financial.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$partner = partner_require_login();

$page_title = 'Request Withdrawal';
$active_nav = 'withdrawals';
$pid = (int) $partner['id'];

// ----- Determine the bucket -----
$bucketRaw = (string) ($_GET['bucket'] ?? $_POST['bucket'] ?? 'own');

$bucketType    = 'own';
$bucketSubId   = null;
$bucketLabel   = 'My Own NIN — ' . $partner['full_name'];
$bucketContext = 'own';

if (ctype_digit($bucketRaw) && (int) $bucketRaw > 0) {
    $stmt = db()->prepare("
        SELECT id, full_name, nin_number, status
        FROM partner_sub_accounts
        WHERE id = :id AND partner_id = :pid AND status = 'ACTIVE'
        LIMIT 1
    ");
    $stmt->execute([':id' => (int) $bucketRaw, ':pid' => $pid]);
    $sub = $stmt->fetch();
    if ($sub) {
        $bucketType    = 'sub';
        $bucketSubId   = (int) $sub['id'];
        $bucketLabel   = 'Sub-Account — ' . $sub['full_name'];
        $bucketContext = (string) $sub['id'];
    }
}

// ----- Load all available buckets for the selector -----
$stmt = db()->prepare("
    SELECT id, full_name
    FROM partner_sub_accounts
    WHERE partner_id = :pid AND status = 'ACTIVE'
    ORDER BY full_name ASC
");
$stmt->execute([':pid' => $pid]);
$availableSubAccounts = $stmt->fetchAll();

// ----- Available and reserved for the current bucket -----
$available = partner_bucket_balance($pid, $bucketSubId);
$reserved  = partner_bucket_reserved($pid, $bucketSubId);
$minAmount = setting_decimal('withdrawal_min_amount', 1000.0);

// ----- Bank accounts -----
$stmt = db()->prepare("
    SELECT * FROM partner_bank_accounts
    WHERE partner_id = :pid AND status = 'ACTIVE'
    ORDER BY is_primary DESC, id DESC
");
$stmt->execute([':pid' => $pid]);
$banks = $stmt->fetchAll();

$errors = [];

if (is_post()) {
    csrf_require();

    $amount = trim((string) ($_POST['amount'] ?? ''));
    $bankId = (int) ($_POST['bank_account_id'] ?? 0);
    $remark = trim((string) ($_POST['remark'] ?? ''));

    $errors = validate(
        ['amount' => $amount, 'bank_account_id' => $bankId],
        ['amount' => ['required', 'decimal', 'min:' . (int) $minAmount], 'bank_account_id' => ['required', 'int']],
        ['amount' => 'Amount', 'bank_account_id' => 'Bank Account']
    );

    if (!$errors && (float) $amount <= 0) {
        $errors['amount'] = 'Amount must be greater than zero.';
    }

    // Verify bank account belongs to partner
    $bank = null;
    if (!$errors) {
        foreach ($banks as $b) {
            if ((int) $b['id'] === $bankId) { $bank = $b; break; }
        }
        if (!$bank) {
            $errors['bank_account_id'] = 'The selected bank account is not valid.';
        }
    }

    if (!$errors) {
        $amountFloat = round((float) $amount, 2);

        db()->beginTransaction();
        try {
            // Serialise all balance-affecting writes for this partner
            $lock = db()->prepare("SELECT id FROM partners WHERE id = :pid FOR UPDATE");
            $lock->execute([':pid' => $pid]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Partner row missing.');
            }

            // Recompute inside the lock, scoped to the bucket
            $availNow = partner_bucket_balance($pid, $bucketSubId);
            if ($amountFloat > $availNow) {
                throw new RuntimeException('The selected bucket has insufficient balance.');
            }

            // Create withdrawal with bank snapshot and bucket tag
            $ref = 'WD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = db()->prepare("
                INSERT INTO withdrawals
                  (withdrawal_reference, partner_id, sub_account_id, amount, bank_account_id,
                   bank_name_snapshot, account_name_snapshot, account_number_snapshot,
                   partner_remark, status, created_at)
                VALUES
                  (:ref, :pid, :sid, :amt, :bid,
                   :bn, :an, :acn,
                   :rm, 'PENDING', :ca)
            ");
            $stmt->execute([
                ':ref' => $ref,
                ':pid' => $pid,
                ':sid' => $bucketSubId,
                ':amt' => $amountFloat,
                ':bid' => (int) $bank['id'],
                ':bn'  => $bank['bank_name'],
                ':an'  => $bank['account_name'],
                ':acn' => $bank['account_number'],
                ':rm'  => $remark !== '' ? mb_substr($remark, 0, 500) : null,
                ':ca'  => now_dt(),
            ]);
            $withdrawalId = (int) db()->lastInsertId();

            // Reserve funds from the bucket
            ledger_record(
                $pid,
                'WITHDRAWAL_RESERVED',
                -$amountFloat,
                $ref,
                'Withdrawal requested — funds reserved',
                [],
                ['withdrawal_id' => $withdrawalId],
                'PARTNER',
                $pid,
                $bucketSubId
            );

            // Status history
            $stmt = db()->prepare("
                INSERT INTO withdrawal_status_history
                  (withdrawal_id, from_status, to_status, changed_by_role, changed_by_id, comment, created_at)
                VALUES (:id, NULL, 'PENDING', 'PARTNER', :pid, :cmt, :ca)
            ");
            $stmt->execute([
                ':id'  => $withdrawalId,
                ':pid' => $pid,
                ':cmt' => 'Request submitted by partner from bucket: ' . $bucketLabel,
                ':ca'  => now_dt(),
            ]);

            // Save reservation transaction reference
            $stmt = db()->prepare("
                SELECT id FROM partner_financial_transactions
                WHERE reference = :ref AND type = 'WITHDRAWAL_RESERVED'
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([':ref' => $ref]);
            $ledgerId = (int) $stmt->fetchColumn();
            if ($ledgerId > 0) {
                $stmt = db()->prepare("UPDATE withdrawals SET reservation_transaction_id = :lid WHERE id = :id");
                $stmt->execute([':lid' => $ledgerId, ':id' => $withdrawalId]);
            }

            db()->commit();

            $bucketDesc = $bucketType === 'sub'
                ? 'sub-account ' . $bucketLabel
                : 'own NIN';

            audit_log('WITHDRAWAL_REQUESTED', 'withdrawals', $withdrawalId,
                'Withdrawal ' . $ref . ' for ' . format_money($amountFloat) . ' from ' . $bucketDesc,
                null, null,
                ['role' => 'PARTNER', 'id' => $pid, 'label' => (string) $partner['full_name']]);

            notify_all_superadmins(
                'WITHDRAWAL_REQUESTED',
                'New withdrawal request',
                $partner['full_name'] . ' (' . $partner['partner_code'] . ') requested '
                    . format_money($amountFloat) . ' from ' . $bucketDesc . '.',
                '/superadmin/withdrawals.php?view=' . $withdrawalId,
                'withdrawals',
                $withdrawalId
            );

            notify(
                'PARTNER', $pid, 'WITHDRAWAL_SUBMITTED',
                'Withdrawal request submitted',
                'Your withdrawal of ' . format_money($amountFloat) . ' from ' . $bucketDesc
                    . ' has been received and is pending review.',
                '/partner/withdrawals.php?view=' . $withdrawalId,
                'withdrawals', $withdrawalId
            );

            flash('success', 'Your withdrawal request has been submitted and is pending review.');
            redirect(root_url('/partner/withdrawals.php') . '?view=' . $withdrawalId);
        } catch (RuntimeException $re) {
            if (db()->inTransaction()) db()->rollBack();
            $msg = $re->getMessage();
            if ($msg === 'The selected bucket has insufficient balance.') {
                $errors['amount'] = 'The selected bucket does not have enough available balance for this request.';
            } else {
                $errors['_global'] = 'Could not submit the request. Please try again.';
            }
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('[withdrawal-new] ' . $ex->getMessage());
            $errors['_global'] = 'Could not submit the request. Please try again.';
        }
    }
}

require PICT_ROOT . '/includes/header-partner.php';
?>

<div class="page-head">
  <div>
    <h1>Request Withdrawal</h1>
    <p>Choose which bucket you are withdrawing from. Funds are reserved from that bucket immediately.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="<?= e(root_url('/partner/withdrawals.php')) ?>">Back</a>
  </div>
</div>

<?php if (!empty($errors['_global'])): ?>
  <div class="alert alert-danger"><?= e($errors['_global']) ?></div>
<?php endif; ?>

<?php if (empty($banks)): ?>
  <div class="card">
    <div class="alert alert-warn" style="margin:0">
      You must add a bank account before requesting a withdrawal.
    </div>
    <p class="mt-2"><a class="btn btn-primary" href="<?= e(root_url('/partner/bank-details.php')) ?>">Add Bank Account</a></p>
  </div>
<?php else: ?>

<!-- Bucket selector -->
<div class="filter-bar">
  <form method="get">
    <div class="form-group">
      <label class="form-label">Withdraw From</label>
      <select class="form-control" name="bucket" onchange="this.form.submit()">
        <option value="own"<?= $bucketType === 'own' ? ' selected' : '' ?>>
          My Own NIN — <?= e($partner['full_name']) ?>
        </option>
        <?php foreach ($availableSubAccounts as $s): ?>
          <option value="<?= (int) $s['id'] ?>"<?= $bucketType === 'sub' && $bucketSubId === (int) $s['id'] ? ' selected' : '' ?>>
            Sub-Account — <?= e($s['full_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<div class="stat-grid">
  <div class="stat stat--avail">
    <div class="stat__label">Available in This Bucket</div>
    <div class="stat__value"><?= e(format_money($available)) ?></div>
    <div class="stat__sub"><?= e($bucketLabel) ?></div>
  </div>
  <div class="stat stat--pending">
    <div class="stat__label">Already Reserved</div>
    <div class="stat__value"><?= e(format_money($reserved)) ?></div>
    <div class="stat__sub">Held for pending requests</div>
  </div>
  <div class="stat">
    <div class="stat__label">Minimum Withdrawal</div>
    <div class="stat__value"><?= e(format_money($minAmount)) ?></div>
  </div>
</div>

<div class="card card--pad-lg" style="max-width:680px">
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="bucket" value="<?= e($bucketContext) ?>">

    <div class="form-group">
      <label class="form-label">Amount <span class="req">*</span></label>
      <input class="form-control" type="number" step="0.01"
             min="<?= e(number_format($minAmount, 2, '.', '')) ?>"
             max="<?= e(number_format($available, 2, '.', '')) ?>"
             id="amount" name="amount"
             value="<?= e(old('amount')) ?>" required>
      <div class="form-hint">
        Must be at least <?= e(format_money($minAmount)) ?> and no more than the
        <?= e(format_money($available)) ?> available in <?= e($bucketLabel) ?>.
      </div>
      <?php if (!empty($errors['amount'])): ?>
        <div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['amount']) ?></div>
      <?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label">Bank Account <span class="req">*</span></label>
      <select class="form-control" id="bank_account_id" name="bank_account_id" required>
        <option value="">— Select Account —</option>
        <?php foreach ($banks as $b): ?>
          <option value="<?= (int) $b['id'] ?>"<?= (string) old('bank_account_id') === (string) $b['id'] ? ' selected' : '' ?>>
            <?= e($b['bank_name']) ?> &middot; <?= e($b['account_name']) ?> &middot; <?= e($b['account_number']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if (!empty($errors['bank_account_id'])): ?>
        <div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['bank_account_id']) ?></div>
      <?php endif; ?>
      <div class="form-hint"><a href="<?= e(root_url('/partner/bank-details.php')) ?>">Manage bank accounts &rarr;</a></div>
    </div>

    <div class="form-group">
      <label class="form-label">Remark (optional)</label>
      <textarea class="form-control" id="remark" name="remark" maxlength="500" rows="2"><?= e(old('remark')) ?></textarea>
    </div>

    <button class="btn btn-primary btn-lg" type="submit"<?= $available <= 0 ? ' disabled' : '' ?>>
      Submit Request
    </button>
    <a class="btn btn-outline" href="<?= e(root_url('/partner/withdrawals.php')) ?>" style="margin-left:8px">Cancel</a>
  </form>
</div>

<?php endif; ?>

<?php old_clear(); require PICT_ROOT . '/includes/footer-partner.php'; ?>