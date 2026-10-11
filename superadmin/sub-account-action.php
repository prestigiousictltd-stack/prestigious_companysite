<?php
/**
 * POST handler for sub-account actions.
 * Actions: approve, reject, deactivate, reactivate, edit,
 *          add_rate, supersede_rate, run_backfill
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$sa = superadmin_require_login();

if (!is_post()) {
    redirect(root_url('/superadmin/sub-accounts.php'));
}
csrf_require();

$id     = (int) ($_POST['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');

if ($id <= 0) {
    flash('error', 'Sub-account ID is required.');
    redirect(root_url('/superadmin/sub-accounts.php'));
}

$sub = sub_account_find($id);
if (!$sub) {
    flash('error', 'Sub-account not found.');
    redirect(root_url('/superadmin/sub-accounts.php'));
}

$now = now_dt();

try {
    switch ($action) {

        // ---------------------------------------------------------------
        case 'approve':
            if ($sub['status'] !== 'PENDING') {
                throw new RuntimeException('Only pending sub-accounts can be approved.');
            }

            $pct = trim((string) ($_POST['commission_percentage'] ?? ''));
            $effFrom = trim((string) ($_POST['effective_from'] ?? today_date()));
            $runBackfill = ((string) ($_POST['run_backfill'] ?? '1')) === '1';

            if (!is_numeric($pct) || (float) $pct < 0 || (float) $pct > 100) {
                throw new RuntimeException('Commission percentage must be between 0 and 100.');
            }
            $d = DateTime::createFromFormat('Y-m-d', $effFrom);
            if (!$d || $d->format('Y-m-d') !== $effFrom) {
                throw new RuntimeException('Effective From must be a valid date.');
            }

            // Cross-table uniqueness check once more, inside the lock
            db()->beginTransaction();

            $lock = db()->prepare("SELECT id FROM partners WHERE id = :pid FOR UPDATE");
            $lock->execute([':pid' => (int) $sub['partner_id']]);
            if (!$lock->fetchColumn()) {
                throw new RuntimeException('Parent partner row missing.');
            }

            $chk = sub_account_nin_is_taken((string) $sub['nin_number'], null, $id);
            if (!empty($chk['taken'])) {
                throw new RuntimeException($chk['reason']);
            }

            $result = sub_account_approve($id, (float) $pct, $effFrom, (int) $sa['id'], $runBackfill);

            if (empty($result['ok'])) {
                throw new RuntimeException($result['reason'] ?? 'Approval failed.');
            }

            db()->commit();

            audit_log(
                'SUB_ACCOUNT_APPROVED',
                'partner_sub_accounts',
                $id,
                'Approved at ' . $pct . '% effective ' . $effFrom
                    . ($runBackfill ? ' with backfill (' . $result['backfilled'] . ' records)' : '')
            );

            notify(
                'PARTNER',
                (int) $sub['partner_id'],
                'SUB_ACCOUNT_APPROVED',
                'Sub-account approved',
                $sub['full_name'] . ' has been approved and will earn commission from its NIN.',
                '/partner/sub-account-view.php?id=' . $id,
                'partner_sub_accounts',
                $id
            );

            flash('success', 'Sub-account approved.'
                . ($runBackfill ? ' ' . (int) $result['backfilled'] . ' historical record(s) attached.' : ''));
            break;

        // ---------------------------------------------------------------
        case 'reject':
            if ($sub['status'] !== 'PENDING') {
                throw new RuntimeException('Only pending sub-accounts can be rejected.');
            }
            $reason = trim((string) ($_POST['reason'] ?? ''));

            db()->prepare("
                UPDATE partner_sub_accounts SET
                    status                = 'REJECTED',
                    status_reason         = :reason,
                    status_changed_by_id  = :by,
                    status_changed_at     = :at,
                    updated_at            = :ua
                WHERE id = :id
            ")->execute([
                ':reason' => $reason !== '' ? $reason : null,
                ':by'     => (int) $sa['id'],
                ':at'     => $now,
                ':ua'     => $now,
                ':id'     => $id,
            ]);

            audit_log('SUB_ACCOUNT_REJECTED', 'partner_sub_accounts', $id, $reason);
            notify(
                'PARTNER',
                (int) $sub['partner_id'],
                'SUB_ACCOUNT_REJECTED',
                'Sub-account rejected',
                $sub['full_name'] . ' was not approved.' . ($reason !== '' ? ' Reason: ' . $reason : ''),
                '/partner/sub-account-view.php?id=' . $id,
                'partner_sub_accounts',
                $id
            );
            flash('success', 'Sub-account rejected.');
            break;

        // ---------------------------------------------------------------
        case 'deactivate':
            if ($sub['status'] !== 'ACTIVE') {
                throw new RuntimeException('Only active sub-accounts can be deactivated.');
            }
            $reason = trim((string) ($_POST['reason'] ?? ''));

            db()->prepare("
                UPDATE partner_sub_accounts SET
                    status                = 'INACTIVE',
                    status_reason         = :reason,
                    status_changed_by_id  = :by,
                    status_changed_at     = :at,
                    updated_at            = :ua
                WHERE id = :id
            ")->execute([
                ':reason' => $reason !== '' ? $reason : null,
                ':by'     => (int) $sa['id'],
                ':at'     => $now,
                ':ua'     => $now,
                ':id'     => $id,
            ]);

            audit_log('SUB_ACCOUNT_DEACTIVATED', 'partner_sub_accounts', $id, $reason);
            notify(
                'PARTNER',
                (int) $sub['partner_id'],
                'SUB_ACCOUNT_DEACTIVATED',
                'Sub-account deactivated',
                $sub['full_name'] . ' will no longer earn commission on future imports.',
                '/partner/sub-account-view.php?id=' . $id,
                'partner_sub_accounts',
                $id
            );
            flash('success', 'Sub-account deactivated.');
            break;

        // ---------------------------------------------------------------
        case 'reactivate':
            if ($sub['status'] !== 'INACTIVE') {
                throw new RuntimeException('Only inactive sub-accounts can be reactivated.');
            }
            $reason = trim((string) ($_POST['reason'] ?? ''));

            // Re-check that its NIN has not been taken while inactive
            $chk = sub_account_nin_is_taken((string) $sub['nin_number'], null, $id);
            if (!empty($chk['taken'])) {
                throw new RuntimeException('Cannot reactivate: ' . $chk['reason']);
            }

            db()->prepare("
                UPDATE partner_sub_accounts SET
                    status                = 'ACTIVE',
                    status_reason         = :reason,
                    status_changed_by_id  = :by,
                    status_changed_at     = :at,
                    updated_at            = :ua
                WHERE id = :id
            ")->execute([
                ':reason' => $reason !== '' ? $reason : null,
                ':by'     => (int) $sa['id'],
                ':at'     => $now,
                ':ua'     => $now,
                ':id'     => $id,
            ]);

            audit_log('SUB_ACCOUNT_REACTIVATED', 'partner_sub_accounts', $id, $reason);
            notify(
                'PARTNER',
                (int) $sub['partner_id'],
                'SUB_ACCOUNT_REACTIVATED',
                'Sub-account reactivated',
                $sub['full_name'] . ' is active again.',
                '/partner/sub-account-view.php?id=' . $id,
                'partner_sub_accounts',
                $id
            );
            flash('success', 'Sub-account reactivated.');
            break;

        // ---------------------------------------------------------------
        case 'edit':
            $name  = trim((string) ($_POST['full_name'] ?? ''));
            $email = strtolower(trim((string) ($_POST['email'] ?? '')));

            $errs = validate(
                ['full_name' => $name, 'email' => $email],
                [
                    'full_name' => ['required', 'min_len:2', 'max_len:150'],
                    'email'     => ['email', 'max_len:190'],
                ],
                ['full_name' => 'Full Name']
            );
            if (!empty($errs)) {
                flash('error', reset($errs));
                break;
            }

            db()->prepare("
                UPDATE partner_sub_accounts SET
                    full_name   = :name,
                    email       = :email,
                    updated_at  = :ua
                WHERE id = :id
            ")->execute([
                ':name'  => $name,
                ':email' => $email !== '' ? $email : null,
                ':ua'    => $now,
                ':id'    => $id,
            ]);

            audit_log('SUB_ACCOUNT_EDITED', 'partner_sub_accounts', $id, 'Details edited');
            flash('success', 'Sub-account updated.');
            break;

        // ---------------------------------------------------------------
        case 'add_rate':
            if (!in_array($sub['status'], ['ACTIVE','INACTIVE'], true)) {
                throw new RuntimeException('Rate can only be added while the sub-account is active or inactive.');
            }
            $pct     = trim((string) ($_POST['commission_percentage'] ?? ''));
            $effFrom = trim((string) ($_POST['effective_from'] ?? ''));
            $effTo   = trim((string) ($_POST['effective_to'] ?? ''));
            $rnotes  = trim((string) ($_POST['rate_notes'] ?? ''));

            $errs = validate(
                ['commission_percentage' => $pct, 'effective_from' => $effFrom, 'effective_to' => $effTo],
                [
                    'commission_percentage' => ['required', 'decimal', 'between:0,100'],
                    'effective_from'        => ['required', 'date'],
                    'effective_to'          => ['date'],
                ],
                ['commission_percentage' => 'Commission Percentage', 'effective_from' => 'Effective From']
            );
            if (!empty($errs)) {
                flash('error', reset($errs));
                break;
            }
            if ($effTo !== '' && strtotime($effTo) < strtotime($effFrom)) {
                flash('error', 'Effective To cannot be before Effective From.');
                break;
            }
            if (sub_account_would_overlap($id, $effFrom, $effTo !== '' ? $effTo : null)) {
                flash('error', 'The date range overlaps an existing active rate.');
                break;
            }

            db()->beginTransaction();

            // Close any open-ended rate that starts before the new one
            db()->prepare("
                UPDATE sub_account_commission_rates
                SET effective_to = DATE_SUB(:nf_a, INTERVAL 1 DAY),
                    status       = 'SUPERSEDED',
                    updated_at   = :ua
                WHERE sub_account_id = :sid
                  AND status = 'ACTIVE'
                  AND effective_to IS NULL
                  AND effective_from < :nf_b
            ")->execute([
                ':nf_a' => $effFrom,
                ':ua'   => $now,
                ':sid'  => $id,
                ':nf_b' => $effFrom,
            ]);

            db()->prepare("
                INSERT INTO sub_account_commission_rates
                  (sub_account_id, commission_percentage, effective_from, effective_to,
                   status, notes, created_by_super_admin_id, created_at)
                VALUES
                  (:sid, :pct, :ef, :et, 'ACTIVE', :notes, :by, :ca)
            ")->execute([
                ':sid'   => $id,
                ':pct'   => (float) $pct,
                ':ef'    => $effFrom,
                ':et'    => $effTo !== '' ? $effTo : null,
                ':notes' => $rnotes !== '' ? $rnotes : null,
                ':by'    => (int) $sa['id'],
                ':ca'    => $now,
            ]);

            db()->commit();

            audit_log('SUB_ACCOUNT_RATE_ADDED', 'partner_sub_accounts', $id, 'Rate ' . $pct . '% from ' . $effFrom);
            flash('success', 'New rate added.');
            break;

        // ---------------------------------------------------------------
        case 'supersede_rate':
            $rid = (int) ($_POST['rate_id'] ?? 0);
            db()->prepare("
                UPDATE sub_account_commission_rates
                SET status = 'SUPERSEDED',
                    effective_to = COALESCE(effective_to, CURDATE()),
                    updated_at = :ua
                WHERE id = :rid AND sub_account_id = :sid
            ")->execute([
                ':ua'  => $now,
                ':rid' => $rid,
                ':sid' => $id,
            ]);
            audit_log('SUB_ACCOUNT_RATE_SUPERSEDED', 'partner_sub_accounts', $id, 'Rate #' . $rid);
            flash('success', 'Rate superseded.');
            break;

        // ---------------------------------------------------------------
        case 'run_backfill':
            if ($sub['status'] !== 'ACTIVE') {
                throw new RuntimeException('Backfill is only available for active sub-accounts.');
            }

            $stmt = db()->prepare("
                SELECT id FROM partner_enrolment_records
                WHERE partner_nin_raw = :n
                  AND (sub_account_id IS NULL OR sub_account_id <> :sid)
                  AND commission_amount >= 0
                ORDER BY period_year ASC, period_month ASC, id ASC
            ");
            $stmt->execute([':n' => $sub['nin_number'], ':sid' => $id]);
            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                flash('info', 'Nothing to backfill — no matching unattached records.');
                break;
            }

            $attached = 0;
            $total = 0.0;

            db()->beginTransaction();
            foreach ($rows as $r) {
                $res = sub_account_attach_enrolment((int) $r['id'], $id, (int) $sa['id']);
                if (!empty($res['ok'])) {
                    $attached++;
                    if (!empty($res['credited'])) {
                        $total += (float) $res['amount'];
                    }
                }
            }
            db()->commit();

            audit_log('SUB_ACCOUNT_BACKFILL', 'partner_sub_accounts', $id,
                'Attached ' . $attached . ' records totalling ' . format_money($total));

            flash('success', 'Backfilled ' . $attached . ' record(s) totalling ' . format_money($total) . '.');
            break;

    
        // ---------------------------------------------------------------
        case 'reassign':
            if (!in_array($sub['status'], ['ACTIVE','INACTIVE'], true)) {
                throw new RuntimeException('Only ACTIVE or INACTIVE sub-accounts can be reassigned.');
            }

            $newPartnerId = (int) ($_POST['new_partner_id'] ?? 0);
            if ($newPartnerId <= 0) {
                throw new RuntimeException('Please choose a destination partner.');
            }

            $reason = trim((string) ($_POST['reason'] ?? ''));

            $result = sub_account_reassign($id, $newPartnerId, (int) $sa['id']);
            if (empty($result['ok'])) {
                throw new RuntimeException($result['reason'] ?? 'Reassignment failed.');
            }

            // Notify both partners
            notify(
                'PARTNER',
                (int) $result['old_partner_id'],
                'SUB_ACCOUNT_REASSIGNED_OUT',
                'Sub-account moved to another partner',
                $sub['full_name'] . ' has been reassigned to a different partner.'
                    . ' Its balance of ' . format_money((float) $result['balance_moved'])
                    . ' has been transferred out of your account.'
                    . ($reason !== '' ? ' Note: ' . $reason : ''),
                '/partner/sub-accounts.php',
                'partner_sub_accounts',
                $id
            );

            notify(
                'PARTNER',
                (int) $result['new_partner_id'],
                'SUB_ACCOUNT_REASSIGNED_IN',
                'Sub-account transferred to you',
                $sub['full_name'] . ' has been assigned to your account.'
                    . ' Its balance of ' . format_money((float) $result['balance_moved'])
                    . ' has been credited to your ledger.'
                    . ($reason !== '' ? ' Note: ' . $reason : ''),
                '/partner/sub-account-view.php?id=' . $id,
                'partner_sub_accounts',
                $id
            );

            audit_log(
                'SUB_ACCOUNT_REASSIGNED',
                'partner_sub_accounts',
                $id,
                'Moved from partner #' . (int) $result['old_partner_id']
                    . ' to partner #' . (int) $result['new_partner_id']
                    . '; balance ' . format_money((float) $result['balance_moved'])
                    . ($reason !== '' ? '; reason: ' . $reason : '')
            );

            flash('success', 'Sub-account reassigned to partner #' . (int) $result['new_partner_id']
                . '. Balance moved: ' . format_money((float) $result['balance_moved']) . '.');

            // Refresh the cached $sub so the redirect page shows the new owner
            $sub = sub_account_find($id);
            break;

    // ---------------------------------------------------------------

        default:
            throw new RuntimeException('Unknown action.');
    }
} catch (RuntimeException $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash('error', $e->getMessage());
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('[sub-account-action] ' . $e->getMessage());
    flash('error', 'Action failed: ' . $e->getMessage());
}

redirect(root_url('/superadmin/sub-account-view.php') . '?id=' . $id);