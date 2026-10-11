<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';
$partner = partner_require_login();

$page_title = 'Bank Details';
$active_nav = 'bank-details';
$pid = (int) $partner['id'];

$errors = [];
$added = false;

if (is_post()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? 'add');

    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare("UPDATE partner_bank_accounts SET status = 'DISABLED', is_primary = 0, updated_at = :ua WHERE id = :id AND partner_id = :pid");
        $stmt->execute([':ua' => now_dt(), ':id' => $id, ':pid' => $pid]);
        audit_log('PARTNER_BANK_UPDATE', 'partner_bank_accounts', $id, 'Bank account disabled.');
        flash('success', 'Bank account removed.');
        redirect('/partner/bank-details.php');
    }

    if ($action === 'primary') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->beginTransaction();
        try {
            $stmt = db()->prepare("UPDATE partner_bank_accounts SET is_primary = 0, updated_at = :ua WHERE partner_id = :pid");
            $stmt->execute([':ua' => now_dt(), ':pid' => $pid]);
            $stmt = db()->prepare("UPDATE partner_bank_accounts SET is_primary = 1, updated_at = :ua WHERE id = :id AND partner_id = :pid");
            $stmt->execute([':ua' => now_dt(), ':id' => $id, ':pid' => $pid]);
            db()->commit();
        } catch (Throwable $e) {
            db()->rollBack();
        }
        flash('success', 'Primary account updated.');
        redirect('/partner/bank-details.php');
    }

    // Add
    $bankName = trim((string) ($_POST['bank_name'] ?? ''));
    $bankCode = trim((string) ($_POST['bank_code'] ?? ''));
    $acctName = trim((string) ($_POST['account_name'] ?? ''));
    $acctNum  = preg_replace('/\s+/', '', (string) ($_POST['account_number'] ?? ''));

    $errors = validate([
        'bank_name' => $bankName, 'account_name' => $acctName, 'account_number' => $acctNum, 'bank_code' => $bankCode,
    ], [
        'bank_name'      => ['required', 'string', 'min_len:2', 'max_len:150'],
        'account_name'   => ['required', 'string', 'min_len:2', 'max_len:190'],
        'account_number' => ['required', 'string', 'min_len:8', 'max_len:30'],
        'bank_code'      => ['max_len:20'],
    ], [
        'bank_name' => 'Bank Name', 'account_name' => 'Account Name', 'account_number' => 'Account Number', 'bank_code' => 'Bank Code',
    ]);

    if (!$errors) {
        db()->beginTransaction();
        try {
            // First bank account becomes primary by default.
            $stmt = db()->prepare('SELECT COUNT(*) FROM partner_bank_accounts WHERE partner_id = :pid AND status = "ACTIVE"');
            $stmt->execute([':pid' => $pid]);
            $isPrimary = ((int) $stmt->fetchColumn() === 0) ? 1 : 0;

            $stmt = db()->prepare('
                INSERT INTO partner_bank_accounts
                  (partner_id, bank_name, bank_code, account_name, account_number, is_primary, status, created_at)
                VALUES (:pid, :bn, :bc, :an, :ac, :pr, :st, :ca)
            ');
            $stmt->execute([
                ':pid' => $pid, ':bn' => $bankName,
                ':bc' => $bankCode !== '' ? $bankCode : null,
                ':an' => $acctName, ':ac' => $acctNum,
                ':pr' => $isPrimary, ':st' => 'ACTIVE', ':ca' => now_dt(),
            ]);
            $newId = (int) db()->lastInsertId();
            db()->commit();

            audit_log('PARTNER_BANK_UPDATE', 'partner_bank_accounts', $newId, 'Bank account added.', null, null,
                ['role' => 'PARTNER', 'id' => $pid, 'label' => (string) $partner['full_name']]);

            notify('PARTNER', $pid, 'BANK_DETAILS_UPDATED', 'Bank account added',
                'A new bank account was added to your profile.', '/partner/bank-details.php',
                'partner_bank_accounts', $newId);

            flash('success', 'Bank account added.');
            redirect('/partner/bank-details.php');
        } catch (Throwable $e) {
            db()->rollBack();
            error_log('[bank-details] ' . $e->getMessage());
            $errors['_global'] = 'Could not save the bank account.';
        }
    }
    flash_old($_POST, []);
}

$stmt = db()->prepare("SELECT * FROM partner_bank_accounts WHERE partner_id = :pid AND status = 'ACTIVE' ORDER BY is_primary DESC, id DESC");
$stmt->execute([':pid' => $pid]);
$banks = $stmt->fetchAll();

require PICT_ROOT . '/includes/header-partner.php';
?>

<div class="page-head">
  <div>
    <h1>Bank Details</h1>
    <p>Where your withdrawal funds will be sent. Account numbers are masked in listings.</p>
  </div>
</div>

<?php if (!empty($errors['_global'])): ?><div class="alert alert-danger"><?= e($errors['_global']) ?></div><?php endif; ?>

<div class="card mb-3">
  <h2>Saved Accounts</h2>
  <?php if (empty($banks)): ?>
    <div class="empty"><h3>No accounts yet</h3><p>Add a bank account below so you can request withdrawals.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr>
            <th>Bank</th>
            <th>Account Name</th>
            <th>Account Number</th>
            <th>Bank Code</th>
            <th>Primary</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($banks as $b): ?>
            <tr>
              <td><strong><?= e($b['bank_name']) ?></strong></td>
              <td><?= e($b['account_name']) ?></td>
                           <td><span class="masked"><?= e($b['account_number']) ?></span></td>
              <td><?= e((string) $b['bank_code']) ?: '<span class="muted">—</span>' ?></td>
              <td><?= $b['is_primary'] ? '<span class="badge badge--active">Primary</span>' : '<span class="muted">—</span>' ?></td>
              <td class="text-right">
                <?php if (!$b['is_primary']): ?>
                  <form method="post" style="display:inline">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="primary">
                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                    <button class="btn btn-outline btn-sm" type="submit">Make Primary</button>
                  </form>
                <?php endif; ?>
                <form method="post" style="display:inline" onsubmit="return confirm('Remove this bank account?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                  <button class="btn btn-danger btn-sm" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card card--pad-lg" style="max-width:640px">
  <h2>Add a New Bank Account</h2>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">

    <div class="form-group">
      <label class="form-label" for="bank_name">Bank Name <span class="req">*</span></label>
      <input class="form-control" type="text" id="bank_name" name="bank_name" value="<?= e(old('bank_name')) ?>" maxlength="150" required>
      <?php if (!empty($errors['bank_name'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['bank_name']) ?></div><?php endif; ?>
    </div>
    <div class="form-group">
      <label class="form-label" for="bank_code">Bank Code</label>
      <input class="form-control" type="text" id="bank_code" name="bank_code" value="<?= e(old('bank_code')) ?>" maxlength="20">
    </div>
    <div class="form-group">
      <label class="form-label" for="account_name">Account Name <span class="req">*</span></label>
      <input class="form-control" type="text" id="account_name" name="account_name" value="<?= e(old('account_name')) ?>" maxlength="190" required>
      <?php if (!empty($errors['account_name'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['account_name']) ?></div><?php endif; ?>
    </div>
    <div class="form-group">
      <label class="form-label" for="account_number">Account Number <span class="req">*</span></label>
      <input class="form-control" type="text" id="account_number" name="account_number" value="<?= e(old('account_number')) ?>" maxlength="30" inputmode="numeric" required>
      <?php if (!empty($errors['account_number'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['account_number']) ?></div><?php endif; ?>
    </div>
    <button class="btn btn-primary" type="submit">Save Bank Account</button>
  </form>
</div>

<?php old_clear(); require PICT_ROOT . '/includes/footer-partner.php'; ?>