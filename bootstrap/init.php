<?php
/**
 * Application bootstrap.
 * Every entry-point file (public/, partner/, admin/, superadmin/, api/)
 * must include this file first.
 */

declare(strict_types=1);

if (defined('PICT_BOOTSTRAPPED')) {
    return;
}
define('PICT_BOOTSTRAPPED', true);
define('PICT_START_TIME', microtime(true));

// ---- Paths ---------------------------------------------------------
define('PICT_ROOT', dirname(__DIR__));

// ---- Configuration -------------------------------------------------
$GLOBALS['APP_CONFIG'] = require PICT_ROOT . '/config/app.php';
$GLOBALS['DB_CONFIG']  = require PICT_ROOT . '/config/database.php';
$GLOBALS['SEC_CONFIG'] = require PICT_ROOT . '/config/security.php';

$app = $GLOBALS['APP_CONFIG'];

// ---- Errors --------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', $app['debug'] ? '1' : '0');
ini_set('log_errors', '1');

if (!is_dir($app['log_path'])) {
    @mkdir($app['log_path'], 0775, true);
}
ini_set('error_log', $app['log_path'] . '/error.log');

// ---- Timezone ------------------------------------------------------
date_default_timezone_set($app['timezone']);

// ---- Session (per-portal isolation) --------------------------------
if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $sec = $GLOBALS['SEC_CONFIG']['session'];

    $uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';

    $base = $app['base_path'];
    if ($base !== '' && strpos($path, $base) === 0) {
        $path = substr($path, strlen($base));
    }

    $cookieName = $sec['public_cookie'];
    if (strpos($path, '/superadmin') === 0) {
        $cookieName = $sec['superadmin_cookie'];
    } elseif (strpos($path, '/admin') === 0) {
        $cookieName = $sec['admin_cookie'];
    } elseif (strpos($path, '/partner') === 0) {
        $cookieName = $sec['partner_cookie'];
    }

    $secure = $sec['secure'];
    if ($secure === null) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    $isAdminPortal = (strpos($path, '/admin') === 0 || strpos($path, '/superadmin') === 0);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) $sec['idle_seconds']);
    ini_set('session.cookie_samesite', $isAdminPortal ? 'Strict' : $sec['samesite']);

    session_name($cookieName);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $sec['path'],
        'domain'   => $sec['domain'],
        'secure'   => $secure,
        'httponly' => $sec['httponly'],
        'samesite' => $isAdminPortal ? 'Strict' : $sec['samesite'],
    ]);
    session_start();

    $idleKey = '_last_activity';
    $idleLimit = (strpos($path, '/superadmin') === 0)
        ? $sec['superadmin_idle']
        : $sec['idle_seconds'];
    if (!empty($_SESSION[$idleKey]) && (time() - $_SESSION[$idleKey]) > $idleLimit) {
        $_SESSION = [];
        session_destroy();
        session_start();
    }
    $_SESSION[$idleKey] = time();

    if (empty($_SESSION['_created_at'])) {
        $_SESSION['_created_at'] = time();
    } elseif ((time() - $_SESSION['_created_at']) > $sec['regenerate_every']) {
        session_regenerate_id(true);
        $_SESSION['_created_at'] = time();
    }
}

// ---- Security headers ----------------------------------------------
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    foreach ($GLOBALS['SEC_CONFIG']['headers'] as $h => $v) {
        header($h . ': ' . $v);
    }
    header_remove('X-Powered-By');
}

// ---- Database handle (lazy) ----------------------------------------
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = $GLOBALS['DB_CONFIG'];
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        $c['host'], $c['port'], $c['name'], $c['charset']
    );
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], $c['options']);
        $pdo->exec("SET time_zone = '+01:00'");
        $pdo->exec("SET NAMES {$c['charset']} COLLATE {$c['collation']}");
    } catch (PDOException $e) {
        error_log('[DB] Connection failed: ' . $e->getMessage());
        if (!empty($GLOBALS['APP_CONFIG']['debug'])) {
            http_response_code(500);
            echo 'Database connection failed. See error log.';
        } else {
            http_response_code(500);
            echo 'Service temporarily unavailable.';
        }
        exit;
    }
    return $pdo;
}

// ---- Shared includes (order matters) -------------------------------
require_once PICT_ROOT . '/includes/helpers.php';
require_once PICT_ROOT . '/includes/settings.php';
require_once PICT_ROOT . '/includes/csrf.php';
require_once PICT_ROOT . '/includes/audit.php';
require_once PICT_ROOT . '/includes/validation.php';
require_once PICT_ROOT . '/includes/notifications.php';
require_once PICT_ROOT . '/includes/mailer.php';
require_once PICT_ROOT . '/includes/auth.php';
require_once PICT_ROOT . '/includes/permissions.php';
require_once PICT_ROOT . '/includes/public-data.php';
require_once PICT_ROOT . '/includes/daily-report-render.php';
require_once PICT_ROOT . '/includes/sub-account.php';