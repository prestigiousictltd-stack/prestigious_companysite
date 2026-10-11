<?php
/**
 * Read-only view of what a CSV import batch produced.
 * Available for any batch status (including UPLOADED and PREVIEWED).
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    flash('error', 'Batch ID is required.');
    redirect('/superadmin/imports.php');
}

$stmt = db()->prepare("
    SELECT b.*, sa.full_name AS uploader
    FROM import_batches b
    LEFT JOIN super_admins sa ON sa.id = b.uploaded_by_super_admin_id
    WHERE b.id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$b = $stmt->fetch();
if (!$b) {
    flash('error', 'Batch not found.');
    redirect('/superadmin/imports.php');
}

$page_title = 'Batch ' . $b['batch_reference'];
$active_nav = 'imports';

// Filters within the batch records
$fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
if (!in_array($fStatus, ['VALID','ERROR','DUPLICATE','SKIPPED'], true)) {
    $fStatus = '';
}
$fSearch = trim((string) ($_GET['q'] ?? ''));

$where  = ['r.import_batch_id = :bid'];
$params = [':bid' => $id];
if ($fStatus !== '') {
    $where[] = 'r.status = :st';
    $params[':st'] = $fStatus;
}
if ($fSearch !== '') {
    $where[] = '(r.partner_nin_raw LIKE :q OR r.station_id_raw LIKE :q OR r.station_name_raw LIKE :q)';
    $params[':q'] = '%' . $fSearch . '%';
}
$whereSql = implode(' AND ', $where);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$cStmt = db()->prepare("SELECT COUNT(*) FROM import_batch_records r WHERE $whereSql");
$cStmt->execute($params);
$total = (int) $cStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare("
    SELECT r.*,
           p.full_name AS partner_name, p.partner_code,
           sa.full_name AS sub_account_name,
           per.period_year AS per_year, per.period_month AS per_month,
           per.commission_amount AS enrolment_commission,
           per.commission_percentage AS enrolment_pct,
           per.gross_revenue AS enrolment_gross,
           per.sub_account_id AS enrolment_sub_account_id
    FROM import_batch_records r
    LEFT JOIN partners p ON p.id = r.partner_id
    LEFT JOIN partner_enrolment_records per
           ON per.partner_nin_raw = r.partner_nin_raw
          AND per.period_year = r.period_year
          AND per.period_month = r.period_month
          AND COALESCE(per.station_id_raw, '') = COALESCE(r.station_id_raw, '')
          AND COALESCE(per.station_name_raw, '') = COALESCE(r.station_name_raw, '')
          AND per.import_batch_id = r.import_batch_id
    LEFT JOIN partner_sub_accounts sa ON sa.id = per.sub_account_id
    WHERE $whereSql
    ORDER BY r.row_number ASC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Summary counts (always unfiltered so the header shows the true totals)
$sumStmt = db()->prepare("
    SELECT
      SUM(status = 'VALID')     AS valid_count,
      SUM(status = 'ERROR')     AS error_count,
      SUM(status = 'DUPLICATE') AS duplicate_count,
      SUM(status = 'SKIPPED')   AS skipped_count,
      COUNT(*) AS total
    FROM import_batch_records
    WHERE import_batch_id = :bid
");
$sumStmt->execute([':bid' => $id]);
$sum = $sumStmt->fetch() ?: ['valid_count'=>0,'error_count'=>0,'duplicate_count'=>0,'skipped_count'=>0,'total'=>0];

// Enrolment records that this batch actually wrote (only meaningful for COMPLETED batches)
$enrolStmt = db()->prepare("
    SELECT COUNT(*) AS cnt,
           COALESCE(SUM(total_nin_generated), 0) AS total_nin,
           COALESCE(SUM(gross_revenue), 0)       AS total_gross,
           COALESCE(SUM(commission_amount), 0)   AS total_commission,
           SUM(partner_id IS NULL)               AS orphan_count
    FROM partner_enrolment_records
    WHERE import_batch_id = :bid
");
$enrolStmt->execute([':bid' => $id]);
$enrolStats = $enrolStmt->fetch() ?: ['cnt'=>0,'total_nin'=>0,'total_gross'=>0,'total_commission'=>0,'orphan_count'=>0];

require PICT_ROOT . '/includes/header-superadmin.php';

$statusBadge = function (string $s): string {
    $cls = match ($s) {
        'COMPLETED'  => 'badge--active',
        'FAILED'     => 'badge--rejected',
        'CANCELLED'  => 'badge--inactive',
        'IMPORTING'  => 'badge--submitted',
        'PREVIEWED'  => 'badge--submitted',
        'VALIDATED'  => 'badge--pending',
        'UPLOADED'   => 'badge--pending',
        default      => 'badge--pending',
    };
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
};

$rowBadge = function (string $s): string {
    $cls = match ($s) {
        'VALID'     => 'badge--active',
        'ERROR'     => 'badge--rejected',
        'DUPLICATE' => 'badge--pending',
        'SKIPPED'   => 'badge--inactive',
        default     => 'badge--inactive',
    };
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
};
?>

<div class="page-head">
  <div>
    <h1>Batch <span class="masked"><?= e($b['batch_reference']) ?></span> <?= $statusBadge((string) $b['status']) ?></h1>
    <p>
      Uploaded <?= e(date('d M Y H:i', strtotime($b['created_at']))) ?>
      <?php if (!empty($b['uploader'])): ?> by <?= e($b['uploader']) ?><?php endif; ?>
      <?php if (!empty($b['confirmed_at'])): ?> &middot; Confirmed <?= e(date('d M Y H:i', strtotime($b['confirmed_at']))) ?><?php endif; ?>
    </p>
  </div>
    <div class="page-head__actions">
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/imports.php')) ?>">All Batches</a>
    <?php if ($b['status'] === 'PREVIEWED' || $b['status'] === 'VALIDATED'): ?>
      <a class="btn btn-grape" href="<?= e(root_url('/superadmin/import-preview.php')) ?>?id=<?= $id ?>">Resume Preview</a>
    <?php endif; ?>
    <?php if ((int) $b['error_rows'] > 0): ?>
      <a class="btn btn-outline" href="<?= e(root_url('/superadmin/import-errors.php')) ?>?id=<?= $id ?>">Error List</a>
    <?php endif; ?>
    <a class="btn btn-grape" href="<?= e(root_url('/superadmin/import-batch-print.php')) ?>?id=<?= $id ?>"
       target="_blank" rel="noopener">Print Batch</a>
    <?php if (!empty($rows)): ?>
      <a class="btn btn-outline" href="<?= e(root_url('/superadmin/import-batch-print.php')) ?>?id=<?= $id ?>&mode=rows"
         target="_blank" rel="noopener">Print Rows</a>
    <?php endif; ?>
  </div>
</div>

<!-- Batch metadata -->
<div class="card mb-3">
  <h2>Batch Details</h2>
  <table class="tbl tbl--compact">
    <tr><th style="width:220px">Batch Reference</th><td><span class="masked"><?= e($b['batch_reference']) ?></span></td></tr>
    <tr><th>Status</th><td><?= $statusBadge((string) $b['status']) ?></td></tr>
    <tr><th>Period</th>
      <td>
        <?php if ($b['period_year'] !== null && $b['period_month'] !== null): ?>
          <?= e(format_period((int) $b['period_year'], (int) $b['period_month'])) ?>
        <?php else: ?>
          <span class="muted">Multiple periods</span>
        <?php endif; ?>
      </td>
    </tr>
    <tr><th>Revenue per NIN</th><td><?= e(format_money((float) $b['revenue_per_nin'])) ?></td></tr>
    <tr><th>Original Filename</th><td><?= e($b['original_filename']) ?></td></tr>
    <tr><th>File Hash (SHA-256)</th><td><span class="masked"><?= e(substr((string) $b['file_hash'], 0, 16)) ?>…</span></td></tr>
    <tr><th>Uploaded By</th><td><?= e((string) $b['uploader']) ?: '<span class="muted">—</span>' ?></td></tr>
    <tr><th>Uploaded At</th><td><?= e(date('d M Y H:i', strtotime($b['created_at']))) ?></td></tr>
    <?php if (!empty($b['error_message'])): ?>
      <tr><th>Error Message</th><td style="color:#991b1b"><?= e($b['error_message']) ?></td></tr>
    <?php endif; ?>
  </table>
</div>

<!-- Batch totals -->
<div class="stat-grid">
  <div class="stat">
    <div class="stat__label">Total Rows</div>
    <div class="stat__value"><?= number_format((int) $b['total_rows']) ?></div>
  </div>
  <div class="stat stat--ok">
    <div class="stat__label">Valid Rows</div>
    <div class="stat__value"><?= number_format((int) $b['valid_rows']) ?></div>
  </div>
  <div class="stat stat--err">
    <div class="stat__label">Error Rows</div>
    <div class="stat__value"><?= number_format((int) $b['error_rows']) ?></div>
  </div>
  <div class="stat stat--pending">
    <div class="stat__label">Duplicate Rows</div>
    <div class="stat__value"><?= number_format((int) $b['duplicate_rows']) ?></div>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--purple">
    <div class="stat__label">Total NIN Generated</div>
    <div class="stat__value"><?= number_format((int) $b['total_nin_generated']) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Gross Revenue</div>
    <div class="stat__value"><?= e(format_money((float) $b['gross_revenue'])) ?></div>
  </div>
  <div class="stat stat--ok">
    <div class="stat__label">Total Partner Commission</div>
    <div class="stat__value"><?= e(format_money((float) $b['total_partner_commission'])) ?></div>
  </div>
</div>

<?php if ((int) $enrolStats['cnt'] > 0): ?>
<div class="card mb-3">
  <h2>What This Batch Wrote</h2>
  <table class="tbl tbl--compact">
    <tr>
      <th style="width:220px">Enrolment Records Created</th>
      <td><?= number_format((int) $enrolStats['cnt']) ?></td>
    </tr>
    <tr>
      <th>Total NIN (stored)</th>
      <td><?= number_format((int) $enrolStats['total_nin']) ?></td>
    </tr>
    <tr>
      <th>Total Gross Revenue (stored)</th>
      <td><?= e(format_money((float) $enrolStats['total_gross'])) ?></td>
    </tr>
    <tr>
      <th>Total Commission Credited</th>
      <td><strong><?= e(format_money((float) $enrolStats['total_commission'])) ?></strong></td>
    </tr>
    <?php if ((int) $enrolStats['orphan_count'] > 0): ?>
      <tr>
        <th>Unattached Records</th>
        <td>
          <span class="badge badge--pending"><?= number_format((int) $enrolStats['orphan_count']) ?> awaiting partner</span>
        </td>
      </tr>
    <?php endif; ?>
  </table>
</div>
<?php endif; ?>

<!-- Filter bar -->
<div class="filter-bar">
  <form method="get">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <div class="form-group">
      <label class="form-label">Row Status</label>
      <select class="form-control" name="status">
        <option value="">All statuses</option>
        <?php foreach (['VALID','ERROR','DUPLICATE','SKIPPED'] as $s): ?>
          <option value="<?= $s ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Search</label>
      <input class="form-control" type="text" name="q" value="<?= e($fSearch) ?>" placeholder="NIN, Station ID, Station Name">
    </div>
    <div class="form-group">
      <button class="btn btn-grape" type="submit">Filter</button>
      <a class="btn btn-outline" href="?id=<?= (int) $id ?>">Reset</a>
    </div>
  </form>
</div>

<!-- Row list -->
<div class="card">
  <div class="flex-between mb-2">
    <h2 style="margin:0">Row Records</h2>
    <span class="muted" style="font-size:13px"><?= number_format($total) ?> row(s) shown</span>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty"><p>No rows match your filters.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr>
            <th style="width:60px">Row #</th>
            <th>Status</th>
            <th>Partner NIN</th>
            <th>Partner</th>
            <th>Sub-Account</th>
            <th>Station ID</th>
            <th>Station Name</th>
            <th>Period</th>
            <th class="num">Enrolments</th>
            <th class="num">NIN</th>
            <th class="num">ERNEW</th>
            <th class="num">CRNEW</th>
            <th class="num">F</th>
            <th class="num">M</th>
            <?php if ((int) $b['status'] === 0 || in_array($b['status'], ['COMPLETED'], true)): ?>
              <th class="num">Gross</th>
              <th class="num">Comm %</th>
              <th class="num">Commission</th>
            <?php endif; ?>
            <th>Message</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= (int) $r['row_number'] ?></td>
              <td><?= $rowBadge((string) $r['status']) ?></td>
              <td>
                <?php
                  $nin = (string) ($r['partner_nin_raw'] ?? '');
                  echo $nin !== ''
                      ? '<span class="masked">' . e($nin) . '</span>'
                      : '<span class="muted">—</span>';
                ?>
              </td>
              <td>
                <?php if (!empty($r['partner_name'])): ?>
                  <?= e($r['partner_name']) ?>
                  <?php if (!empty($r['partner_code'])): ?>
                    <br><span class="muted" style="font-size:12px"><?= e($r['partner_code']) ?></span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="badge badge--pending">Unattached</span>
                <?php endif; ?>
              </td>
                            <td>
                <?php if (!empty($r['sub_account_name'])): ?>
                  <span class="badge badge--active" style="background:#e0e7ff;color:#4338ca">Sub</span>
                  <?= e($r['sub_account_name']) ?>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?= !empty($r['station_id_raw'])
                    ? '<span class="masked">' . e($r['station_id_raw']) . '</span>'
                    : '<span class="muted">—</span>' ?>
              </td>
              <td>
                <?= !empty($r['station_name_raw'])
                    ? e($r['station_name_raw'])
                    : '<span class="muted">—</span>' ?>
              </td>
              <td>
                <?php if ($r['period_year'] && $r['period_month']): ?>
                  <?= e(format_period((int) $r['period_year'], (int) $r['period_month'])) ?>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td class="num"><?= number_format((int) $r['total_enrolments']) ?></td>
              <td class="num"><?= number_format((int) $r['total_nin_generated']) ?></td>
              <td class="num"><?= number_format((int) $r['ernew_nin']) ?></td>
              <td class="num"><?= number_format((int) $r['crnew_nin']) ?></td>
              <td class="num"><?= number_format((int) $r['female_count']) ?></td>
              <td class="num"><?= number_format((int) $r['male_count']) ?></td>
              <?php if (in_array($b['status'], ['COMPLETED'], true)): ?>
                <td class="num">
                  <?= $r['enrolment_gross'] !== null ? e(format_money((float) $r['enrolment_gross'])) : '<span class="muted">—</span>' ?>
                </td>
                <td class="num">
                  <?= $r['enrolment_pct'] !== null
                      ? e(number_format((float) $r['enrolment_pct'], 2)) . '%'
                      : '<span class="muted">—</span>' ?>
                </td>
                <td class="num">
                  <?= $r['enrolment_commission'] !== null
                      ? '<strong>' . e(format_money((float) $r['enrolment_commission'])) . '</strong>'
                      : '<span class="muted">deferred</span>' ?>
                </td>
              <?php endif; ?>
              <td>
                <?php if (!empty($r['error_message'])): ?>
                  <span style="color:#991b1b;font-size:12.5px"><?= e($r['error_message']) ?></span>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <ul class="pagination">
        <?php
        $qs = ['id' => $id, 'status' => $fStatus, 'q' => $fSearch];
        $base = http_build_query($qs);
        ?>
        <?php if ($page > 1): ?>
          <li><a href="?<?= e($base) ?>&page=<?= $page - 1 ?>">Previous</a></li>
        <?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <li>
            <?= $i === $page
                ? '<span class="active">' . $i . '</span>'
                : '<a href="?' . e($base) . '&page=' . $i . '">' . $i . '</a>' ?>
          </li>
        <?php endfor; ?>
        <?php if ($page < $pages): ?>
          <li><a href="?<?= e($base) ?>&page=<?= $page + 1 ?>">Next</a></li>
        <?php endif; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>