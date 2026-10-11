<?php
/**
 * Partner guard. Load after bootstrap/init.php.
 * Requires the includes chain already loaded.
 */

declare(strict_types=1);

const PARTNER_STATUS_ACTIVE = 'ACTIVE';

/**
 * Attempt a partner login.
 * Returns ['ok' => bool, 'reason' => string|null, 'partner' => array|null].
 */
function partner_attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));

    $throttle = auth_check_throttle('PARTNER', $email);
    if ($throttle['locked']) {
        auth_log_attempt('PARTNER', $email, false, 'throttled');
        return ['ok' => false, 'reason' => 'Too many failed attempts. Please try again later.', 'partner' => null];
    }

    $stmt = db()->prepare('SELECT * FROM partners WHERE email = :e LIMIT 1');
    $stmt->execute([':e' => $email]);
    $p = $stmt->fetch();

    if (!$p) {
        auth_log_attempt('PARTNER', $email, false, 'unknown_email');
        return ['ok' => false, 'reason' => 'Invalid credentials.', 'partner' => null];
    }

    if (!auth_verify($password, (string) $p['password_hash'])) {
        auth_log_attempt('PARTNER', $email, false, 'bad_password');
        return ['ok' => false, 'reason' => 'Invalid credentials.', 'partner' => null];
    }

    if ($p['status'] !== PARTNER_STATUS_ACTIVE) {
        auth_log_attempt('PARTNER', $email, false, 'status_' . $p['status']);
        $msg = match ($p['status']) {
            'PENDING'   => 'Your account is pending Super Admin approval.',
            'REJECTED'  => 'Your registration was not approved.',
            'SUSPENDED' => 'Your account is suspended. Contact support.',
            'INACTIVE'  => 'Your account is inactive.',
            default     => 'Account is not active.',
        };
        return ['ok' => false, 'reason' => $msg, 'partner' => null];
    }

    auth_log_attempt('PARTNER', $email, true, null);

    session_regenerate_id(true);
    csrf_rotate();

    $_SESSION['partner_id']       = (int) $p['id'];
    $_SESSION['partner_name']     = (string) $p['full_name'];
    $_SESSION['partner_email']    = (string) $p['email'];
    $_SESSION['partner_code']     = (string) $p['partner_code'];
    $_SESSION['partner_logged_in_at'] = time();

    $stmt = db()->prepare('UPDATE partners SET last_login_at = ?, last_login_ip = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([now_dt(), client_ip(), now_dt(), (int) $p['id']]);

    audit_log('LOGIN', 'partners', (int) $p['id'], 'Partner signed in.');

    return ['ok' => true, 'reason' => null, 'partner' => $p];
}

function partner_logged_in(): bool
{
    return !empty($_SESSION['partner_id']);
}

function partner_id(): ?int
{
    return !empty($_SESSION['partner_id']) ? (int) $_SESSION['partner_id'] : null;
}

/**
 * Return the authenticated partner row, or null.
 * Cached per-request.
 */
function partner_current(): ?array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $id = partner_id();
    if ($id === null) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM partners WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] !== PARTNER_STATUS_ACTIVE) {
        partner_logout(false);
        return null;
    }
    $cache = $row;
    return $cache;
}

/**
 * Guard for partner pages. Redirects unauthenticated users to login.
 * Call at the top of every partner page after bootstrap.
 */
function partner_require_login(): array
{
    $p = partner_current();
    if ($p === null) {
        flash('error', 'Please sign in to continue.');
        redirect('/partner/login.php');
    }
    return $p;
}

function partner_logout(bool $audit = true): void
{
    if ($audit && !empty($_SESSION['partner_id'])) {
        audit_log('LOGOUT', 'partners', (int) $_SESSION['partner_id'], 'Partner signed out.');
    }
    unset(
        $_SESSION['partner_id'],
        $_SESSION['partner_name'],
        $_SESSION['partner_email'],
        $_SESSION['partner_code'],
        $_SESSION['partner_logged_in_at']
    );
    // Regenerate to prevent session fixation across the login boundary
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}