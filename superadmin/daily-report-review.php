<?php
/**
 * Super Admin Daily Report review handler.
 * Receives POST with: report_id, decision (approve|return|archive), comment
 */

require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

if (!is_post()) { redirect('/superadmin/daily-reports.php'); }
csrf_require();

$reportId = (int) ($_POST['report_id'] ?? 0);
$decision = (string) ($_POST['decision'] ?? '');
$comment  = trim((string) ($_POST['comment'] ?? ''));

$stmt = db()->prepare("SELECT * FROM daily_reports WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $reportId]);
$r = $stmt->fetch();
if (!$r) {
    flash('error', 'Report not found.');
    redirect('/superadmin/daily-reports.php');
}

$allowed = match ($decision) {
    'approve' => in_array($r['status'], ['SUBMITTED', 'UNDER_REVIEW'], true),
    'return'  => in_array($r['status'], ['SUBMITTED', 'UNDER_REVIEW'], true),
    'archive' => in_array($r['status'], ['APPROVED', 'RETURNED', 'SUBMITTED'], true),
    default   => false,
};

if (!$allowed) {
    flash('error', 'This action is not permitted in the current state.');
    redirect('/superadmin/daily-reports.php?view=' . $reportId);
}

$newStatus = match ($decision) {
    'approve' => 'APPROVED',
    'return'  => 'RETURNED',
    'archive' => 'ARCHIVED',
};

if ($decision === 'return' && $comment === '') {
    flash('error', 'A comment is required when returning a report.');
    redirect('/superadmin/daily-reports.php?view=' . $reportId);
}

// NOTE: with PDO::ATTR_EMULATE_PREPARES = false, every named placeholder
// must be UNIQUE in the statement. Do not reuse :ts (or any name) twice.
$stmt = db()->prepare("
    UPDATE daily_reports SET
      status                  = :status,
      reviewed_by_super_admin_id = :reviewer,
      reviewed_at             = :reviewed_at,
      review_comment          = :comment,
      updated_at              = :updated_at
    WHERE id = :id
");
$stmt->execute([
    ':status'      => $newStatus,
    ':reviewer'    => (int) $sa['id'],
    ':reviewed_at' => now_dt(),
    ':comment'     => $comment !== '' ? $comment : null,
    ':updated_at'  => now_dt(),
    ':id'          => $reportId,
]);

audit_log('DAILY_REPORT_REVIEW', 'daily_reports', $reportId, 'Decision: ' . $decision);

// Notify the author
if (!empty($r['created_by_admin_id'])) {
    $title = match ($decision) {
        'approve' => 'Daily Report approved',
        'return'  => 'Daily Report returned for correction',
        'archive' => 'Daily Report archived',
        default   => 'Daily Report updated',
    };
    notify(
        'ADMIN',
        (int) $r['created_by_admin_id'],
        'DAILY_REPORT_' . strtoupper($decision),
        $title,
        $comment !== '' ? $comment : 'Report ' . $r['report_reference'] . ' was ' . strtolower($decision) . 'd.',
        '/admin/report-view.php?id=' . $reportId,
        'daily_reports',
        $reportId
    );
}

flash('success', 'Report ' . strtolower($decision) . 'd.');
redirect('/superadmin/daily-reports.php?view=' . $reportId);