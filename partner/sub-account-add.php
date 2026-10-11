<?php
/**
 * Partner submits a new sub-account.
 * The sub-account goes to PENDING until a Super Admin approves and sets a rate.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$partner = partner_require_login();

$page_title = 'Add Sub-Account';
$active_nav = 'sub-accounts';
$pid = (int) $partner['id'];
$errors = [];

if (is_post()) {
    csrf_require();

    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $ninRaw   = preg_replace('/\D/', '', (string) ($_POST['nin_number'] ?? ''));
    $email    = strtolower(trim((string) ($_POST['email'] ?? '')));

    $errors = validate([
        'full_name'  => $fullName,
        'nin_number' => $ninRaw,
        'email'      => $email,
    ], [
        'full_name'  => ['required', 'min_len:2', 'max_len:150'],
        'nin_number' => ['required', 'nin'],
        'email'      => ['email', 'max_len:190'],
    ], [
        'full_name'  => 'Full Name',
        'nin_number' => 'NIN',
        'email'      => 'Email',
    ]);

    // Reject self — the partner cannot add their own NIN as a sub-account
    if (empty($errors['nin_number']) && $ninRaw === (string) $partner['nin_number']) {
        $errors['nin_number'] = 'You cannot add your own NIN as a sub-account.';
    }

    // Cross-table uniqueness
    if (empty($errors['nin_number'])) {
        $chk = sub_account_nin_is_taken($ninRaw);
        if (!empty($chk['taken'])) {
            $errors['nin_number'] = $chk['reason']
                . ' Contact support if you believe this is an error.';
        }
    }

    // Prevent duplicate pending submissions under the same partner
    if (empty($errors['nin_number'])) {
        $stmt = db()->prepare("
            SELECT 1 FROM partner_sub_accounts
            WHERE partner_id = :pid AND nin_number = :n AND status IN ('PENDING','ACTIVE','SUSPENDED')
            LIMIT 1
        ");
        $stmt->execute([':pid' => $pid, ':n' => $ninRaw]);
        if ($stmt->fetchColumn()) {
            $errors['nin_number'] = 'This NIN has already been submitted under your account.';
        }
    }

    if (!$errors) {
        try {
            db()->beginTransaction();
            // Lock the partner row so two simultaneous submissions cannot both
            // pass the uniqueness check for the same NIN.
            $lock = db()->prepare("SELECT id FROM partners WHERE id = :pid FOR UPDATE");
            $lock->execute([':pid' => $pid]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Partner row missing.');
            }

            // Recheck within the lock
            $chk = sub_account_nin_is_taken($ninRaw);
            if (!empty($chk['taken'])) {
                throw new RuntimeException($chk['reason']);
            }

            $stmt = db()->prepare("
                INSERT INTO partner_sub_accounts
                  (partner_id, full_name, nin_number, email, status, created_at)
                VALUES
                  (:pid, :name, :nin, :email, 'PENDING', :ca)
            ");
            $stmt->execute([
                ':pid'   => $pid,
                ':name'  => $fullName,
                ':nin'   => $ninRaw,
                ':email' => $email !== '' ? $email : null,
                ':ca'    => now_dt(),
            ]);
            $newId = (int) db()->lastInsertId();
            db()->commit();

            audit_log('SUB_ACCOUNT_SUBMITTED', 'partner_sub_accounts', $newId,
                'Partner ' . $partner['partner_code'] . ' submitted sub-account ' . $fullName,
                null, null,
                ['role' => 'PARTNER', 'id' => $pid, 'label' => $partner['full_name']]);

            notify_all_superadmins(
                'SUB_ACCOUNT_SUBMITTED',
                'New sub-account pending approval',
                $partner['full_name'] . ' (' . $partner['partner_code'] . ') submitted sub-account '
                    . $fullName . ' (' . mask_nin($ninRaw) . ') for approval.',
                '/superadmin/sub-accounts.php?status=PENDING',
                'partner_sub_accounts',
                $newId
            );

            flash('success', 'Sub-account submitted. It will appear in your list once approved.');
            redirect(root_url('/partner/sub-accounts.php'));
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('[sub-account-add] ' . $ex->getMessage());
            $errors['_global'] = 'Could not submit the sub-account: ' . $ex->getMessage();
        }
    }

    flash_old($_POST, []);
}

require PICT_ROOT . '/includes/header-partner.php';
?>

<div class="page-head">
  <div>
    <h1>Add Sub-Account</h1>
    <p>Register an individual or device whose NIN will appear in CSV uploads under your account.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="<?= e(root_url('/partner/sub-accounts.php')) ?>">Cancel</a>
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
  <strong>How this works.</strong>
  After you submit, the sub-account remains <em>Pending</em> until a Super Admin approves it
  and sets its commission rate. Once approved, its NIN earns commission on every CSV import
  it appears in, and any historical records already imported for that NIN are attached to it.
</div>

<div class="card card--pad-lg" style="max-width:680px">
  <form method="post" novalidate>
    <?= csrf_field() ?>

    <div class="form-group">
      <label class="form-label" for="full_name">Full Name <span class="req">*</span></label>
      <input class="form-control" type="text" id="full_name" name="full_name"
             value="<?= e(old('full_name')) ?>" maxlength="150" required>
      <div class="form-hint">The name of the individual or the device operating under your account.</div>
      <?php if (!empty($errors['full_name'])): ?>
        <div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['full_name']) ?></div>
      <?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label" for="nin_number">NIN <span class="req">*</span></label>
      <input class="form-control" type="text" id="nin_number" name="nin_number"
             value="<?= e(old('nin_number')) ?>" maxlength="11" inputmode="numeric"
             pattern="\d{11}" placeholder="11 digits" required>
      <div class="form-hint">
        The 11-digit National Identification Number that appears in the CSV uploads.
        It must not already belong to another partner or sub-account.
      </div>
      <?php if (!empty($errors['nin_number'])): ?>
        <div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['nin_number']) ?></div>
      <?php endif; ?>
    </div>

    <div class="form-group">
      <label class="form-label" for="email">Email Address</label>
      <input class="form-control" type="email" id="email" name="email"
             value="<?= e(old('email')) ?>" maxlength="190" required>
      <div class="form-hint">email of owner of NIN</div>
      <?php if (!empty($errors['email'])): ?>
        <div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['email']) ?></div>
      <?php endif; ?>
    </div>

    <button class="btn btn-primary btn-lg" type="submit">Submit for Approval</button>
    <a class="btn btn-outline" href="<?= e(root_url('/partner/sub-accounts.php')) ?>" style="margin-left:8px">Cancel</a>
  </form>
</div>

<?php old_clear(); require PICT_ROOT . '/includes/footer-partner.php'; ?>