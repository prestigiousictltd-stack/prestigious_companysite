<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/commission.php';
$sa = superadmin_require_login();

$page_title = 'Commission Rates';
$active_nav = 'commission-rates';
$errors = [];

// Filter by partner
$partnerId = (int) ($_GET['partner_id'] ?? 0);
$partners = db()->query("SELECT id, partner_code, full_name FROM partners ORDER BY full_name")->fetchAll();

if (is_post()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? 'create');
    $pid = (int) ($_POST['partner_id'] ?? 0);
    $pct = (string) ($_POST['commission_percentage'] ?? '');
    $from = trim((string) ($_POST['effective_from'] ?? ''));
    $to  = trim((string) ($_POST['effective_to'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($action === 'supersede') {
        $rid = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare("UPDATE partner_commission_rates SET status='SUPERSEDED', updated_at=:ua WHERE id=:id");
        $stmt->execute([':ua' => now_dt(), ':id' => $rid]);
        audit_log('COMMISSION_RATE_CHANGE', 'partner_commission_rates', $rid, 'Rate superseded.');
        flash('success', 'Rate marked as superseded.');
        redirect('/superadmin/commission-rates.php' . ($partnerId ? '?partner_id=' . $partnerId : ''));
    }

    $errors = validate([
        'partner_id' => $pid, 'commission_percentage' => $pct, 'effective_from' => $from, 'effective_to' => $to,
    ], [
        'partner_id' => ['required', 'int'],
        'commission_percentage' => ['required', 'decimal', 'between:0,100'],
        'effective_from' => ['required', 'date'],
        'effective_to' => ['date'],
    ], ['commission_percentage' => 'Commission %', 'effective_from' => 'Effective From']);

    if (!$errors && $to !== '' && strtotime($to) < strtotime($from)) {
        $errors['effective_to'] = 'Effective To cannot be before Effective From.';
    }
    if (!$errors && commission_would_overlap($pid, $from, $to !== '' ? $to : null)) {
        $errors['_global'] = 'The date range overlaps an existing active rate for this partner.';
    }

    if (!$errors) {
        try {
            db()->beginTransaction();

            // If a new rate starts and there is an existing open-ended rate, close it the day before.
            $stmt = db()->prepare("
                UPDATE partner_commission_rates
                SET effective_to = DATE_SUB(:nf, INTERVAL 1 DAY), status = 'SUPERSEDED', updated_at = :ua
                WHERE partner_id = :pid AND status = 'ACTIVE' AND effective_to IS NULL AND effective_from < :nf2
            ");
            $stmt->execute([':nf' => $from, ':nf2' => $from, ':pid' => $pid, ':ua' => now_dt()]);

            $stmt = db()->prepare("
                INSERT INTO partner_commission_rates
                  (partner_id, commission_percentage, effective_from, effective_to, status, notes, created_by_super_admin_id, created_at)
                VALUES (:pid, :pct, :ef, :et, 'ACTIVE', :n, :by, :ca)
            ");
            $stmt->execute([
                ':pid' => $pid, ':pct' => (float) $pct,
                ':ef' => $from, ':et' => $to !== '' ? $to : null,
                ':n' => $notes !== '' ? $notes : null,
                ':by' => (int) $sa['id'], ':ca' => now_dt(),
            ]);
            $newId = (int) db()->lastInsertId();
            db()->commit();

            audit_log('COMMISSION_RATE_CHANGE', 'partner_commission_rates', $newId,
                'New rate ' . $pct . '% effective ' . $from, null, null);
            flash('success', 'Commission rate added.');
            redirect('/superadmin/commission-rates.php?partner_id=' . $pid);
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('[commission-rates] ' . $e->getMessage());
            $errors['_global'] = 'Could not save the rate.';
        }
    }
}

// List
$where = ['1=1']; $params = [];
if ($partnerId > 0) { $where[] = 'r.partner_id = :pid'; $params[':pid'] = $partnerId; }
$whereSql = implode(' AND ', $where);
$stmt = db()->prepare("
    SELECT r.*, p.full_name, p.partner_code
    FROM partner_commission_rates r
    JOIN partners p ON p.id = r.partner_id
    WHERE $whereSql
    ORDER BY r.created_at DESC LIMIT 200
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head">
  <div><h1>Commission Rates</h1><p>Effective-dated. Historical records are never recalculated.</p></div>
</div>

<?php if (!empty($errors['_global'])): ?><div class="alert alert-danger"><?= e($errors['_global']) ?></div><?php endif; ?>

<div class="card mb-3">
  <h2>Add New Rate</h2>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-row">
      <div class="form-group"><label class="form-label">Partner</label>
        <select class="form-control" name="partner_id" required>
          <option value="">— Select —</option>
          <?php foreach ($partners as $p): ?>
            <option value="<?= (int) $p['id'] ?>"<?= $partnerId === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['full_name']) ?> (<?= e($p['partner_code']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($errors['partner_id'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['partner_id']) ?></div><?php endif; ?>
      </div>
      <div class="form-group"><label class="form-label">Commission %</label>
        <input class="form-control" type="number" step="0.01" min="0" max="100" name="commission_percentage" value="<?= e(old('commission_percentage', '40.00')) ?>" required>
        <?php if (!empty($errors['commission_percentage'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['commission_percentage']) ?></div><?php endif; ?>
      </div>
    </div>
    <div class="form-row--3 form-row">
      <div class="form-group"><label class="form-label">Effective From</label>
        <input class="form-control" type="date" name="effective_from" value="<?= e(old('effective_from', date('Y-m-d'))) ?>" required>
        <?php if (!empty($errors['effective_from'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['effective_from']) ?></div><?php endif; ?>
      </div>
      <div class="form-group"><label class="form-label">Effective To (optional)</label>
        <input class="form-control" type="date" name="effective_to" value="<?= e(old('effective_to')) ?>">
      </div>
      <div class="form-group"><label class="form-label">Notes</label>
        <input class="form-control" type="text" name="notes" maxlength="255" value="<?= e(old('notes')) ?>">
      </div>
    </div>
    <button class="btn btn-grape" type="submit">Add Rate</button>
  </form>
</div>

<div class="card">
  <div class="flex-between mb-2">
    <h2 style="margin:0">Rate History</h2>
    <form method="get" class="flex-between" style="gap:8px">
      <select class="form-control" name="partner_id" onchange="this.form.submit()" style="width:auto;min-width:220px">
        <option value="">All partners</option>
        <?php foreach ($partners as $p): ?>
          <option value="<?= (int) $p['id'] ?>"<?= $partnerId === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>

  <?php if (empty($rows)): ?><div class="empty"><p>No rates yet.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead><tr><th>Partner</th><th class="num">Rate</th><th>Effective From</th><th>Effective To</th><th>Status</th><th>Notes</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e($r['full_name']) ?> <span class="muted">(<?= e($r['partner_code']) ?>)</span></td>
              <td class="num"><strong><?= e(number_format((float) $r['commission_percentage'], 2)) ?>%</strong></td>
              <td><?= e($r['effective_from']) ?></td>
              <td><?= e((string) $r['effective_to']) ?: '<span class="muted">Open</span>' ?></td>
              <td><span class="badge badge--<?= strtolower($r['status']) ?>"><?= e($r['status']) ?></span></td>
              <td><?= e((string) $r['notes']) ?></td>
              <td class="text-right">
                <?php if ($r['status'] === 'ACTIVE'): ?>
                  <form method="post" style="display:inline" onsubmit="return confirm('Mark as superseded?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="supersede">
                    <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                    <input type="hidden" name="partner_id" value="<?= (int) $r['partner_id'] ?>">
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
</div>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>