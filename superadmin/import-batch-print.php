<?php
/**
 * Printable view of a CSV import batch.
 * Query:
 *   ?id=N            — the batch
 *   &mode=rows       — optional; if present, only the row records are shown
 *   &status=VALID    — optional; filter rows by status (valid/error/duplicate)
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$id   = (int) ($_GET['id'] ?? 0);
$mode = (string) ($_GET['mode'] ?? 'full');   // 'full' or 'rows'
$rowsOnly = ($mode === 'rows');

if ($id <= 0) {
    http_response_code(400);
    echo 'Batch ID required.';
    exit;
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
    http_response_code(404);
    echo 'Batch not found.';
    exit;
}

// Row filters (only used on the row-records section)
$fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
if (!in_array($fStatus, ['VALID','ERROR','DUPLICATE','SKIPPED'], true)) {
    $fStatus = '';
}

$where  = ['r.import_batch_id = :bid'];
$params = [':bid' => $id];
if ($fStatus !== '') {
    $where[] = 'r.status = :st';
    $params[':st'] = $fStatus;
}
$whereSql = implode(' AND ', $where);

$stmt = db()->prepare("
    SELECT r.*,
           p.full_name AS partner_name, p.partner_code,
           sa.full_name AS sub_account_name
    FROM import_batch_records r
    LEFT JOIN partners p ON p.id = r.partner_id
    LEFT JOIN partner_sub_accounts sa ON sa.id = (
        SELECT s2.id FROM partner_sub_accounts s2
        WHERE s2.nin_number = r.partner_nin_raw
        LIMIT 1
    )
    WHERE $whereSql
    ORDER BY r.row_number ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Batch summary counts — always unfiltered
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

$companyName  = (string) setting('company_name', 'Prestigious ICT Investment Limited');
$companyAddr  = (string) setting('company_address', '');
$companyPhone = (string) setting('company_phone', '');
$companyEmail = (string) setting('company_email', '');
$esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fmtDate = fn($d) => $d ? date('d M Y H:i', strtotime((string) $d)) : '—';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= $esc($b['batch_reference']) ?> — <?= $esc($companyName) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Helvetica Neue",Arial,sans-serif;color:#0f172a;font-size:11pt;line-height:1.5;background:#f5f5f5;padding:20px 0}
.page{background:#fff;max-width:1100px;margin:0 auto;padding:36px 44px;box-shadow:0 2px 12px rgba(0,0,0,.08)}

/* Header */
.report-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;border-bottom:2px solid #0f172a;padding-bottom:14px;margin-bottom:22px;flex-wrap:wrap}
.report-head h1{margin:0 0 3px;font-size:17pt;font-weight:700;letter-spacing:-.01em}
.report-head h2{margin:0;font-size:10pt;font-weight:600;letter-spacing:.18em;text-transform:uppercase;color:#666}
.report-meta{border-collapse:collapse;font-size:9.5pt}
.report-meta th,.report-meta td{padding:1px 0;text-align:left;vertical-align:top}
.report-meta th{color:#666;font-weight:600;padding-right:10px;white-space:nowrap}

/* Sections */
.section{margin-bottom:22px;page-break-inside:avoid}
.section h3{font-size:10.5pt;letter-spacing:.06em;text-transform:uppercase;margin:0 0 10px;padding-bottom:4px;border-bottom:1px solid #cbd5e1;font-weight:700}

/* Tables */
.tbl{width:100%;border-collapse:collapse;font-size:9.5pt}
.tbl th,.tbl td{padding:6px 8px;border:1px solid #cbd5e1;text-align:left;vertical-align:top}
.tbl th{background:#f1f5f9;font-weight:700;font-size:8.5pt;letter-spacing:.04em;text-transform:uppercase;color:#475569}
.tbl td.num,.tbl th.num{text-align:right;font-variant-numeric:tabular-nums}
.tbl tfoot td{background:#f1f5f9;font-weight:700}
.tbl--compact th,.tbl--compact td{padding:4px 6px;font-size:9pt}
.tbl--rows th,.tbl--rows td{font-size:8.5pt;padding:4px 5px}

/* Status badges — print-safe grayscale-compatible */
.badge{display:inline-block;font-size:8pt;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:1px 6px;border-radius:3px;border:1px solid}
.badge--valid    {background:#dcfce7;color:#166534;border-color:#bbf7d0}
.badge--error    {background:#fee2e2;color:#991b1b;border-color:#fecaca}
.badge--duplicate{background:#fef3c7;color:#92400e;border-color:#fde68a}
.badge--skipped  {background:#e5e7eb;color:#374151;border-color:#d1d5db}

/* Summary grid */
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}
.summary-item{border:1px solid #cbd5e1;border-radius:6px;padding:10px 12px}
.summary-item .label{font-size:8.5pt;color:#666;text-transform:uppercase;letter-spacing:.05em;font-weight:600}
.summary-item .value{font-size:13pt;font-weight:700;margin-top:2px}

/* Footer */
.foot{border-top:1px solid #cbd5e1;margin-top:28px;padding-top:12px;font-size:8.5pt;color:#666;text-align:center;line-height:1.5}
.foot-sign{margin-top:4px;font-style:italic}

/* Toolbar (screen-only) */
.toolbar{max-width:1100px;margin:0 auto 14px;display:flex;justify-content:space-between;gap:10px;padding:0 4px}
.toolbar .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;background:#7c3aed;color:#fff;text-decoration:none;font-size:13px;font-weight:600;border:0;cursor:pointer}
.toolbar .btn-outline{background:#fff;color:#0f172a;border:1px solid #cbd5e1}

@page{margin:14mm}
@media print{
  body{background:#fff;padding:0}
  .page{box-shadow:none;padding:0;max-width:none;margin:0}
  .toolbar{display:none}
  .tbl{page-break-inside:auto}
  .tbl tr{page-break-inside:avoid;page-break-after:auto}
  .tbl thead{display:table-header-group}
  .tbl tfoot{display:table-footer-group}
}
</style>
</head>
<body>

<div class="toolbar">
  <button class="btn" onclick="window.print()">&#128424; Print / Save as PDF</button>
  <a class="btn btn-outline" href="<?= e(root_url('/superadmin/import-batch-view.php')) ?>?id=<?= $id ?>">Close</a>
</div>

<div class="page">

  <div class="report-head">
    <div>
      <h1><?= $esc($companyName) ?></h1>
      <h2><?= $rowsOnly ? 'CSV Import — Row Records' : 'CSV Import Batch Report' ?></h2>
    </div>
    <table class="report-meta">
      <tr><th>Batch:</th><td><?= $esc($b['batch_reference']) ?></td></tr>
      <tr><th>Status:</th><td><?= $esc($b['status']) ?></td></tr>
      <tr><th>Uploaded:</th><td><?= $esc($fmtDate($b['created_at'])) ?></td></tr>
      <?php if (!empty($b['confirmed_at'])): ?>
        <tr><th>Confirmed:</th><td><?= $esc($fmtDate($b['confirmed_at'])) ?></td></tr>
      <?php endif; ?>
      <?php if (!empty($b['uploader'])): ?>
        <tr><th>Uploaded by:</th><td><?= $esc($b['uploader']) ?></td></tr>
      <?php endif; ?>
      <tr><th>Generated:</th><td><?= date('d M Y H:i') ?></td></tr>
    </table>
  </div>

  <?php if (!$rowsOnly): ?>
  <!-- Batch details -->
  <div class="section">
    <h3>Batch Details</h3>
    <table class="tbl">
      <tbody>
        <tr><th style="width:220px">Batch Reference</th><td><?= $esc($b['batch_reference']) ?></td></tr>
        <tr><th>Status</th><td><?= $esc($b['status']) ?></td></tr>
        <tr><th>Period</th><td>
          <?php if ($b['period_year'] !== null && $b['period_month'] !== null): ?>
            <?= $esc(format_period((int) $b['period_year'], (int) $b['period_month'])) ?>
          <?php else: ?>
            Multiple / pending
          <?php endif; ?>
        </td></tr>
        <tr><th>Revenue per NIN</th><td><?= $esc(format_money((float) $b['revenue_per_nin'])) ?></td></tr>
        <tr><th>Original Filename</th><td><?= $esc($b['original_filename']) ?></td></tr>
        <tr><th>File Hash (SHA-256)</th><td><?= $esc(substr((string) $b['file_hash'], 0, 24)) ?>…</td></tr>
        <?php if (!empty($b['error_message'])): ?>
          <tr><th>Error Message</th><td><?= $esc($b['error_message']) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Batch summary -->
  <div class="section">
    <h3>Batch Summary</h3>
    <div class="summary-grid">
      <div class="summary-item">
        <div class="label">Total Rows</div>
        <div class="value"><?= number_format((int) $b['total_rows']) ?></div>
      </div>
      <div class="summary-item">
        <div class="label">Valid Rows</div>
        <div class="value"><?= number_format((int) $b['valid_rows']) ?></div>
      </div>
      <div class="summary-item">
        <div class="label">Error Rows</div>
        <div class="value"><?= number_format((int) $b['error_rows']) ?></div>
      </div>
      <div class="summary-item">
        <div class="label">Duplicate Rows</div>
        <div class="value"><?= number_format((int) $b['duplicate_rows']) ?></div>
      </div>
    </div>

    <table class="tbl">
      <tbody>
        <tr><th style="width:260px">Total NIN Generated</th><td class="num"><?= number_format((int) $b['total_nin_generated']) ?></td></tr>
        <tr><th>Gross Revenue</th><td class="num"><?= $esc(format_money((float) $b['gross_revenue'])) ?></td></tr>
        <tr><th>Total Partner Commission</th><td class="num"><?= $esc(format_money((float) $b['total_partner_commission'])) ?></td></tr>
        <tr><th>Unknown Partner Rows</th><td class="num"><?= number_format((int) $b['unknown_partner_rows']) ?></td></tr>
        <tr><th>Unknown Station Rows</th><td class="num"><?= number_format((int) $b['unknown_station_rows']) ?></td></tr>
        <tr><th>Partial Import</th><td><?= (int) $b['partial_import'] === 1 ? 'Yes' : 'No' ?></td></tr>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Row records -->
  <div class="section">
    <h3>Row Records<?= $fStatus !== '' ? ' — ' . $esc($fStatus) . ' only' : '' ?></h3>

    <?php if (empty($rows)): ?>
      <p style="color:#888;font-style:italic">No rows match.</p>
    <?php else: ?>
      <table class="tbl tbl--rows">
        <thead>
          <tr>
            <th style="width:40px">#</th>
            <th style="width:60px">Status</th>
            <th>Partner NIN</th>
            <th>Partner / Sub-Account</th>
            <th>Station ID</th>
            <th>Station Name</th>
            <th>Period</th>
            <th class="num">Enrol.</th>
            <th class="num">NIN</th>
            <th class="num">ERNEW</th>
            <th class="num">CRNEW</th>
            <th class="num">F</th>
            <th class="num">M</th>
            <th>Message</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= (int) $r['row_number'] ?></td>
              <td>
                <span class="badge badge--<?= strtolower((string) $r['status']) ?>">
                  <?= $esc($r['status']) ?>
                </span>
              </td>
              <td style="font-family:'SF Mono',Menlo,Consolas,monospace;letter-spacing:.4px">
                <?= $esc((string) ($r['partner_nin_raw'] ?? '')) ?: '—' ?>
              </td>
              <td>
                <?php if (!empty($r['sub_account_name'])): ?>
                  <strong>Sub:</strong> <?= $esc($r['sub_account_name']) ?>
                <?php elseif (!empty($r['partner_name'])): ?>
                  <?= $esc($r['partner_name']) ?>
                <?php else: ?>
                  <em style="color:#888">Unattached</em>
                <?php endif; ?>
              </td>
              <td><?= $esc((string) ($r['station_id_raw'] ?? '')) ?: '—' ?></td>
              <td><?= $esc((string) ($r['station_name_raw'] ?? '')) ?: '—' ?></td>
              <td>
                <?php if ($r['period_year'] && $r['period_month']): ?>
                  <?= $esc(format_period((int) $r['period_year'], (int) $r['period_month'])) ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="num"><?= number_format((int) $r['total_enrolments']) ?></td>
              <td class="num"><?= number_format((int) $r['total_nin_generated']) ?></td>
              <td class="num"><?= number_format((int) $r['ernew_nin']) ?></td>
              <td class="num"><?= number_format((int) $r['crnew_nin']) ?></td>
              <td class="num"><?= number_format((int) $r['female_count']) ?></td>
              <td class="num"><?= number_format((int) $r['male_count']) ?></td>
              <td><?= $esc((string) ($r['error_message'] ?? '')) ?: '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="7"><strong>Totals for shown rows</strong></td>
            <td class="num"><strong><?= number_format(array_sum(array_column($rows, 'total_enrolments'))) ?></strong></td>
            <td class="num"><strong><?= number_format(array_sum(array_column($rows, 'total_nin_generated'))) ?></strong></td>
            <td class="num"><strong><?= number_format(array_sum(array_column($rows, 'ernew_nin'))) ?></strong></td>
            <td class="num"><strong><?= number_format(array_sum(array_column($rows, 'crnew_nin'))) ?></strong></td>
            <td class="num"><strong><?= number_format(array_sum(array_column($rows, 'female_count'))) ?></strong></td>
            <td class="num"><strong><?= number_format(array_sum(array_column($rows, 'male_count'))) ?></strong></td>
            <td></td>
          </tr>
        </tfoot>
      </table>

      <?php if ($fStatus === ''): ?>
        <div style="margin-top:10px;font-size:9pt;color:#666">
          Status breakdown: Valid <?= number_format((int) $sum['valid_count']) ?>,
          Error <?= number_format((int) $sum['error_count']) ?>,
          Duplicate <?= number_format((int) $sum['duplicate_count']) ?>,
          Skipped <?= number_format((int) $sum['skipped_count']) ?>,
          Total <?= number_format((int) $sum['total']) ?>.
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="foot">
    <?php if ($companyAddr !== ''): ?><div><?= $esc($companyAddr) ?></div><?php endif; ?>
    <?php if ($companyPhone !== '' || $companyEmail !== ''): ?>
      <div><?= $esc($companyPhone) ?><?= ($companyPhone !== '' && $companyEmail !== '') ? ' · ' : '' ?><?= $esc($companyEmail) ?></div>
    <?php endif; ?>
    <div class="foot-sign">Generated <?= date('d M Y H:i') ?> by <?= $esc($sa['full_name']) ?> — Batch <?= $esc($b['batch_reference']) ?></div>
  </div>

</div>

</body>
</html>