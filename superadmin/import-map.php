<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/csv.php';
$sa = superadmin_require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM import_batches WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$b = $stmt->fetch();
if (!$b) { flash('error', 'Batch not found.'); redirect('/superadmin/imports.php'); }
if (!in_array($b['status'], ['UPLOADED','VALIDATED'], true)) {
    flash('error', 'Batch is no longer editable.');
    redirect('/superadmin/imports.php');
}

$path = PICT_ROOT . '/uploads/imports/' . $b['stored_filename'];
if (!is_file($path)) { flash('error', 'Uploaded file is missing.'); redirect('/superadmin/imports.php'); }

$errors = [];
try { $csv = csv_open($path); } catch (Throwable $e) { $errors['_global'] = $e->getMessage(); }

$targets = csv_target_fields();
$existingMap = $b['column_map'] ? json_decode((string) $b['column_map'], true) : [];

if (is_post() && $csv) {
    csrf_require();
    $map = [];
    foreach ($targets as $field => $label) {
        $value = $_POST['map'][$field] ?? '';
        if ($value !== '') {
            $map[$field] = (string) $value;
        }
    }
    foreach (csv_required_fields() as $req) {
        if (empty($map[$req])) {
            $errors['_global'] = 'The field "' . $targets[$req] . '" must be mapped.';
        }
    }
    if (!$errors) {
        $stmt = db()->prepare("UPDATE import_batches SET column_map = :cm, status = 'VALIDATED', updated_at = :ua WHERE id = :id");
        $stmt->execute([':cm' => json_encode($map), ':ua' => now_dt(), ':id' => $id]);
        audit_log('CSV_MAPPED', 'import_batches', $id, 'Columns mapped.');
        redirect('/superadmin/import-preview.php?id=' . $id);
    }
}

// Preview first 5 data rows
$previewRows = [];
if ($csv) {
    $handle = $csv['handle'];
    rewind($handle);
    fgetcsv($handle, 0, $csv['delimiter'], '"', '\\'); // skip headers
    for ($i = 0; $i < 5; $i++) {
        $row = fgetcsv($handle, 0, $csv['delimiter'], '"', '\\');
        if ($row === false) break;
        $previewRows[] = $row;
    }
    fclose($handle);
}

require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head"><div><h1>Map Columns</h1><p>Batch <span class="masked"><?= e($b['batch_reference']) ?></span></p></div></div>

<div class="steps">
  <div class="step step--done"><b>✓</b> Upload</div>
  <div class="step step--active"><b>2</b> Map Columns</div>
  <div class="step"><b>3</b> Preview</div>
  <div class="step"><b>4</b> Confirm</div>
</div>

<?php if (!empty($errors['_global'])): ?><div class="alert alert-danger"><?= e($errors['_global']) ?></div><?php endif; ?>

<?php if ($csv): ?>

<div class="card mb-3">
  <h2>Detected CSV Columns</h2>
  <p class="muted">Delimiter: <code><?= e($csv['delimiter'] === "\t" ? 'TAB' : $csv['delimiter']) ?></code> &middot; <?= count($csv['headers']) ?> column(s)</p>
  <div class="flex-between" style="flex-wrap:wrap;gap:6px">
    <?php foreach ($csv['headers'] as $h): ?>
      <span class="badge badge--inactive" style="font-size:12px;padding:5px 10px"><?= e($h) ?></span>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!empty($previewRows)): ?>
<div class="card mb-3">
  <h2>First Rows Preview</h2>
  <div style="overflow-x:auto">
    <table class="tbl tbl--compact">
      <thead><tr><?php foreach ($csv['headers'] as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($previewRows as $row): ?>
          <tr><?php foreach ($csv['headers'] as $i => $h): ?><td><?= e((string) ($row[$i] ?? '')) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="card card--pad-lg">
  <h2>Map Columns</h2>
  <form method="post">
    <?= csrf_field() ?>
    <table class="tbl">
      <thead><tr><th>System Field</th><th>CSV Column</th></tr></thead>
      <tbody>
        <?php foreach ($targets as $field => $label):
          $required = in_array($field, csv_required_fields(), true);
          $current = $existingMap[$field] ?? '';
        ?>
          <tr>
            <td><strong><?= e($label) ?></strong> <?= $required ? '<span class="req">*</span>' : '' ?></td>
            <td>
              <select class="form-control" name="map[<?= e($field) ?>]"<?= $required ? ' required' : '' ?>>
                <option value="">— Not mapped —</option>
                <?php foreach ($csv['headers'] as $h): ?>
                  <option value="<?= e($h) ?>"<?= $current === $h ? ' selected' : '' ?>><?= e($h) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="mt-3">
      <button class="btn btn-grape" type="submit">Continue to Preview</button>
      <a class="btn btn-outline" href="imports.php">Cancel</a>
    </div>
  </form>
</div>

<?php endif; ?>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>