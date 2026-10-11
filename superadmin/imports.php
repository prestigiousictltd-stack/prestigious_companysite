<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$page_title = 'CSV Import Batches';
$active_nav = 'imports';

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20; $offset = ($page - 1) * $perPage;

$total = (int) db()->query("SELECT COUNT(*) FROM import_batches")->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$rows = db()->query("
    SELECT b.*, sa.full_name AS uploader
    FROM import_batches b
    LEFT JOIN super_admins sa ON sa.id = b.uploaded_by_super_admin_id
    ORDER BY b.created_at DESC LIMIT $perPage OFFSET $offset
")->fetchAll();

require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head">
  <div><h1>CSV Import Batches</h1><p><?= number_format($total) ?> batch<?= $total === 1 ? '' : 'es' ?>.</p></div>
  <div class="page-head__actions"><a class="btn btn-grape" href="import-upload.php">+ New Import</a></div>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="empty"><h3>No imports yet</h3><p>Upload a CSV file to get started.</p>
      <a class="btn btn-grape" href="import-upload.php">Upload CSV</a></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead><tr>
          <th>Reference</th><th>Period</th><th class="num">Revenue/NIN</th>
          <th class="num">Rows</th><th class="num">NIN</th>
          <th class="num">Gross</th><th class="num">Commission</th>
          <th>Status</th><th>Uploaded</th><th></th>
        </tr></thead>
        <tbody>
          <?php foreach ($rows as $b): ?>
            <tr>
              <td><span class="masked"><?= e($b['batch_reference']) ?></span></td>
             <td>
  <?php if ($b['period_year'] !== null && $b['period_month'] !== null): ?>
    <?= e(format_period((int) $b['period_year'], (int) $b['period_month'])) ?>
  <?php else: ?>
    <span class="muted">Multiple / pending</span>
  <?php endif; ?>
</td>
  <td class="num"><?= e(format_money((float) $b['revenue_per_nin'])) ?></td>
              <td class="num">
                <?= number_format((int) $b['total_rows']) ?>
                <?php if ((int) $b['error_rows'] > 0): ?>
                  <span class="badge badge--rejected" style="margin-left:4px"><?= (int) $b['error_rows'] ?> err</span>
                <?php endif; ?>
              </td>
              <td class="num"><?= number_format((int) $b['total_nin_generated']) ?></td>
              <td class="num"><?= e(format_money((float) $b['gross_revenue'])) ?></td>
              <td class="num"><?= e(format_money((float) $b['total_partner_commission'])) ?></td>
              <td><span class="badge badge--<?php
                echo match($b['status']) {
                    'COMPLETED' => 'active','FAILED' => 'rejected','CANCELLED' => 'inactive',
                    'IMPORTING' => 'submitted','PREVIEWED' => 'submitted','VALIDATED' => 'pending',
                    default => 'pending',
                };
              ?>"><?= e($b['status']) ?></span></td>
              <td><?= e(date('d M Y H:i', strtotime($b['created_at']))) ?></td>
                           <td class="text-right">
                <div class="tbl__actions" style="justify-content:flex-end">
                  <a class="btn btn-outline btn-sm"
                     href="<?= e(root_url('/superadmin/import-batch-view.php')) ?>?id=<?= (int) $b['id'] ?>">View</a>
                  <?php if ((int) $b['error_rows'] > 0): ?>
                    <a class="btn btn-outline btn-sm"
                       href="<?= e(root_url('/superadmin/import-errors.php')) ?>?id=<?= (int) $b['id'] ?>">Errors</a>
                  <?php endif; ?>
                  <?php if ($b['status'] === 'PREVIEWED' || $b['status'] === 'VALIDATED'): ?>
                    <a class="btn btn-grape btn-sm"
                       href="<?= e(root_url('/superadmin/import-preview.php')) ?>?id=<?= (int) $b['id'] ?>">Resume</a>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
      <ul class="pagination">
        <?php if ($page > 1): ?><li><a href="?page=<?= $page - 1 ?>">Previous</a></li><?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <li><?= $i === $page ? '<span class="active">' . $i . '</span>' : '<a href="?page=' . $i . '">' . $i . '</a>' ?></li>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><li><a href="?page=<?= $page + 1 ?>">Next</a></li><?php endif; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>