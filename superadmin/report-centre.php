<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$type = (string) ($_GET['type'] ?? 'partner');
$page_title = 'Report Centre';
$active_nav = 'report-centre';

$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to'] ?? ''));
$fPartner = (int) ($_GET['partner'] ?? 0);
$fState = (int) ($_GET['state'] ?? 0);
$fStation = (int) ($_GET['station'] ?? 0);
$fStatus = (string) ($_GET['status'] ?? '');

$where = ['1=1']; $params = [];
if ($fPartner > 0) { $where[] = 'r.partner_id = :pid'; $params[':pid'] = $fPartner; }
if ($fStation > 0) { $where[] = 'r.station_id = :sid'; $params[':sid'] = $fStation; }
if ($fState > 0) { $where[] = 'p.state_id = :stid'; $params[':stid'] = $fState; }
if (preg_match('/^\d{4}-\d{2}$/', $from)) { [$y, $m] = explode('-', $from); $where[] = '(r.period_year > :fy OR (r.period_year = :fy AND r.period_month >= :fm))'; $params[':fy'] = (int)$y; $params[':fm'] = (int)$m; }
if (preg_match('/^\d{4}-\d{2}$/', $to))   { [$y, $m] = explode('-', $to);   $where[] = '(r.period_year < :ty OR (r.period_year = :ty AND r.period_month <= :tm))'; $params[':ty'] = (int)$y; $params[':tm'] = (int)$m; }
$whereSql = implode(' AND ', $where);

// Handle exports
if (!empty($_GET['export'])) {
    $exportType = (string) $_GET['export'];
    if ($exportType === 'csv') {
        audit_log('DATA_EXPORT', 'report-centre', null, 'Report ' . $type . ' exported as CSV.');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="report_' . $type . '_' . date('Ymd_His') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, "\xEF\xBB\xBF");
           if ($type === 'sub_account') {
        fputcsv($out, ['Sub-Account', 'Parent Partner', 'Partner Code', 'NIN', 'Status', 'Rate %', 'Total NIN', 'Total Commission', 'Balance']);
        $sql = "
            SELECT s.full_name AS sub_name, p.full_name AS partner_name, p.partner_code,
                   s.nin_number, s.status,
                   (SELECT commission_percentage FROM sub_account_commission_rates
                     WHERE sub_account_id = s.id AND status = 'ACTIVE'
                       AND effective_from <= CURDATE()
                       AND (effective_to IS NULL OR effective_to >= CURDATE())
                     ORDER BY effective_from DESC, id DESC LIMIT 1) AS current_pct,
                   COALESCE((SELECT SUM(total_nin_generated) FROM partner_enrolment_records WHERE sub_account_id = s.id), 0) AS total_nin,
                   COALESCE((SELECT SUM(commission_amount) FROM partner_enrolment_records WHERE sub_account_id = s.id), 0) AS total_commission,
                   COALESCE((SELECT SUM(amount) FROM partner_financial_transactions WHERE sub_account_id = s.id), 0) AS balance
            FROM partner_sub_accounts s
            JOIN partners p ON p.id = s.partner_id
            WHERE 1=1 " . ($fPartner > 0 ? " AND s.partner_id = " . (int) $fPartner : '') . "
            ORDER BY total_commission DESC LIMIT 5000
        ";
        $stmt2 = db()->query($sql);
        while ($row = $stmt2->fetch()) {
            fputcsv($out, [
                $row['sub_name'], $row['partner_name'], $row['partner_code'],
                $row['nin_number'], $row['status'], $row['current_pct'],
                $row['total_nin'], $row['total_commission'], $row['balance'],
            ]);
        }
    } elseif ($type === 'nin' || $type === 'partner') {
            fputcsv($out, ['Period', 'Partner', 'Partner Code', 'State', 'LGA', 'Station', 'Enrolments', 'NIN', 'ERNEW', 'CRNEW', 'Female', 'Male', 'Revenue/NIN', 'Commission %', 'Commission']);
            $stmt = db()->prepare("
                SELECT r.period_year, r.period_month, p.full_name, p.partner_code, st.name AS state_name, l.name AS lga_name, s.station_code, r.*
                FROM partner_enrolment_records r
                JOIN partners p ON p.id = r.partner_id
                LEFT JOIN states st ON st.id = p.state_id
                LEFT JOIN lgas l ON l.id = p.lga_id
                LEFT JOIN stations s ON s.id = r.station_id
                WHERE $whereSql ORDER BY r.period_year DESC, r.period_month DESC LIMIT 5000
            ");
            $stmt->execute($params);
            while ($row = $stmt->fetch()) {
                fputcsv($out, [
                    format_period((int) $row['period_year'], (int) $row['period_month']),
                    $row['full_name'], $row['partner_code'], $row['state_name'], $row['lga_name'], $row['station_code'],
                    $row['total_enrolments'], $row['total_nin_generated'], $row['ernew_nin'], $row['crnew_nin'],
                    $row['female_count'], $row['male_count'], $row['revenue_per_nin'], $row['commission_percentage'], $row['commission_amount'],
                ]);
            }
        } elseif ($type === 'financial') {
            fputcsv($out, ['Date', 'Transaction ID', 'Partner', 'Type', 'Reference', 'Amount', 'Prev Balance', 'New Balance']);
            $stmt = db()->prepare("
                SELECT t.*, p.full_name, p.partner_code FROM partner_financial_transactions t
                JOIN partners p ON p.id = t.partner_id
                WHERE 1=1 " . ($fPartner ? "AND t.partner_id = " . (int) $fPartner : '') . "
                ORDER BY t.created_at DESC LIMIT 5000
            ");
            $stmt->execute();
            while ($row = $stmt->fetch()) {
                fputcsv($out, [date('Y-m-d H:i', strtotime($row['created_at'])), $row['transaction_id'], $row['full_name'], $row['type'], $row['reference'], $row['amount'], $row['previous_balance'], $row['new_balance']]);
            }
        }
        fclose($out);
        exit;
    }
    if ($exportType === 'print') {
        audit_log('DATA_EXPORT', 'report-centre', null, 'Report ' . $type . ' printed.');
    }
}

// Fetch rows
$rows = [];


if ($type === 'sub_account') {
    // Per-sub-account summary
    $sql = "
        SELECT s.id AS sub_account_id, s.full_name AS sub_name, s.nin_number AS sub_nin, s.status AS sub_status,
               p.id AS partner_id, p.full_name AS partner_name, p.partner_code,
               (SELECT commission_percentage FROM sub_account_commission_rates
                 WHERE sub_account_id = s.id AND status = 'ACTIVE'
                   AND effective_from <= CURDATE()
                   AND (effective_to IS NULL OR effective_to >= CURDATE())
                 ORDER BY effective_from DESC, id DESC LIMIT 1) AS current_pct,
               COALESCE((SELECT SUM(total_nin_generated) FROM partner_enrolment_records WHERE sub_account_id = s.id), 0) AS total_nin,
               COALESCE((SELECT SUM(commission_amount) FROM partner_enrolment_records WHERE sub_account_id = s.id), 0) AS total_commission,
               COALESCE((SELECT SUM(amount) FROM partner_financial_transactions WHERE sub_account_id = s.id), 0) AS balance
        FROM partner_sub_accounts s
        JOIN partners p ON p.id = s.partner_id
        WHERE 1=1
    ";
    $params2 = [];
    if ($fPartner > 0) {
        $sql .= " AND s.partner_id = :pid";
        $params2[':pid'] = $fPartner;
    }
    if ($fState > 0) {
        $sql .= " AND p.state_id = :stid";
        $params2[':stid'] = $fState;
    }
    $sql .= " ORDER BY total_commission DESC LIMIT 500";

    $stmt = db()->prepare($sql);
    $stmt->execute($params2);
    $rows = $stmt->fetchAll();
}
elseif ($type === 'partner' || $type === 'nin' || $type === 'station') {
    $stmt = db()->prepare("
        SELECT r.period_year, r.period_month, r.total_enrolments, r.total_nin_generated,
               r.ernew_nin, r.crnew_nin, r.female_count, r.male_count, r.commission_amount, r.gross_revenue,
               p.full_name, p.partner_code, st.name AS state_name, l.name AS lga_name, s.station_code
        FROM partner_enrolment_records r
        JOIN partners p ON p.id = r.partner_id
        LEFT JOIN states st ON st.id = p.state_id
        LEFT JOIN lgas l ON l.id = p.lga_id
        LEFT JOIN stations s ON s.id = r.station_id
        WHERE $whereSql
        ORDER BY r.period_year DESC, r.period_month DESC LIMIT 500
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
} elseif ($type === 'financial' || $type === 'transaction') {
    $tWhere = ['1=1'];
    $tParams = [];
    if ($fPartner > 0) { $tWhere[] = 't.partner_id = :tpid'; $tParams[':tpid'] = $fPartner; }
    $stmt = db()->prepare("
        SELECT t.*, p.full_name, p.partner_code
        FROM partner_financial_transactions t JOIN partners p ON p.id=t.partner_id
        WHERE " . implode(' AND ', $tWhere) . "
        ORDER BY t.created_at DESC LIMIT 500
    ");
    $stmt->execute($tParams);
    $rows = $stmt->fetchAll();
} elseif ($type === 'withdrawal') {
    $wWhere = ['1=1'];
    $wParams = [];
    if ($fPartner > 0) { $wWhere[] = 'w.partner_id = :wpid'; $wParams[':wpid'] = $fPartner; }
    if (in_array($fStatus, ['PENDING','APPROVED','PROCESSING','PAID','REJECTED','REVERSED','CANCELLED'], true)) {
        $wWhere[] = 'w.status = :wst';
        $wParams[':wst'] = $fStatus;
    }
    $stmt = db()->prepare("
        SELECT w.*, p.full_name, p.partner_code
        FROM withdrawals w JOIN partners p ON p.id=w.partner_id
        WHERE " . implode(' AND ', $wWhere) . "
        ORDER BY w.created_at DESC LIMIT 500
    ");
    $stmt->execute($wParams);
    $rows = $stmt->fetchAll();
} elseif ($type === 'daily') {
    $stmt = db()->prepare("SELECT r.*, a.full_name AS author FROM daily_reports r LEFT JOIN admins a ON a.id=r.created_by_admin_id ORDER BY r.report_date DESC LIMIT 500");
    $stmt->execute();
    $rows = $stmt->fetchAll();
}

$partners = db()->query("SELECT id, partner_code, full_name FROM partners ORDER BY full_name")->fetchAll();
$states = db()->query("SELECT id, name FROM states ORDER BY name")->fetchAll();
$stations = db()->query("SELECT id, station_code, station_name FROM stations ORDER BY station_name LIMIT 1000")->fetchAll();

require PICT_ROOT . '/includes/header-superadmin.php';

$tabs = [
    'partner'      => 'Partner Report',
    'sub_account'  => 'Sub-Account Report',
    'nin'          => 'NIN Report',
    'station'      => 'Station Report',
    'financial'    => 'Financial Report',
    'transaction'  => 'Transaction Report',
    'withdrawal'   => 'Withdrawal Report',
    'daily'        => 'Daily Operational Reports',
];
?>
<div class="page-head">
  <div><h1>Report Centre</h1><p>Cross-cutting reports with CSV export and print view.</p></div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>">Download CSV</a>
  </div>
</div>

<div class="filter-bar">
  <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px">
    <?php foreach ($tabs as $k => $label): ?>
      <a class="btn <?= $type === $k ? 'btn-grape' : 'btn-outline' ?> btn-sm" href="?type=<?= e($k) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get">
    <input type="hidden" name="type" value="<?= e($type) ?>">
    <div class="form-group"><label class="form-label">Partner</label>
      <select class="form-control" name="partner"><option value="">All</option>
        <?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $fPartner === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['full_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">State</label>
      <select class="form-control" name="state"><option value="">All</option>
        <?php foreach ($states as $st): ?><option value="<?= (int) $st['id'] ?>"<?= $fState === (int) $st['id'] ? ' selected' : '' ?>><?= e($st['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Station</label>
      <select class="form-control" name="station"><option value="">All</option>
        <?php foreach ($stations as $st): ?><option value="<?= (int) $st['id'] ?>"<?= $fStation === (int) $st['id'] ? ' selected' : '' ?>><?= e($st['station_code']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">From (YYYY-MM)</label><input class="form-control" type="month" name="from" value="<?= e($from) ?>"></div>
    <div class="form-group"><label class="form-label">To (YYYY-MM)</label><input class="form-control" type="month" name="to" value="<?= e($to) ?>"></div>
    <div class="form-group"><button class="btn btn-grape" type="submit">Apply</button>
      <a class="btn btn-outline" href="?type=<?= e($type) ?>">Reset</a></div>
  </form>
</div>

<div class="card">
  <?php if (empty($rows)): ?><div class="empty"><p>No rows.</p></div>
 <?php elseif ($type === 'sub_account'): ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Sub-Account</th>
            <th>Parent Partner</th>
            <th>NIN</th>
            <th>Status</th>
            <th class="num">Rate</th>
            <th class="num">Total NIN</th>
            <th class="num">Total Commission</th>
            <th class="num">Balance</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><strong><?= e($r['sub_name']) ?></strong></td>
              <td><?= e($r['partner_name']) ?><br><span class="muted" style="font-size:12px"><?= e($r['partner_code']) ?></span></td>
              <td><span class="masked"><?= e(mask_nin($r['sub_nin'])) ?></span></td>
              <td>
                <span class="badge badge--<?= match($r['sub_status']) {
                  'ACTIVE' => 'active', 'PENDING' => 'pending',
                  'INACTIVE' => 'inactive', 'REJECTED' => 'rejected',
                  default => 'inactive'
                } ?>"><?= e($r['sub_status']) ?></span>
              </td>
              <td class="num"><?= $r['current_pct'] !== null ? e(number_format((float) $r['current_pct'], 2)) . '%' : '<span class="muted">—</span>' ?></td>
              <td class="num"><?= number_format((int) $r['total_nin']) ?></td>
              <td class="num"><strong><?= e(format_money((float) $r['total_commission'])) ?></strong></td>
              <td class="num"><?= e(format_money((float) $r['balance'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php elseif ($type === 'partner' || $type === 'nin' || $type === 'station'): ?>
    <div style="overflow-x:auto"><table class="tbl tbl--compact">
      <thead><tr><th>Period</th><th>Partner</th><th>Location</th><th>Station</th><th class="num">Enrol</th><th class="num">NIN</th><th class="num">ERNEW</th><th class="num">CRNEW</th><th class="num">F</th><th class="num">M</th><th class="num">Commission</th></tr></thead>
      <tbody><?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(format_period((int) $r['period_year'], (int) $r['period_month'])) ?></td>
          <td><?= e($r['full_name']) ?></td>
          <td><?= e((string) $r['state_name']) ?> / <?= e((string) $r['lga_name']) ?></td>
          <td><?= e((string) $r['station_code']) ?: '—' ?></td>
          <td class="num"><?= number_format((int) $r['total_enrolments']) ?></td>
          <td class="num"><?= number_format((int) $r['total_nin_generated']) ?></td>
          <td class="num"><?= number_format((int) $r['ernew_nin']) ?></td>
          <td class="num"><?= number_format((int) $r['crnew_nin']) ?></td>
          <td class="num"><?= number_format((int) $r['female_count']) ?></td>
          <td class="num"><?= number_format((int) $r['male_count']) ?></td>
          <td class="num"><?= e(format_money((float) $r['commission_amount'])) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>

  <?php elseif ($type === 'financial' || $type === 'transaction'): ?>
    <div style="overflow-x:auto"><table class="tbl tbl--compact">
      <thead><tr><th>Date</th><th>Transaction ID</th><th>Partner</th><th>Type</th><th>Reference</th><th class="num">Amount</th><th class="num">New Balance</th></tr></thead>
      <tbody><?php foreach ($rows as $r): ?>
        <tr>
          <td><?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
          <td><span class="masked"><?= e($r['transaction_id']) ?></span></td>
          <td><?= e($r['full_name']) ?></td>
          <td><span class="badge badge--inactive"><?= e($r['type']) ?></span></td>
          <td><span class="masked"><?= e((string) $r['reference']) ?></span></td>
          <td class="num"><?= e(format_money((float) $r['amount'])) ?></td>
          <td class="num"><?= e(format_money((float) $r['new_balance'])) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>

  <?php elseif ($type === 'withdrawal'): ?>
    <div style="overflow-x:auto"><table class="tbl tbl--compact">
      <thead><tr><th>Reference</th><th>Partner</th><th class="num">Amount</th><th>Status</th><th>Bank</th><th>Requested</th></tr></thead>
      <tbody><?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="masked"><?= e($r['withdrawal_reference']) ?></span></td>
          <td><?= e($r['full_name']) ?></td>
          <td class="num"><?= e(format_money((float) $r['amount'])) ?></td>
          <td><span class="badge badge--<?= strtolower($r['status']) ?>"><?= e($r['status']) ?></span></td>
          <td><?= e($r['bank_name_snapshot']) ?></td>
          <td><?= e(date('d M Y', strtotime($r['created_at']))) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>

  <?php else: ?>
    <div style="overflow-x:auto"><table class="tbl tbl--compact">
      <thead><tr><th>Reference</th><th>Date</th><th>Author</th><th>Status</th><th class="num">Net Income</th></tr></thead>
      <tbody><?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="masked"><?= e($r['report_reference']) ?></span></td>
          <td><?= e(date('d M Y', strtotime($r['report_date']))) ?></td>
          <td><?= e((string) $r['author']) ?></td>
          <td><span class="badge badge--<?= strtolower($r['status']) ?>"><?= e($r['status']) ?></span></td>
          <td class="num"><?= e(format_money((float) $r['net_income'])) ?></td>
        </tr>
      <?php endforeach; ?></tbody>
    </table></div>
  <?php endif; ?>
</div>
<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>