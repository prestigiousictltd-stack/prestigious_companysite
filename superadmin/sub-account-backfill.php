<?php
/**
 * Standalone sub-account backfill.
 *
 * Finds every partner_enrolment_records row whose NIN matches an ACTIVE
 * sub-account but is not yet credited to that sub-account's bucket —
 * whether the record is currently orphan or sitting in the parent
 * partner's own bucket.
 *
 * Preview first; commit on POST.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$sa = superadmin_require_login();

$page_title = 'Sub-Account Backfill';
$active_nav = 'sub-accounts';

// Optional filters
$fSubId = (int) ($_GET['sub_id'] ?? 0);

// ---- Scan for pending attachments ----
$sql = "
    SELECT r.id AS enrolment_id,
           r.partner_id AS current_partner_id,
           r.sub_account_id AS current_sub_id,
           r.partner_nin_raw,
           r.period_year, r.period_month,
           r.total_nin_generated, r.commission_amount, r.gross_revenue,
           r.effective_date_used,
           s.id AS target_sub_id,
           s.full_name AS target_sub_name,
           s.nin_number AS target_sub_nin,
           p.id AS partner_id, p.full_name AS partner_name, p.partner_code
    FROM partner_enrolment_records r
    JOIN partner_sub_accounts s
         ON s.nin_number = r.partner_nin_raw AND s.status = 'ACTIVE'
    JOIN partners p ON p.id = s.partner_id
    WHERE (r.sub_account_id IS NULL OR r.sub_account_id <> s.id)
      AND (r.partner_id IS NULL OR r.partner_id = s.partner_id)
      AND r.commission_amount > 0
";
$params = [];
if ($fSubId > 0) {
    $sql .= " AND s.id = :fsid";
    $params[':fsid'] = $fSubId;
}
$sql .= " ORDER BY s.full_name ASC, r.period_year ASC, r.period_month ASC, r.id ASC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$candidates = $stmt->fetchAll();

// Compute the amount that will be credited per row using the sub-account's rate
foreach ($candidates as &$c) {
    $effDate = (string) ($c['effective_date_used'] ?? '');
    if ($effDate === '') {
        $effDate = sprintf('%04d-%02d-01', (int) $c['period_year'], (int) $c['period_month']);
    }
    $rate = sub_account_resolve_rate((int) $c['target_sub_id'], $effDate);
    $pct  = $rate ? (float) $rate['commission_percentage'] : 0.0;
    $comm = round((float) $c['gross_revenue'] * $pct / 100, 2);
    $c['target_pct']       = $pct;
    $c['target_commission'] = $comm;
    $c['is_reattribution'] = ($c['current_partner_id'] !== null);
}
unset($c);

// Build per-sub-account summary
$bySub = [];
foreach ($candidates as $c) {
    $key = (int) $c['target_sub_id'];
    if (!isset($bySub[$key])) {
        $bySub[$key] = [
            'sub_id'       => $key,
            'sub_name'     => (string) $c['target_sub_name'],
            'partner_name' => (string) $c['partner_name'],
            'partner_code' => (string) $c['partner_code'],
            'count'        => 0,
            'reattributions' => 0,
            'orphans'      => 0,
            'amount'       => 0.0,
        ];
    }
    $bySub[$key]['count']++;
    $bySub[$key]['amount'] += $c['target_commission'];
    if ($c['is_reattribution']) {
        $bySub[$key]['reattributions']++;
    } else {
        $bySub[$key]['orphans']++;
    }
}

$grandTotal = array_sum(array_column($bySub, 'amount'));

// ---- Commit ----
$result = null;
if (is_post()) {
    csrf_require();
    $runSubId = (int) ($_POST['sub_id'] ?? 0);   // 0 = all

    if (empty($candidates)) {
        flash('info', 'Nothing to backfill.');
        redirect(root_url('/superadmin/sub-account-backfill.php'));
    }

    // Filter to the chosen subset if a specific sub-account was requested
    $toRun = $candidates;
    if ($runSubId > 0) {
        $toRun = array_values(array_filter($candidates, fn($c) => (int) $c['target_sub_id'] === $runSubId));
    }

    if (empty($toRun)) {
        flash('error', 'No candidates match the requested sub-account.');
        redirect(root_url('/superadmin/sub-account-backfill.php'));
    }

    $attached = 0;
    $reattributed = 0;
    $totalCredited = 0.0;
    $errors = [];

    try {
        db()->beginTransaction();

        foreach ($toRun as $c) {
            $res = sub_account_attach_enrolment((int) $c['enrolment_id'], (int) $c['target_sub_id'], (int) $sa['id']);
            if (!empty($res['ok'])) {
                $attached++;
                if (!empty($res['was_reattribution'])) {
                    $reattributed++;
                }
                if (!empty($res['credited'])) {
                    $totalCredited += (float) $res['amount'];
                }
            } else {
                $errors[] = 'Row #' . $c['enrolment_id'] . ': ' . ($res['reason'] ?? 'Unknown error');
            }
        }

        db()->commit();

        $result = [
            'attached'       => $attached,
            'reattributed'   => $reattributed,
            'total_credited' => $totalCredited,
            'errors'         => $errors,
        ];

        audit_log(
            'SUB_ACCOUNT_BACKFILL_RUN',
            'partner_sub_accounts',
            $runSubId > 0 ? $runSubId : null,
            'Attached ' . $attached . ' record(s) totalling ' . format_money($totalCredited)
                . ($reattributed > 0 ? ' (' . $reattributed . ' reattribution(s))' : '')
        );

        flash('success', 'Backfill complete: ' . $attached . ' record(s) attached, '
            . format_money($totalCredited) . ' credited.');

        // Reload the page fresh so the table reflects the new state
        redirect(root_url('/superadmin/sub-account-backfill.php')
            . ($fSubId > 0 ? '?sub_id=' . $fSubId : ''));
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        error_log('[sub-account-backfill] ' . $ex->getMessage());
        $result = [
            'attached' => 0,
            'reattributed' => 0,
            'total_credited' => 0.0,
            'errors' => ['Fatal: ' . $ex->getMessage()],
        ];
    }
}

// Sub-accounts dropdown
$subs = db()->query("
    SELECT s.id, s.full_name, p.full_name AS partner_name, p.partner_code
    FROM partner_sub_accounts s
    JOIN partners p ON p.id = s.partner_id
    WHERE s.status = 'ACTIVE'
    ORDER BY s.full_name ASC
")->fetchAll();

require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head">
  <div>
    <h1>Sub-Account Backfill</h1>
    <p>Attach historical enrolment records to sub-accounts that were approved after the records were imported.</p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/sub-accounts.php')) ?>">All Sub-Accounts</a>
    <a class="btn btn-outline" href="<?= e(root_url('/superadmin/aggregator-backfill.php')) ?>">Aggregator Backfill</a>
  </div>
</div>

<div class="alert alert-info">
  <strong>What this does.</strong>
  For every partner enrolment record whose NIN matches an <em>active</em> sub-account
  but is not yet credited to that sub-account's bucket, this tool attaches the record
  and credits the correct bucket.
  <br><br>
  Two cases are handled:
  <ul style="margin:8px 0 0 20px;padding:0">
    <li><strong>Orphan records</strong> — never attached to any partner or sub-account. The commission is credited to the sub-account bucket.</li>
    <li><strong>Reattributions</strong> — records currently credited to the parent partner's own bucket.
        The prior credit is reversed and a new credit is written to the sub-account bucket, so no double-counting occurs.</li>
  </ul>
</div>

<?php if ($result !== null): ?>
  <div class="card card--pad-lg mb-3">
    <h2>Last Run Result</h2>
    <table class="tbl tbl--compact">
      <tr><th style="width:220px">Records Attached</th><td><?= number_format((int) $result['attached']) ?></td></tr>
      <tr><th>Of which Reattributions</th><td><?= number_format((int) $result['reattributed']) ?></td></tr>
      <tr><th>Total Commission Credited</th><td><strong><?= e(format_money((float) $result['total_credited'])) ?></strong></td></tr>
    </table>
    <?php if (!empty($result['errors'])): ?>
      <div class="alert alert-danger" style="margin-top:14px">
        <strong><?= count($result['errors']) ?> error(s):</strong>
        <ul style="margin:6px 0 0 18px;padding:0">
          <?php foreach (array_slice($result['errors'], 0, 10) as $err): ?>
            <li><?= e($err) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (empty($candidates)): ?>
  <div class="card card--pad-lg">
    <div class="empty">
      <h3>Nothing to backfill</h3>
      <p>
        Every enrolment record whose NIN matches an active sub-account is already
        attached to the correct bucket. There is no historical data waiting.
      </p>
      <p class="muted" style="font-size:13.5px">This is the normal state after the automatic backfill at approval, or after this tool has been run.</p>
    </div>
  </div>
<?php else: ?>
  <div class="stat-grid">
    <div class="stat stat--purple">
      <div class="stat__label">Records to Process</div>
      <div class="stat__value"><?= number_format(count($candidates)) ?></div>
    </div>
    <div class="stat stat--pending">
      <div class="stat__label">Reattributions</div>
      <div class="stat__value"><?= number_format(array_sum(array_column($bySub, 'reattributions'))) ?></div>
      <div class="stat__sub">Moved from the parent's own bucket</div>
    </div>
    <div class="stat">
      <div class="stat__label">Orphans</div>
      <div class="stat__value"><?= number_format(array_sum(array_column($bySub, 'orphans'))) ?></div>
      <div class="stat__sub">Never attached</div>
    </div>
    <div class="stat stat--ok">
      <div class="stat__label">Total Commission to Credit</div>
      <div class="stat__value"><?= e(format_money($grandTotal)) ?></div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="flex-between mb-2">
      <h2 style="margin:0">Impact by Sub-Account</h2>
      <form method="get" style="display:flex;gap:8px;align-items:end">
        <div class="form-group" style="margin:0;min-width:220px">
          <label class="form-label">Filter</label>
          <select class="form-control" name="sub_id" onchange="this.form.submit()">
            <option value="">All sub-accounts</option>
            <?php foreach ($subs as $s): ?>
              <option value="<?= (int) $s['id'] ?>"<?= $fSubId === (int) $s['id'] ? ' selected' : '' ?>>
                <?= e($s['full_name']) ?> (<?= e($s['partner_name']) ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </form>
    </div>

    <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr>
            <th>Sub-Account</th>
            <th>Parent Partner</th>
            <th class="num">Records</th>
            <th class="num">Reattributions</th>
            <th class="num">Orphans</th>
            <th class="num">Commission to Credit</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bySub as $s): ?>
            <tr>
              <td><strong><?= e($s['sub_name']) ?></strong></td>
              <td><?= e($s['partner_name']) ?><br><span class="muted" style="font-size:12px"><?= e($s['partner_code']) ?></span></td>
              <td class="num"><?= number_format($s['count']) ?></td>
              <td class="num"><?= number_format($s['reattributions']) ?></td>
              <td class="num"><?= number_format($s['orphans']) ?></td>
              <td class="num"><strong><?= e(format_money($s['amount'])) ?></strong></td>
              <td class="text-right">
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="sub_id" value="<?= (int) $s['sub_id'] ?>">
                  <button class="btn btn-grape btn-sm" type="submit"
                          onclick="return confirm('Attach <?= $s['count'] ?> record(s) to <?= e(addslashes($s['sub_name'])) ?>?');">
                    Run
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td colspan="2"><strong>All Sub-Accounts</strong></td>
            <td class="num"><strong><?= number_format(count($candidates)) ?></strong></td>
            <td class="num"><strong><?= number_format(array_sum(array_column($bySub, 'reattributions'))) ?></strong></td>
            <td class="num"><strong><?= number_format(array_sum(array_column($bySub, 'orphans'))) ?></strong></td>
            <td class="num"><strong><?= e(format_money($grandTotal)) ?></strong></td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <div class="card mb-3">
    <h2>Detail (first 100 records)</h2>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead>
          <tr>
            <th>Sub-Account</th>
            <th>Partner</th>
            <th>Period</th>
            <th>NIN</th>
            <th>Current Location</th>
            <th class="num">Gross</th>
            <th class="num">Rate</th>
            <th class="num">Commission</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach (array_slice($candidates, 0, 100) as $c): ?>
            <tr>
              <td><?= e($c['target_sub_name']) ?></td>
              <td><?= e($c['partner_name']) ?></td>
              <td><?= e(format_period((int) $c['period_year'], (int) $c['period_month'])) ?></td>
              <td class="num"><?= number_format((int) $c['total_nin_generated']) ?></td>
              <td>
                <?php if ($c['is_reattribution']): ?>
                  <span class="badge badge--pending">Reattribute</span>
                <?php else: ?>
                  <span class="badge">Orphan</span>
                <?php endif; ?>
              </td>
              <td class="num"><?= e(format_money((float) $c['gross_revenue'])) ?></td>
              <td class="num"><?= e(number_format((float) $c['target_pct'], 2)) ?>%</td>
              <td class="num"><strong><?= e(format_money((float) $c['target_commission'])) ?></strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card card--pad-lg">
    <h2>Confirm Backfill</h2>
    <div class="alert alert-warn">
      This will attach <strong><?= number_format(count($candidates)) ?></strong> record(s) and
      credit <strong><?= e(format_money($grandTotal)) ?></strong> across
      <?= number_format(count($bySub)) ?> sub-account<?= count($bySub) === 1 ? '' : 's' ?>.
      Any reattributions will also write a matching reversal in the parent partner's own bucket
      so no commission is double-counted. This action is logged.
    </div>
    <form method="post" style="display:flex;gap:10px;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="sub_id" value="0">
      <button class="btn btn-grape btn-lg" type="submit"
              onclick="return confirm('Run backfill for ALL <?= count($candidates) ?> record(s)?');">
        Run Backfill for All Sub-Accounts
      </button>
      <a class="btn btn-outline" href="<?= e(root_url('/superadmin/sub-accounts.php')) ?>">Cancel</a>
    </form>
  </div>
<?php endif; ?>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>