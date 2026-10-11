<?php
/**
 * Housekeeping cron.
 *
 * Run daily via:
 *   - cPanel cron: php /home/USER/prestigious-ict/cron/housekeeping.php
 *   - or via HTTP with a token:
 *     /cron/housekeeping.php?token=<cron_token>
 *
 * The HTTP path requires the .htaccess in this directory to allow the
 * request through — the .htaccess above blocks direct web access to cron/
 * entirely. To enable the HTTP path, either relax that rule for this one
 * file, or configure cPanel to invoke the script via CLI (recommended).
 *
 * Idempotent. Safe to run more than once per day.
 */

declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    // Require bootstrap and a matching token
    require_once dirname(__DIR__) . '/bootstrap/init.php';
    $expected = (string) setting('cron_token', '');
    $given = (string) ($_GET['token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
} else {
    require_once dirname(__DIR__) . '/bootstrap/init.php';
}

$log = [];
$log[] = '=== ' . date('Y-m-d H:i:s') . ' housekeeping start ===';

try {
    // 1. Expire old password reset tokens
    $stmt = db()->prepare("
        UPDATE password_reset_tokens
        SET consumed_at = COALESCE(consumed_at, :now)
        WHERE consumed_at IS NULL AND expires_at < :now
    ");
    $stmt->execute([':now' => now_dt()]);
    $log[] = 'Expired reset tokens: ' . $stmt->rowCount();

    // 2. Purge password reset tokens older than 7 days
    $stmt = db()->prepare("DELETE FROM password_reset_tokens WHERE expires_at < :cut");
    $stmt->execute([':cut' => date('Y-m-d H:i:s', time() - 7 * 86400)]);
    $log[] = 'Purged old reset tokens: ' . $stmt->rowCount();

    // 3. Trim login_attempts older than 30 days
    $stmt = db()->prepare("DELETE FROM login_attempts WHERE created_at < :cut");
    $stmt->execute([':cut' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
    $log[] = 'Purged old login attempts: ' . $stmt->rowCount();

    // 4. Trim read notifications older than 180 days
    $stmt = db()->prepare("DELETE FROM notifications WHERE is_read = 1 AND created_at < :cut");
    $stmt->execute([':cut' => date('Y-m-d H:i:s', time() - 180 * 86400)]);
    $log[] = 'Purged read notifications: ' . $stmt->rowCount();

    // 5. Cancel stale import batches (uploaded but never mapped within 7 days)
    $stmt = db()->prepare("
        UPDATE import_batches
        SET status = 'CANCELLED', error_message = 'Auto-cancelled: never mapped.', updated_at = :now
        WHERE status IN ('UPLOADED') AND created_at < :cut
    ");
    $stmt->execute([':now' => now_dt(), ':cut' => date('Y-m-d H:i:s', time() - 7 * 86400)]);
    $log[] = 'Auto-cancelled stale imports: ' . $stmt->rowCount();

    // 6. Remove orphaned import files whose batch is CANCELLED/FAILED and older than 30 days
    $stmt = db()->prepare("
        SELECT stored_filename FROM import_batches
        WHERE status IN ('CANCELLED','FAILED') AND created_at < :cut
    ");
    $stmt->execute([':cut' => date('Y-m-d H:i:s', time() - 30 * 86400)]);
    $deleted = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $stored) {
        $path = PICT_ROOT . '/uploads/imports/' . basename((string) $stored);
        if (is_file($path) && @unlink($path)) {
            $deleted++;
        }
    }
    $log[] = 'Deleted orphaned import files: ' . $deleted;

    // 7. Report stale DRAFT reports older than 30 days (informational only)
    $stmt = db()->query("
        SELECT COUNT(*) FROM daily_reports
        WHERE status = 'DRAFT' AND created_at < (NOW() - INTERVAL 30 DAY)
    ");
    $log[] = 'Stale DRAFT reports (informational): ' . (int) $stmt->fetchColumn();

    $log[] = '=== housekeeping complete ===';
} catch (Throwable $e) {
    $log[] = 'ERROR: ' . $e->getMessage();
    error_log('[housekeeping] ' . $e->getMessage());
}

$logPath = PICT_ROOT . '/logs/housekeeping.log';
if (!is_dir(dirname($logPath))) { @mkdir(dirname($logPath), 0775, true); }
@file_put_contents($logPath, implode("\n", $log) . "\n\n", FILE_APPEND | LOCK_EX);

if ($isCli) {
    echo implode("\n", $log) . "\n";
}