<?php
/**
 * Admin guard.
 */

declare(strict_types=1);

function admin_attempt_login(string $email, string $password): array
{
    $email = strtolower(trim($email));

    $throttle = auth_check_throttle('ADMIN', $email);
    if ($throttle['locked']) {
        auth_log_attempt('ADMIN', $email, false, 'throttled');
        return ['ok' => false, 'reason' => 'Too many failed attempts. Please try again later.', 'admin' => null];
    }

    $stmt = db()->prepare('SELECT * FROM admins WHERE email = :e LIMIT 1');
    $stmt->execute([':e' => $email]);
    $a = $stmt->fetch();

    if (!$a) {
        auth_log_attempt('ADMIN', $email, false, 'unknown_email');
        return ['ok' => false, 'reason' => 'Invalid credentials.', 'admin' => null];
    }
    if (!auth_verify($password, (string) $a['password_hash'])) {
        auth_log_attempt('ADMIN', $email, false, 'bad_password');
        return ['ok' => false, 'reason' => 'Invalid credentials.', 'admin' => null];
    }
    if ($a['status'] !== 'ACTIVE') {
        auth_log_attempt('ADMIN', $email, false, 'status_' . $a['status']);
        return ['ok' => false, 'reason' => 'Account is not active.', 'admin' => null];
    }

    auth_log_attempt('ADMIN', $email, true, null);

    session_regenerate_id(true);
    csrf_rotate();

    $_SESSION['admin_id']        = (int) $a['id'];
    $_SESSION['admin_name']      = (string) $a['full_name'];
    $_SESSION['admin_email']     = (string) $a['email'];
    $_SESSION['admin_logged_in_at'] = time();

    $stmt = db()->prepare('UPDATE admins SET last_login_at = ?, last_login_ip = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([now_dt(), client_ip(), now_dt(), (int) $a['id']]);

    audit_log('LOGIN', 'admins', (int) $a['id'], 'Admin signed in.');

    return ['ok' => true, 'reason' => null, 'admin' => $a];
}

function admin_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

function admin_id(): ?int
{
    return !empty($_SESSION['admin_id']) ? (int) $_SESSION['admin_id'] : null;
}

function admin_current(): ?array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $id = admin_id();
    if ($id === null) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row || $row['status'] !== 'ACTIVE') {
        admin_logout(false);
        return null;
    }
    $cache = $row;
    return $cache;
}

function admin_require_login(): array
{
    $a = admin_current();
    if ($a === null) {
        flash('error', 'Please sign in to continue.');
        redirect('/admin/login.php');
    }
    return $a;
}

function admin_logout(bool $audit = true): void
{
    if ($audit && !empty($_SESSION['admin_id'])) {
        audit_log('LOGOUT', 'admins', (int) $_SESSION['admin_id'], 'Admin signed out.');
    }
    unset(
        $_SESSION['admin_id'],
        $_SESSION['admin_name'],
        $_SESSION['admin_email'],
        $_SESSION['admin_logged_in_at']
    );
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}