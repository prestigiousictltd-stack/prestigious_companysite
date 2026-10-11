<?php
/**
 * Daily Report renderer.
 * Produces the report matching the reference document structure.
 * Used by preview.php and print.php.
 *
 * @param array  $r         Parent row from daily_reports
 * @param array  $children  ['receivables'=>..., 'performance'=>..., 'services'=>..., 'expenses'=>..., 'revenue'=>..., 'notes'=>...]
 * @param string $mode      'web' | 'print'
 */

declare(strict_types=1);

function render_daily_report(array $r, array $children, string $mode = 'web'): string
{
    $companyName = (string) setting('company_name', 'Prestigious ICT Investment Limited');
    $companyAddr = (string) setting('company_address', '');
    $companyPhone= (string) setting('company_phone', '');
    $companyEmail= (string) setting('company_email', '');
    $sym         = (string) setting('system_currency_symbol', '₦');

       $arCurrency  = $r['receivable_currency'] ?: 'NGN';
    $arLabel     = 'Amount (N)';
    $arPrefix    = $sym;   // ₦ from system_currency_symbol
    // ---- Helpers (local) ----
    $fmtMoney = function ($v, string $prefix) {
        return $prefix . number_format((float) $v, 2);
    };
    $fmtDate = function (?string $d): string {
        if (!$d) return '';
        $ts = strtotime($d);
        return $ts ? date('d M Y', $ts) : e($d);
    };
    $esc = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // ---- Section IDs for print-mode CSS ----
    $isPrint = $mode === 'print';

   

   

   

    
    // ---- Begin HTML ----
    ob_start();
    ?>
<article class="dr-report">
  <header class="dr-report__head">
    <div>
      <h1><?= $esc($companyName) ?></h1>
      <h2>Daily Report</h2>
    </div>
    <table class="dr-report__meta">
      <tr><th>Report ID:</th><td><?= $esc($r['report_reference']) ?></td></tr>
      <tr><th>Created By:</th><td><?= $esc($r['created_by_name_snapshot']) ?></td></tr>
      <tr><th>Date:</th><td><?= $esc($fmtDate($r['report_date'])) ?></td></tr>
      <tr><th>Status:</th><td><?= $esc($r['status']) ?></td></tr>
      <?php if (!empty($r['submitted_at'])): ?>
      <tr><th>Submitted:</th><td><?= $esc(date('d M Y H:i', strtotime($r['submitted_at']))) ?></td></tr>
      <?php endif; ?>
    </table>
  </header>

  <?php if (!empty($r['executive_summary'])): ?>
  <section class="dr-report__section">
    <h3>Executive Summary</h3>
    <div class="dr-report__narrative"><?= nl2br($esc($r['executive_summary'])) ?></div>
  </section>
  <?php endif; ?>

    <section class="dr-report__section">
    <h3>Accounts Receivable</h3>
    <?php if (empty($children['receivables'])): ?>
      <p class="dr-report__empty">No receivable entries recorded.</p>
    <?php else: ?>
      <table class="dr-report__tbl">
        <thead><tr>
          <th style="width:44px">S/N</th>
          <th>Staff Name</th>
          <th>Service Rendered</th>
          <th class="num">Unit</th>
          <th class="num"><?= $esc($arLabel) ?></th>
          <th class="num">Row Total</th>
          <th>Due Date</th>
        </tr></thead>
        <tbody>
          <?php
            $arGrandTotal = 0.0;
            foreach ($children['receivables'] as $i => $row):
                $qty = (float) ($row['unit_quantity'] ?? 1);
                if ($qty <= 0) { $qty = 1; }
                $rowTotal = $qty * (float) $row['amount'];
                $arGrandTotal += $rowTotal;
          ?>
            <tr>
              <td><?= $i + 1 ?></td>
              <td><?= $esc($row['staff_name_snapshot']) ?></td>
              <td><?= $esc($row['service_rendered']) ?></td>
              <td class="num"><?= $esc(number_format($qty, 2)) ?></td>
              <td class="num"><?= $esc($fmtMoney($row['amount'], $arPrefix)) ?></td>
              <td class="num"><?= $esc($fmtMoney($rowTotal, $arPrefix)) ?></td>
              <td><?= $esc($fmtDate($row['due_date'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="5" class="num"><strong>Total</strong></td>
            <td class="num"><strong><?= $esc($fmtMoney($arGrandTotal, $arPrefix)) ?></strong></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    <?php endif; ?>
  </section>

 

   <section class="dr-report__section">
    <h3>Services Rendered Out</h3>
    <?php if (empty($children['services'])): ?>
      <p class="dr-report__empty">No services recorded.</p>
    <?php else: ?>
      <table class="dr-report__tbl">
        <thead><tr>
          <th>Service</th>
          <th>Staff Name</th>
          <th class="num">Units Sold</th>
          <th class="num">Revenue (<?= $esc($sym === '₦' ? 'N' : $sym) ?>)</th>
          <th>Due Date</th>
        </tr></thead>
        <tbody>
          <?php foreach ($children['services'] as $row): ?>
            <tr>
              <td><?= $esc($row['service_name_snapshot']) ?></td>
              <td>
                <?php
                  $staffName = (string) ($row['staff_name_snapshot'] ?? '');
                  echo $staffName !== '' ? $esc($staffName) : '<span class="dr-report__empty">—</span>';
                ?>
              </td>
              <td class="num"><?= (int) $row['units_sold'] ?></td>
              <td class="num"><?= $esc($fmtMoney($row['revenue'], '')) ?></td>
              <td><?= $esc($fmtDate($row['due_date'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2" class="num"><strong>Totals</strong></td>
            <td class="num"><strong><?= (int) $r['service_units_total'] ?></strong></td>
            <td class="num"><strong><?= $esc($fmtMoney($r['service_revenue_total'], '')) ?></strong></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    <?php endif; ?>
  </section>

  <section class="dr-report__section">
    <h3>Expenses Breakdown</h3>
    <?php if (empty($children['expenses'])): ?>
      <p class="dr-report__empty">No expenses recorded.</p>
    <?php else: ?>
      <table class="dr-report__tbl">
        <thead><tr>
          <th>Category</th>
          <th>Description</th>
          <th class="num">Amount (<?= $esc($sym === '₦' ? 'N' : $sym) ?>)</th>
        </tr></thead>
        <tbody>
          <?php foreach ($children['expenses'] as $row): ?>
            <tr>
              <td><?= $esc($row['category_name_snapshot']) ?></td>
              <td><?= $esc((string) $row['description']) ?></td>
              <td class="num"><?= $esc($fmtMoney($row['amount'], '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2" class="num"><strong>Total Expenses</strong></td>
            <td class="num"><strong><?= $esc($fmtMoney($r['expense_total'], '')) ?></strong></td>
          </tr>
        </tfoot>
      </table>
    <?php endif; ?>
  </section>

  <section class="dr-report__section">
    <h3>Financial Summary</h3>
    <table class="dr-report__tbl dr-report__tbl--fin">
      <thead><tr><th>Items</th><th class="num">Amount (<?= $esc($sym === '₦' ? 'N' : $sym) ?>)</th></tr></thead>
            <tbody>
        <?php
        $basisLabel = match ($r['revenue_basis'] ?? 'SERVICES') {
            'WEEKLY_SALES'             => 'Revenue (Weekly Sales)',
            'SERVICES_PLUS_RECEIVABLE' => 'Revenue (Services + Receivable)',
            'MANUAL'                   => 'Revenue (Manual)',
            default                    => 'Revenue (Services)',
        };
        ?>
        <tr><td><?= $esc($basisLabel) ?></td><td class="num"><?= $esc($fmtMoney($r['revenue_total'], '')) ?></td></tr>
        <tr><td>Expenses</td><td class="num"><?= $esc($fmtMoney($r['expense_total'], '')) ?></td></tr>
           <tr class="dr-report__tbl--net">
          <td><strong>Net Income</strong></td>
          <td class="num"><strong><?= $esc($fmtMoney($r['net_income'], '')) ?></strong></td>
        </tr>
      </tbody>
    </table>
  </section>

 

  <?php if (!empty($children['notes'])):
    $notes = $children['notes'];
  ?>
  <section class="dr-report__section">
    <h3>Problem / Issues</h3>
    <?php if (!empty($notes['problems_identified'])): ?>
      <h4>Problems / Issues Identified</h4>
      <div class="dr-report__narrative"><?= nl2br($esc($notes['problems_identified'])) ?></div>
    <?php endif; ?>
    <?php if (!empty($notes['actions_taken'])): ?>
      <h4>Actions Taken</h4>
      <div class="dr-report__narrative"><?= nl2br($esc($notes['actions_taken'])) ?></div>
    <?php endif; ?>
    <?php if (!empty($notes['recommended_action'])): ?>
      <h4>Recommended Action</h4>
      <div class="dr-report__narrative"><?= nl2br($esc($notes['recommended_action'])) ?></div>
    <?php endif; ?>
    <?php if (!empty($notes['additional_notes'])): ?>
      <h4>Additional Notes</h4>
      <div class="dr-report__narrative"><?= nl2br($esc($notes['additional_notes'])) ?></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($isPrint): ?>
  <footer class="dr-report__foot">
    <?php if ($companyAddr !== ''): ?><div><?= $esc($companyAddr) ?></div><?php endif; ?>
    <div>
      <?php if ($companyPhone !== ''): ?>Tel: <?= $esc($companyPhone) ?><?php endif; ?>
      <?php if ($companyPhone !== '' && $companyEmail !== ''): ?> &middot; <?php endif; ?>
      <?php if ($companyEmail !== ''): ?><?= $esc($companyEmail) ?><?php endif; ?>
    </div>
    <div class="dr-report__foot-sign">Generated <?= date('d M Y H:i') ?> — Report <?= $esc($r['report_reference']) ?></div>
  </footer>
  <?php endif; ?>
</article>
    <?php
    return (string) ob_get_clean();
}

/** Fetch parent + all children for a report. */
function fetch_daily_report_bundle(int $reportId): ?array
{
    $stmt = db()->prepare('SELECT * FROM daily_reports WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $reportId]);
    $r = $stmt->fetch();
    if (!$r) {
        return null;
    }

    $children = [
        'receivables' => [],
        'performance' => [],
        'services'    => [],
        'expenses'    => [],
        'revenue'     => [],
        'notes'       => null,
    ];

    $q = function (string $table, string $orderBy) use ($reportId): array {
        $stmt = db()->prepare("SELECT * FROM `$table` WHERE report_id = :id ORDER BY $orderBy");
        $stmt->execute([':id' => $reportId]);
        return $stmt->fetchAll();
    };

    $children['receivables'] = $q('daily_report_receivables', 'row_order ASC, id ASC');
    $children['performance'] = $q('daily_report_user_performance', 'row_order ASC, id ASC');
    $children['services']    = $q('daily_report_services', 'row_order ASC, id ASC');
    $children['expenses']    = $q('daily_report_expenses', 'row_order ASC, id ASC');
    $children['revenue']     = $q('daily_report_revenue', "FIELD(day_of_week,'MON','TUE','WED','THU','FRI','SAT','SUN')");

    $stmt = db()->prepare('SELECT * FROM daily_report_notes WHERE report_id = :id LIMIT 1');
    $stmt->execute([':id' => $reportId]);
    $children['notes'] = $stmt->fetch() ?: null;

    return ['report' => $r, 'children' => $children];
}