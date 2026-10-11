<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/csv.php';
require_once PICT_ROOT . '/includes/commission.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$sa = superadmin_require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM import_batches WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$b = $stmt->fetch();
if (!$b) { flash('error', 'Batch not found.'); redirect('/superadmin/imports.php'); }
if (!in_array($b['status'], ['VALIDATED','PREVIEWED'], true)) {
    flash('error', 'Batch not ready for preview.');
    redirect('/superadmin/imports.php');
}

$map = $b['column_map'] ? json_decode((string) $b['column_map'], true) : [];
$path = PICT_ROOT . '/uploads/imports/' . $b['stored_filename'];
if (!is_file($path)) { flash('error', 'Uploaded file is missing.'); redirect('/superadmin/imports.php'); }

$stats = [
    'total' => 0, 'valid' => 0, 'error' => 0,
    'partner_own' => 0, 'sub_account' => 0, 'orphan' => 0,
    'unknown_partner' => 0, 'invalid_period' => 0, 'invalid_nin' => 0,
    'will_insert' => 0, 'will_update' => 0,
    'total_nin' => 0, 'gross_revenue' => 0.0, 'net_commission' => 0.0,
];
$validRows = [];
$errorRows = [];
$partnerBuckets   = [];
$subAccountBuckets = [];
$orphanBuckets    = [];
$stationBuckets   = [];
$uniquePeriods    = [];

try {
    $csv = csv_open($path);
    $handle = $csv['handle'];
    $headers = $csv['headers'];

    $colToField = [];
    foreach ($map as $field => $colName) {
        $idx = array_search($colName, $headers, true);
        if ($idx !== false) { $colToField[$idx] = $field; }
    }

    $rowNum = 1;
    while (($row = fgetcsv($handle, 0, $csv['delimiter'], '"', '\\')) !== false) {
        $rowNum++;
        if (count($row) === 1 && trim((string) $row[0]) === '') continue;
        $stats['total']++;

        $mapped = [];
        foreach ($colToField as $idx => $field) {
            $mapped[$field] = trim((string) ($row[$idx] ?? ''));
        }

        // --- Period ---
        $period = csv_parse_period($mapped['period'] ?? '');
        if ($period === null) {
            $errorRows[] = ['row' => $rowNum, 'reason' => 'Invalid Period value "' . ($mapped['period'] ?? '') . '"'];
            $stats['error']++; $stats['invalid_period']++;
            continue;
        }
        $effectiveDate = sprintf('%04d-%02d-01', $period['year'], $period['month']);
        $uniquePeriods[$period['year'] . '-' . $period['month']] = $period;

        // --- Numbers ---
        $nin    = (int) preg_replace('/\D/', '', $mapped['total_nin_generated'] ?? '0');
        $enrol  = (int) preg_replace('/\D/', '', $mapped['total_enrolments'] ?? '0');
        $ernew  = (int) preg_replace('/\D/', '', $mapped['ernew_nin'] ?? '0');
        $crnew  = (int) preg_replace('/\D/', '', $mapped['crnew_nin'] ?? '0');
        $female = (int) preg_replace('/\D/', '', $mapped['female_count'] ?? '0');
        $male   = (int) preg_replace('/\D/', '', $mapped['male_count'] ?? '0');
        if ($nin < 0) { $nin = 0; }

        // --- NIN ---
        $ninRaw = preg_replace('/\D/', '', $mapped['partner_nin'] ?? '');
        if (!preg_match('/^\d{11}$/', $ninRaw)) {
            $errorRows[] = ['row' => $rowNum, 'reason' => 'Invalid Partner NIN "' . ($mapped['partner_nin'] ?? '') . '" (must be 11 digits)'];
            $stats['error']++; $stats['invalid_nin']++;
            continue;
        }

        // --- Three-way resolution ---
        $resolved = csv_resolve_nin($ninRaw, $effectiveDate);
        $entity = $resolved['entity'];
        $partnerId = $resolved['partner_id'];
        $subAccountId = $resolved['sub_account_id'];
        $isOrphan = ($entity === 'orphan');
        $isSubAccount = ($entity === 'sub_account');

        if ($isOrphan) {
            $stats['orphan']++;
        } elseif ($isSubAccount) {
            $stats['sub_account']++;
        } else {
            $stats['partner_own']++;
        }

        // --- Station ---
        $stationId   = trim((string) ($mapped['station_id']   ?? ''));
        $stationName = trim((string) ($mapped['station_name'] ?? ''));
        if (mb_strlen($stationId) > 120)   { $stationId   = mb_substr($stationId, 0, 120); }
        if (mb_strlen($stationName) > 150) { $stationName = mb_substr($stationName, 0, 150); }

        // --- Commission ---
        if ($isOrphan) {
            $gross = round($nin * (float) $b['revenue_per_nin'], 2);
            $snap = [
                'gross_revenue' => $gross, 'commission_amount' => 0.0,
                'pct' => 0.0, 'rate_id' => null,
            ];
        } else {
            $rate = $resolved['rate_row'];
            if ($rate === null) {
                $errorRows[] = [
                    'row' => $rowNum,
                    'reason' => 'No applicable commission rate for ' . e($resolved['display_name']) . ' on ' . $effectiveDate,
                ];
                $stats['error']++;
                continue;
            }
            if ($isSubAccount) {
                $snap = sub_account_compute($nin, (float) $b['revenue_per_nin'], $rate);
            } else {
                $snap = commission_compute($nin, (float) $b['revenue_per_nin'], $rate);
            }
        }

        // --- Existing record? ---
        $exStmt = db()->prepare("
            SELECT id, commission_amount, partner_id, sub_account_id
            FROM partner_enrolment_records
            WHERE partner_nin_raw = :pn
              AND period_year = :py
              AND period_month = :pm
              AND COALESCE(station_id_raw, '')   = :sid
              AND COALESCE(station_name_raw, '') = :sn
            ORDER BY id DESC
            LIMIT 1
        ");
        $exStmt->execute([
            ':pn'  => $ninRaw,
            ':py'  => (int) $period['year'],
            ':pm'  => (int) $period['month'],
            ':sid' => $stationId,
            ':sn'  => $stationName,
        ]);
        $existing = $exStmt->fetch();
        $action = $existing ? 'update' : 'insert';

        $validRows[] = [
            'row' => $rowNum, 'action' => $action,
            'entity' => $entity,
            'is_orphan' => $isOrphan,
            'is_sub_account' => $isSubAccount,
            'existing_id' => $existing ? (int) $existing['id'] : null,
            'existing_commission' => $existing ? (float) $existing['commission_amount'] : 0.0,
            'existing_partner_id' => $existing && $existing['partner_id'] !== null ? (int) $existing['partner_id'] : null,
            'existing_sub_account_id' => $existing && $existing['sub_account_id'] !== null ? (int) $existing['sub_account_id'] : null,
            'partner_id' => $partnerId,
            'sub_account_id' => $subAccountId,
            'partner_nin' => $ninRaw,
            'partner_name' => $resolved['display_name'],
            'partner_own_name' => $resolved['partner_own_name'],
            'partner_code' => $resolved['partner_code'],
            'station_id' => $stationId,
            'station_name' => $stationName,
            'period_year' => (int) $period['year'],
            'period_month' => (int) $period['month'],
            'effective_date' => $effectiveDate,
            'total_enrolments' => $enrol,
            'total_nin_generated' => $nin,
            'ernew_nin' => $ernew, 'crnew_nin' => $crnew,
            'female_count' => $female, 'male_count' => $male,
            'rate_id' => $snap['rate_id'],
            'commission_percentage' => $snap['pct'],
            'gross_revenue' => $snap['gross_revenue'],
            'commission_amount' => $snap['commission_amount'],
        ];

        $stats['valid']++;
        $stats[$action === 'update' ? 'will_update' : 'will_insert']++;
        $stats['total_nin'] += $nin;
        $stats['gross_revenue'] += $snap['gross_revenue'];

        if (!$isOrphan) {
            $delta = $action === 'update'
                ? ($snap['commission_amount'] - (float) $existing['commission_amount'])
                : $snap['commission_amount'];
            $stats['net_commission'] += $delta;
        }

        // Buckets
        if ($isSubAccount) {
            $key = $subAccountId;
            if (!isset($subAccountBuckets[$key])) {
                $subAccountBuckets[$key] = [
                    'sub_account_id' => $subAccountId,
                    'partner_id'     => $partnerId,
                    'partner_name'   => $resolved['partner_own_name'],
                    'sub_name'       => $resolved['display_name'],
                    'partner_nin'    => $ninRaw,
                    'nin'            => 0,
                    'gross'          => 0.0,
                    'commission'     => 0.0,
                    'pct'            => $snap['pct'],
                    'is_orphan'      => false,
                ];
            }
            $subAccountBuckets[$key]['nin']        += $nin;
            $subAccountBuckets[$key]['gross']      += $snap['gross_revenue'];
            $subAccountBuckets[$key]['commission'] += $snap['commission_amount'];
        } elseif (!$isOrphan) {
            $key = $partnerId;
            if (!isset($partnerBuckets[$key])) {
                $partnerBuckets[$key] = [
                    'partner_id'    => $partnerId,
                    'partner_name'  => $resolved['display_name'],
                    'partner_code'  => $resolved['partner_code'],
                    'partner_nin'   => $ninRaw,
                    'nin'           => 0,
                    'gross'         => 0.0,
                    'commission'    => 0.0,
                    'delta_commission' => 0.0,
                    'pct'           => $snap['pct'],
                ];
            }
            $partnerBuckets[$key]['nin']        += $nin;
            $partnerBuckets[$key]['gross']      += $snap['gross_revenue'];
            $partnerBuckets[$key]['commission'] += $snap['commission_amount'];
            $partnerBuckets[$key]['delta_commission'] += ($action === 'update'
                ? ($snap['commission_amount'] - (float) $existing['commission_amount'])
                : $snap['commission_amount']);
        } else {
            $key = $ninRaw;
            if (!isset($orphanBuckets[$key])) {
                $orphanBuckets[$key] = [
                    'partner_nin' => $ninRaw,
                    'name'        => $resolved['display_name'],
                    'nin'         => 0,
                    'gross'       => 0.0,
                ];
            }
            $orphanBuckets[$key]['nin']   += $nin;
            $orphanBuckets[$key]['gross'] += $snap['gross_revenue'];
        }
    }
    fclose($handle);
} catch (Throwable $ex) {
    flash('error', 'Failed to parse file: ' . $ex->getMessage());
    redirect('/superadmin/imports.php');
}

$batchPeriodYear = null;
$batchPeriodMonth = null;
if (count($uniquePeriods) === 1) {
    $p = reset($uniquePeriods);
    $batchPeriodYear = $p['year'];
    $batchPeriodMonth = $p['month'];
}

db()->beginTransaction();
try {
    db()->prepare("DELETE FROM import_batch_records WHERE import_batch_id = :id")->execute([':id' => $id]);

    $ins = db()->prepare("
        INSERT INTO import_batch_records
          (import_batch_id, row_number, partner_id, partner_code_raw, partner_nin_raw,
           station_id, station_code_raw, station_name_raw, station_id_raw,
           period_year, period_month,
           total_enrolments, total_nin_generated, ernew_nin, crnew_nin, female_count, male_count,
           status, error_message, created_at)
        VALUES
          (:b, :r, :p, NULL, :pn,
           NULL, NULL, :sn, :sid,
           :py, :pm,
           :e, :n, :er, :cr, :f, :m,
           :st, :err, :ca)
    ");
    foreach ($validRows as $v) {
        $ins->execute([
            ':b' => $id, ':r' => $v['row'], ':p' => $v['partner_id'],
            ':pn' => $v['partner_nin'],
            ':sn' => $v['station_name'] !== '' ? $v['station_name'] : null,
            ':sid' => $v['station_id'] !== '' ? $v['station_id'] : null,
            ':py' => $v['period_year'], ':pm' => $v['period_month'],
            ':e' => $v['total_enrolments'], ':n' => $v['total_nin_generated'],
            ':er' => $v['ernew_nin'], ':cr' => $v['crnew_nin'],
            ':f' => $v['female_count'], ':m' => $v['male_count'],
            ':st' => 'VALID', ':err' => null, ':ca' => now_dt(),
        ]);
    }
    foreach ($errorRows as $e) {
        $ins->execute([
            ':b' => $id, ':r' => $e['row'], ':p' => null,
            ':pn' => null, ':sn' => null, ':sid' => null, ':py' => null, ':pm' => null,
            ':e' => 0, ':n' => 0, ':er' => 0, ':cr' => 0, ':f' => 0, ':m' => 0,
            ':st' => 'ERROR', ':err' => mb_substr($e['reason'], 0, 500), ':ca' => now_dt(),
        ]);
    }

    db()->prepare("
        UPDATE import_batches SET
            period_year = :py, period_month = :pm,
            total_rows = :tr, valid_rows = :vr, error_rows = :er,
            duplicate_rows = 0, unknown_partner_rows = :upr, unknown_station_rows = 0,
            total_nin_generated = :tn, gross_revenue = :gr, total_partner_commission = :tc,
            status = 'PREVIEWED', updated_at = :ua
        WHERE id = :id
    ")->execute([
        ':py' => $batchPeriodYear, ':pm' => $batchPeriodMonth,
        ':tr' => $stats['total'], ':vr' => $stats['valid'], ':er' => $stats['error'],
        ':upr' => $stats['orphan'],
        ':tn' => $stats['total_nin'], ':gr' => round($stats['gross_revenue'], 2),
        ':tc' => round($stats['net_commission'], 2), ':ua' => now_dt(), ':id' => $id,
    ]);

    db()->commit();
} catch (Throwable $e) {
    db()->rollBack();
    error_log('[import-preview] ' . $e->getMessage());
    flash('error', 'Failed to stage preview.');
    redirect('/superadmin/imports.php');
}

require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head">
  <div>
    <h1>Import Preview</h1>
    <p>
      Batch <span class="masked"><?= e($b['batch_reference']) ?></span>
      <?php if ($batchPeriodYear !== null): ?>
        &middot; Period <?= e(format_period((int) $batchPeriodYear, (int) $batchPeriodMonth)) ?>
      <?php else: ?>
        &middot; Multiple periods
      <?php endif; ?>
    </p>
  </div>
</div>

<div class="steps">
  <div class="step step--done"><b>✓</b> Upload</div>
  <div class="step step--done"><b>✓</b> Map Columns</div>
  <div class="step step--active"><b>3</b> Preview</div>
  <div class="step"><b>4</b> Confirm</div>
</div>

<div class="stat-grid">
  <div class="stat"><div class="stat__label">Total Rows</div><div class="stat__value"><?= number_format($stats['total']) ?></div></div>
  <div class="stat stat--ok"><div class="stat__label">Valid</div><div class="stat__value"><?= number_format($stats['valid']) ?></div></div>
  <div class="stat"><div class="stat__label">Partner-Own NIN</div><div class="stat__value"><?= number_format($stats['partner_own']) ?></div></div>
  <div class="stat stat--purple"><div class="stat__label">Sub-Account NIN</div><div class="stat__value"><?= number_format($stats['sub_account']) ?></div></div>
  <div class="stat stat--pending"><div class="stat__label">Orphan (unmatched)</div><div class="stat__value"><?= number_format($stats['orphan']) ?></div></div>
  <div class="stat stat--err"><div class="stat__label">Errors</div><div class="stat__value"><?= number_format($stats['error']) ?></div></div>
</div>

<div class="stat-grid">
  <div class="stat"><div class="stat__label">Will Insert</div><div class="stat__value"><?= number_format($stats['will_insert']) ?></div></div>
  <div class="stat stat--pending"><div class="stat__label">Will Update</div><div class="stat__value"><?= number_format($stats['will_update']) ?></div></div>
  <div class="stat stat--purple"><div class="stat__label">Total NIN Generated</div><div class="stat__value"><?= number_format($stats['total_nin']) ?></div></div>
  <div class="stat stat--ok"><div class="stat__label">Estimated Gross Revenue</div><div class="stat__value"><?= e(format_money($stats['gross_revenue'])) ?></div></div>
  <div class="stat stat--purple"><div class="stat__label">Net Commission Change</div><div class="stat__value"><?= e(format_money($stats['net_commission'])) ?></div></div>
</div>

<?php if ($stats['orphan'] > 0): ?>
  <div class="alert alert-warn">
    <strong><?= number_format($stats['orphan']) ?> row(s)</strong> have NINs that do not yet belong to an ACTIVE partner or sub-account.
    These will be stored as unattached enrolment records. When a matching entity is approved, the records will attach automatically.
  </div>
<?php endif; ?>

<?php if (!empty($subAccountBuckets)): ?>
<div class="card mb-3">
  <h2>Sub-Account Impact</h2>
  <div style="overflow-x:auto">
    <table class="tbl">
      <thead>
        <tr>
          <th>Sub-Account</th>
          <th>Parent Partner</th>
          <th>NIN</th>
          <th class="num">NIN Count</th>
          <th class="num">Gross Commission</th>
          <th class="num">Comm %</th>
          <th class="num">Net Commission</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $saTotalGross = 0; $saTotalComm = 0; $saTotalNin = 0;
        foreach ($subAccountBuckets as $sb):
            $saTotalGross += $sb['gross'];
            $saTotalComm  += $sb['commission'];
            $saTotalNin   += $sb['nin'];
        ?>
          <tr>
            <td><strong><?= e($sb['sub_name']) ?></strong></td>
            <td><?= e($sb['partner_name']) ?></td>
            <td><span class="masked"><?= e($sb['partner_nin']) ?></span></td>
            <td class="num"><?= number_format($sb['nin']) ?></td>
            <td class="num"><?= e(format_money($sb['gross'])) ?></td>
            <td class="num"><?= e(number_format($sb['pct'], 2)) ?>%</td>
            <td class="num"><strong><?= e(format_money($sb['commission'])) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3"><strong>Sub-Account Totals</strong></td>
          <td class="num"><strong><?= number_format($saTotalNin) ?></strong></td>
          <td class="num"><strong><?= e(format_money($saTotalGross)) ?></strong></td>
          <td></td>
          <td class="num"><strong><?= e(format_money($saTotalComm)) ?></strong></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($partnerBuckets)): ?>
<div class="card mb-3">
  <h2>Partner-Own Impact</h2>
  <div style="overflow-x:auto">
    <table class="tbl">
      <thead>
        <tr>
          <th>Partner</th>
          <th>Code</th>
          <th>NIN</th>
          <th class="num">NIN Count</th>
          <th class="num">Gross Commission</th>
          <th class="num">Comm %</th>
          <th class="num">Net Commission</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($partnerBuckets as $pb): ?>
          <tr>
            <td><strong><?= e($pb['partner_name']) ?></strong></td>
            <td><span class="masked"><?= e($pb['partner_code']) ?></span></td>
            <td><span class="masked"><?= e($pb['partner_nin']) ?></span></td>
            <td class="num"><?= number_format($pb['nin']) ?></td>
            <td class="num"><?= e(format_money($pb['gross'])) ?></td>
            <td class="num"><?= e(number_format($pb['pct'], 2)) ?>%</td>
            <td class="num"><strong><?= e(format_money($pb['commission'])) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($orphanBuckets)): ?>
<div class="card mb-3">
  <h2>Orphan NINs (will attach on approval)</h2>
  <div style="overflow-x:auto">
    <table class="tbl tbl--compact">
      <thead>
        <tr>
          <th>NIN</th>
          <th>Resolved As</th>
          <th class="num">NIN Count</th>
          <th class="num">Gross Revenue</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($orphanBuckets as $ob): ?>
          <tr>
            <td><span class="masked"><?= e($ob['partner_nin']) ?></span></td>
            <td><?= e($ob['name']) ?></td>
            <td class="num"><?= number_format($ob['nin']) ?></td>
            <td class="num"><?= e(format_money($ob['gross'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if (!empty($errorRows)): ?>
<div class="card mb-3">
  <h2>Errors (first 50)</h2>
  <div style="overflow-x:auto">
    <table class="tbl tbl--compact">
      <thead><tr><th>Row #</th><th>Reason</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($errorRows, 0, 50) as $e): ?>
          <tr><td><?= (int) $e['row'] ?></td><td><?= e($e['reason']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p class="mt-2"><a class="btn btn-outline btn-sm" href="/superadmin/import-errors.php?id=<?= (int) $id ?>">Full error list</a></p>
</div>
<?php endif; ?>

<div class="card card--pad-lg">
  <h2>Confirm Import</h2>
  <?php if ($stats['valid'] === 0): ?>
    <div class="alert alert-danger">No valid rows. Fix the file and try again.</div>
    <a class="btn btn-outline" href="/superadmin/imports.php">Cancel</a>
  <?php else: ?>
    <div class="alert alert-info">
      This import will <strong>insert <?= number_format($stats['will_insert']) ?></strong> and
      <strong>update <?= number_format($stats['will_update']) ?></strong> record(s).
      <?php if ($stats['orphan'] > 0): ?>
        <br><strong><?= number_format($stats['orphan']) ?></strong> orphan record(s) will be stored unattached.
      <?php endif; ?>
    </div>
    <form method="post" action="<?= e(root_url('/superadmin/import-confirm.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="batch_id" value="<?= $id ?>">
      <button class="btn btn-grape btn-lg" type="submit" onclick="return confirm('Commit this import?');">Confirm Import</button>
      <a class="btn btn-outline" href="/superadmin/imports.php" style="margin-left:8px">Cancel</a>
    </form>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>