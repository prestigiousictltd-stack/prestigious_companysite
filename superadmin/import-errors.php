<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM import_batches WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$b = $stmt->fetch();
if (!$b) { flash('error', 'Batch not found.'); redirect('/superadmin/imports.php'); }

$rows = db()->prepare("SELECT * FROM import_batch_records WHERE import_batch_id = :id AND status = 'ERROR' ORDER BY row_number LIMIT 500");
$rows->execute([':id' => $id]);
$errors = $rows->fetchAll();

// Download as CSV
if (isset($_GET['download']) && $_GET['download'] === '1') {
    audit_log('DATA_EXPORT', 'import_batches', $id, 'Error CSV downloaded.');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="errors_' . $b['batch_reference'] . '.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Row #', 'Reason']);
    foreach ($errors as $e) {
        fputcsv($out, [$e['row_number'], $e['error_message']]);
    }
    fclose($out);
    exit;
}

$page_title = 'Import Errors';
$active_nav = 'imports';
require PICT_ROOT . '/includes/header-superadmin.php';
?>
<div class="page-head">
  <div><h1>Import Errors</h1><p>Batch <span class="masked"><?= e($b['batch_reference']) ?></span> &middot; <?= count($errors) ?> error row(s)</p></div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="imports.php">Back</a>
    <?php if (!empty($errors)): ?><a class="btn btn-grape" href="?id=<?= $id ?>&download=1">Download Error CSV</a><?php endif; ?>
  </div>
</div>

<div class="card">
  <?php if (empty($errors)): ?><div class="empty"><p>No errors for this batch.</p></div>
  <?php else: ?>
    <table class="tbl">
      <thead><tr><th>Row #</th><th>Reason</th></tr></thead>
      <tbody><?php foreach ($errors as $e): ?>
        <tr><td><?= (int) $e['row_number'] ?></td><td><?= e((string) $e['error_message']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
  <?php endif; ?>
</div>
<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>