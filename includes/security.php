<?php
/**
 * Additional security utilities.
 * Load after helpers.php (uses setting()).
 */

declare(strict_types=1);

/**
 * Emit HSTS and Permissions-Policy when HTTPS is confirmed.
 * Call once after bootstrap on every request (already done in init for CSP
 * and standard headers; this adds the transport-level ones).
 */
function security_headers_extended(): void
{
    if (headers_sent()) {
        return;
    }
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    if ($isHttps) {
        header('Strict-Transport-Security: max-age=15552000; includeSubDomains');
    }
    header('Cross-Origin-Opener-Policy: same-origin');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');
}

/**
 * Reject any redirect target that is not an internal path.
 * Prevents open redirect via ?next=… style parameters.
 */
function security_safe_redirect(string $path, string $fallback = '/'): string
{
    if ($path === '' || str_starts_with($path, '//') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)) {
        return $fallback;
    }
    if (!str_starts_with($path, '/')) {
        return $fallback;
    }
    return $path;
}

/**
 * Generic rate-limit check backed by the audit_logs table.
 * Returns ['allowed' => bool, 'remaining' => int].
 *
 * Caller must pass a unique $bucket string (e.g. "contact:{ip}").
 * The check counts audit rows with action_code = $actionPrefix that match
 * $bucketKey in the description over the window.
 */
function security_rate_limit(string $actionPrefix, string $bucketKey, int $max, int $windowSeconds): array
{
    try {
        $stmt = db()->prepare("
            SELECT COUNT(*) FROM audit_logs
            WHERE action_code = :code
              AND description LIKE :needle
              AND created_at >= :since
        ");
        $stmt->execute([
            ':code'   => $actionPrefix,
            ':needle' => '%' . $bucketKey . '%',
            ':since'  => date('Y-m-d H:i:s', time() - $windowSeconds),
        ]);
        $count = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[security_rate_limit] ' . $e->getMessage());
        return ['allowed' => true, 'remaining' => $max];
    }
    $allowed = $count < $max;
    return ['allowed' => $allowed, 'remaining' => max(0, $max - $count)];
}

/** Record a rate-limit hit for the given bucket. */
function security_rate_limit_hit(string $actionPrefix, string $bucketKey, string $note = ''): void
{
    audit_log(
        $actionPrefix,
        null,
        null,
        $bucketKey . ($note !== '' ? ' — ' . $note : ''),
        null,
        null,
        ['role' => 'PUBLIC', 'id' => null, 'label' => null]
    );
}

/**
 * Password strength score 0–4 for advisory UI feedback.
 * Server policy remains the authority (see validation.php).
 */
function security_password_strength(string $password): int
{
    $score = 0;
    if (mb_strlen($password) >= 8)  $score++;
    if (mb_strlen($password) >= 12) $score++;
    if (preg_match('/[A-Z]/', $password) && preg_match('/[a-z]/', $password)) $score++;
    if (preg_match('/\d/', $password)) $score++;
    if (preg_match('/[^A-Za-z0-9]/', $password)) $score++;
    return min(4, $score);
}

/**
 * Normalise an IP for audit purposes.
 * Optional: hash with a salt to anonymise in exports without losing correlation.
 */
function security_hash_ip(?string $ip): string
{
    $ip = $ip ?? client_ip();
    $salt = (string) setting('security_ip_hash_salt', 'pict-default-rotate-me');
    return substr(hash('sha256', $salt . $ip), 0, 16);
}

/**
 * Detect whether the current request is a same-origin XHR.
 * Extra defence-in-depth for JSON APIs beyond CSRF tokens.
 */
function security_same_origin_request(): bool
{
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    $host   = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($origin === '' || $host === '') {
        return true; // non-CORS request; rely on CSRF + session
    }
    $originHost = parse_url($origin, PHP_URL_HOST);
    return $originHost !== null && strcasecmp($originHost, $host) === 0;
}

/**
 * Strip a value to its safe characters for use in logging without redacting
 * the entire field. Passwords, NINs and bank accounts must never be logged,
 * but a short correlation prefix is often useful for triage.
 */
function security_log_safe_id(?string $value): string
{
    if ($value === null || $value === '') {
        return '';
    }
    $clean = preg_replace('/[^A-Za-z0-9\-_]/', '', $value) ?? '';
    return substr($clean, 0, 12);
}