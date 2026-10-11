<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/csv.php';
require_once PICT_ROOT . '/includes/commission.php';
require_once PICT_ROOT . '/includes/financial.php';
require_once PICT_ROOT . '/includes/aggregator.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$sa = superadmin_require_login();

if (!is_post()) { redirect('/superadmin/imports.php'); }
csrf_require();

$batchId = (int) ($_POST['batch_id'] ?? 0);

$stmt = db()->prepare("SELECT * FROM import_batches WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $batchId]);
$b = $stmt->fetch();
if (!$b) { flash('error', 'Batch not found.'); redirect('/superadmin/imports.php'); }
if (!in_array($b['status'], ['PREVIEWED','VALIDATED'], true)) {
    flash('error', 'Batch already processed or in an invalid state.');
    redirect('/superadmin/imports.php');
}

$map = $b['column_map'] ? json_decode((string) $b['column_map'], true) : [];
$path = PICT_ROOT . '/uploads/imports/' . $b['stored_filename'];
if (!is_file($path)) { flash('error', 'Uploaded file is missing.'); redirect('/superadmin/imports.php'); }

try {
    $csv = csv_open($path);
    $handle = $csv['handle'];
    $headers = $csv['headers'];
    $colToField = [];
    foreach ($map as $field => $colName) {
        $idx = array_search($colName, $headers, true);
        if ($idx !== false) $colToField[$idx] = $field;
    }

    $rows = [];
    $rowNum = 1;
    while (($row = fgetcsv($handle, 0, $csv['delimiter'], '"', '\\')) !== false) {
        $rowNum++;
        if (count($row) === 1 && trim((string) $row[0]) === '') continue;
        $mapped = [];
        foreach ($colToField as $idx => $field) $mapped[$field] = trim((string) ($row[$idx] ?? ''));

        $period = csv_parse_period($mapped['period'] ?? '');
        if ($period === null) continue;
        $effectiveDate = sprintf('%04d-%02d-01', $period['year'], $period['month']);

        $ninRaw = preg_replace('/\D/', '', $mapped['partner_nin'] ?? '');
        if (!preg_match('/^\d{11}$/', $ninRaw)) continue;

        $resolved = csv_resolve_nin($ninRaw, $effectiveDate);
        $entity       = $resolved['entity'];
        $partnerId    = $resolved['partner_id'];
        $subAccountId = $resolved['sub_account_id'];
        $isOrphan     = ($entity === 'orphan');
        $isSubAccount = ($entity === 'sub_account');

        $nin    = (int) preg_replace('/\D/', '', $mapped['total_nin_generated'] ?? '0');
        $enrol  = (int) preg_replace('/\D/', '', $mapped['total_enrolments'] ?? '0');
        $ernew  = (int) preg_replace('/\D/', '', $mapped['ernew_nin'] ?? '0');
        $crnew  = (int) preg_replace('/\D/', '', $mapped['crnew_nin'] ?? '0');
        $female = (int) preg_replace('/\D/', '', $mapped['female_count'] ?? '0');
        $male   = (int) preg_replace('/\D/', '', $mapped['male_count'] ?? '0');

        $stationId   = trim((string) ($mapped['station_id']   ?? ''));
        $stationName = trim((string) ($mapped['station_name'] ?? ''));
        if (mb_strlen($stationId) > 120)   { $stationId   = mb_substr($stationId, 0, 120); }
        if (mb_strlen($stationName) > 150) { $stationName = mb_substr($stationName, 0, 150); }

        if ($isOrphan) {
            $gross = round($nin * (float) $b['revenue_per_nin'], 2);
            $snap = ['gross_revenue' => $gross, 'commission_amount' => 0.0, 'pct' => 0.0, 'rate_id' => null];
        } else {
            $rate = $resolved['rate_row'];
            if ($rate === null) continue;
            $snap = $isSubAccount
                ? sub_account_compute($nin, (float) $b['revenue_per_nin'], $rate)
                : commission_compute($nin, (float) $b['revenue_per_nin'], $rate);
        }

        $rows[] = [
            'partner_id' => $partnerId,
            'sub_account_id' => $subAccountId,
            'entity' => $entity,
            'is_orphan' => $isOrphan,
            'is_sub_account' => $isSubAccount,
            'partner_nin' => $ninRaw,
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
    }
    fclose($handle);
} catch (Throwable $e) {
    flash('error', 'Failed to re-parse file: ' . $e->getMessage());
    redirect('/superadmin/imports.php');
}

db()->beginTransaction();
try {
    db()->prepare("UPDATE import_batches SET status = 'IMPORTING', updated_at = :ua WHERE id = :id")
        ->execute([':ua' => now_dt(), ':id' => $batchId]);

    $stats = [
        'inserted' => 0, 'updated' => 0, 'orphan_inserted' => 0, 'orphan_updated' => 0,
        'sub_inserted' => 0, 'sub_updated' => 0,
        'total_nin' => 0, 'gross' => 0.0, 'net_commission' => 0.0,
        'aggregator_settled' => 0, 'aggregator_amount' => 0.0,
    ];
    $partnerTotals = [];
    $subTotals = [];

    foreach ($rows as $v) {
        $exStmt = db()->prepare("
            SELECT id, commission_amount, partner_id, sub_account_id
            FROM partner_enrolment_records
            WHERE partner_nin_raw = :pn
              AND period_year = :py
              AND period_month = :pm
              AND COALESCE(station_id_raw, '')   = :sid
              AND COALESCE(station_name_raw, '') = :sn
            ORDER BY id DESC LIMIT 1
        ");
        $exStmt->execute([
            ':pn'  => $v['partner_nin'],
            ':py'  => $v['period_year'],
            ':pm'  => $v['period_month'],
            ':sid' => $v['station_id'],
            ':sn'  => $v['station_name'],
        ]);
        $existing = $exStmt->fetch();

        if ($existing) {
            $oldCommission = (float) $existing['commission_amount'];
            $delta = round($v['commission_amount'] - $oldCommission, 2);

            db()->prepare("
                UPDATE partner_enrolment_records SET
                    import_batch_id = :bid,
                    partner_id = :pid,
                    sub_account_id = :subid,
                    partner_nin_raw = :pn,
                    total_enrolments = :en,
                    total_nin_generated = :nin,
                    ernew_nin = :er,
                    crnew_nin = :cr,
                    female_count = :f,
                    male_count = :m,
                    revenue_per_nin = :rpn,
                    commission_percentage = :cpct,
                    commission_rate_id = :crid,
                    effective_date_used = :ed,
                    gross_revenue = :gr,
                    commission_amount = :ca,
                    station_id_raw = :sid,
                    station_name_raw = :sn
                WHERE id = :id
            ")->execute([
                ':bid' => $batchId, ':pid' => $v['partner_id'], ':subid' => $v['sub_account_id'],
                ':pn' => $v['partner_nin'],
                ':en' => $v['total_enrolments'], ':nin' => $v['total_nin_generated'],
                ':er' => $v['ernew_nin'], ':cr' => $v['crnew_nin'],
                ':f'  => $v['female_count'], ':m' => $v['male_count'],
                ':rpn' => (float) $b['revenue_per_nin'], ':cpct' => $v['commission_percentage'],
                ':crid' => $v['is_sub_account'] ? null : $v['rate_id'],
                ':ed' => $v['effective_date'],
                ':gr' => $v['gross_revenue'], ':ca' => $v['commission_amount'],
                ':sid' => $v['station_id'] !== '' ? $v['station_id'] : null,
                ':sn'  => $v['station_name'] !== '' ? $v['station_name'] : null,
                ':id' => (int) $existing['id'],
            ]);
            $enrolId = (int) $existing['id'];

            if ($v['is_orphan']) {
                $stats['orphan_updated']++;
            } elseif ($v['is_sub_account']) {
                $stats['sub_updated']++;
                if (abs($delta) > 0.009) {
                    $type = $delta > 0 ? 'COMMISSION_EARNED' : 'REVERSAL';
                    ledger_record(
                        (int) $v['partner_id'], $type, $delta,
                        $b['batch_reference'],
                        'Sub-account re-import adjustment for '
                            . format_period($v['period_year'], $v['period_month']),
                        ['year' => $v['period_year'], 'month' => $v['period_month']],
                        ['enrolment_id' => $enrolId, 'import_batch_id' => $batchId],
                        'SUPER_ADMIN', (int) $sa['id'],
                        (int) $v['sub_account_id']
                    );
                    $stats['net_commission'] += $delta;
                }
            } else {
                $stats['updated']++;
                if ($existing['partner_id'] !== null && abs($delta) > 0.009) {
                    $type = $delta > 0 ? 'COMMISSION_EARNED' : 'REVERSAL';
                    ledger_record(
                        (int) $v['partner_id'], $type, $delta,
                        $b['batch_reference'],
                        'Period ' . format_period($v['period_year'], $v['period_month']) . ' re-import adjustment',
                        ['year' => $v['period_year'], 'month' => $v['period_month']],
                        ['enrolment_id' => $enrolId, 'import_batch_id' => $batchId],
                        'SUPER_ADMIN', (int) $sa['id'],
                        null
                    );
                    $stats['net_commission'] += $delta;
                }
            }
        } else {
            db()->prepare("
                INSERT INTO partner_enrolment_records
                  (partner_id, sub_account_id, partner_nin_raw, station_id, station_id_raw, station_name_raw, import_batch_id,
                   period_year, period_month,
                   total_enrolments, total_nin_generated, ernew_nin, crnew_nin,
                   female_count, male_count,
                   revenue_per_nin, commission_percentage, commission_rate_id, effective_date_used,
                   gross_revenue, commission_amount, created_at)
                VALUES
                  (:pid, :subid, :pn, NULL, :sid, :sn, :bid,
                   :py, :pm,
                   :en, :nin, :er, :cr,
                   :f, :m,
                   :rpn, :cpct, :crid, :ed,
                   :gr, :ca, :cat)
            ")->execute([
                ':pid' => $v['partner_id'], ':subid' => $v['sub_account_id'],
                ':pn' => $v['partner_nin'],
                ':sid' => $v['station_id'] !== '' ? $v['station_id'] : null,
                ':sn'  => $v['station_name'] !== '' ? $v['station_name'] : null,
                ':bid' => $batchId,
                ':py' => $v['period_year'], ':pm' => $v['period_month'],
                ':en' => $v['total_enrolments'], ':nin' => $v['total_nin_generated'],
                ':er' => $v['ernew_nin'], ':cr' => $v['crnew_nin'],
                ':f'  => $v['female_count'], ':m' => $v['male_count'],
                ':rpn' => (float) $b['revenue_per_nin'], ':cpct' => $v['commission_percentage'],
                ':crid' => $v['is_sub_account'] ? null : $v['rate_id'],
                ':ed' => $v['effective_date'],
                ':gr' => $v['gross_revenue'], ':ca' => $v['commission_amount'],
                ':cat' => now_dt(),
            ]);
            $enrolId = (int) db()->lastInsertId();

            if ($v['is_orphan']) {
                $stats['orphan_inserted']++;
            } elseif ($v['is_sub_account']) {
                $stats['sub_inserted']++;
                ledger_record(
                    (int) $v['partner_id'], 'COMMISSION_EARNED', $v['commission_amount'],
                    $b['batch_reference'],
                    'Sub-account commission for '
                        . format_period($v['period_year'], $v['period_month']),
                    ['year' => $v['period_year'], 'month' => $v['period_month']],
                    ['enrolment_id' => $enrolId, 'import_batch_id' => $batchId],
                    'SUPER_ADMIN', (int) $sa['id'],
                    (int) $v['sub_account_id']
                );
                $stats['net_commission'] += $v['commission_amount'];
            } else {
                $stats['inserted']++;
                ledger_record(
                    (int) $v['partner_id'], 'COMMISSION_EARNED', $v['commission_amount'],
                    $b['batch_reference'],
                    'Commission for ' . format_period($v['period_year'], $v['period_month']),
                    ['year' => $v['period_year'], 'month' => $v['period_month']],
                    ['enrolment_id' => $enrolId, 'import_batch_id' => $batchId],
                    'SUPER_ADMIN', (int) $sa['id'],
                    null
                );
                $stats['net_commission'] += $v['commission_amount'];
            }
        }

        // ---- Aggregator settlement (NIN-scoped) ----
        if (!$v['is_orphan']) {
            $enrolFull = db()->prepare("SELECT * FROM partner_enrolment_records WHERE id = :id LIMIT 1");
            $enrolFull->execute([':id' => $enrolId]);
            $enrolmentRow = $enrolFull->fetch();
            if ($enrolmentRow) {
                $assignment = aggregator_resolve_for_enrolment($enrolmentRow);
                if ($assignment !== null) {
                    $result = aggregator_create_settlement($enrolmentRow, $assignment, (int) $sa['id']);
                    if (!empty($result['created'])) {
                        $stats['aggregator_settled']++;
                        $stats['aggregator_amount'] += (float) $result['amount'];
                    }
                }
            }
        }

        $stats['total_nin'] += $v['total_nin_generated'];
        $stats['gross'] += $v['gross_revenue'];

        if (!$v['is_orphan']) {
            if ($v['is_sub_account']) {
                $key = (int) $v['sub_account_id'];
                if (!isset($subTotals[$key])) $subTotals[$key] = ['nin' => 0];
                $subTotals[$key]['nin'] += $v['total_nin_generated'];
            } else {
                $key = (int) $v['partner_id'];
                if (!isset($partnerTotals[$key])) $partnerTotals[$key] = ['nin' => 0, 'delta' => 0.0];
                $partnerTotals[$key]['nin'] += $v['total_nin_generated'];
                $partnerTotals[$key]['delta'] += ($existing && $existing['partner_id'] !== null
                    ? round($v['commission_amount'] - (float) $existing['commission_amount'], 2)
                    : $v['commission_amount']);
            }
        }
    }

    db()->prepare("
        UPDATE import_batches SET
            status = 'COMPLETED', confirmed_at = :ca, updated_at = :ua,
            valid_rows = :vr, total_nin_generated = :tn,
            gross_revenue = :gr, total_partner_commission = :tc
        WHERE id = :id
    ")->execute([
        ':ca' => now_dt(), ':ua' => now_dt(),
        ':vr' => $stats['inserted'] + $stats['updated']
              + $stats['orphan_inserted'] + $stats['orphan_updated']
              + $stats['sub_inserted'] + $stats['sub_updated'],
        ':tn' => $stats['total_nin'],
        ':gr' => round($stats['gross'], 2),
        ':tc' => round($stats['net_commission'], 2),
        ':id' => $batchId,
    ]);

    db()->commit();

    // Notifications
    foreach ($partnerTotals as $pid => $t) {
        notify('PARTNER', (int) $pid, 'NEW_NIN_RECORDS', 'NIN records updated',
            number_format($t['nin']) . ' NIN recorded.',
            '/partner/enrolments.php', 'import_batches', $batchId);
        if (abs($t['delta']) > 0.009) {
            notify('PARTNER', (int) $pid, 'COMMISSION_GENERATED', 'Commission credited',
                format_money($t['delta']) . ' has been added to your account.',
                '/partner/financials.php', 'import_batches', $batchId);
        }
    }
    foreach ($subTotals as $subId => $t) {
        $subRow = sub_account_find((int) $subId);
        if ($subRow) {
            notify('PARTNER', (int) $subRow['partner_id'], 'NEW_NIN_RECORDS',
                'Sub-account NIN records updated',
                $subRow['full_name'] . ': ' . number_format($t['nin']) . ' NIN recorded.',
                '/partner/sub-account-view.php?id=' . (int) $subId,
                'import_batches', $batchId);
        }
    }

    audit_log('CSV_IMPORT', 'import_batches', $batchId,
        'Batch ' . $b['batch_reference'] . ' imported: '
        . $stats['inserted'] . ' partner-insert, ' . $stats['updated'] . ' partner-update, '
        . $stats['sub_inserted'] . ' sub-insert, ' . $stats['sub_updated'] . ' sub-update, '
        . $stats['orphan_inserted'] . ' orphan-insert, ' . $stats['orphan_updated'] . ' orphan-update, '
        . $stats['aggregator_settled'] . ' aggregator-settled (' . format_money($stats['aggregator_amount']) . '), '
        . 'net partner commission ' . format_money($stats['net_commission']));

    flash('success', 'Import complete. '
        . $stats['inserted'] . ' partner row(s) inserted, '
        . $stats['updated'] . ' updated, '
        . $stats['sub_inserted'] . ' sub-account row(s) inserted, '
        . $stats['sub_updated'] . ' updated, '
        . $stats['orphan_inserted'] . ' unattached inserted, '
        . $stats['orphan_updated'] . ' unattached updated.'
        . ($stats['aggregator_settled'] > 0
            ? ' ' . $stats['aggregator_settled'] . ' aggregator settlement(s) totalling '
              . format_money($stats['aggregator_amount']) . '.'
            : ''));
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    db()->prepare("UPDATE import_batches SET status = 'FAILED', error_message = :msg, updated_at = :ua WHERE id = :id")
        ->execute([':msg' => mb_substr($e->getMessage(), 0, 500), ':ua' => now_dt(), ':id' => $batchId]);
    error_log('[import-confirm] ' . $e->getMessage());
    flash('error', 'Import failed. Nothing was committed.');
}

redirect('/superadmin/imports.php');