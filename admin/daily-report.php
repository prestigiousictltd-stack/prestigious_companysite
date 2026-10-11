<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/admin-auth.php';
$admin = admin_require_login();

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;

$existingReport = null;
$existingBundle = null;

if ($editId > 0) {
    $stmt = db()->prepare("SELECT * FROM daily_reports WHERE id = :id AND created_by_admin_id = :aid LIMIT 1");
    $stmt->execute([':id' => $editId, ':aid' => (int) $admin['id']]);
    $existingReport = $stmt->fetch();
    if (!$existingReport) {
        flash('error', 'Report not found or not editable.');
        redirect('/admin/reports.php');
    }
    if (!in_array($existingReport['status'], ['DRAFT', 'RETURNED'], true)) {
        flash('error', 'This report can no longer be edited.');
        redirect('/admin/report-view.php?id=' . $editId);
    }
    $existingBundle = fetch_daily_report_bundle($editId);
}

$page_title = $existingReport ? 'Edit Daily Report' : 'New Daily Report';
$active_nav = 'daily-report';

// Catalogues
$staffList   = db()->query("SELECT id, display_name FROM staff WHERE is_active = 1 ORDER BY display_name")->fetchAll();
$serviceList = db()->query("SELECT id, service_name FROM services WHERE is_active = 1 ORDER BY service_name")->fetchAll();
$expenseList = db()->query("SELECT id, category_name FROM expense_categories WHERE is_active = 1 ORDER BY category_name")->fetchAll();

// Settings
$revenueBasis = (string) setting('report_revenue_basis', 'SERVICES');
$currencySymbol = (string) setting('system_currency_symbol', '₦');
$arCurrency   = (string) setting('report_ar_default_currency', 'NGN');
 $arCurrency='NGN';
$arSymbol = match ($arCurrency) {
    
    'NGN' => '₦',
    
    default => $arCurrency . ' ',
};
// Old input (on validation failure)
$oldInput = $_SESSION['_old'] ?? [];

$c = $existingBundle['children'] ?? null;

require PICT_ROOT . '/includes/header-admin.php';
?>

<div class="page-head">
  <div>
    <h1><?= $existingReport ? 'Edit Daily Report' : 'New Daily Report' ?></h1>
    <p>
      Complete all sections. Financial figures are recalculated on the server when you submit.
      <?php if ($existingReport && $existingReport['status'] === 'RETURNED' && !empty($existingReport['review_comment'])): ?>
        <br><strong>Super Admin comment:</strong> <?= e($existingReport['review_comment']) ?>
      <?php endif; ?>
    </p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="./reports.php">Cancel</a>
  </div>
</div>

<?php if (!empty($oldInput['_global'])): ?>
  <div class="alert alert-danger"><?= e($oldInput['_global']) ?></div>
<?php endif; ?>

<datalist id="dl-services">
  <?php foreach ($serviceList as $s): ?>
    <option value="<?= e($s['service_name']) ?>"></option>
  <?php endforeach; ?>
</datalist>
<datalist id="dl-expenses">
  <?php foreach ($expenseList as $c): ?>
    <option value="<?= e($c['category_name']) ?>"></option>
  <?php endforeach; ?>
</datalist>
<datalist id="dl-staff">
  <?php foreach ($staffList as $s): ?>
    <option value="<?= e($s['display_name']) ?>"></option>
  <?php endforeach; ?>
</datalist>

<form method="post" action="./daily-report-save.php" id="daily-report-form" novalidate>
  <?= csrf_field() ?>
  <input type="hidden" name="report_id" value="<?= $existingReport ? (int) $existingReport['id'] : '' ?>">
  <input type="hidden" name="action" id="dr-action" value="submit">

  <!-- SECTION 1: Report Information -->
  <div class="dr-section">
    <div class="dr-section__head">
      <h2>Report Information</h2>
      <span class="hint">Report ID is assigned on save</span>
    </div>
    <div class="dr-section__body">
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="report_date">Date <span class="req">*</span></label>
          <input class="form-control" type="date" id="report_date" name="report_date"
                 value="<?= e($existingReport['report_date'] ?? ($oldInput['report_date'] ?? date('Y-m-d'))) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Created By</label>
          <input class="form-control" type="text" value="<?= e($admin['full_name']) ?>" readonly>
          <div class="form-hint">Automatically set to the signed-in Admin.</div>
        </div>
      </div>
    </div>
  </div>

  <!-- SECTION 2: Executive Summary -->
  <div class="dr-section">
    <div class="dr-section__head">
      <h2>Executive Summary</h2>
      <span class="hint">Narrative overview of the day</span>
    </div>
    <div class="dr-section__body">
      <div class="form-group">
        <textarea class="form-control" name="executive_summary" rows="5"
                  placeholder="Summarise the day: receivables collected, services rendered, expenses, revenue, and any notable items."><?= e($existingReport['executive_summary'] ?? ($oldInput['executive_summary'] ?? '')) ?></textarea>
      </div>
    </div>
  </div>

     <!-- SECTION 3: Accounts Receivable -->
  <div class="dr-section">
    <div class="dr-section__head">
      <h2>Accounts Receivable</h2>
           <span class="hint">
        Currency: <strong>Amount (N)</strong>
        &middot; Row total = Unit &times; Amount
      </span>
    </div>
    <div class="dr-section__body dr-section__body--flush">
      <div style="overflow-x:auto">
        <table class="dr-table">
          <thead>
            <tr>
              <th style="width:44px">S/N</th>
              <th>Staff Name</th>
              <th>Service Rendered</th>
              <th style="width:90px">Unit</th>
                           <th style="width:130px">Amount (N)</th>
                             <th style="width:140px">Total Amount</th>
              <th style="width:150px">Date</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="ar-rows">
            <?php if (!empty($c['receivables'])): foreach ($c['receivables'] as $i => $row):
                $qty = (float) ($row['unit_quantity'] ?? 1);
                if ($qty <= 0) { $qty = 1; }
                $rowTotal = $qty * (float) $row['amount'];
            ?>
              <tr>
                <td class="dr-num"><?= $i + 1 ?></td>
                <td><input type="text"   name="ar_staff[]"    list="dl-staff"    value="<?= e($row['staff_name_snapshot']) ?>" maxlength="150"></td>
                <td><input type="text"   name="ar_service[]"  list="dl-services" value="<?= e($row['service_rendered']) ?>" maxlength="190"></td>
                <td><input type="number" name="ar_quantity[]" class="ar-qty" step="1" min="0" value="<?= e(number_format($qty, 2, '.', '')) ?>"></td>
                <td><input type="number" name="ar_amount[]"   class="ar-amt" step="50" min="0" value="<?= e(number_format((float) $row['amount'], 2, '.', '')) ?>"></td>
                <td><input type="text"   class="ar-row-total" readonly tabindex="-1" value="<?= e(number_format($rowTotal, 2, '.', '')) ?>"></td>
                <td><input type="date"   name="ar_due[]" value="<?= e((string) $row['due_date']) ?>"></td>
                <td class="actions"><button type="button" class="row-remove" title="Remove">&times;</button></td>
              </tr>
            <?php endforeach; else: ?>
              <tr>
                <td class="dr-num">1</td>
                <td><input type="text"   name="ar_staff[]"    list="dl-staff"    maxlength="150"></td>
                <td><input type="text"   name="ar_service[]"  list="dl-services" maxlength="190"></td>
                <td><input type="number" name="ar_quantity[]" class="ar-qty" step="1" min="0" value="1"></td>
                <td><input type="number" name="ar_amount[]"   class="ar-amt" step="50" min="0" value="0"></td>
                <td><input type="text"   class="ar-row-total" readonly tabindex="-1" value="0.00"></td>
                <td><input type="date"   name="ar_due[]"></td>
                <td class="actions"><button type="button" class="row-remove" title="Remove">&times;</button></td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="dr-total">
        <span>Total Receivable:</span>
        <b id="ar-total"><?= $currencySymbol ?>0.00</b>
      </div>
      <div class="dr-section__body" style="padding-top:0">
        <button type="button" class="btn btn-outline btn-sm" id="add-ar">+ Add Row</button>
      </div>
    </div>
  </div>

  
   <!-- SECTION 5: Services Rendered Out -->
  <div class="dr-section">
    <div class="dr-section__head">
      <h2>Services Rendered Out</h2>
      <span class="hint">Revenue in Naira</span>
    </div>
    <div class="dr-section__body dr-section__body--flush">
      <div style="overflow-x:auto">
        <table class="dr-table">
          <thead>
            <tr>
              <th>Service</th>
              <th>Staff Name</th>
              <th style="width:120px" class="num">Units Sold</th>
              <th style="width:170px" class="num">Revenue (N)</th>
              <th style="width:160px">Due Date</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="svc-rows">
            <?php if (!empty($c['services'])): foreach ($c['services'] as $row): ?>
              <tr>
                <td><input type="text"   name="svc_name[]"    list="dl-services" value="<?= e($row['service_name_snapshot']) ?>" maxlength="190"></td>
                <td><input type="text"   name="svc_staff[]"   list="dl-staff"    value="<?= e((string) ($row['staff_name_snapshot'] ?? '')) ?>" maxlength="150"></td>
                <td><input type="number" name="svc_units[]"   step="1"    min="0" value="<?= (int) $row['units_sold'] ?>"></td>
                <td><input type="number" name="svc_revenue[]" step="0.01" min="0" value="<?= e(number_format((float) $row['revenue'], 2, '.', '')) ?>"></td>
                <td><input type="date"   name="svc_due[]" value="<?= e((string) $row['due_date']) ?>"></td>
                <td class="actions"><button type="button" class="row-remove" title="Remove">&times;</button></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="dr-total">
        <span>Total Units:</span><b id="svc-units-total">0</b>
        <span style="margin-left:22px">Total Revenue:</span><b id="svc-revenue-total"><?= $currencySymbol ?>0.00</b>
      </div>
      <div class="dr-section__body" style="padding-top:0">
        <button type="button" class="btn btn-outline btn-sm" id="add-svc">+ Add Service</button>
      </div>
    </div>
  </div>

  
  <!-- SECTION 6: Expenses Breakdown -->
  <div class="dr-section">
    <div class="dr-section__head">
      <h2>Expenses Breakdown</h2>
      <span class="hint">Amount in Naira</span>
    </div>
    <div class="dr-section__body dr-section__body--flush">
      <div style="overflow-x:auto">
        <table class="dr-table">
          <thead>
            <tr>
              <th>Category</th>
              <th>Description</th>
              <th style="width:170px" class="num">Amount (N)</th>
              <th></th>
            </tr>
          </thead>
          <tbody id="exp-rows">
            <?php if (!empty($c['expenses'])): foreach ($c['expenses'] as $row): ?>
              <tr>
                <td><input type="text" name="exp_category[]" list="dl-expenses" value="<?= e($row['category_name_snapshot']) ?>" maxlength="150"></td>
                <td><input type="text" name="exp_desc[]" value="<?= e((string) $row['description']) ?>" maxlength="255"></td>
                <td><input type="number" step="0.01" min="0" name="exp_amount[]" value="<?= e(number_format((float) $row['amount'], 2, '.', '')) ?>"></td>
                <td class="actions"><button type="button" class="row-remove" title="Remove">&times;</button></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <div class="dr-total">
        <span>Total Expenses:</span><b id="exp-total"><?= $currencySymbol ?>0.00</b>
      </div>
      <div class="dr-section__body" style="padding-top:0">
        <button type="button" class="btn btn-outline btn-sm" id="add-exp">+ Add Expense</button>
      </div>
    </div>
  </div>

  <!-- SECTION 7: Financial Summary (calculated) -->
  <div class="dr-section">
    <div class="dr-section__head">
      <h2>Financial Summary</h2>
      <span class="hint">Calculated server-side — not editable</span>
    </div>
    <div class="dr-section__body">
      <div class="fin-summary">
        <div class="fin-summary__item">
          <span>Revenue</span><b id="fin-revenue"><?= $currencySymbol ?>0.00</b>
        </div>
        <div class="fin-summary__item">
          <span>Expenses</span><b id="fin-expenses"><?= $currencySymbol ?>0.00</b>
        </div>
        <div class="fin-summary__item fin-summary__item--net" style="grid-column:1/-1" id="fin-net-box">
          <span><strong>Net Income</strong> (Revenue &minus; Expenses)</span>
          <b id="fin-net"><?= $currencySymbol ?>0.00</b>
        </div>
      </div>
      <p class="form-hint" style="margin-top:10px">
        Revenue basis: <strong><?= e($revenueBasis) ?></strong>. This is configured in system settings.
      </p>
    </div>
  </div>

 

  <!-- SECTION 9: Problem / Issues -->
  <div class="dr-section">
    <div class="dr-section__head">
      <h2>Problem / Issues</h2>
      <span class="hint">Narrative</span>
    </div>
    <div class="dr-section__body">
      <?php
      $notes = $c['notes'] ?? null;
      ?>
      <div class="form-group">
        <label class="form-label" for="problems_identified">Problems / Issues Identified</label>
        <textarea class="form-control" id="problems_identified" name="problems_identified" rows="3"><?= e($notes['problems_identified'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label class="form-label" for="actions_taken">Actions Taken</label>
        <textarea class="form-control" id="actions_taken" name="actions_taken" rows="3"><?= e($notes['actions_taken'] ?? '') ?></textarea>
      </div>
      <div class="form-group">
        <label class="form-label" for="recommended_action">Recommended Action</label>
        <textarea class="form-control" id="recommended_action" name="recommended_action" rows="3"><?= e($notes['recommended_action'] ?? '') ?></textarea>
      </div>
    </div>
  </div>

  <!-- SECTION 10: Additional Notes -->
  <div class="dr-section">
    <div class="dr-section__head">
      <h2>Additional Notes</h2>
    </div>
    <div class="dr-section__body">
      <textarea class="form-control" name="additional_notes" rows="4"><?= e($notes['additional_notes'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="save-bar">
    <div>
      <button type="submit" class="btn btn-primary btn-lg" id="btn-submit">Submit Daily Report</button>
    </div>
    <div class="save-bar__actions">
      <button type="button" class="btn btn-outline" id="btn-save-draft">Save as Draft</button>
      <a class="btn btn-outline" href="./reports.php">Cancel</a>
    </div>
  </div>
</form>

<script>
window.DR_CONFIG = {
  currencySymbol: <?= json_encode($currencySymbol) ?>,
  revenueBasis:   <?= json_encode($revenueBasis) ?>
};
</script>

<?php
$admin_scripts = ['./assets/js/daily-report.js'];
require PICT_ROOT . '/includes/footer-admin.php';
?>

<script>
// Form actions: set action field and submit
(function(){
  var form = document.getElementById('daily-report-form');
  var actionField = document.getElementById('dr-action');
  var draftBtn = document.getElementById('btn-save-draft');
  if (form && actionField && draftBtn) {
    draftBtn.addEventListener('click', function(){
      actionField.value = 'save_draft';
      form.submit();
    });
  }
})();
</script>