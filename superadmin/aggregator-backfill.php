<?php
/**
 * Backfill aggregator settlements for historical partner enrolments.
 *
 * Finds every partner_enrolment_records row that has no aggregator settlement,
 * checks whether the partner was attached to an aggregator on the record's
 * effective date, and creates the missing settlement + ledger entry.
 *
 * Read-only preview first; commit on POST confirmation.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/aggregator.php';
$sa = superadmin_require_login();

$page_title = 'Aggregator Backfill';
$active_nav = 'aggregators';
$errors = [];
$done = false;
$result = ['processed' => 0, 'created' => 0, 'amount' => 0.0, 'skipped' => 0, 'details' => []];

// Find enrolment records that need a settlement
$rows = db()->query("
    SELECT r.*,
           p.full_name AS partner_name, p.partner_code,
           (SELECT COUNT(*) FROM aggregator_enrolment_records a
             WHERE a.partner_enrolment_id = r.id) AS settlement_count
    FROM partner_enrolment_records r
    LEFT JOIN partners p ON p.id = r.partner_id
    WHERE r.partner_id IS NOT NULL
    ORDER BY r.period_year ASC, r.period_month ASC, r.id ASC
")->fetchAll();

$candidates = [];
foreach ($rows as $r) {
    if ((int) $r['settlement_count'] > 0) {
        continue;
    }
        // Resolve the aggregator scoped to this specific enrolment's NIN —
    // partner-own or sub-account, whichever the record belongs to.
    $assignment = aggregator_resolve_for_enrolment($r);
    if ($assignment === null) {
        continue;
    }
    $rate = aggregator_resolve_rate((int) $assignment['aggregator_id'], (string) $r['effective_date_used']);
    if ($rate === null) {
        continue;
    }
    $pct = (float) $rate['commission_percentage'];
    $partnerCommission = (float) $r['commission_amount'];
    $aggregatorCommission = round($partnerCommission * $pct / 100, 2);

    $candidates[] = [
        'enrolment_id'          => (int) $r['id'],
        'partner_id'            => (int) $r['partner_id'],
        'partner_name'          => (string) $r['partner_name'],
        'period'                => format_period((int) $r['period_year'], (int) $r['period_month']),
        'aggregator_id'         => (int) $assignment['aggregator_id'],
        'aggregator_name'       => (string) $assignment['aggregator_name'],
        'partner_commission'    => $partnerCommission,
        'aggregator_percentage' => $pct,
        'aggregator_commission' => $aggregatorCommission,
    ];
}

// Commit on POST
if (is_post()) {
    csrf_require();

    if (empty($candidates)) {
        flash('info', 'Nothing to backfill — all records already have a settlement.');
        redirect(root_url('/superadmin/aggregator-backfill.php'));
    }

    try {
        db()->beginTransaction();
        foreach ($candidates as $c) {
            $stmt = db()->prepare("SELECT * FROM partner_enrolment_records WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $c['enrolment_id']]);
            $enrolment = $stmt->fetch();
            if (!$enrolment) { continue; }

            $assignment = [
                'aggregator_id'   => $c['aggregator_id'],
                'aggregator_name' => $c['aggregator_name'],
            ];

            $res = aggregator_create_settlement($enrolment, $assignment, (int) $sa['id']);
            $result['processed']++;
            if ($res['created']) {
                $result['created']++;
                $result['amount'] += $res['amount'];
            } else {
                $result['skipped']++;
            }
        }
        db()->commit();
        $done = true;

        audit_log('AGGREGATOR_BACKFILL', 'aggregators', null,
            'Backfilled ' . $result['created'] . ' settlement(s) totalling ' . format_money($result['amount']));

        flash('success', 'Backfill complete: '
            . $result['created'] . ' settlement(s) created totalling '
            . format_money($result['amount']) . '.');
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[aggregator-backfill] ' . $e->getMessage());
        $errors['_global'] = 'Backfill failed: ' . $e->getMessage();
    }
}

require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head">
  <div>
    <h1>Aggregator Backfill</h1>
    <p>Creates missing aggregator settlements for historical partner enrolments.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregators.php')) ?>">All Aggregators</a>
  </div>
</div>

<div class="alert alert-info">
  <strong>What this does.</strong>
  For every partner enrolment record that has no aggregator settlement yet,
  this tool checks whether the partner was assigned to an aggregator at the time,
  and creates the settlement (plus the ledger entry) that the import engine would
  have created if the integration had been in place.
  Existing settlements are never modified or duplicated.
</div>

<?php if (!empty($errors['_global'])): ?>
  <div class="alert alert-danger"><?= e($errors['_global']) ?></div>
<?php endif; ?>

<?php if ($done): ?>
  <div class="card card--pad-lg mb-3">
    <h2>Result</h2>
    <table class="tbl tbl--compact">
      <tr><th style="width:220px">Records Processed</th><td><?= number_format($result['processed']) ?></td></tr>
      <tr><th>Settlements Created</th><td><?= number_format($result['created']) ?></td></tr>
      <tr><th>Already Settled (Skipped)</th><td><?= number_format($result['skipped']) ?></td></tr>
      <tr><th>Total Commission Credited</th><td><strong><?= e(format_money($result['amount'])) ?></strong></td></tr>
    </table>
  </div>
<?php endif; ?>

<?php if (empty($candidates)): ?>
  <div class="card card--pad-lg">
    <div class="empty">
      <h3>Nothing to backfill</h3>
      <p>
        Every partner enrolment record either already has an aggregator settlement,
        or its partner was not assigned to any aggregator at the time.
      </p>
      <p class="muted" style="font-size:13.5px">
        If you expect settlements to be created, confirm that:
      </p>
      <ul class="muted" style="font-size:13.5px;text-align:left;max-width:520px;margin:8px auto">
        <li>The aggregator has a rate in force for the enrolment period's date.</li>
        <li>The partner's assignment to the aggregator starts on or before the enrolment period's date.</li>
        <li>The partner enrolment record has a non-zero <code>commission_amount</code>.</li>
      </ul>
    </div>
  </div>
<?php else: ?>
  <?php
  // Summary: per-aggregator totals
  $aggSummary = [];
  foreach ($candidates as $c) {
      $key = $c['aggregator_id'];
      if (!isset($aggSummary[$key])) {
          $aggSummary[$key] = ['name' => $c['aggregator_name'], 'count' => 0, 'amount' => 0.0];
      }
      $aggSummary[$key]['count']++;
      $aggSummary[$key]['amount'] += $c['aggregator_commission'];
  }
  $grandTotal = array_sum(array_column($aggSummary, 'amount'));
  ?>

  <div class="stat-grid">
    <div class="stat stat--purple">
      <div class="stat__label">Records to Process</div>
      <div class="stat__value"><?= number_format(count($candidates)) ?></div>
    </div>
    <div class="stat stat--ok">
      <div class="stat__label">Total Commission to Credit</div>
      <div class="stat__value"><?= e(format_money($grandTotal)) ?></div>
    </div>
  </div>

  <div class="card mb-3">
    <h2>Impact by Aggregator</h2>
    <table class="tbl">
      <thead>
        <tr>
          <th>Aggregator</th>
          <th class="num">Records</th>
          <th class="num">Commission to Credit</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($aggSummary as $s): ?>
          <tr>
            <td><?= e($s['name']) ?></td>
            <td class="num"><?= number_format($s['count']) ?></td>
            <td class="num"><strong><?= e(format_money($s['amount'])) ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td><strong>Total</strong></td>
          <td class="num"><strong><?= number_format(count($candidates)) ?></strong></td>
          <td class="num"><strong><?= e(format_money($grandTotal)) ?></strong></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="card mb-3">
    <h2>Detail (first 100 rows)</h2>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Partner</th>
            <th>Period</th>
            <th>Aggregator</th>
            <th class="num">Partner Commission</th>
            <th class="num">Agg %</th>
            <th class="num">Agg Commission</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_slice($candidates, 0, 100) as $c): ?>
            <tr>
              <td><?= e($c['partner_name']) ?></td>
              <td><?= e($c['period']) ?></td>
              <td><?= e($c['aggregator_name']) ?></td>
              <td class="num"><?= e(format_money($c['partner_commission'])) ?></td>
              <td class="num"><?= e(number_format($c['aggregator_percentage'], 2)) ?>%</td>
              <td class="num"><strong><?= e(format_money($c['aggregator_commission'])) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card card--pad-lg">
    <h2>Confirm Backfill</h2>
    <div class="alert alert-warn">
      This will create <strong><?= number_format(count($candidates)) ?></strong> settlement(s)
      and credit <strong><?= e(format_money($grandTotal)) ?></strong> to the aggregators' ledgers.
      The action is logged and irreversible through the UI.
    </div>
    <form method="post">
      <?= csrf_field() ?>
      <button class="btn btn-grape btn-lg" type="submit"
              onclick="return confirm('Create <?= count($candidates) ?> aggregator settlement(s)? This cannot be undone from the UI.');">
        Run Backfill Now
      </button>
      <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregators.php')) ?>" style="margin-left:8px">Cancel</a>
    </form>
  </div>
<?php endif; ?>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>