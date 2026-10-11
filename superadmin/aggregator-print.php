<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/aggregator.php';
$sa = superadmin_require_login();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); echo 'Aggregator ID required.'; exit; }

$stmt = db()->prepare("SELECT * FROM aggregators WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$agg = $stmt->fetch();
if (!$agg) { http_response_code(404); echo 'Aggregator not found.'; exit; }

$from = trim((string) ($_GET['from'] ?? ''));
$to   = trim((string) ($_GET['to']   ?? ''));

$where  = ['aggregator_id = :aid'];
$params = [':aid' => $id];
if (preg_match('/^(\d{4})-(\d{2})$/', $from, $m)) {
    $where[] = '(period_year > :fy OR (period_year = :fy AND period_month >= :fm))';
    $params[':fy'] = (int) $m[1]; $params[':fm'] = (int) $m[2];
}
if (preg_match('/^(\d{4})-(\d{2})$/', $to, $m)) {
    $where[] = '(period_year < :ty OR (period_year = :ty AND period_month <= :tm))';
    $params[':ty'] = (int) $m[1]; $params[':tm'] = (int) $m[2];
}
$whereSql = implode(' AND ', $where);

$stmt = db()->prepare("
    SELECT period_year, period_month,
           SUM(partner_commission_amount)    AS partner_commission,
           SUM(aggregator_commission_amount) AS agg_commission,
           MIN(aggregator_percentage) AS pct_min,
           MAX(aggregator_percentage) AS pct_max
    FROM aggregator_enrolment_records
    WHERE $whereSql
    GROUP BY period_year, period_month
    ORDER BY period_year ASC, period_month ASC
");
$stmt->execute($params);
$periods = $stmt->fetchAll();

$linkedNins = aggregator_list_linked_nins($id, false);

$earned  = aggregator_total_earned($id);
$paid    = aggregator_total_paid($id);
$balance = aggregator_available_balance($id);

$companyName  = (string) setting('company_name', 'Prestigious ICT Investment Limited');
$companyAddr  = (string) setting('company_address', '');
$companyPhone = (string) setting('company_phone', '');
$companyEmail = (string) setting('company_email', '');
$sym = (string) setting('system_currency_symbol', '₦');
$esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$fmt = fn($v) => $sym . number_format((float) $v, 2);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Aggregator Statement — <?= $esc($agg['full_name']) ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:"Times New Roman",Georgia,serif;color:#0f172a;font-size:12.5pt;line-height:1.5;background:#f5f5f5;padding:24px 0}
.page{background:#fff;max-width:820px;margin:0 auto;padding:42px 52px;box-shadow:0 2px 12px rgba(0,0,0,.08)}
.head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;border-bottom:2px solid #0f172a;padding-bottom:14px;margin-bottom:22px;flex-wrap:wrap}
.head h1{margin:0 0 3px;font-size:17pt;font-family:Arial,sans-serif;font-weight:700}
.head h2{margin:0;font-size:10pt;font-weight:600;letter-spacing:.18em;text-transform:uppercase;color:#666;font-family:Arial,sans-serif}
.meta{border-collapse:collapse;font-size:10pt;font-family:Arial,sans-serif}
.meta th,.meta td{padding:1px 0;text-align:left;vertical-align:top}
.meta th{color:#666;font-weight:600;padding-right:10px;white-space:nowrap}
.section{margin-bottom:22px;page-break-inside:avoid}
.section h3{font-size:10.5pt;letter-spacing:.06em;text-transform:uppercase;margin:0 0 10px;padding-bottom:4px;border-bottom:1px solid #cbd5e1;font-family:Arial,sans-serif;font-weight:700}
.tbl{width:100%;border-collapse:collapse;font-size:10.5pt;font-family:Arial,sans-serif}
.tbl th,.tbl td{padding:6px 8px;border:1px solid #cbd5e1;text-align:left;vertical-align:top}
.tbl th{background:#f1f5f9;font-weight:700;font-size:9pt;letter-spacing:.05em;text-transform:uppercase;color:#475569}
.tbl td.num,.tbl th.num{text-align:right;font-variant-numeric:tabular-nums}
.tbl tfoot td{background:#f1f5f9;font-weight:700}
.summary{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:#cbd5e1;border-radius:6px;overflow:hidden;max-width:520px}
.summary__item{background:#fff;padding:12px 16px;display:flex;justify-content:space-between;align-items:center}
.summary__item span{font-size:11.5pt;color:#475569}
.summary__item b{font-size:14pt}
.foot{border-top:1px solid #cbd5e1;margin-top:28px;padding-top:12px;font-size:9pt;color:#666;text-align:center;line-height:1.5;font-family:Arial,sans-serif}
.toolbar{max-width:820px;margin:0 auto 14px;display:flex;justify-content:space-between;gap:10px;font-family:Arial,sans-serif}
.toolbar .btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;background:#7c3aed;color:#fff;text-decoration:none;font-size:13.5px;font-weight:600;border:0;cursor:pointer}
.toolbar .btn-outline{background:#fff;color:#0f172a;border:1px solid #cbd5e1}
@page{margin:16mm}
@media print{body{background:#fff;padding:0}.page{box-shadow:none;padding:0;max-width:none;margin:0}.toolbar{display:none}}
</style>
</head>
<body>

<div class="toolbar">
  <button class="btn" onclick="window.print()">&#128424; Print / Save as PDF</button>
  <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregator-view.php')) ?>?id=<?= $id ?>">Close</a>
</div>

<div class="page">

  <div class="head">
    <div>
      <h1><?= $esc($companyName) ?></h1>
      <h2>Aggregator Commission Statement</h2>
    </div>
    <table class="meta">
      <tr><th>Aggregator:</th><td><?= $esc($agg['full_name']) ?></td></tr>
      <tr><th>Status:</th><td><?= $esc($agg['status']) ?></td></tr>
      <?php if ($from !== '' || $to !== ''): ?>
        <tr><th>Period Range:</th><td><?= $esc($from !== '' ? $from : 'start') ?> to <?= $esc($to !== '' ? $to : 'current') ?></td></tr>
      <?php endif; ?>
      <tr><th>Generated:</th><td><?= date('d M Y H:i') ?></td></tr>
    </table>
  </div>

  <div class="section">
    <h3>Aggregator Details</h3>
    <table class="tbl">
      <tbody>
        <tr><th style="width:200px">Name</th><td><?= $esc($agg['full_name']) ?></td></tr>
        <tr><th>Phone</th><td><?= $esc((string) $agg['phone']) ?: '—' ?></td></tr>
        <tr><th>Email</th><td><?= $esc((string) $agg['email']) ?: '—' ?></td></tr>
        <tr><th>Bank</th><td><?= $esc((string) $agg['bank_name']) ?: '—' ?></td></tr>
        <tr><th>Account Name</th><td><?= $esc((string) $agg['bank_account_name']) ?: '—' ?></td></tr>
        <tr><th>Account Number</th><td><?= $esc((string) $agg['bank_account_number']) ?: '—' ?></td></tr>
      </tbody>
    </table>
  </div>

  <div class="section">
    <h3>Linked NINs</h3>
    <?php if (empty($linkedNins)): ?>
      <p style="font-family:Arial,sans-serif;color:#888;font-style:italic">No NINs currently linked.</p>
    <?php else: ?>
      <table class="tbl">
        <thead>
          <tr><th>Owner</th><th>Type</th><th>NIN</th><th>Parent Partner</th><th>Since</th></tr>
        </thead>
        <tbody>
          <?php foreach ($linkedNins as $n): ?>
            <tr>
              <td><?= $esc($n['display_name']) ?></td>
              <td><?= $n['type'] === 'sub_account' ? 'Sub-Account' : 'Partner Own' ?></td>
              <td><?= $esc(mask_nin($n['nin'])) ?></td>
              <td><?= $esc($n['partner_name']) ?><?= $n['type'] === 'sub_account' ? ' (' . $esc($n['partner_code']) . ')' : '' ?></td>
              <td><?= $esc($n['effective_from']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="section">
    <h3>Commission by Period</h3>
    <?php if (empty($periods)): ?>
      <p style="font-family:Arial,sans-serif;color:#888;font-style:italic">No commission recorded in this range.</p>
    <?php else:
        $totalPartner = 0.0; $totalAgg = 0.0;
    ?>
      <table class="tbl">
        <thead>
          <tr>
            <th>Period</th>
            <th class="num">Rate Applied</th>
            <th class="num">Linked NINs' Commission</th>
            <th class="num">Aggregator Commission</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($periods as $p):
            $totalPartner += (float) $p['partner_commission'];
            $totalAgg     += (float) $p['agg_commission'];
            $rateText = ($p['pct_min'] == $p['pct_max'])
              ? number_format((float) $p['pct_min'], 2) . '%'
              : number_format((float) $p['pct_min'], 2) . '% – ' . number_format((float) $p['pct_max'], 2) . '%';
          ?>
            <tr>
              <td><?= $esc(format_period((int) $p['period_year'], (int) $p['period_month'])) ?></td>
              <td class="num"><?= $esc($rateText) ?></td>
              <td class="num"><?= $esc($fmt($p['partner_commission'])) ?></td>
              <td class="num"><?= $esc($fmt($p['agg_commission'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2"><strong>Totals</strong></td>
            <td class="num"><strong><?= $esc($fmt($totalPartner)) ?></strong></td>
            <td class="num"><strong><?= $esc($fmt($totalAgg)) ?></strong></td>
          </tr>
        </tfoot>
      </table>
    <?php endif; ?>
  </div>

  <div class="section">
    <h3>Summary</h3>
    <div class="summary">
      <div class="summary__item"><span>Total Commission Earned</span><b><?= $esc($fmt($earned)) ?></b></div>
      <div class="summary__item"><span>Total Paid Out</span><b><?= $esc($fmt($paid)) ?></b></div>
      <div class="summary__item" style="grid-column:1 / -1;background:#f0f7ff"><span><strong>Outstanding Balance</strong></span><b><?= $esc($fmt($balance)) ?></b></div>
    </div>
  </div>

  <div class="foot">
    <?php if ($companyAddr !== ''): ?><div><?= $esc($companyAddr) ?></div><?php endif; ?>
    <?php if ($companyPhone !== '' || $companyEmail !== ''): ?>
      <div><?= $esc($companyPhone) ?><?= ($companyPhone !== '' && $companyEmail !== '') ? ' · ' : '' ?><?= $esc($companyEmail) ?></div>
    <?php endif; ?>
    <div style="margin-top:4px;font-style:italic">Generated <?= date('d M Y H:i') ?> by <?= $esc($sa['full_name']) ?></div>
  </div>

</div>

</body>
</html>