<?php
/**
 * Super Admin guard.
 */

declare(strict_types=1);

function superadmin_attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));

    $throttle = auth_check_throttle('SUPER_ADMIN', $email);
    if ($throttle['locked']) {
        auth_log_attempt('SUPER_ADMIN', $email, false, 'throttled');
        return ['ok' => false, 'reason' => 'Too many failed attempts. Please try again later.', 'superadmin' => null];
    }

    $stmt = db()->prepare('SELECT * FROM super_admins WHERE email = :e LIMIT 1');
    $stmt->execute([':e' => $email]);
    $s = $stmt->fetch();

    if (!$s) {
        auth_log_attempt('SUPER_ADMIN', $email, false, 'unknown_email');
        return ['ok' => false, 'reason' => 'Invalid credentials.', 'superadmin' => null];
    }
    if (!auth_verify($password, (string) $s['password_hash'])) {
        auth_log_attempt('SUPER_ADMIN', $email, false, 'bad_password');
        return ['ok' => false, 'reason' => 'Invalid credentials.', 'superadmin' => null];
    }
    if ($s['status'] !== 'ACTIVE') {
        auth_log_attempt('SUPER_ADMIN', $email, false, 'status_' . $s['status']);
        return ['ok' => false, 'reason' => 'Account is not active.', 'superadmin' => null];
    }

    auth_log_attempt('SUPER_ADMIN', $email, true, null);

    session_regenerate_id(true);
    csrf_rotate();

    $_SESSION['superadmin_id']        = (int) $s['id'];
    $_SESSION['superadmin_name']      = (string) $s['full_name'];
    $_SESSION['superadmin_email']     = (string) $s['email'];
    $_SESSION['superadmin_logged_in_at'] = time();

    $stmt = db()->prepare('UPDATE super_admins SET last_login_at = ?, last_login_ip = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([now_dt(), client_ip(), now_dt(), (int) $s['id']]);

    audit_log('LOGIN', 'super_admins', (int) $s['id'], 'Super Admin signed in.');

    return ['ok' => true, 'reason' => null, 'superadmin' => $s];
}

function superadmin_logged_in(): bool
{
    return !empty($_SESSION['superadmin_id']);
}

function superadmin_id(): ?int
{
    return !empty($_SESSION['superadmin_id']) ? (int) $_SESSION['superadmin_id'] : null;
}

function superadmin_current(): ?array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $id = superadmin_id();
    if ($id === null) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM super_admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] !== 'ACTIVE') {
        superadmin_logout(false);
        return null;
    }
    $cache = $row;
    return $cache;
}

function superadmin_require_login(): array
{
    $s = superadmin_current();
    if ($s === null) {
        flash('error', 'Please sign in to continue.');
        redirect('/superadmin/login.php');
    }
    return $s;
}

function superadmin_logout(bool $audit = true): void
{
    if ($audit && !empty($_SESSION['superadmin_id'])) {
        audit_log('LOGOUT', 'super_admins', (int) $_SESSION['superadmin_id'], 'Super Admin signed out.');
    }
    unset(
        $_SESSION['superadmin_id'],
        $_SESSION['superadmin_name'],
        $_SESSION['superadmin_email'],
        $_SESSION['superadmin_logged_in_at']
    );
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}