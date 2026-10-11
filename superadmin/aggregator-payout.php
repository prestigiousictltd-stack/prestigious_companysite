<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/aggregator.php';
$sa = superadmin_require_login();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Aggregator ID is required.');
    redirect(root_url('/superadmin/aggregators.php'));
}

$stmt = db()->prepare("SELECT * FROM aggregators WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$agg = $stmt->fetch();
if (!$agg) {
    flash('error', 'Aggregator not found.');
    redirect(root_url('/superadmin/aggregators.php'));
}

$page_title = 'Record Payout';
$active_nav = 'aggregators';
$errors = [];
$balance = aggregator_available_balance($id);

if (is_post()) {
    csrf_require();

    $amount   = trim((string) ($_POST['amount'] ?? ''));
    $ref      = trim((string) ($_POST['payment_reference'] ?? ''));
    $desc     = trim((string) ($_POST['description'] ?? ''));

    $errors = validate(['amount' => $amount], [
        'amount' => ['required', 'decimal', 'min:0.01'],
    ], ['amount' => 'Payout Amount']);

    if (!$errors) {
        $amountFloat = round((float) $amount, 2);

        try {
            db()->beginTransaction();

            $lock = db()->prepare("SELECT id FROM aggregators WHERE id = :aid FOR UPDATE");
            $lock->execute([':aid' => $id]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Aggregator row missing.');
            }

            $availNow = aggregator_available_balance($id);
            if ($amountFloat > $availNow + 0.001) {
                throw new RuntimeException('Payout exceeds the available balance of ' . format_money($availNow) . '.');
            }

            aggregator_ledger_record(
                $id,
                'PAYOUT',
                -$amountFloat,
                $ref !== '' ? $ref : 'PAYOUT-' . date('YmdHis'),
                $desc !== '' ? $desc : 'Manual payout recorded by Super Admin',
                [],
                [],
                'SUPER_ADMIN',
                (int) $sa['id']
            );

            db()->commit();

            audit_log('AGGREGATOR_PAYOUT', 'aggregators', $id,
                'Payout ' . format_money($amountFloat) . ($ref !== '' ? ' ref ' . $ref : ''));

            flash('success', 'Payout of ' . format_money($amountFloat) . ' recorded.');
            redirect(root_url('/superadmin/aggregator-view.php') . '?id=' . $id);
        } catch (RuntimeException $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errors['_global'] = $e->getMessage();
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('[aggregator-payout] ' . $e->getMessage());
            $errors['_global'] = 'Payout failed. Please try again.';
        }
    }

    flash_old($_POST, []);
}

require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head">
  <div>
    <h1>Record Payout</h1>
    <p><?= e($agg['full_name']) ?> &middot; Available balance: <strong><?= e(format_money($balance)) ?></strong></p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregator-view.php')) ?>?id=<?= $id ?>">Back</a>
  </div>
</div>

<?php if (!empty($errors['_global'])): ?>
  <div class="alert alert-danger"><?= e($errors['_global']) ?></div>
<?php endif; ?>

<?php if ($balance <= 0): ?>
  <div class="alert alert-warn">This aggregator has no outstanding balance. Nothing to pay out.</div>
<?php endif; ?>

<?php if (!empty($agg['bank_name']) || !empty($agg['bank_account_number'])): ?>
  <div class="card mb-3 card--pad-lg">
    <h2>Bank Details on File</h2>
    <table class="tbl tbl--compact">
      <tr><th style="width:200px">Bank</th><td><?= e((string) $agg['bank_name']) ?: '<span class="muted">—</span>' ?></td></tr>
      <tr><th>Account Name</th><td><?= e((string) $agg['bank_account_name']) ?: '<span class="muted">—</span>' ?></td></tr>
      <tr><th>Account Number</th><td><span class="masked"><?= e((string) $agg['bank_account_number']) ?></span></td></tr>
    </table>
  </div>
<?php endif; ?>

<div class="card card--pad-lg" style="max-width:640px">
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="form-group">
      <label class="form-label">Payout Amount <span class="req">*</span></label>
      <input class="form-control" type="number" step="0.01" min="0.01"
             max="<?= e(number_format($balance, 2, '.', '')) ?>"
             name="amount" value="<?= e(old('amount', number_format($balance, 2, '.', ''))) ?>" required>
      <div class="form-hint">Must not exceed the available balance of <?= e(format_money($balance)) ?>.</div>
      <?php if (!empty($errors['amount'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['amount']) ?></div><?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label">Payment Reference</label>
      <input class="form-control" type="text" name="payment_reference" maxlength="80" value="<?= e(old('payment_reference')) ?>" placeholder="Bank transfer ref, cheque number, etc.">
    </div>

    <div class="form-group">
      <label class="form-label">Description / Note</label>
      <textarea class="form-control" name="description" rows="2" maxlength="500"><?= e(old('description')) ?></textarea>
    </div>

    <button class="btn btn-grape btn-lg" type="submit"
            <?= $balance <= 0 ? 'disabled' : '' ?>
            onclick="return confirm('Record this payout? The amount will be deducted from the aggregator balance.');">
      Record Payout
    </button>
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregator-view.php')) ?>?id=<?= $id ?>" style="margin-left:8px">Cancel</a>
  </form>
</div>

<?php old_clear(); require PICT_ROOT . '/includes/footer-superadmin.php'; ?>