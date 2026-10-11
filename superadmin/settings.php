<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$page_title = 'System Settings';
$active_nav = 'settings';
$errors = []; $saved = false;

if (is_post()) {
    csrf_require();
    $updates = $_POST['settings'] ?? [];
    if (is_array($updates)) {
        db()->beginTransaction();
        try {
            $stmt = db()->prepare("UPDATE system_settings SET setting_value = :v, updated_by_role='SUPER_ADMIN', updated_by_id=:by, updated_at=:ts WHERE setting_key = :k");
            foreach ($updates as $k => $v) {
                $k = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $k));
                if ($k === '') continue;
                $stmt->execute([':v' => (string) $v, ':by' => (int) $sa['id'], ':ts' => now_dt(), ':k' => $k]);
            }
            db()->commit();
            settings_flush();
            audit_log('SETTINGS_UPDATE', 'system_settings', null, 'Updated ' . count($updates) . ' setting(s)');
            flash('success', 'Settings saved.');
            redirect('/superadmin/settings.php');
        } catch (Throwable $e) {
            db()->rollBack();
            $errors['_global'] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$groups = [
    'COMPANY' => 'Company', 'LOCALE' => 'Locale & Currency', 'PARTNER' => 'Partner',
    'WITHDRAWAL' => 'Withdrawals', 'UPLOAD' => 'Uploads', 'REPORT' => 'Daily Report',
    'SMTP' => 'Email (SMTP)', 'SECURITY' => 'Security', 'SYSTEM' => 'System', 'PUBLIC' => 'Public Pages',
];

$rows = db()->query("SELECT * FROM system_settings ORDER BY category, setting_key")->fetchAll();
$byCategory = [];
foreach ($rows as $r) { $byCategory[$r['category']][] = $r; }

require PICT_ROOT . '/includes/header-superadmin.php';
?>
<div class="page-head"><div><h1>System Settings</h1><p>All configurable values — no hard-coded settings anywhere else in the platform.</p></div></div>

<?php if (!empty($errors['_global'])): ?><div class="alert alert-danger"><?= e($errors['_global']) ?></div><?php endif; ?>

<form method="post">
  <?= csrf_field() ?>
  <?php foreach ($groups as $cat => $label):
      if (empty($byCategory[$cat])) continue;
  ?>
    <div class="card mb-3">
      <h2><?= e($label) ?></h2>
      <?php foreach ($byCategory[$cat] as $s):
          $type = $s['setting_type']; $val = (string) $s['setting_value'];
      ?>
        <div class="form-group">
          <label class="form-label" for="s-<?= e($s['setting_key']) ?>">
            <?= e(str_replace('_', ' ', ucwords($s['setting_key'], '_'))) ?>
            <span class="muted" style="font-weight:400;font-size:12px">— <?= e($s['setting_key']) ?></span>
          </label>
          <?php if ($type === 'BOOL'): ?>
            <select class="form-control" name="settings[<?= e($s['setting_key']) ?>]" id="s-<?= e($s['setting_key']) ?>">
              <option value="0"<?= $val === '0' ? ' selected' : '' ?>>Disabled / No</option>
              <option value="1"<?= $val === '1' ? ' selected' : '' ?>>Enabled / Yes</option>
            </select>
          <?php elseif ($type === 'TEXT'): ?>
            <textarea class="form-control" name="settings[<?= e($s['setting_key']) ?>]" id="s-<?= e($s['setting_key']) ?>" rows="3"><?= e($val) ?></textarea>
          <?php elseif ($type === 'INT'): ?>
            <input class="form-control" type="number" name="settings[<?= e($s['setting_key']) ?>]" id="s-<?= e($s['setting_key']) ?>" value="<?= e($val) ?>">
          <?php elseif ($type === 'DECIMAL'): ?>
            <input class="form-control" type="number" step="0.01" name="settings[<?= e($s['setting_key']) ?>]" id="s-<?= e($s['setting_key']) ?>" value="<?= e($val) ?>">
          <?php else: ?>
            <input class="form-control" type="text" name="settings[<?= e($s['setting_key']) ?>]" id="s-<?= e($s['setting_key']) ?>" value="<?= e($val) ?>">
          <?php endif; ?>
          <?php if (!empty($s['description'])): ?><div class="form-hint"><?= e($s['description']) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <div class="flex-between">
    <button class="btn btn-grape btn-lg" type="submit">Save All Settings</button>
    <a class="btn btn-outline" href="/superadmin/dashboard.php">Cancel</a>
  </div>
</form>
<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>