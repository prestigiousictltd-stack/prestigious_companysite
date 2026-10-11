<?php
/**
 * Security policy defaults.
 * These are the fallback values used if system_settings is unreachable
 * or a specific key is missing. Runtime values are read from the
 * system_settings table via setting().
 */

return [
    'session' => [
        'partner_cookie'    => 'PICT_PARTNER_SESS',
        'admin_cookie'      => 'PICT_ADMIN_SESS',
        'superadmin_cookie' => 'PICT_SUPERADMIN_SESS',
        'public_cookie'     => 'PICT_PUBLIC_SESS',
        'path'              => '/',
        'domain'            => '',                 // '' = current host
        'secure'            => null,               // null = auto-detect HTTPS
        'httponly'          => true,
        'samesite'          => 'Lax',              // 'Strict' for admin portals
        'idle_seconds'      => 3600,
        'superadmin_idle'   => 1800,
        'regenerate_every'  => 900,
    ],
    'password' => [
        'algo'          => PASSWORD_DEFAULT,
        'min_length'    => 8,
        'require_upper' => true,
        'require_lower' => true,
        'require_digit' => true,
        'require_symbol'=> false,
    ],
    'throttle' => [
        'max_attempts'    => 5,
        'window_minutes'  => 15,
    ],
    'reset_token' => [
        'ttl_minutes'   => 60,
        'bytes'         => 32,
    ],
    'headers' => [
        'X-Frame-Options'           => 'SAMEORIGIN',
        'X-Content-Type-Options'    => 'nosniff',
        'Referrer-Policy'           => 'strict-origin-when-cross-origin',
        'Permissions-Policy'        => 'geolocation=(), microphone=(), camera=()',
        'Content-Security-Policy'   => "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self' data:; form-action 'self'; base-uri 'self'; frame-ancestors 'self'",
    ],
];