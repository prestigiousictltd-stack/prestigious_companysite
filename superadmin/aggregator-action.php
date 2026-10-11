<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/aggregator.php';
$sa = superadmin_require_login();

if (!is_post()) { redirect(root_url('/superadmin/aggregators.php')); }
csrf_require();

$id     = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$reason = trim((string) ($_POST['reason'] ?? ''));

$stmt = db()->prepare("SELECT * FROM aggregators WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$agg = $stmt->fetch();
if (!$agg) {
    flash('error', 'Aggregator not found.');
    redirect(root_url('/superadmin/aggregators.php'));
}

$now = now_dt();
$redirectToList = false;

try {
    db()->beginTransaction();

    switch ($action) {

        // -------------------------------------------------------------
        case 'deactivate':
            if ($agg['status'] !== 'ACTIVE') {
                throw new RuntimeException('Aggregator is not active.');
            }

            // Lock the aggregator row so no concurrent ledger write occurs
            $lock = db()->prepare("SELECT id FROM aggregators WHERE id = :id FOR UPDATE");
            $lock->execute([':id' => $id]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Aggregator row missing.');
            }

            // Recompute the balance inside the lock
            $balanceNow = aggregator_available_balance($id);
            $reclaimed  = 0.0;

            // Write a reversal that zeroes the outstanding balance, so the
            // amount flows back to the organisation's Net Revenue.
            if ($balanceNow > 0.001) {
                aggregator_ledger_record(
                    $id,
                    'REVERSAL',
                    -$balanceNow,
                    'DEACTIVATE-' . date('YmdHis'),
                    'Deactivation clawback: outstanding commission of '
                        . format_money($balanceNow) . ' returned to the organisation'
                        . ($reason !== '' ? '. Reason: ' . $reason : ''),
                    [],
                    [],
                    'SUPER_ADMIN',
                    (int) $sa['id']
                );
                $reclaimed = $balanceNow;
            }

            db()->prepare("
                UPDATE aggregators SET
                    status     = 'INACTIVE',
                    notes      = COALESCE(:reason, notes),
                    updated_at = :updated_at
                WHERE id = :id
            ")->execute([
                ':reason'     => $reason !== '' ? $reason : null,
                ':updated_at' => $now,
                ':id'         => $id,
            ]);

            audit_log('AGGREGATOR_DEACTIVATED', 'aggregators', $id,
                'Deactivated' . ($reclaimed > 0 ? ' with clawback of ' . format_money($reclaimed) : '')
                . ($reason !== '' ? '; reason: ' . $reason : ''));

            if ($reclaimed > 0) {
                flash('success', 'Aggregator deactivated. '
                    . format_money($reclaimed) . ' reclaimed to organisation revenue.');
            } else {
                flash('success', 'Aggregator deactivated. No outstanding balance to reclaim.');
            }
            break;

        // -------------------------------------------------------------
        case 'reactivate':
            if ($agg['status'] !== 'INACTIVE') {
                throw new RuntimeException('Aggregator is not inactive.');
            }
            db()->prepare("
                UPDATE aggregators SET
                    status     = 'ACTIVE',
                    notes      = COALESCE(:reason, notes),
                    updated_at = :updated_at
                WHERE id = :id
            ")->execute([
                ':reason'     => $reason !== '' ? $reason : null,
                ':updated_at' => $now,
                ':id'         => $id,
            ]);
            audit_log('AGGREGATOR_REACTIVATED', 'aggregators', $id, $reason);
            flash('success', 'Aggregator reactivated. It will resume earning on future imports.');
            break;

        // -------------------------------------------------------------
        case 'adjust':
            $amount = trim((string) ($_POST['amount'] ?? ''));
            $desc   = trim((string) ($_POST['description'] ?? ''));

            if (!is_numeric($amount) || (float) $amount == 0.0) {
                throw new RuntimeException('Adjustment amount must be a non-zero number.');
            }
            if ($desc === '') {
                throw new RuntimeException('A description is required for an adjustment.');
            }

            $amountFloat = round((float) $amount, 2);

            $lock = db()->prepare("SELECT id FROM aggregators WHERE id = :aid FOR UPDATE");
            $lock->execute([':aid' => $id]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Aggregator row missing.');
            }

            aggregator_ledger_record(
                $id,
                'ADJUSTMENT',
                $amountFloat,
                'MANUAL',
                $desc,
                [],
                [],
                'SUPER_ADMIN',
                (int) $sa['id']
            );

            audit_log('AGGREGATOR_ADJUSTMENT', 'aggregators', $id, format_money($amountFloat) . ' — ' . $desc);
            flash('success', 'Adjustment recorded: ' . format_money($amountFloat));
            break;

        // -------------------------------------------------------------
        case 'delete':
            // Only allow deletion of aggregators that have never been used.
            if ($agg['status'] !== 'INACTIVE') {
                throw new RuntimeException('Deactivate the aggregator first, then delete.');
            }

            $txCount = (int) db()->query(
                "SELECT COUNT(*) FROM aggregator_financial_transactions WHERE aggregator_id = " . $id
            )->fetchColumn();
            if ($txCount > 0) {
                throw new RuntimeException(
                    'Cannot delete: the aggregator has ' . $txCount
                    . ' ledger entries. Keep it inactive instead.'
                );
            }

            $settlementCount = (int) db()->query(
                "SELECT COUNT(*) FROM aggregator_enrolment_records WHERE aggregator_id = " . $id
            )->fetchColumn();
            if ($settlementCount > 0) {
                throw new RuntimeException(
                    'Cannot delete: the aggregator has ' . $settlementCount
                    . ' settlement records. Keep it inactive instead.'
                );
            }

            $assignCount = (int) db()->query(
                "SELECT COUNT(*) FROM partner_aggregator_assignments WHERE aggregator_id = " . $id
            )->fetchColumn();
            if ($assignCount > 0) {
                throw new RuntimeException(
                    'Cannot delete: the aggregator has ' . $assignCount
                    . ' linked NIN(s). Unlink them first.'
                );
            }

            db()->prepare("DELETE FROM aggregators WHERE id = :id")->execute([':id' => $id]);

            audit_log('AGGREGATOR_DELETED', 'aggregators', $id,
                'Deleted aggregator ' . $agg['full_name']
                . ($reason !== '' ? '; reason: ' . $reason : ''));

            flash('success', 'Aggregator deleted.');
            $redirectToList = true;
            break;

        // -------------------------------------------------------------
        default:
            throw new RuntimeException('Unknown action.');
    }

    db()->commit();
} catch (RuntimeException $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash('error', $e->getMessage());
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('[aggregator-action] ' . $e->getMessage());
    flash('error', 'Action failed. Please try again.');
}

if ($redirectToList) {
    redirect(root_url('/superadmin/aggregators.php'));
}
redirect(root_url('/superadmin/aggregator-view.php') . '?id=' . $id);