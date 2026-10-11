<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/aggregator.php';
$sa = superadmin_require_login();

$page_title = 'Add Aggregator';
$active_nav = 'aggregators';
$errors = [];

if (is_post()) {
    csrf_require();

    $fullName   = trim((string) ($_POST['full_name'] ?? ''));
    $phone      = trim((string) ($_POST['phone'] ?? ''));
    $email      = strtolower(trim((string) ($_POST['email'] ?? '')));
    $bankName   = trim((string) ($_POST['bank_name'] ?? ''));
    $bankAcctN  = trim((string) ($_POST['bank_account_name'] ?? ''));
    $bankAcctNo = trim((string) ($_POST['bank_account_number'] ?? ''));
    $notes      = trim((string) ($_POST['notes'] ?? ''));
    $pct        = trim((string) ($_POST['commission_percentage'] ?? ''));
    $effFrom    = trim((string) ($_POST['effective_from'] ?? today_date()));
    $ninList    = (string) ($_POST['link_nins'] ?? '');

    $errors = validate([
        'full_name'             => $fullName,
        'phone'                 => $phone,
        'email'                 => $email,
        'commission_percentage' => $pct,
        'effective_from'        => $effFrom,
    ], [
        'full_name'             => ['required', 'min_len:2', 'max_len:150'],
        'phone'                 => ['max_len:20'],
        'email'                 => ['email', 'max_len:190'],
        'commission_percentage' => ['required', 'decimal', 'between:0,100'],
        'effective_from'        => ['required', 'date'],
    ], [
        'full_name'             => 'Full Name',
        'phone'                 => 'Phone',
        'email'                 => 'Email',
        'commission_percentage' => 'Commission Percentage',
        'effective_from'        => 'Effective From',
    ]);

    // Parse the NIN list into a clean array
    $ninCandidates = [];
    if ($ninList !== '') {
        $lines = preg_split('/[\r\n,;]+/', $ninList) ?: [];
        foreach ($lines as $line) {
            $clean = preg_replace('/\D/', '', $line);
            if ($clean === '') continue;
            if (!preg_match('/^\d{11}$/', $clean)) {
                $errors['link_nins'] = 'Invalid NIN "' . $line . '" — must be 11 digits.';
                break;
            }
            $ninCandidates[$clean] = $clean;
        }
    }

    if (!$errors) {
        try {
            db()->beginTransaction();

            // 1. Create the aggregator
            $stmt = db()->prepare("
                INSERT INTO aggregators
                  (full_name, phone, email,
                   bank_name, bank_account_name, bank_account_number,
                   status, notes, created_by_super_admin_id, created_at)
                VALUES
                  (:name, :phone, :email,
                   :bn, :ban, :bacn,
                   'ACTIVE', :notes, :by, :ca)
            ");
            $stmt->execute([
                ':name'  => $fullName,
                ':phone' => $phone !== '' ? $phone : null,
                ':email' => $email !== '' ? $email : null,
                ':bn'    => $bankName  !== '' ? $bankName  : null,
                ':ban'   => $bankAcctN !== '' ? $bankAcctN : null,
                ':bacn'  => $bankAcctNo !== '' ? $bankAcctNo : null,
                ':notes' => $notes !== '' ? $notes : null,
                ':by'    => (int) $sa['id'],
                ':ca'    => now_dt(),
            ]);
            $aggregatorId = (int) db()->lastInsertId();

            // 2. Initial rate
            $stmt = db()->prepare("
                INSERT INTO aggregator_commission_rates
                  (aggregator_id, commission_percentage, effective_from, effective_to,
                   status, notes, created_by_super_admin_id, created_at)
                VALUES
                  (:aid, :pct, :ef, NULL,
                   'ACTIVE', 'Initial rate at creation', :by, :ca)
            ");
            $stmt->execute([
                ':aid' => $aggregatorId,
                ':pct' => (float) $pct,
                ':ef'  => $effFrom,
                ':by'  => (int) $sa['id'],
                ':ca'  => now_dt(),
            ]);

            // 3. Link NINs
            $linked = 0;
            $linkErrors = [];
            foreach ($ninCandidates as $ninRaw) {
                $res = aggregator_assign_nin($aggregatorId, $ninRaw, $effFrom, (int) $sa['id'], 'Linked at aggregator creation');
                if (!empty($res['ok'])) {
                    $linked++;
                } else {
                    $linkErrors[] = $ninRaw . ' (' . $res['reason'] . ')';
                }
            }

            db()->commit();

            audit_log('AGGREGATOR_CREATED', 'aggregators', $aggregatorId,
                'Created aggregator ' . $fullName . ' (' . $pct . '% effective ' . $effFrom . ') with ' . $linked . ' NIN(s) linked');

            $msg = 'Aggregator created';
            if ($linked > 0) {
                $msg .= ' with ' . $linked . ' NIN(s) linked';
            }
            if (!empty($linkErrors)) {
                $msg .= '. Skipped: ' . implode(', ', array_slice($linkErrors, 0, 3));
            }
            flash($linkErrors ? 'warn' : 'success', $msg . '.');
            redirect(root_url('/superadmin/aggregator-view.php') . '?id=' . $aggregatorId);
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('[aggregator-add] ' . $ex->getMessage());
            $errors['_global'] = 'Could not create aggregator: ' . $ex->getMessage();
        }
    }

    flash_old($_POST, []);
}

require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head">
  <div>
    <h1>Add Aggregator</h1>
    <p>Aggregators earn a percentage of the commission of the NINs linked to them.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregators.php')) ?>">Cancel</a>
  </div>
</div>

<?php if (!empty($errors['_global'])): ?>
  <div class="alert alert-danger"><?= e($errors['_global']) ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <strong>Please correct the following:</strong>
    <ul style="margin:6px 0 0 18px;padding:0">
      <?php foreach ($errors as $field => $msg): if ($field === '_global') continue; ?>
        <li><?= e($msg) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="alert alert-info">
  <strong>How linking works.</strong> A NIN can belong to a partner's own identity or to a sub-account under a partner.
  Only the exact NIN linked here earns aggregator commission — no inheritance in either direction.
  If a partner's own NIN is linked, their sub-accounts' activity does not count. If a sub-account's NIN is linked,
  only that sub-account counts.
</div>

<form method="post" novalidate>
  <?= csrf_field() ?>

  <div class="card mb-3 card--pad-lg">
    <h2>Identity</h2>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Full Name <span class="req">*</span></label>
        <input class="form-control" type="text" name="full_name" value="<?= e(old('full_name')) ?>" required maxlength="150">
      </div>
      <div class="form-group">
        <label class="form-label">Phone</label>
        <input class="form-control" type="tel" name="phone" value="<?= e(old('phone')) ?>" maxlength="20">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Email</label>
      <input class="form-control" type="email" name="email" value="<?= e(old('email')) ?>" maxlength="190">
    </div>
  </div>

  <div class="card mb-3 card--pad-lg">
    <h2>Commission Agreement</h2>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Commission Percentage <span class="req">*</span></label>
        <input class="form-control" type="number" step="0.01" min="0" max="100" name="commission_percentage"
               value="<?= e(old('commission_percentage', '10.00')) ?>" required>
        <div class="form-hint">
          Applied to the commission of each linked NIN.
          Example: linked NIN earns ₦6,000; aggregator at 10% earns ₦600.
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Effective From <span class="req">*</span></label>
        <input class="form-control" type="date" name="effective_from" value="<?= e(old('effective_from', today_date())) ?>" required>
      </div>
    </div>
  </div>

  <div class="card mb-3 card--pad-lg">
    <h2>Bank Details (for payout reference)</h2>
    <div class="form-group">
      <label class="form-label">Bank Name</label>
      <input class="form-control" type="text" name="bank_name" value="<?= e(old('bank_name')) ?>" maxlength="150">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Account Name</label>
        <input class="form-control" type="text" name="bank_account_name" value="<?= e(old('bank_account_name')) ?>" maxlength="190">
      </div>
      <div class="form-group">
        <label class="form-label">Account Number</label>
        <input class="form-control" type="text" name="bank_account_number" value="<?= e(old('bank_account_number')) ?>" maxlength="30">
      </div>
    </div>
  </div>

  <div class="card mb-3 card--pad-lg">
    <h2>Link NINs</h2>
    <div class="form-group">
      <label class="form-label">NINs to Link</label>
      <textarea class="form-control" name="link_nins" rows="8"
                placeholder="Paste 11-digit NINs here, one per line or comma-separated"><?= e(old('link_nins')) ?></textarea>
      <div class="form-hint">
        Each NIN must belong to an ACTIVE partner or sub-account. NINs that are not found are skipped
        and reported after creation. Leave blank to create the aggregator without any links.
      </div>
      <?php if (!empty($errors['link_nins'])): ?>
        <div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['link_nins']) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card mb-3 card--pad-lg">
    <h2>Notes</h2>
    <div class="form-group">
      <textarea class="form-control" name="notes" rows="3" placeholder="Optional internal notes"><?= e(old('notes')) ?></textarea>
    </div>
  </div>

  <div class="alert alert-warn" style="margin-bottom:16px">
    <strong>Heads up:</strong> if the NINs you link have already been imported in a previous CSV batch,
    those historical settlements are not created automatically. After creating the aggregator, run
    <em>Backfill Historical</em> from the aggregators list.
  </div>

  <div class="flex-between">
    <button class="btn btn-grape btn-lg" type="submit">Create Aggregator</button>
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregators.php')) ?>">Cancel</a>
  </div>
</form>

<?php old_clear(); require PICT_ROOT . '/includes/footer-superadmin.php'; ?>