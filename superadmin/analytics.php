<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$page_title = 'Analytics';
$active_nav = 'analytics';

// Monthly NIN
$monthly = db()->query("SELECT period_year, period_month, SUM(total_nin_generated) AS nin FROM partner_enrolment_records GROUP BY period_year, period_month ORDER BY period_year DESC, period_month DESC LIMIT 12")->fetchAll();
$monthly = array_reverse($monthly);

// NIN by state
$byState = db()->query("
    SELECT st.name, SUM(r.total_nin_generated) AS nin
    FROM partner_enrolment_records r
    JOIN partners p ON p.id = r.partner_id
    JOIN states st ON st.id = p.state_id
    GROUP BY st.id ORDER BY nin DESC LIMIT 10
")->fetchAll();

// Gender split
$gender = db()->query("SELECT SUM(female_count) AS f, SUM(male_count) AS m FROM partner_enrolment_records")->fetch();
$totF = (int) ($gender['f'] ?? 0); $totM = (int) ($gender['m'] ?? 0);

// ERNEW vs CRNEW
$types = db()->query("SELECT SUM(ernew_nin) AS e, SUM(crnew_nin) AS c FROM partner_enrolment_records")->fetch();

// Commission by partner (top 10)
$byPartner = db()->query("
    SELECT p.full_name, SUM(t.amount) AS total
    FROM partner_financial_transactions t JOIN partners p ON p.id=t.partner_id
    WHERE t.type='COMMISSION_EARNED'
    GROUP BY p.id ORDER BY total DESC LIMIT 10
")->fetchAll();

// Withdrawals by month
$wMonth = db()->query("SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, SUM(amount) AS total FROM withdrawals WHERE status='PAID' GROUP BY ym ORDER BY ym DESC LIMIT 12")->fetchAll();
$wMonth = array_reverse($wMonth);

// Partner growth
$growth = db()->query("SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym, COUNT(*) AS c FROM partners GROUP BY ym ORDER BY ym DESC LIMIT 12")->fetchAll();
$growth = array_reverse($growth);

require PICT_ROOT . '/includes/header-superadmin.php';

$barChart = function(array $pairs, int $w = 640, int $h = 240, string $color = '#0b5ed7') {
    $padL = 50; $padR = 20; $padT = 20; $padB = 46;
    $cW = $w - $padL - $padR; $cH = $h - $padT - $padB;
    $max = 1.0; foreach ($pairs as $p) $max = max($max, (float) $p['value']);
    $n = max(1, count($pairs));
    $bw = (int) max(10, (($cW - ($n - 1) * 6) / $n));
    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="xMidYMid meet" font-family="inherit">';
    if (empty($pairs)) { $svg .= '<text x="' . ($w/2) . '" y="' . ($h/2) . '" text-anchor="middle" fill="#9ca3af" font-size="13">No data</text>'; }
    else foreach ($pairs as $i => $p) {
        $x = $padL + $i * ($bw + 6);
        $bh = ((float) $p['value'] / $max) * $cH;
        $y = $padT + ($cH - $bh);
        $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $bw . '" height="' . max(1, $bh) . '" rx="3" fill="' . $color . '"/>';
        if ((float) $p['value'] > 0) $svg .= '<text x="' . ($x + $bw/2) . '" y="' . ($y - 4) . '" text-anchor="middle" font-size="9.5" font-weight="600" fill="#0f172a">' . number_format((float) $p['value'], 0) . '</text>';
        $lbl = $p['label']; if (strlen($lbl) > 10) $lbl = substr($lbl, 0, 9) . '…';
        $svg .= '<text x="' . ($x + $bw/2) . '" y="' . ($h - $padB + 16) . '" text-anchor="middle" font-size="9.5" fill="#6b7280">' . e($lbl) . '</text>';
    }
    return $svg . '</svg>';
};

$donut = function($a, $b, string $labelA, string $labelB) {
    $total = $a + $b; if ($total == 0) $total = 1;
    $pctA = round($a / $total * 100, 1); $pctB = round(100 - $pctA, 1);
    return '<div style="display:flex;align-items:center;gap:20px;padding:12px 0">'
        . '<div style="flex:1"><div style="height:20px;border-radius:10px;background:#e5e7eb;overflow:hidden"><div style="height:100%;width:' . $pctA . '%;background:#0b5ed7"></div></div>'
        . '<div style="display:flex;justify-content:space-between;margin-top:8px;font-size:13px"><span><strong>' . e($labelA) . '</strong> &mdash; ' . number_format($a) . ' (' . $pctA . '%)</span>'
        . '<span><strong>' . e($labelB) . '</strong> &mdash; ' . number_format($b) . ' (' . $pctB . '%)</span></div></div></div>';
};
?>

<div class="page-head"><div><h1>Advanced Analytics</h1><p>System-wide trends. All charts are computed from raw data — no pre-aggregated snapshots.</p></div></div>

<div class="chart-grid">

  <div class="chart-host">
    <div class="chart-host__title">Monthly NIN Generation</div>
    <?= $barChart(array_map(fn($m)=>['label'=>explode(' ',format_period((int)$m['period_year'],(int)$m['period_month']))[0],'value'=>(float)$m['nin']], $monthly), 640, 240) ?>
  </div>

  <div class="chart-host">
    <div class="chart-host__title">NIN by State (Top 10)</div>
    <?= $barChart(array_map(fn($r)=>['label'=>$r['name'],'value'=>(float)$r['nin']], $byState), 640, 240, '#7c3aed') ?>
  </div>

  <div class="chart-host">
    <div class="chart-host__title">Female vs Male</div>
    <?= $donut($totF, $totM, 'Female', 'Male') ?>
  </div>

  <div class="chart-host">
    <div class="chart-host__title">ERNEW vs CRNEW</div>
    <?= $donut((int)$types['e'], (int)$types['c'], 'ERNEW', 'CRNEW') ?>
  </div>

  <div class="chart-host">
    <div class="chart-host__title">Commission by Partner (Top 10)</div>
    <?= $barChart(array_map(fn($r)=>['label'=>$r['full_name'],'value'=>(float)$r['total']], $byPartner), 640, 260, '#15803d') ?>
  </div>

  <div class="chart-host">
    <div class="chart-host__title">Withdrawals by Month (Paid)</div>
    <?= $barChart(array_map(fn($r)=>['label'=>$r['ym'],'value'=>(float)$r['total']], $wMonth), 640, 240, '#dc2626') ?>
  </div>

  <div class="chart-host">
    <div class="chart-host__title">Partner Growth (New Partners per Month)</div>
    <?= $barChart(array_map(fn($r)=>['label'=>$r['ym'],'value'=>(float)$r['c']], $growth), 640, 240, '#0b5ed7') ?>
  </div>

</div>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>