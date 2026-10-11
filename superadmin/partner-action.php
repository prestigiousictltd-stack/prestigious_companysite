<?php
/**
 * Super Admin partner action handler.
 * Receives POST with: partner_id, action, reason
 */

require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/commission.php';
$sa = superadmin_require_login();

if (!is_post()) {
    redirect('/superadmin/partners.php');
}
csrf_require();

$partnerId = (int) ($_POST['partner_id'] ?? 0);
$action    = (string) ($_POST['action'] ?? '');
$reason    = trim((string) ($_POST['reason'] ?? ''));

$stmt = db()->prepare("SELECT * FROM partners WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $partnerId]);
$p = $stmt->fetch();
if (!$p) {
    flash('error', 'Partner not found.');
    redirect('/superadmin/partners.php');
}

$newPassword = null;

try {
    db()->beginTransaction();

    // Every UPDATE below uses DISTINCT placeholder names because
    // PDO::ATTR_EMULATE_PREPARES = false does not allow a placeholder
    // to be reused within a single statement.
    $now = now_dt();

    switch ($action) {

        case 'approve':
            if (!in_array($p['status'], ['PENDING', 'REJECTED', 'INACTIVE', 'SUSPENDED'], true)) {
                throw new RuntimeException('Cannot approve a partner in the current state.');
            }
            $stmt = db()->prepare("
                UPDATE partners SET
                    status              = 'ACTIVE',
                    status_reason       = :reason,
                    status_changed_at   = :status_at,
                    status_changed_by_id= :status_by,
                    approved_at         = :approved_at,
                    approved_by_id      = :approved_by,
                    updated_at          = :updated_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':reason'      => $reason !== '' ? $reason : null,
                ':status_at'   => $now,
                ':status_by'   => (int) $sa['id'],
                ':approved_at' => $now,
                ':approved_by' => (int) $sa['id'],
                ':updated_at'  => $now,
                ':id'          => $partnerId,
            ]);

            // Auto-create an initial commission rate if none exists
            $chk = db()->prepare("SELECT COUNT(*) FROM partner_commission_rates WHERE partner_id = :pid AND status = 'ACTIVE'");
            $chk->execute([':pid' => $partnerId]);
            if ((int) $chk->fetchColumn() === 0) {
                $defaultPct = (float) setting('partner_default_commission_pct', 0);
                if ($defaultPct > 0) {
                    $ins = db()->prepare("
                        INSERT INTO partner_commission_rates
                          (partner_id, commission_percentage, effective_from, status,
                           created_by_super_admin_id, created_at)
                        VALUES
                          (:pid, :pct, :ef, 'ACTIVE', :by, :ca)
                    ");
                    $ins->execute([
                        ':pid' => $partnerId,
                        ':pct' => $defaultPct,
                        ':ef'  => today_date(),
                        ':by'  => (int) $sa['id'],
                        ':ca'  => $now,
                    ]);
                }
            }

            // Settle any orphan records that match this partner's NIN
            $settle = commission_settle_orphans($partnerId, (string) $p['nin_number'], (int) $sa['id']);
            if ($settle['settled'] > 0) {
                notify(
                    'PARTNER',
                    $partnerId,
                    'COMMISSION_GENERATED',
                    'Backdated commission settled',
                    number_format($settle['total_nin']) . ' previously recorded NINs have been attached to your account. '
                    . format_money($settle['commission']) . ' credited.',
                    '/partner/financials.php',
                    'partners',
                    $partnerId
                );
                audit_log(
                    'ORPHAN_SETTLED',
                    'partners',
                    $partnerId,
                    $settle['settled'] . ' orphan record(s) attached; commission '
                    . format_money($settle['commission'])
                );
            }

            notify('PARTNER', $partnerId, 'PARTNER_APPROVED', 'Your account is approved',
                'Welcome! You can now sign in to the Partner Portal.', '/partner/dashboard.php');
            audit_log('PARTNER_APPROVED', 'partners', $partnerId, 'Partner approved.',
                ['status' => $p['status']], ['status' => 'ACTIVE']);
            break;

        case 'reject':
            if ($p['status'] !== 'PENDING') {
                throw new RuntimeException('Only pending partners can be rejected.');
            }
            $stmt = db()->prepare("
                UPDATE partners SET
                    status              = 'REJECTED',
                    status_reason       = :reason,
                    status_changed_at   = :status_at,
                    status_changed_by_id= :status_by,
                    updated_at          = :updated_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':reason'    => $reason !== '' ? $reason : null,
                ':status_at' => $now,
                ':status_by' => (int) $sa['id'],
                ':updated_at'=> $now,
                ':id'        => $partnerId,
            ]);
            notify('PARTNER', $partnerId, 'PARTNER_REJECTED', 'Partner application not approved',
                $reason !== '' ? $reason : 'Your application was not approved at this time.');
            audit_log('PARTNER_REJECTED', 'partners', $partnerId, $reason);
            break;

        case 'suspend':
            if ($p['status'] !== 'ACTIVE') {
                throw new RuntimeException('Only active partners can be suspended.');
            }
            $stmt = db()->prepare("
                UPDATE partners SET
                    status              = 'SUSPENDED',
                    status_reason       = :reason,
                    status_changed_at   = :status_at,
                    status_changed_by_id= :status_by,
                    updated_at          = :updated_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':reason'    => $reason !== '' ? $reason : null,
                ':status_at' => $now,
                ':status_by' => (int) $sa['id'],
                ':updated_at'=> $now,
                ':id'        => $partnerId,
            ]);
            notify('PARTNER', $partnerId, 'PARTNER_SUSPENDED', 'Account suspended',
                $reason !== '' ? $reason : 'Contact support for details.');
            audit_log('PARTNER_SUSPENDED', 'partners', $partnerId, $reason);
            break;

        case 'deactivate':
            if ($p['status'] !== 'ACTIVE') {
                throw new RuntimeException('Only active partners can be deactivated.');
            }
            $stmt = db()->prepare("
                UPDATE partners SET
                    status              = 'INACTIVE',
                    status_reason       = :reason,
                    status_changed_at   = :status_at,
                    status_changed_by_id= :status_by,
                    updated_at          = :updated_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':reason'    => $reason !== '' ? $reason : null,
                ':status_at' => $now,
                ':status_by' => (int) $sa['id'],
                ':updated_at'=> $now,
                ':id'        => $partnerId,
            ]);
            audit_log('PARTNER_DEACTIVATED', 'partners', $partnerId, $reason);
            break;

        case 'reactivate':
            if (!in_array($p['status'], ['SUSPENDED', 'INACTIVE'], true)) {
                throw new RuntimeException('Partner is not in a reactivatable state.');
            }
            $stmt = db()->prepare("
                UPDATE partners SET
                    status              = 'ACTIVE',
                    status_reason       = :reason,
                    status_changed_at   = :status_at,
                    status_changed_by_id= :status_by,
                    updated_at          = :updated_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':reason'    => $reason !== '' ? $reason : null,
                ':status_at' => $now,
                ':status_by' => (int) $sa['id'],
                ':updated_at'=> $now,
                ':id'        => $partnerId,
            ]);
            notify('PARTNER', $partnerId, 'PARTNER_REACTIVATED', 'Account reactivated',
                'You can sign in again.', '/partner/login.php');
            audit_log('PARTNER_REACTIVATED', 'partners', $partnerId, '');
            break;

        case 'reset_password':
            $temp = 'Pict!' . bin2hex(random_bytes(4)) . 'a1';
            $hash = auth_hash($temp);
            $stmt = db()->prepare("
                UPDATE partners SET
                    password_hash    = :hash,
                    must_change_pwd  = 0,
                    updated_at       = :updated_at
                WHERE id = :id
            ");
            $stmt->execute([
                ':hash'       => $hash,
                ':updated_at' => $now,
                ':id'         => $partnerId,
            ]);
            $newPassword = $temp;

            notify('PARTNER', $partnerId, 'PASSWORD_RESET_BY_ADMIN', 'Password reset by administrator',
                'Your password has been reset. Please check your email for the temporary password.');

            $intro = '<p>Hello ' . e($p['full_name']) . ',</p>'
                   . '<p>Your password has been reset by an administrator.</p>'
                   . '<p><strong>Temporary password:</strong> <code>' . e($temp) . '</code></p>'
                   . '<p>Please sign in and change it immediately.</p>';
            send_branded_mail($p['email'], 'Your password has been reset', $intro, 'Sign in', absolute_url('/partner/login.php'));

            audit_log('PARTNER_PASSWORD_RESET', 'partners', $partnerId, 'Password reset by super admin.');
            break;

        default:
            throw new RuntimeException('Unknown action.');
    }

    db()->commit();

    if ($newPassword !== null) {
        flash('success', 'Password reset. Temporary password: ' . $newPassword);
    } else {
        flash('success', 'Action completed.');
    }
} catch (RuntimeException $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash('error', $e->getMessage());
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('[partner-action] ' . $e->getMessage());
    flash('error', 'Action failed. Please try again.');
}

redirect('/superadmin/partner-view.php?id=' . $partnerId);