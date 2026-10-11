<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/csv.php';
$sa = superadmin_require_login();

$page_title = 'Upload CSV';
$active_nav = 'imports';
$errors = [];

if (is_post()) {
    csrf_require();
    $revenuePerNin = trim((string) ($_POST['revenue_per_nin'] ?? ''));

    $errors = validate(
        ['revenue_per_nin' => $revenuePerNin],
        ['revenue_per_nin' => ['required', 'decimal', 'min:0']],
        ['revenue_per_nin' => 'Revenue per NIN']
    );

    if (empty($_FILES['csv_file']['name']) || (int) $_FILES['csv_file']['error'] === UPLOAD_ERR_NO_FILE) {
        $errors['csv_file'] = 'Please select a CSV file.';
    } elseif ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $errors['csv_file'] = 'Upload failed (code ' . (int) $_FILES['csv_file']['error'] . ').';
    } else {
        $file = $_FILES['csv_file'];
        $maxMb = setting_int('upload_max_size_mb', 20);
        if ($file['size'] > $maxMb * 1024 * 1024) {
            $errors['csv_file'] = 'File exceeds ' . $maxMb . ' MB.';
        }
        $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            $errors['csv_file'] = 'Only CSV files are accepted.';
        }
    }

    if (!$errors) {
        $file = $_FILES['csv_file'];
        $hash = hash_file('sha256', (string) $file['tmp_name']);

        $dup = db()->prepare("SELECT id, batch_reference FROM import_batches WHERE file_hash = :h AND status = 'COMPLETED' LIMIT 1");
        $dup->execute([':h' => $hash]);
        $existing = $dup->fetch();

        $uploadDir = PICT_ROOT . '/uploads/imports';
        if (!is_dir($uploadDir)) { @mkdir($uploadDir, 0775, true); }

        $safeName = csv_safe_filename((string) $file['name']);
        $storedPath = $uploadDir . '/' . $safeName;

        if (!move_uploaded_file((string) $file['tmp_name'], $storedPath)) {
            $errors['csv_file'] = 'Could not store the uploaded file.';
        } else {
            @chmod($storedPath, 0644);

            $ref = 'IMP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $stmt = db()->prepare("
                INSERT INTO import_batches
                  (batch_reference, period_year, period_month, revenue_per_nin,
                   original_filename, stored_filename, file_hash, status,
                   uploaded_by_super_admin_id, created_at)
                VALUES (:r, NULL, NULL, :rpn, :orig, :stored, :hash, 'UPLOADED', :by, :ca)
            ");
            $stmt->execute([
                ':r' => $ref,
                ':rpn' => (float) $revenuePerNin,
                ':orig' => mb_substr((string) $file['name'], 0, 255),
                ':stored' => $safeName,
                ':hash' => $hash,
                ':by' => (int) $sa['id'],
                ':ca' => now_dt(),
            ]);
            $batchId = (int) db()->lastInsertId();

            audit_log('CSV_UPLOAD', 'import_batches', $batchId, 'Batch ' . $ref . ' uploaded');
            if ($existing) {
                flash('warn', 'Note: an identical file hash already exists as batch ' . $existing['batch_reference'] . '.');
            }
            flash('success', 'File uploaded. Map the columns next.');
            redirect('/superadmin/import-map.php?id=' . $batchId);
        }
    }
}

require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head"><div><h1>Upload NIN CSV</h1><p>The reporting period is read from each row of the CSV, not from this form.</p></div></div>

<div class="steps">
  <div class="step step--active"><b>1</b> Upload</div>
  <div class="step"><b>2</b> Map Columns</div>
  <div class="step"><b>3</b> Preview</div>
  <div class="step"><b>4</b> Confirm</div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger"><?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div class="card card--pad-lg" style="max-width:720px">
  <form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="form-group"><label class="form-label">Revenue per NIN</label>
      <input class="form-control" type="number" step="0.01" min="0" name="revenue_per_nin"
             value="<?= e(old('revenue_per_nin', '100.00')) ?>" required>
      <div class="form-hint">This value is snapshotted into every record of this batch and is never recalculated.</div>
    </div>
    <div class="form-group"><label class="form-label">CSV File</label>
      <input class="form-control" type="file" name="csv_file" accept=".csv,text/csv" required>
      <div class="form-hint">
        Maximum <?= (int) setting('upload_max_size_mb', 20) ?> MB. Header row required.
        The file must include a <strong>Period</strong> column (e.g. <code>92025</code> = September 2025) and a
        <strong>Partner NIN</strong> column.
      </div>
    </div>
    <button class="btn btn-grape btn-lg" type="submit">Upload &amp; Continue</button>
    <a class="btn btn-outline" href="imports.php" style="margin-left:8px">Cancel</a>
  </form>
</div>

<?php old_clear(); require PICT_ROOT . '/includes/footer-superadmin.php'; ?>