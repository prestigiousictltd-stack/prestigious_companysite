<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$page_title = 'NIN Records';
$active_nav = 'nin-records';

$fPartner = (int) ($_GET['partner'] ?? 0);
$fState   = (int) ($_GET['state'] ?? 0);
$fLga     = (int) ($_GET['lga'] ?? 0);
$fStation = (int) ($_GET['station'] ?? 0);
$fYear    = (int) ($_GET['year'] ?? 0);
$fMonth   = (int) ($_GET['month'] ?? 0);
$fBucket  = (string) ($_GET['bucket'] ?? '');   // '', 'own', 'sub'

$where  = ['1=1'];
$params = [];

if ($fPartner > 0) { $where[] = 'r.partner_id = :pid'; $params[':pid'] = $fPartner; }
if ($fStation > 0) { $where[] = 'r.station_id = :sid'; $params[':sid'] = $fStation; }
if ($fState > 0)   { $where[] = 'p.state_id = :stid';  $params[':stid'] = $fState; }
if ($fLga > 0)     { $where[] = 'p.lga_id = :lid';     $params[':lid']  = $fLga; }
if ($fYear > 0)    { $where[] = 'r.period_year = :py'; $params[':py']   = $fYear; }
if ($fMonth > 0)   { $where[] = 'r.period_month = :pm'; $params[':pm']  = $fMonth; }
if ($fBucket === 'own') {
    $where[] = 'r.sub_account_id IS NULL';
} elseif ($fBucket === 'sub') {
    $where[] = 'r.sub_account_id IS NOT NULL';
}
$whereSql = implode(' AND ', $where);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$c = db()->prepare("
    SELECT COUNT(*)
    FROM partner_enrolment_records r
    JOIN partners p ON p.id = r.partner_id
    WHERE $whereSql
");
$c->execute($params);
$total = (int) $c->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare("
    SELECT r.*,
           p.full_name AS partner_name, p.partner_code, p.state_id, p.lga_id,
           s.station_code AS station_code_fk, s.station_name AS station_name_fk,
           st.name AS state_name, l.name AS lga_name,
           sa.full_name AS sub_account_name
    FROM partner_enrolment_records r
    JOIN partners p ON p.id = r.partner_id
    LEFT JOIN stations s ON s.id = r.station_id
    LEFT JOIN states st ON st.id = p.state_id
    LEFT JOIN lgas l ON l.id = p.lga_id
    LEFT JOIN partner_sub_accounts sa ON sa.id = r.sub_account_id
    WHERE $whereSql
    ORDER BY r.period_year DESC, r.period_month DESC, r.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$sum = db()->prepare("
    SELECT
      COALESCE(SUM(r.total_enrolments), 0)    AS enrol,
      COALESCE(SUM(r.total_nin_generated), 0) AS nin,
      COALESCE(SUM(r.ernew_nin), 0)           AS ernew,
      COALESCE(SUM(r.crnew_nin), 0)           AS crnew,
      COALESCE(SUM(r.female_count), 0)        AS female,
      COALESCE(SUM(r.male_count), 0)          AS male
    FROM partner_enrolment_records r
    JOIN partners p ON p.id = r.partner_id
    WHERE $whereSql
");
$sum->execute($params);
$ninSummary = $sum->fetch();

$partners = db()->query("SELECT id, partner_code, full_name FROM partners ORDER BY full_name")->fetchAll();
$states   = db()->query("SELECT id, name FROM states ORDER BY name")->fetchAll();
$stations = db()->query("SELECT id, station_code, station_name FROM stations ORDER BY station_name LIMIT 1000")->fetchAll();

require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head">
  <div>
    <h1>NIN Records</h1>
    <p>System-wide enrolment records.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/report-centre.php')) ?>?type=nin&<?= http_build_query($_GET) ?>">Open in Report Centre</a>
  </div>
</div>

<div class="filter-bar">
  <form method="get">
    <div class="form-group">
      <label class="form-label">Partner</label>
      <select class="form-control" name="partner">
        <option value="">All</option>
        <?php foreach ($partners as $p): ?>
          <option value="<?= (int) $p['id'] ?>"<?= $fPartner === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['full_name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Bucket</label>
      <select class="form-control" name="bucket">
        <option value="">All buckets</option>
        <option value="own"<?= $fBucket === 'own' ? ' selected' : '' ?>>Partner-Own only</option>
        <option value="sub"<?= $fBucket === 'sub' ? ' selected' : '' ?>>Sub-Accounts only</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">State</label>
      <select class="form-control" name="state">
        <option value="">All</option>
        <?php foreach ($states as $st): ?>
          <option value="<?= (int) $st['id'] ?>"<?= $fState === (int) $st['id'] ? ' selected' : '' ?>><?= e($st['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Station</label>
      <select class="form-control" name="station">
        <option value="">All</option>
        <?php foreach ($stations as $st): ?>
          <option value="<?= (int) $st['id'] ?>"<?= $fStation === (int) $st['id'] ? ' selected' : '' ?>><?= e($st['station_code']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Year</label>
      <input class="form-control" type="number" name="year" value="<?= $fYear ?: '' ?>" min="2000" max="2100" placeholder="Any">
    </div>
    <div class="form-group">
      <label class="form-label">Month</label>
      <select class="form-control" name="month">
        <option value="">All</option>
        <?php for ($i = 1; $i <= 12; $i++): ?>
          <option value="<?= $i ?>"<?= $fMonth === $i ? ' selected' : '' ?>><?= e(date('F', mktime(0, 0, 0, $i, 1, 2000))) ?></option>
        <?php endfor; ?>
      </select>
    </div>
    <div class="form-group">
      <button class="btn btn-grape" type="submit">Filter</button>
      <a class="btn btn-outline" href="<?= e(root_url('/superadmin/nin-records.php')) ?>">Reset</a>
    </div>
  </form>
</div>

<div class="stat-grid">
  <div class="stat"><div class="stat__label">Enrolments</div><div class="stat__value"><?= number_format((int) $ninSummary['enrol']) ?></div></div>
  <div class="stat stat--purple"><div class="stat__label">NIN Generated</div><div class="stat__value"><?= number_format((int) $ninSummary['nin']) ?></div></div>
  <div class="stat"><div class="stat__label">ERNEW</div><div class="stat__value"><?= number_format((int) $ninSummary['ernew']) ?></div></div>
  <div class="stat"><div class="stat__label">CRNEW</div><div class="stat__value"><?= number_format((int) $ninSummary['crnew']) ?></div></div>
  <div class="stat"><div class="stat__label">Female</div><div class="stat__value"><?= number_format((int) $ninSummary['female']) ?></div></div>
  <div class="stat"><div class="stat__label">Male</div><div class="stat__value"><?= number_format((int) $ninSummary['male']) ?></div></div>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="empty"><p>No records match filters.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr>
            <th>Period</th>
            <th>Partner</th>
            <th>Bucket</th>
            <th>State / LGA</th>
            <th>Station ID</th>
            <th>Station Name</th>
            <th class="num">Enrolments</th>
            <th class="num">NIN</th>
            <th class="num">ERNEW</th>
            <th class="num">CRNEW</th>
            <th class="num">F</th>
            <th class="num">M</th>
            <th class="num">Revenue/NIN</th>
            <th class="num">Comm %</th>
            <th class="num">Commission</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e(format_period((int) $r['period_year'], (int) $r['period_month'])) ?></td>

              <td>
                <?= e($r['partner_name']) ?><br>
                <span class="muted" style="font-size:12px"><?= e($r['partner_code']) ?></span>
              </td>

              <td>
                <?php if (!empty($r['sub_account_id'])): ?>
                  <span class="badge" style="background:#e0e7ff;color:#4338ca">Sub</span>
                  <?= e((string) $r['sub_account_name']) ?>
                <?php else: ?>
                  <span class="badge" style="background:#dbeafe;color:#1e40af">Own</span>
                <?php endif; ?>
              </td>

              <td>
                <?= e((string) $r['state_name']) ?><br>
                <span class="muted" style="font-size:12px"><?= e((string) $r['lga_name']) ?></span>
              </td>

              <td>
                <?php
                  $sid = !empty($r['station_id_raw'])
                      ? (string) $r['station_id_raw']
                      : (string) ($r['station_code_fk'] ?? '');
                ?>
                <?= $sid !== '' ? '<span class="masked">' . e($sid) . '</span>' : '<span class="muted">—</span>' ?>
              </td>

              <td>
                <?php
                  $sname = !empty($r['station_name_raw'])
                      ? (string) $r['station_name_raw']
                      : (string) ($r['station_name_fk'] ?? '');
                ?>
                <?= $sname !== '' ? e($sname) : '<span class="muted">—</span>' ?>
              </td>

              <td class="num"><?= number_format((int) $r['total_enrolments']) ?></td>
              <td class="num"><?= number_format((int) $r['total_nin_generated']) ?></td>
              <td class="num"><?= number_format((int) $r['ernew_nin']) ?></td>
              <td class="num"><?= number_format((int) $r['crnew_nin']) ?></td>
              <td class="num"><?= number_format((int) $r['female_count']) ?></td>
              <td class="num"><?= number_format((int) $r['male_count']) ?></td>
              <td class="num"><?= e(format_money((float) $r['revenue_per_nin'])) ?></td>
              <td class="num"><?= e(number_format((float) $r['commission_percentage'], 2)) ?>%</td>
              <td class="num"><?= e(format_money((float) $r['commission_amount'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <ul class="pagination">
        <?php
        $qs = $_GET;
        unset($qs['page']);
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