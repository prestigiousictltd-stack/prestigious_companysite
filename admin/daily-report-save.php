<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/admin-auth.php';
$admin = admin_require_login();

if (!is_post()) {
    redirect('/admin/daily-report.php');
}
csrf_require();

// -------- Which action? --------
$action = (string) ($_POST['action'] ?? 'submit');
if (!in_array($action, ['save_draft', 'submit'], true)) {
    $action = 'submit';
}

$existingId = (int) ($_POST['report_id'] ?? 0);

// -------- Collect the date first so the fallback lookup below can use it --------
$reportDate       = trim((string) ($_POST['report_date'] ?? ''));
$executiveSummary = trim((string) ($_POST['executive_summary'] ?? ''));

// -------- Existing report by id (DRAFT / RETURNED only may be edited) --------
$existing = null;
if ($existingId > 0) {
    $stmt = db()->prepare("
        SELECT * FROM daily_reports
        WHERE id = :id AND created_by_admin_id = :aid
        LIMIT 1
    ");
    $stmt->execute([':id' => $existingId, ':aid' => (int) $admin['id']]);
    $existing = $stmt->fetch();
    if (!$existing || !in_array($existing['status'], ['DRAFT', 'RETURNED'], true)) {
        flash('error', 'This report cannot be edited.');
        redirect('/admin/reports.php');
    }
}

// -------- Fallback lookup: if no id was posted but a report already exists
//          for this admin on this date, reuse it and update in place.
//          This prevents the uq_dr_admin_date_rev duplicate-entry error. --------
if (!$existing && $reportDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    $stmt = db()->prepare("
        SELECT * FROM daily_reports
        WHERE created_by_admin_id = :aid
          AND report_date = :rd
          AND revision_number = 1
        LIMIT 1
    ");
    $stmt->execute([':aid' => (int) $admin['id'], ':rd' => $reportDate]);
    $found = $stmt->fetch();

    if ($found) {
        if (!in_array($found['status'], ['DRAFT', 'RETURNED'], true)) {
            flash(
                'error',
                'A report for ' . date('d M Y', strtotime($reportDate)) .
                ' already exists and is currently ' . strtolower(str_replace('_', ' ', $found['status'])) .
                '. Open it from Report History instead.'
            );
            redirect('/admin/report-view.php?id=' . (int) $found['id']);
        }
        $existing   = $found;
        $existingId = (int) $found['id'];
    }
}

// -------- Collect inputs --------
// Accounts Receivable
$arStaff     = (array) ($_POST['ar_staff'] ?? []);
$arService   = (array) ($_POST['ar_service'] ?? []);
$arQuantity  = (array) ($_POST['ar_quantity'] ?? []);
$arAmount    = (array) ($_POST['ar_amount'] ?? []);
$arDue       = (array) ($_POST['ar_due'] ?? []);

// Services Rendered Out (now includes staff)
$svcName     = (array) ($_POST['svc_name'] ?? []);
$svcStaff    = (array) ($_POST['svc_staff'] ?? []);
$svcUnits    = (array) ($_POST['svc_units'] ?? []);
$svcRevenue  = (array) ($_POST['svc_revenue'] ?? []);
$svcDue      = (array) ($_POST['svc_due'] ?? []);

// Expenses
$expCategory = (array) ($_POST['exp_category'] ?? []);
$expDesc     = (array) ($_POST['exp_desc'] ?? []);
$expAmount   = (array) ($_POST['exp_amount'] ?? []);

// Problems / Issues
$problemsIdentified = trim((string) ($_POST['problems_identified'] ?? ''));
$actionsTaken       = trim((string) ($_POST['actions_taken'] ?? ''));
$recommendedAction  = trim((string) ($_POST['recommended_action'] ?? ''));
$additionalNotes    = trim((string) ($_POST['additional_notes'] ?? ''));

// -------- Helpers --------
$toDec = function ($v): float {
    $s = is_string($v) ? trim($v) : (string) $v;
    if ($s === '') return 0.0;
    if (!is_numeric($s)) return 0.0;
    return round((float) $s, 2);
};
$toInt = function ($v): int {
    $s = is_string($v) ? trim($v) : (string) $v;
    if ($s === '' || !is_numeric($s)) return 0;
    $n = (int) $s;
    return $n < 0 ? 0 : $n;
};
$toDate = function ($v): ?string {
    $s = trim((string) $v);
    if ($s === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return ($d && $d->format('Y-m-d') === $s) ? $s : null;
};

// Staff lookup cache for the services section
$staffLookup = [];
$resolveStaffId = function (string $staffName) use (&$staffLookup): ?int {
    $staffName = trim($staffName);
    if ($staffName === '') return null;
    $key = mb_strtolower($staffName);
    if (!array_key_exists($key, $staffLookup)) {
        $stmt = db()->prepare("SELECT id FROM staff WHERE display_name = :n LIMIT 1");
        $stmt->execute([':n' => $staffName]);
        $id = $stmt->fetchColumn();
        $staffLookup[$key] = $id ? (int) $id : null;
    }
    return $staffLookup[$key];
};

// -------- Validate --------
$errors = [];
if ($action === 'submit') {
    $errors = validate(
        ['report_date' => $reportDate],
        ['report_date' => ['required', 'date']],
        ['report_date' => 'Report Date']
    );

    if (!$errors) {
        $rd = new DateTime($reportDate);
        $today = new DateTime('today');
        $diff = (int) $today->diff($rd)->format('%r%a');
        if ($diff < -30) {
            $errors['report_date'] = 'Report Date cannot be more than 30 days in the past.';
        } elseif ($diff > 1) {
            $errors['report_date'] = 'Report Date cannot be in the future.';
        }
    }

    if ($executiveSummary === '' && setting_bool('report_require_executive_summary', true)) {
        $errors['executive_summary'] = 'Executive Summary is required.';
    }
} else {
    if ($reportDate === '') {
        $errors['report_date'] = 'Report Date is required even for a draft.';
    }
}

if ($errors) {
    $_SESSION['_old'] = $_POST;
    $_SESSION['_old']['_global'] = reset($errors);
    flash('error', reset($errors));
    $url = $existingId > 0 ? '/admin/daily-report.php?edit=' . $existingId : '/admin/daily-report.php';
    redirect($url);
}

// -------- Build clean rows --------
$receivables = [];
$count = max(count($arStaff), count($arService), count($arAmount), count($arQuantity));
for ($i = 0; $i < $count; $i++) {
    $staff = trim((string) ($arStaff[$i] ?? ''));
    $svc   = trim((string) ($arService[$i] ?? ''));
    $qty   = $toDec($arQuantity[$i] ?? 1);
    $amt   = $toDec($arAmount[$i] ?? 0);
    $due   = $toDate($arDue[$i] ?? null);
    if ($qty < 0) { $qty = 0.0; }

    if ($staff === '' && $svc === '' && $amt <= 0 && $qty <= 1 && $due === null) {
        continue;
    }
    if ($amt < 0) {
        $_SESSION['_old'] = $_POST;
        flash('error', 'Accounts Receivable amounts cannot be negative.');
        redirect('/admin/daily-report.php' . ($existingId ? '?edit=' . $existingId : ''));
    }
    $receivables[] = [
        'staff_name' => $staff,
        'service'    => $svc,
        'quantity'   => $qty,
        'amount'     => $amt,
        'row_total'  => round($qty * $amt, 2),
        'due_date'   => $due,
    ];
}

$services = [];
$count = max(count($svcName), count($svcUnits), count($svcRevenue), count($svcStaff));
for ($i = 0; $i < $count; $i++) {
    $name   = trim((string) ($svcName[$i] ?? ''));
    $staff  = trim((string) ($svcStaff[$i] ?? ''));
    $units  = $toInt($svcUnits[$i] ?? 0);
    $rev    = $toDec($svcRevenue[$i] ?? 0);
    $due    = $toDate($svcDue[$i] ?? null);

    if ($name === '' && $staff === '' && $units === 0 && $rev == 0.0 && $due === null) {
        continue;
    }
    if ($rev < 0) {
        flash('error', 'Service revenue cannot be negative.');
        redirect('/admin/daily-report.php' . ($existingId ? '?edit=' . $existingId : ''));
    }
    $services[] = [
        'name'       => $name,
        'staff_name' => $staff,
        'staff_id'   => $resolveStaffId($staff),
        'units'      => $units,
        'revenue'    => $rev,
        'due_date'   => $due,
    ];
}

$expenses = [];
$count = max(count($expCategory), count($expAmount));
for ($i = 0; $i < $count; $i++) {
    $cat = trim((string) ($expCategory[$i] ?? ''));
    $desc = trim((string) ($expDesc[$i] ?? ''));
    $amt = $toDec($expAmount[$i] ?? 0);
    if ($cat === '' && $desc === '' && $amt == 0.0) {
        continue;
    }
    if ($amt < 0) {
        flash('error', 'Expense amounts cannot be negative.');
        redirect('/admin/daily-report.php' . ($existingId ? '?edit=' . $existingId : ''));
    }
    $expenses[] = ['category' => $cat, 'description' => $desc, 'amount' => $amt];
}

// -------- Server-side recalculation (authoritative) --------
$receivableTotal = 0.0;
foreach ($receivables as $r) { $receivableTotal += $r['row_total']; }
$receivableTotal = round($receivableTotal, 2);

$serviceUnitsTotal = 0;
$serviceRevenueTotal = 0.0;
foreach ($services as $s) {
    $serviceUnitsTotal += $s['units'];
    $serviceRevenueTotal += $s['revenue'];
}
$serviceRevenueTotal = round($serviceRevenueTotal, 2);

$expenseTotal = 0.0;
foreach ($expenses as $e) { $expenseTotal += $e['amount']; }
$expenseTotal = round($expenseTotal, 2);

// Weekly revenue and performance sections were removed.
// These parent columns still exist; they now hold zero/zero.
$weeklyRevenueTotal    = 0.0;
$performanceGrandTotal  = 0.0;

// Revenue basis resolution.
// WEEKLY_SALES and MANUAL are no longer meaningful; both fall back to Services.
$revenueBasis = (string) setting('report_revenue_basis', 'SERVICES');
if (!in_array($revenueBasis, ['SERVICES', 'WEEKLY_SALES', 'MANUAL', 'SERVICES_PLUS_RECEIVABLE'], true)) {
    $revenueBasis = 'SERVICES';
}
$revenueTotal = match ($revenueBasis) {
    'SERVICES_PLUS_RECEIVABLE' => round($serviceRevenueTotal + $receivableTotal, 2),
    default                    => $serviceRevenueTotal,
};
$netIncome = round($revenueTotal - $expenseTotal, 2);

// Currency for AR section
$arCurrency = (string) setting('report_ar_default_currency', 'NGN');
if (!preg_match('/^[A-Z]{3}$/', $arCurrency)) { $arCurrency = 'NGN'; }

// Performance unit is no longer used; keep whatever was set for the column
$performanceUnit = (string) setting('report_performance_unit', '');

// -------- Persist inside a transaction --------
try {
    db()->beginTransaction();

    if ($existing) {
        // UPDATE existing report row
        $reportId = (int) $existing['id'];

        $stmt = db()->prepare('
            UPDATE daily_reports SET
                report_date = :rd,
                executive_summary = :es,
                receivable_total = :rt,
                receivable_currency = :rc,
                service_units_total = :sut,
                service_revenue_total = :srt,
                expense_total = :et,
                weekly_revenue_total = :wrt,
                performance_grand_total = :pgt,
                revenue_basis = :rb,
                revenue_total = :rv,
                net_income = :ni,
                performance_unit = :pu,
                updated_at = :ua
            WHERE id = :id
        ');
        $stmt->execute([
            ':rd'  => $reportDate,
            ':es'  => $executiveSummary !== '' ? $executiveSummary : null,
            ':rt'  => $receivableTotal,
            ':rc'  => $arCurrency,
            ':sut' => $serviceUnitsTotal,
            ':srt' => $serviceRevenueTotal,
            ':et'  => $expenseTotal,
            ':wrt' => $weeklyRevenueTotal,
            ':pgt' => $performanceGrandTotal,
            ':rb'  => $revenueBasis,
            ':rv'  => $revenueTotal,
            ':ni'  => $netIncome,
            ':pu'  => $performanceUnit !== '' ? $performanceUnit : null,
            ':ua'  => now_dt(),
            ':id'  => $reportId,
        ]);

        // Delete child rows (only the tables that are still in use)
        foreach (['daily_report_receivables','daily_report_services','daily_report_expenses','daily_report_notes'] as $t) {
            $s = db()->prepare("DELETE FROM `$t` WHERE report_id = :id");
            $s->execute([':id' => $reportId]);
        }
        $isNew = false;
    } else {
        // INSERT new report
        $reference = '';
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $reference = 'PICT-DR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $chk = db()->prepare('SELECT 1 FROM daily_reports WHERE report_reference = :r LIMIT 1');
            $chk->execute([':r' => $reference]);
            if (!$chk->fetchColumn()) break;
        }

        $stmt = db()->prepare('
            INSERT INTO daily_reports
              (report_reference, report_date, created_by_admin_id, created_by_name_snapshot,
               executive_summary, receivable_total, receivable_currency,
               service_units_total, service_revenue_total, expense_total,
               weekly_revenue_total, performance_grand_total,
               revenue_basis, revenue_total, net_income, performance_unit,
               status, revision_number, created_at)
            VALUES
              (:ref, :rd, :aid, :aname,
               :es, :rt, :rc,
               :sut, :srt, :et,
               :wrt, :pgt,
               :rb, :rv, :ni, :pu,
               :st, 1, :ca)
        ');
        $stmt->execute([
            ':ref'   => $reference,
            ':rd'    => $reportDate,
            ':aid'   => (int) $admin['id'],
            ':aname' => (string) $admin['full_name'],
            ':es'    => $executiveSummary !== '' ? $executiveSummary : null,
            ':rt'    => $receivableTotal,
            ':rc'    => $arCurrency,
            ':sut'   => $serviceUnitsTotal,
            ':srt'   => $serviceRevenueTotal,
            ':et'    => $expenseTotal,
            ':wrt'   => $weeklyRevenueTotal,
            ':pgt'   => $performanceGrandTotal,
            ':rb'    => $revenueBasis,
            ':rv'    => $revenueTotal,
            ':ni'    => $netIncome,
            ':pu'    => $performanceUnit !== '' ? $performanceUnit : null,
            ':st'    => 'DRAFT',
            ':ca'    => now_dt(),
        ]);
        $reportId = (int) db()->lastInsertId();
        $isNew = true;
    }

    // Insert receivables
    if (!empty($receivables)) {
        $s = db()->prepare('
            INSERT INTO daily_report_receivables
              (report_id, row_order, staff_name_snapshot, service_rendered,
               unit_label, unit_quantity, amount, currency_code, due_date, created_at)
            VALUES (:rid, :ro, :staff, :svc, :ul, :uq, :amt, :cur, :due, :ca)
        ');
        foreach ($receivables as $i => $row) {
            $s->execute([
                ':rid'   => $reportId,
                ':ro'    => $i + 1,
                ':staff' => $row['staff_name'],
                ':svc'   => $row['service'],
                ':ul'    => null,
                ':uq'    => $row['quantity'],
                ':amt'   => $row['amount'],
                ':cur'   => $arCurrency,
                ':due'   => $row['due_date'],
                ':ca'    => now_dt(),
            ]);
        }
    }

    // Insert services (with staff)
    if (!empty($services)) {
        $s = db()->prepare('
            INSERT INTO daily_report_services
              (report_id, row_order, service_name_snapshot,
               staff_id, staff_name_snapshot,
               units_sold, revenue, due_date, created_at)
            VALUES (:rid, :ro, :name, :staff_id, :staff_name, :units, :rev, :due, :ca)
        ');
        foreach ($services as $i => $row) {
            $s->execute([
                ':rid'        => $reportId,
                ':ro'         => $i + 1,
                ':name'       => $row['name'],
                ':staff_id'   => $row['staff_id'],
                ':staff_name' => $row['staff_name'] !== '' ? $row['staff_name'] : null,
                ':units'      => $row['units'],
                ':rev'        => $row['revenue'],
                ':due'        => $row['due_date'],
                ':ca'         => now_dt(),
            ]);
        }
    }

    // Insert expenses
    if (!empty($expenses)) {
        $s = db()->prepare('
            INSERT INTO daily_report_expenses
              (report_id, row_order, category_name_snapshot, description, amount, created_at)
            VALUES (:rid, :ro, :cat, :desc, :amt, :ca)
        ');
        foreach ($expenses as $i => $row) {
            $s->execute([
                ':rid'  => $reportId,
                ':ro'   => $i + 1,
                ':cat'  => $row['category'],
                ':desc' => $row['description'],
                ':amt'  => $row['amount'],
                ':ca'   => now_dt(),
            ]);
        }
    }

    // Insert notes (only if any narrative present)
    if ($problemsIdentified !== '' || $actionsTaken !== '' || $recommendedAction !== '' || $additionalNotes !== '') {
        $s = db()->prepare('
            INSERT INTO daily_report_notes
              (report_id, problems_identified, actions_taken, recommended_action, additional_notes, created_at)
            VALUES (:rid, :pi, :at, :ra, :an, :ca)
        ');
        $s->execute([
            ':rid' => $reportId,
            ':pi'  => $problemsIdentified !== '' ? $problemsIdentified : null,
            ':at'  => $actionsTaken !== '' ? $actionsTaken : null,
            ':ra'  => $recommendedAction !== '' ? $recommendedAction : null,
            ':an'  => $additionalNotes !== '' ? $additionalNotes : null,
            ':ca'  => now_dt(),
        ]);
    }

    // Status transition
    $newStatus   = ($action === 'submit') ? 'SUBMITTED' : 'DRAFT';
    $submittedAt = ($action === 'submit') ? now_dt() : null;

    if ($action === 'submit') {
        $stmt = db()->prepare('
            UPDATE daily_reports
            SET status = :status,
                submitted_at = :submitted_at,
                updated_at = :updated_at
            WHERE id = :id
        ');
        $stmt->execute([
            ':status'       => $newStatus,
            ':submitted_at' => $submittedAt,
            ':updated_at'   => now_dt(),
            ':id'           => $reportId,
        ]);
    } else {
        $stmt = db()->prepare('
            UPDATE daily_reports
            SET status = :status, updated_at = :updated_at
            WHERE id = :id
        ');
        $stmt->execute([
            ':status'     => $newStatus,
            ':updated_at' => now_dt(),
            ':id'         => $reportId,
        ]);
    }

    db()->commit();
} catch (Throwable $ex) {
    if (db()->inTransaction()) {
        db()->rollBack();
    }
    error_log('[daily-report-save] ' . $ex->getMessage());
    flash('error', 'Could not save the report: ' . $ex->getMessage());
    redirect('/admin/daily-report.php' . ($existingId ? '?edit=' . $existingId : ''));
}

// Post-commit side effects
old_clear();

audit_log(
    $action === 'submit' ? 'DAILY_REPORT_SUBMIT' : 'DAILY_REPORT_SAVE_DRAFT',
    'daily_reports',
    $reportId,
    'Report ' . ($isNew ? 'created' : 'updated') . ': ' . $reportDate
);

if ($action === 'submit') {
    try {
        notify_all_superadmins(
            'DAILY_REPORT_SUBMITTED',
            'Daily Report submitted',
            (string) $admin['full_name'] . ' submitted a daily report for ' . date('d M Y', strtotime($reportDate)) . '.',
            '/superadmin/daily-reports.php?view=' . $reportId,
            'daily_reports',
            $reportId
        );
    } catch (Throwable $e) {
        error_log('[notify after submit] ' . $e->getMessage());
    }
    flash('success', 'Daily Report submitted successfully.');
} else {
    flash('success', 'Draft saved.');
}

redirect('/admin/report-view.php?id=' . $reportId);