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

$page_title = 'Edit Aggregator';
$active_nav = 'aggregators';
$errors = [];

if (is_post()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');

    // Profile update
    if ($action === 'update_profile') {
        $fullName   = trim((string) ($_POST['full_name'] ?? ''));
        $phone      = trim((string) ($_POST['phone'] ?? ''));
        $email      = strtolower(trim((string) ($_POST['email'] ?? '')));
        $bankName   = trim((string) ($_POST['bank_name'] ?? ''));
        $bankAcctN  = trim((string) ($_POST['bank_account_name'] ?? ''));
        $bankAcctNo = trim((string) ($_POST['bank_account_number'] ?? ''));
        $notes      = trim((string) ($_POST['notes'] ?? ''));

        $errors = validate([
            'full_name' => $fullName, 'phone' => $phone, 'email' => $email,
        ], [
            'full_name' => ['required', 'min_len:2', 'max_len:150'],
            'phone'     => ['max_len:20'],
            'email'     => ['email', 'max_len:190'],
        ], ['full_name' => 'Full Name']);

        if (!$errors) {
            $stmt = db()->prepare("
                UPDATE aggregators SET
                    full_name           = :name,
                    phone               = :phone,
                    email               = :email,
                    bank_name           = :bn,
                    bank_account_name   = :ban,
                    bank_account_number = :bacn,
                    notes               = :notes,
                    updated_at          = :updated_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':name'       => $fullName,
                ':phone'      => $phone !== '' ? $phone : null,
                ':email'      => $email !== '' ? $email : null,
                ':bn'         => $bankName !== '' ? $bankName : null,
                ':ban'        => $bankAcctN !== '' ? $bankAcctN : null,
                ':bacn'       => $bankAcctNo !== '' ? $bankAcctNo : null,
                ':notes'      => $notes !== '' ? $notes : null,
                ':updated_at' => now_dt(),
                ':id'         => $id,
            ]);
            audit_log('AGGREGATOR_UPDATED', 'aggregators', $id, 'Profile updated');
            flash('success', 'Aggregator profile updated.');
            redirect(root_url('/superadmin/aggregator-edit.php') . '?id=' . $id);
        }
    }

    // Add rate
    elseif ($action === 'add_rate') {
        $pct     = trim((string) ($_POST['commission_percentage'] ?? ''));
        $effFrom = trim((string) ($_POST['effective_from'] ?? ''));
        $effTo   = trim((string) ($_POST['effective_to'] ?? ''));
        $rnotes  = trim((string) ($_POST['rate_notes'] ?? ''));

        $errors = validate([
            'commission_percentage' => $pct,
            'effective_from'        => $effFrom,
            'effective_to'          => $effTo,
        ], [
            'commission_percentage' => ['required', 'decimal', 'between:0,100'],
            'effective_from'        => ['required', 'date'],
            'effective_to'          => ['date'],
        ], ['commission_percentage' => 'Commission Percentage', 'effective_from' => 'Effective From']);

        if (!$errors && $effTo !== '' && strtotime($effTo) < strtotime($effFrom)) {
            $errors['effective_to'] = 'Effective To cannot be before Effective From.';
        }
        if (!$errors && aggregator_would_overlap($id, $effFrom, $effTo !== '' ? $effTo : null)) {
            $errors['_global'] = 'The date range overlaps an existing active rate.';
        }

        if (!$errors) {
            try {
                db()->beginTransaction();
                db()->prepare("
                    UPDATE aggregator_commission_rates
                    SET effective_to = DATE_SUB(:new_from_a, INTERVAL 1 DAY),
                        status = 'SUPERSEDED',
                        updated_at = :ua
                    WHERE aggregator_id = :aid
                      AND status = 'ACTIVE'
                      AND effective_to IS NULL
                      AND effective_from < :new_from_b
                ")->execute([
                    ':new_from_a' => $effFrom,
                    ':ua'         => now_dt(),
                    ':aid'        => $id,
                    ':new_from_b' => $effFrom,
                ]);

                db()->prepare("
                    INSERT INTO aggregator_commission_rates
                      (aggregator_id, commission_percentage, effective_from, effective_to,
                       status, notes, created_by_super_admin_id, created_at)
                    VALUES
                      (:aid, :pct, :ef, :et, 'ACTIVE', :notes, :by, :ca)
                ")->execute([
                    ':aid'   => $id,
                    ':pct'   => (float) $pct,
                    ':ef'    => $effFrom,
                    ':et'    => $effTo !== '' ? $effTo : null,
                    ':notes' => $rnotes !== '' ? $rnotes : null,
                    ':by'    => (int) $sa['id'],
                    ':ca'    => now_dt(),
                ]);
                db()->commit();
                audit_log('AGGREGATOR_RATE_ADDED', 'aggregators', $id, 'Rate ' . $pct . '% effective ' . $effFrom);
                flash('success', 'New rate added.');
                redirect(root_url('/superadmin/aggregator-edit.php') . '?id=' . $id);
            } catch (Throwable $ex) {
                if (db()->inTransaction()) db()->rollBack();
                error_log('[aggregator-edit add_rate] ' . $ex->getMessage());
                $errors['_global'] = 'Could not add rate: ' . $ex->getMessage();
            }
        }
    }

    // Supersede a rate
    elseif ($action === 'supersede_rate') {
        $rid = (int) ($_POST['rate_id'] ?? 0);
        db()->prepare("
            UPDATE aggregator_commission_rates
            SET status = 'SUPERSEDED', effective_to = COALESCE(effective_to, CURDATE()), updated_at = :ua
            WHERE id = :rid AND aggregator_id = :aid
        ")->execute([
            ':ua'  => now_dt(),
            ':rid' => $rid,
            ':aid' => $id,
        ]);
        audit_log('AGGREGATOR_RATE_SUPERSEDED', 'aggregators', $id, 'Rate #' . $rid);
        flash('success', 'Rate superseded.');
        redirect(root_url('/superadmin/aggregator-edit.php') . '?id=' . $id);
    }

    // Link a NIN
    elseif ($action === 'link_nin') {
        $ninRaw  = preg_replace('/\D/', '', (string) ($_POST['nin'] ?? ''));
        $effFrom = trim((string) ($_POST['link_effective_from'] ?? today_date()));

        if (!preg_match('/^\d{11}$/', $ninRaw)) {
            $errors['nin'] = 'NIN must be 11 digits.';
        } else {
            // Verify it is not already actively linked to this aggregator
            $owner = aggregator_nin_owner($ninRaw);
            if (!$owner) {
                $errors['nin'] = 'No active partner or sub-account found with that NIN.';
            } else {
                $checkPid = (int) $owner['partner_id'];
                $checkSid = $owner['sub_account_id'];
                if ($checkSid === null) {
                    $stmt = db()->prepare("
                        SELECT 1 FROM partner_aggregator_assignments
                        WHERE aggregator_id = :aid AND partner_id = :pid
                          AND sub_account_id IS NULL AND status = 'ACTIVE'
                        LIMIT 1
                    ");
                    $stmt->execute([':aid' => $id, ':pid' => $checkPid]);
                } else {
                    $stmt = db()->prepare("
                        SELECT 1 FROM partner_aggregator_assignments
                        WHERE aggregator_id = :aid AND partner_id = :pid
                          AND sub_account_id = :sid AND status = 'ACTIVE'
                        LIMIT 1
                    ");
                    $stmt->execute([':aid' => $id, ':pid' => $checkPid, ':sid' => $checkSid]);
                }
                if ($stmt->fetchColumn()) {
                    $errors['nin'] = 'This NIN is already linked to this aggregator.';
                }
            }
        }

        if (!$errors) {
            try {
                db()->beginTransaction();
                $res = aggregator_assign_nin($id, $ninRaw, $effFrom, (int) $sa['id'], 'Linked from edit page');
                if (empty($res['ok'])) {
                    throw new RuntimeException($res['reason']);
                }
                db()->commit();

                audit_log('AGGREGATOR_NIN_LINKED', 'aggregators', $id,
                    'Linked NIN ' . mask_nin($ninRaw) . ' (' . $res['type'] . ': ' . $res['display_name'] . ')');

                flash('success', 'NIN linked: ' . $res['display_name']);
                redirect(root_url('/superadmin/aggregator-edit.php') . '?id=' . $id);
            } catch (Throwable $ex) {
                if (db()->inTransaction()) db()->rollBack();
                error_log('[aggregator-edit link] ' . $ex->getMessage());
                $errors['_global'] = 'Could not link NIN: ' . $ex->getMessage();
            }
        }
    }

    // Unlink
    elseif ($action === 'unlink_nin') {
        $assignId = (int) ($_POST['assign_id'] ?? 0);
        db()->prepare("
            UPDATE partner_aggregator_assignments
            SET status = 'SUPERSEDED', effective_to = CURDATE()
            WHERE id = :id AND aggregator_id = :aid
        ")->execute([
            ':id'  => $assignId,
            ':aid' => $id,
        ]);
        audit_log('AGGREGATOR_NIN_UNLINKED', 'aggregators', $id, 'Assignment #' . $assignId);
        flash('success', 'NIN unlinked.');
        redirect(root_url('/superadmin/aggregator-edit.php') . '?id=' . $id);
    }

    else {
        flash('error', 'Unknown action.');
        redirect(root_url('/superadmin/aggregator-edit.php') . '?id=' . $id);
    }

    if ($errors) {
        flash_old($_POST, []);
    }
}

// Reload
$stmt = db()->prepare("SELECT * FROM aggregators WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$agg = $stmt->fetch();

// Rates
$stmt = db()->prepare("SELECT * FROM aggregator_commission_rates WHERE aggregator_id = :aid ORDER BY effective_from DESC, id DESC");
$stmt->execute([':aid' => $id]);
$rates = $stmt->fetchAll();

// Linked NINs
$linkedNins = aggregator_list_linked_nins($id, true);

require PICT_ROOT . '/includes/header-superadmin.php';

$statusBadge = function (string $s): string {
    $cls = match (strtoupper($s)) {
        'ACTIVE'     => 'badge--active',
        'INACTIVE'   => 'badge--inactive',
        'SUPERSEDED' => 'badge--inactive',
        default      => 'badge--inactive',
    };
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
};
?>

<div class="page-head">
  <div>
    <h1>Edit: <?= e($agg['full_name']) ?></h1>
    <p>Update profile, manage rate history, and link or unlink NINs.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregator-view.php')) ?>?id=<?= $id ?>">View</a>
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregators.php')) ?>">All</a>
  </div>
</div>

<?php if (!empty($errors['_global'])): ?>
  <div class="alert alert-danger"><?= e($errors['_global']) ?></div>
<?php endif; ?>

<!-- Profile -->
<div class="card card--pad-lg mb-3">
  <h2>Profile</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_profile">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Full Name <span class="req">*</span></label>
        <input class="form-control" type="text" name="full_name" value="<?= e(old('full_name', $agg['full_name'])) ?>" required maxlength="150">
      </div>
      <div class="form-group">
        <label class="form-label">Phone</label>
        <input class="form-control" type="tel" name="phone" value="<?= e(old('phone', (string) $agg['phone'])) ?>" maxlength="20">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Email</label>
      <input class="form-control" type="email" name="email" value="<?= e(old('email', (string) $agg['email'])) ?>" maxlength="190">
    </div>
    <div class="form-group">
      <label class="form-label">Bank Name</label>
      <input class="form-control" type="text" name="bank_name" value="<?= e(old('bank_name', (string) $agg['bank_name'])) ?>" maxlength="150">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label">Account Name</label>
        <input class="form-control" type="text" name="bank_account_name" value="<?= e(old('bank_account_name', (string) $agg['bank_account_name'])) ?>" maxlength="190">
      </div>
      <div class="form-group">
        <label class="form-label">Account Number</label>
        <input class="form-control" type="text" name="bank_account_number" value="<?= e(old('bank_account_number', (string) $agg['bank_account_number'])) ?>" maxlength="30">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Notes</label>
      <textarea class="form-control" name="notes" rows="3"><?= e(old('notes', (string) $agg['notes'])) ?></textarea>
    </div>
    <button class="btn btn-grape" type="submit">Save Changes</button>
  </form>
</div>

<!-- Rate history -->
<div class="card card--pad-lg mb-3">
  <h2>Commission Rate History</h2>
  <?php if (empty($rates)): ?>
    <div class="alert alert-warn">No rate on file. Add one so the aggregator can earn.</div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr><th>Rate</th><th>Effective From</th><th>Effective To</th><th>Status</th><th>Notes</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($rates as $r): ?>
            <tr>
              <td><strong><?= e(number_format((float) $r['commission_percentage'], 2)) ?>%</strong></td>
              <td><?= e($r['effective_from']) ?></td>
              <td><?= e((string) $r['effective_to']) ?: '<span class="muted">Open</span>' ?></td>
              <td><?= $statusBadge((string) $r['status']) ?></td>
              <td><?= e((string) $r['notes']) ?></td>
              <td class="text-right">
                <?php if ($r['status'] === 'ACTIVE'): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Supersede this rate?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="supersede_rate">
                    <input type="hidden" name="id" value="<?= $id ?>">
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

  <h3 style="margin-top:22px">Add New Rate</h3>
  <form method="post" class="form-row--3 form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_rate">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="form-group">
      <label class="form-label">Commission %</label>
      <input class="form-control" type="number" step="0.01" min="0" max="100" name="commission_percentage" value="<?= e(old('commission_percentage', '10.00')) ?>" required>
    </div>
    <div class="form-group">
      <label class="form-label">Effective From</label>
      <input class="form-control" type="date" name="effective_from" value="<?= e(old('effective_from', today_date())) ?>" required>
    </div>
    <div class="form-group">
      <label class="form-label">Effective To (optional)</label>
      <input class="form-control" type="date" name="effective_to" value="<?= e(old('effective_to')) ?>">
    </div>
    <div class="form-group" style="grid-column:1 / -1">
      <label class="form-label">Notes</label>
      <input class="form-control" type="text" name="rate_notes" maxlength="255" value="<?= e(old('rate_notes')) ?>">
    </div>
    <div class="form-group">
      <button class="btn btn-grape" type="submit">Add Rate</button>
    </div>
  </form>
</div>

<!-- Linked NINs -->
<div class="card card--pad-lg mb-3">
  <h2>Linked NINs</h2>
  <div class="form-hint" style="margin-bottom:12px">
    Only the exact NINs listed below earn aggregator commission. Each is either a partner's own NIN or a sub-account.
  </div>

  <?php if (empty($linkedNins)): ?>
    <div class="empty"><p>No NINs linked yet. Use the form below to add one.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Owner</th>
            <th>Type</th>
            <th>NIN</th>
            <th>Parent Partner</th>
            <th>From</th>
            <th>To</th>
            <th>Status</th>
            <th></th>
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
              <td><span class="masked"><?= e(mask_nin($n['nin'])) ?></span></td>
              <td>
                <?php if ($n['type'] === 'sub_account'): ?>
                  <?= e($n['partner_name']) ?><br>
                  <span class="muted" style="font-size:12px"><?= e($n['partner_code']) ?></span>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td><?= e($n['effective_from']) ?></td>
              <td><?= e((string) $n['effective_to']) ?: '<span class="muted">Open</span>' ?></td>
              <td><?= $statusBadge((string) $n['assignment_status']) ?></td>
              <td class="text-right">
                <?php if ($n['assignment_status'] === 'ACTIVE'): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Unlink this NIN?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="unlink_nin">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="assign_id" value="<?= (int) $n['assignment_id'] ?>">
                    <button class="btn btn-outline btn-sm" type="submit">Unlink</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <h3 style="margin-top:22px">Link a NIN</h3>
  <?php if (!empty($errors['nin'])): ?>
    <div class="alert alert-danger"><?= e($errors['nin']) ?></div>
  <?php endif; ?>
  <form method="post" class="form-row">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="link_nin">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="form-group">
      <label class="form-label">NIN</label>
      <input class="form-control" type="text" name="nin" maxlength="11" inputmode="numeric"
             placeholder="11 digits — partner or sub-account" value="<?= e(old('nin')) ?>" required>
    </div>
    <div class="form-group">
      <label class="form-label">Effective From</label>
      <input class="form-control" type="date" name="link_effective_from" value="<?= e(old('link_effective_from', today_date())) ?>" required>
    </div>
    <div class="form-group">
      <label class="form-label">&nbsp;</label>
      <button class="btn btn-grape" type="submit">Link NIN</button>
    </div>
  </form>
</div>

<?php old_clear(); require PICT_ROOT . '/includes/footer-superadmin.php'; ?>