<?php
/**
 * Shared authentication primitives.
 * Role-specific guards live in partner-auth.php, admin-auth.php, superadmin-auth.php.
 */

declare(strict_types=1);

function auth_hash(string $plain): string
{
    return password_hash($plain, PASSWORD_DEFAULT);
}

function auth_verify(string $plain, string $hash): bool
{
    if ($hash === '' || $hash === null) {
        return false;
    }
    return password_verify($plain, $hash);
}

/** Log a login attempt (success or failure). */
function auth_log_attempt(string $role, string $identifier, bool $success, ?string $reason = null): void
{
    try {
        $stmt = db()->prepare('
            INSERT INTO login_attempts
                (role, identifier, ip_address, user_agent, successful, failure_reason, created_at)
            VALUES
                (:role, :id, :ip, :ua, :ok, :reason, :ts)
        ');
        $stmt->execute([
            ':role'   => $role,
            ':id'     => mb_substr($identifier, 0, 190),
            ':ip'     => client_ip(),
            ':ua'     => user_agent(),
            ':ok'     => $success ? 1 : 0,
            ':reason' => $reason !== null ? mb_substr($reason, 0, 120) : null,
            ':ts'     => now_dt(),
        ]);
    } catch (Throwable $e) {
        error_log('[auth_log_attempt] failed: ' . $e->getMessage());
    }
}

/**
 * Check whether this (role, identifier, ip) combination is currently throttled.
 * Returns ['locked' => bool, 'retry_after' => int seconds].
 */
function auth_check_throttle(string $role, string $identifier, ?string $ip = null): array
{
    $ip = $ip ?? client_ip();
    $max = setting_int('security_login_max_attempts', 5);
    $window = setting_int('security_lockout_minutes', 15);

    try {
        $stmt = db()->prepare('
            SELECT COUNT(*) FROM login_attempts
            WHERE role = :role
              AND (identifier = :id OR ip_address = :ip)
              AND successful = 0
              AND created_at >= (NOW() - INTERVAL :w MINUTE)
        ');
        $stmt->bindValue(':role', $role);
        $stmt->bindValue(':id', mb_substr($identifier, 0, 190));
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':w', $window, PDO::PARAM_INT);
        $stmt->execute();
        $count = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[auth_check_throttle] failed: ' . $e->getMessage());
        return ['locked' => false, 'retry_after' => 0, 'count' => 0];
    }

    if ($count >= $max) {
        return ['locked' => true, 'retry_after' => $window * 60, 'count' => $count];
    }
    return ['locked' => false, 'retry_after' => 0, 'count' => $count];
}

/**
 * Persist a password change and log it.
 * $table must be one of: partners, admins, super_admins.
 */
function auth_change_password(string $table, int $accountId, string $newHash, string $role): void
{
    if (!in_array($table, ['partners', 'admins', 'super_admins'], true)) {
        throw new InvalidArgumentException('Invalid table');
    }
    $stmt = db()->prepare("UPDATE `$table` SET password_hash = ?, must_change_pwd = 0, updated_at = ? WHERE id = ?");
    $stmt->execute([$newHash, now_dt(), $accountId]);
    audit_log('PASSWORD_CHANGED', $table, $accountId, 'Password updated.');
}

// ---------- Password reset tokens ----------

function auth_create_reset_token(string $role, int $accountId): string
{
    $bytes = (int) $GLOBALS['SEC_CONFIG']['reset_token']['bytes'];
    $ttl   = (int) $GLOBALS['SEC_CONFIG']['reset_token']['ttl_minutes'];

    $plain = bin2hex(random_bytes($bytes));         // 64 hex chars
    $hash  = hash('sha256', $plain);
    $exp   = date('Y-m-d H:i:s', time() + ($ttl * 60));

    // Invalidate any unconsumed tokens for this account
    $stmt = db()->prepare('
        UPDATE password_reset_tokens
        SET consumed_at = :now
        WHERE role = :role AND account_id = :aid AND consumed_at IS NULL
    ');
    $stmt->execute([':now' => now_dt(), ':role' => $role, ':aid' => $accountId]);

    $stmt = db()->prepare('
        INSERT INTO password_reset_tokens
            (role, account_id, token_hash, expires_at, requested_ip, created_at)
        VALUES
            (:role, :aid, :hash, :exp, :ip, :now)
    ');
    $stmt->execute([
        ':role' => $role,
        ':aid'  => $accountId,
        ':hash' => $hash,
        ':exp'  => $exp,
        ':ip'   => client_ip(),
        ':now'  => now_dt(),
    ]);
    return $plain;
}

/**
 * Look up an unconsumed, unexpired reset token.
 * Returns ['id' => int, 'role' => string, 'account_id' => int] or null.
 */
function auth_lookup_reset_token(string $plain, string $expectedRole): ?array
{
    $hash = hash('sha256', $plain);
    $stmt = db()->prepare('
        SELECT id, role, account_id, expires_at, consumed_at
        FROM password_reset_tokens
        WHERE token_hash = :hash AND role = :role
        LIMIT 1
    ');
    $stmt->execute([':hash' => $hash, ':role' => $expectedRole]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    if ($row['consumed_at'] !== null) {
        return null;
    }
    if (strtotime($row['expires_at']) < time()) {
        return null;
    }
    return [
        'id'         => (int) $row['id'],
        'role'       => (string) $row['role'],
        'account_id' => (int) $row['account_id'],
    ];
}

function auth_consume_reset_token(int $tokenId): void
{
    $stmt = db()->prepare('UPDATE password_reset_tokens SET consumed_at = ? WHERE id = ?');
    $stmt->execute([now_dt(), $tokenId]);
}

// ---------- Misc ----------

/** Generate a unique partner code, e.g. PICT-P-000042. */
function auth_generate_partner_code(): string
{
    $max = (int) (db()->query('SELECT COALESCE(MAX(id),0) FROM partners')->fetchColumn());
    $next = $max + 1;
    return 'PICT-P-' . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
}