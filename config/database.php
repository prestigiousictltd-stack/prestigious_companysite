<?php
/**
 * Database configuration.
 *
 * Priority order for each value:
 *   1. DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASS  (legacy / local)
 *   2. MYSQLHOST / MYSQLPORT / MYSQLDATABASE / MYSQLUSER / MYSQLPASSWORD  (Railway)
 *   3. DATABASE_URL / MYSQL_URL parse (Railway single-string form)
 *   4. Hardcoded defaults (XAMPP local development)
 */

function pict_db_env(string $legacyKey, string $railwayKey, ?string $default = null): ?string
{
    $v = getenv($legacyKey);
    if ($v !== false && $v !== '') {
        return $v;
    }
    $v = getenv($railwayKey);
    if ($v !== false && $v !== '') {
        return $v;
    }
    return $default;
}

// Optional: parse a single connection URL if present
$urlHost = $urlPort = $urlName = $urlUser = $urlPass = null;
foreach (['DATABASE_URL', 'MYSQL_URL'] as $urlKey) {
    $url = getenv($urlKey);
    if ($url !== false && $url !== '' && preg_match('#^mysql://#i', $url)) {
        $parts = parse_url($url);
        if ($parts) {
            $urlHost = $parts['host']   ?? null;
            $urlPort = isset($parts['port']) ? (string) $parts['port'] : null;
            $urlName = isset($parts['path']) ? ltrim($parts['path'], '/') : null;
            $urlUser = $parts['user']   ?? null;
            $urlPass = $parts['pass']   ?? null;
            break;
        }
    }
}

return [
    'host'      => pict_db_env('DB_HOST', 'MYSQLHOST',     $urlHost ?: '127.0.0.1'),
    'port'      => pict_db_env('DB_PORT', 'MYSQLPORT',     $urlPort ?: '3306'),
    'name'      => pict_db_env('DB_NAME', 'MYSQLDATABASE', $urlName ?: 'prestigiousict_ng'),
    'user'      => pict_db_env('DB_USER', 'MYSQLUSER',     $urlUser ?: 'root'),
    'pass'      => pict_db_env('DB_PASS', 'MYSQLPASSWORD', $urlPass ?: ''),
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'options'   => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ],
];