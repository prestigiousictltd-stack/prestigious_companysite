<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$partner = partner_require_login();

$page_title = 'NIN Enrolment Records';
$active_nav = 'enrolments';
$pid = (int) $partner['id'];

// Bucket selector: 'all', 'own', or a numeric sub-account id
$bucketRaw = (string) ($_GET['bucket'] ?? 'all');
$bucketType = 'all';
$bucketSubId = null;
$bucketLabel = 'All Buckets';

if ($bucketRaw === 'own') {
    $bucketType = 'own';
    $bucketLabel = 'My Own NIN';
} elseif (ctype_digit($bucketRaw) && (int) $bucketRaw > 0) {
    $stmt = db()->prepare("SELECT id, full_name FROM partner_sub_accounts WHERE id = :id AND partner_id = :pid LIMIT 1");
    $stmt->execute([':id' => (int) $bucketRaw, ':pid' => $pid]);
    $sub = $stmt->fetch();
    if ($sub) {
        $bucketType = 'sub';
        $bucketSubId = (int) $sub['id'];
        $bucketLabel = 'Sub-Account: ' . $sub['full_name'];
    }
}

$stmt = db()->prepare("
    SELECT id, full_name FROM partner_sub_accounts
    WHERE partner_id = :pid
    ORDER BY status ASC, full_name ASC
");
$stmt->execute([':pid' => $pid]);
$subAccounts = $stmt->fetchAll();

// Other filters
$fFrom = trim((string) ($_GET['from'] ?? ''));
$fTo   = trim((string) ($_GET['to']   ?? ''));
$fYear = (int) ($_GET['year'] ?? 0);
$fMonth= (int) ($_GET['month'] ?? 0);
$fStation = (int) ($_GET['station'] ?? 0);

$where = ['partner_id = :pid'];
$params = [':pid' => $pid];

if ($bucketType === 'own') {
    $where[] = 'sub_account_id IS NULL';
} elseif ($bucketType === 'sub') {
    $where[] = 'sub_account_id = :bucket_sid';
    $params[':bucket_sid'] = $bucketSubId;
}

if (preg_match('/^(\d{4})-(\d{2})$/', $fFrom, $m)) {
    $where[] = '(period_year > :fy OR (period_year = :fy AND period_month >= :fm))';
    $params[':fy'] = (int) $m[1]; $params[':fm'] = (int) $m[2];
}
if (preg_match('/^(\d{4})-(\d{2})$/', $fTo, $m)) {
    $where[] = '(period_year < :ty OR (period_year = :ty AND period_month <= :tm))';
    $params[':ty'] = (int) $m[1]; $params[':tm'] = (int) $m[2];
}
if ($fMonth >= 1 && $fMonth <= 12) {
    $where[] = 'period_month = :pm';
    $params[':pm'] = $fMonth;
}
if ($fYear >= 2000 && $fYear <= 2100) {
    $where[] = 'period_year = :py';
    $params[':py'] = $fYear;
}
if ($fStation > 0) {
    $where[] = 'station_id = :sid';
    $params[':sid'] = $fStation;
}
$whereSql = implode(' AND ', $where);

// Partner's stations for filter dropdown
$stmt = db()->prepare("SELECT id, station_code, station_name FROM stations WHERE partner_id = :pid ORDER BY station_name");
$stmt->execute([':pid' => $pid]);
$stations = $stmt->fetchAll();

// Summary totals
$stmt = db()->prepare("
    SELECT
      COALESCE(SUM(total_enrolments), 0) AS total_enrolments,
      COALESCE(SUM(total_nin_generated), 0) AS total_nin,
      COALESCE(SUM(ernew_nin), 0) AS ernew,
      COALESCE(SUM(crnew_nin), 0) AS crnew,
      COALESCE(SUM(female_count), 0) AS female,
      COALESCE(SUM(male_count), 0) AS male,
      COALESCE(SUM(commission_amount), 0) AS commission
    FROM partner_enrolment_records
    WHERE $whereSql
");
$stmt->execute($params);
$summary = $stmt->fetch();

// Grouped rows
$stmt = db()->prepare("
    SELECT period_year, period_month, sub_account_id,
           SUM(total_enrolments) AS total_enrolments,
           SUM(total_nin_generated) AS total_nin,
           SUM(ernew_nin) AS ernew,
           SUM(crnew_nin) AS crnew,
           SUM(female_count) AS female,
           SUM(male_count) AS male,
           SUM(commission_amount) AS commission
    FROM partner_enrolment_records
    WHERE $whereSql
    GROUP BY period_year, period_month, sub_account_id
    ORDER BY period_year DESC, period_month DESC, sub_account_id ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Sub-account name lookup
$subNameMap = [];
foreach ($subAccounts as $s) { $subNameMap[(int) $s['id']] = (string) $s['full_name']; }

require PICT_ROOT . '/includes/header-partner.php';
?>

<div class="page-head">
  <div>
    <h1>NIN Enrolment Records</h1>
    <p>Statistics for your stations, per bucket. Records are read-only.</p>
  </div>
</div>

<div class="filter-bar">
  <form method="get">
    <div class="form-group">
      <label class="form-label">Bucket</label>
      <select class="form-control" name="bucket">
        <option value="all"<?= $bucketType === 'all' ? ' selected' : '' ?>>All Buckets</option>
        <option value="own"<?= $bucketType === 'own' ? ' selected' : '' ?>>My Own NIN — <?= e($partner['full_name']) ?></option>
        <?php foreach ($subAccounts as $s): ?>
          <option value="<?= (int) $s['id'] ?>"<?= $bucketType === 'sub' && $bucketSubId === (int) $s['id'] ? ' selected' : '' ?>>
            Sub — <?= e($s['full_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">From (Month)</label>
      <input class="form-control" type="month" name="from" value="<?= e($fFrom) ?>">
    </div>
    <div class="form-group">
      <label class="form-label">To (Month)</label>
      <input class="form-control" type="month" name="to" value="<?= e($fTo) ?>">
    </div>
    <div class="form-group">
      <label class="form-label">Month</label>
      <select class="form-control" name="month">
        <option value="">All months</option>
        <?php for ($i = 1; $i <= 12; $i++): ?>
          <option value="<?= $i ?>"<?= $fMonth === $i ? ' selected' : '' ?>><?= e(date('F', mktime(0,0,0,$i,1,2000))) ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Year</label>
      <input class="form-control" type="number" name="year" value="<?= $fYear ?: '' ?>" min="2000" max="2100" placeholder="Any">
    </div>
    <div class="form-group">
      <label class="form-label">Station</label>
      <select class="form-control" name="station">
        <option value="">All stations</option>
        <?php foreach ($stations as $s): ?>
          <option value="<?= (int) $s['id'] ?>"<?= $fStation === (int) $s['id'] ? ' selected' : '' ?>>
            <?= e($s['station_code'] . ' — ' . $s['station_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <button class="btn btn-primary" type="submit">Apply</button>
      <a class="btn btn-outline" href="<?= e(root_url('/partner/enrolments.php')) ?>">Reset</a>
    </div>
  </form>
</div>

<div class="stat-grid">
  <div class="stat"><div class="stat__label">Total Enrolments</div><div class="stat__value"><?= number_format((int) $summary['total_enrolments']) ?></div></div>
  <div class="stat"><div class="stat__label">Total NIN Generated</div><div class="stat__value"><?= number_format((int) $summary['total_nin']) ?></div></div>
  <div class="stat"><div class="stat__label">ERNEW</div><div class="stat__value"><?= number_format((int) $summary['ernew']) ?></div></div>
  <div class="stat"><div class="stat__label">CRNEW</div><div class="stat__value"><?= number_format((int) $summary['crnew']) ?></div></div>
  <div class="stat"><div class="stat__label">Female</div><div class="stat__value"><?= number_format((int) $summary['female']) ?></div></div>
  <div class="stat"><div class="stat__label">Male</div><div class="stat__value"><?= number_format((int) $summary['male']) ?></div></div>
  <div class="stat stat--money"><div class="stat__label">Commission</div><div class="stat__value"><?= e(format_money((float) $summary['commission'])) ?></div></div>
</div>

<div class="card">
  <div class="flex-between mb-2">
    <h2 style="margin:0">Records</h2>
    <span class="muted" style="font-size:13px">Showing: <strong><?= e($bucketLabel) ?></strong></span>
  </div>
  <?php if (empty($rows)): ?>
    <div class="empty">
      <h3>No records match your filters</h3>
      <p>Try a different period, station or bucket.</p>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr>
            <th>Period</th>
            <th>Bucket</th>
            <th class="num">Enrolments</th>
            <th class="num">NIN</th>
            <th class="num">ERNEW</th>
            <th class="num">CRNEW</th>
            <th class="num">Female</th>
            <th class="num">Male</th>
            <th class="num">Commission</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><strong><?= e(format_period((int) $r['period_year'], (int) $r['period_month'])) ?></strong></td>
              <td>
                <?php if ($r['sub_account_id'] !== null): ?>
                  <span class="badge" style="background:#e0e7ff;color:#4338ca">Sub</span>
                  <?= e($subNameMap[(int) $r['sub_account_id']] ?? '—') ?>
                <?php else: ?>
                  <span class="badge" style="background:#dbeafe;color:#1e40af">Own</span>
                  <?= e($partner['full_name']) ?>
                <?php endif; ?>
              </td>
              <td class="num"><?= number_format((int) $r['total_enrolments']) ?></td>
              <td class="num"><?= number_format((int) $r['total_nin']) ?></td>
              <td class="num"><?= number_format((int) $r['ernew']) ?></td>
              <td class="num"><?= number_format((int) $r['crnew']) ?></td>
              <td class="num"><?= number_format((int) $r['female']) ?></td>
              <td class="num"><?= number_format((int) $r['male']) ?></td>
              <td class="num"><strong><?= e(format_money((float) $r['commission'])) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-partner.php'; ?>