<?php
/**
 * Cross-cutting helper functions.
 */

declare(strict_types=1);

/** Escape for HTML output. */
function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Build an absolute URL relative to the application base. */
function url(string $path = ''): string
{
    $base = $GLOBALS['APP_CONFIG']['base_path'] ?? '';
    if ($path === '' || $path === '/') {
        return $base === '' ? '/' : $base . '/';
    }
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    return $base . $path;
}

/**
 * Build a URL that resolves to the project root, not to public/.
 * Use for links that leave the public site and enter a portal, and for
 * everything inside the partner/, admin/, and superadmin/ folders.
 */
function root_url(string $path = ''): string
{
    $base = $GLOBALS['APP_CONFIG']['base_path'] ?? '';
    // Strip a trailing '/public' if the app is deployed under public/
    $root = preg_replace('#/public$#', '', $base);
    if ($path === '' || $path === '/') {
        return $root . '/';
    }
    if (!str_starts_with($path, '/')) {
        $path = '/' . $path;
    }
    return $root . $path;
}


/** Build an absolute URL (with scheme+host) — for emails and redirects. */
function absolute_url(string $path = ''): string
{
    return rtrim($GLOBALS['APP_CONFIG']['url'], '/') . url($path);
}

/** Issue a 302 redirect and exit. */
function redirect(string $path, int $code = 302): never
{
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        $target = $path;
    } else {
        $base = $GLOBALS['APP_CONFIG']['base_path'] ?? '';   // e.g. '/prestigious-ict/public'
        $root = preg_replace('#/public$#', '', $base);        // e.g. '/prestigious-ict'

        if ($path === '' || $path === '/') {
            $resolved = $base . '/';
        } elseif ($base !== '' && str_starts_with($path, $base)) {
            // Already passed through url() — use as-is, do not double-prefix
            $resolved = $path;
        } elseif ($root !== '' && str_starts_with($path, $root . '/')) {
            // Already passed through root_url() — use as-is
            $resolved = $path;
        } elseif (str_starts_with($path, '/')) {
            // Bare root-relative path — treat as a public-site path
            $resolved = $base . $path;
        } else {
            // Bare relative path
            $resolved = $base . '/' . $path;
        }

        $target = rtrim($GLOBALS['APP_CONFIG']['url'], '/') . $resolved;
    }

    if (!headers_sent()) {
        header('Location: ' . $target, true, $code);
    }
    exit;
}

/** Current client IP, honouring common proxy headers only when trusted. */
function client_ip(): string
{
    $candidates = [];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $candidates = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $candidates[] = $_SERVER['REMOTE_ADDR'];
    }
    foreach ($candidates as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

/** Current request user agent, truncated. */
function user_agent(): string
{
    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    return mb_substr($ua, 0, 255);
}

/** Current datetime in MySQL format. */
function now_dt(): string
{
    return date('Y-m-d H:i:s');
}

/** Today's date in MySQL format. */
function today_date(): string
{
    return date('Y-m-d');
}

/** Random hex token of N bytes (default 32 bytes = 64 hex chars). */
function random_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

/** Cryptographically safe reference with prefix. */
function make_reference(string $prefix, int $bytes = 6): string
{
    return strtoupper($prefix) . '-' . strtoupper(bin2hex(random_bytes($bytes)));
}

/** Mask a NIN: keep last 4 digits. */
function mask_nin(?string $nin): string
{
    if ($nin === null || $nin === '') {
        return '';
    }
    $digits = preg_replace('/\D/', '', $nin) ?? '';
    if (strlen($digits) <= 4) {
        return str_repeat('*', max(0, 11 - strlen($digits))) . $digits;
    }
    return str_repeat('*', 8) . substr($digits, -4);
}

/** Mask a bank account number: keep last 4 digits. */
function mask_account(?string $acct): string
{
    if ($acct === null || $acct === '') {
        return '';
    }
    $s = preg_replace('/\s+/', '', $acct) ?? '';
    if (strlen($s) <= 4) {
        return $s;
    }
    return str_repeat('*', max(4, strlen($s) - 4)) . substr($s, -4);
}

/** Format an amount for display. */
function format_money($amount, ?string $currency = null): string
{
    $currency = $currency ?? (string) setting('system_currency_symbol', '₦');
    $decimals = (int) setting('money_decimals', 2);
    return $currency . number_format((float) $amount, $decimals);
}

/** Format a period as "January 2026". */
function format_period(int $year, int $month): string
{
    $months = [
        1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];
    return ($months[$month] ?? '?') . ' ' . $year;
}

/** Set a one-shot flash message. */
function flash(string $key, $value): void
{
    $_SESSION['_flash'][$key] = $value;
}

/** Retrieve (and clear) a flash message. */
function flash_get(string $key, $default = null)
{
    if (isset($_SESSION['_flash'][$key])) {
        $v = $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);
        return $v;
    }
    return $default;
}

/** Persist form input for redisplay after a validation failure. */
function flash_old(array $input, array $except = ['password', 'password_confirmation', 'current_password']): void
{
    foreach ($except as $k) {
        unset($input[$k]);
    }
    $_SESSION['_old'] = $input;
}

/** Retrieve old form value. */
function old(string $key, $default = '')
{
    if (!empty($_SESSION['_old'][$key])) {
        return $_SESSION['_old'][$key];
    }
    return $default;
}

/** Clear old form input (call on successful submit). */
function old_clear(): void
{
    unset($_SESSION['_old']);
}

/** Case-insensitive array key lookup. */
function arr_get(array $arr, string $key, $default = null)
{
    if (array_key_exists($key, $arr)) {
        return $arr[$key];
    }
    $l = strtolower($key);
    foreach ($arr as $k => $v) {
        if (strtolower((string) $k) === $l) {
            return $v;
        }
    }
    return $default;
}