<?php
/**
 * Backup cron.
 *
 * Preferred invocation: cPanel cron → CLI:
 *   php /home/USER/prestigious-ict/cron/backup.php
 *
 * HTTP path (if enabled): /cron/backup.php?token=<cron_token>
 *
 * Produces:
 *   backups/db-YYYYMMDD-HHMMSS.sql.gz       (database dump, gzip)
 *   backups/uploads-YYYYMMDD-HHMMSS.tar.gz  (uploads directory archive)
 *
 * Retention: keeps the N most recent of each type (default 14).
 * Never writes outside backups/. Never accepts a filename from the request.
 */

declare(strict_types=1);

$isCli = (PHP_SAPI === 'cli');
require_once dirname(__DIR__) . '/bootstrap/init.php';

if (!$isCli) {
    $expected = (string) setting('cron_token', '');
    $given    = (string) ($_GET['token'] ?? '');
    if ($expected === '' || !hash_equals($expected, $given)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$log = [];
$log[] = '=== ' . date('Y-m-d H:i:s') . ' backup start ===';

$backupDir = PICT_ROOT . '/backups';
if (!is_dir($backupDir)) { @mkdir($backupDir, 0755, true); }

$retention = (int) setting('backup_retention_count', 14);
if ($retention < 1) { $retention = 14; }

$timestamp = date('Ymd-His');

// ----- 1. Database dump -----
$dbFile = $backupDir . '/db-' . $timestamp . '.sql.gz';

$dbConfig = $GLOBALS['DB_CONFIG'];
$usedMysqldump = false;

// Try mysqldump if available (best fidelity — preserves triggers, views, routines)
$mysqldump = trim((string) @shell_exec('command -v mysqldump 2>/dev/null'));
if ($mysqldump === '') {
    foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', '/usr/local/mysql/bin/mysqldump'] as $candidate) {
        if (is_executable($candidate)) { $mysqldump = $candidate; break; }
    }
}

if ($mysqldump !== '' && function_exists('exec')) {
    $cmd = sprintf(
        '%s --host=%s --port=%s --user=%s --password=%s --single-transaction --skip-lock-tables --routines --triggers %s | gzip -9 > %s 2>&1',
        escapeshellcmd($mysqldump),
        escapeshellarg($dbConfig['host']),
        escapeshellarg((string) $dbConfig['port']),
        escapeshellarg($dbConfig['user']),
        escapeshellarg($dbConfig['pass']),   // NOTE: still visible to same-host processes in /proc; acceptable on cPanel
        escapeshellarg($dbConfig['name']),
        escapeshellarg($dbFile)
    );
    @exec($cmd, $execOut, $execCode);
    if (($execCode ?? 1) === 0 && is_file($dbFile) && filesize($dbFile) > 0) {
        $usedMysqldump = true;
        $log[] = 'Database dumped via mysqldump: ' . basename($dbFile) . ' (' . filesize($dbFile) . ' bytes)';
    } else {
        @unlink($dbFile);
        $log[] = 'mysqldump failed (code ' . (int) ($execCode ?? -1) . '); falling back to PHP dump.';
    }
}

if (!$usedMysqldump) {
    // PHP-based dump: table-by-table CREATE + INSERT. Streams through gzip.
    $fp = @gzopen($dbFile, 'wb9');
    if (!$fp) {
        $log[] = 'ERROR: could not open ' . $dbFile . ' for writing.';
        error_log('[backup] cannot write to ' . $dbFile);
    } else {
        gzwrite($fp, "-- Prestigious ICT backup (PHP dump)\n");
        gzwrite($fp, "-- Generated: " . date('c') . "\n");
        gzwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $t) {
            $createRow = db()->query("SHOW CREATE TABLE `" . str_replace('`', '``', $t) . "`")->fetch(PDO::FETCH_ASSOC);
            $createSql = $createRow['Create Table'] ?? '';
            gzwrite($fp, "\n-- Table `$t`\n");
            gzwrite($fp, "DROP TABLE IF EXISTS `$t`;\n");
            gzwrite($fp, $createSql . ";\n\n");

            $rowStmt = db()->query("SELECT * FROM `" . str_replace('`', '``', $t) . "`");
            $buffer = [];
            while ($row = $rowStmt->fetch(PDO::FETCH_ASSOC)) {
                $cols = array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', array_keys($row));
                $vals = array_map(function ($v) {
                    if ($v === null) return 'NULL';
                    if (is_int($v) || is_float($v)) return (string) $v;
                    return "'" . addslashes((string) $v) . "'";
                }, array_values($row));
                $buffer[] = 'INSERT INTO `' . str_replace('`', '``', $t) . '` (' . implode(',', $cols) . ') VALUES (' . implode(',', $vals) . ');';
                if (count($buffer) >= 500) {
                    gzwrite($fp, implode("\n", $buffer) . "\n");
                    $buffer = [];
                }
            }
            if (!empty($buffer)) {
                gzwrite($fp, implode("\n", $buffer) . "\n");
            }
        }
        gzwrite($fp, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        gzclose($fp);
        $log[] = 'Database dumped via PHP fallback: ' . basename($dbFile) . ' (' . (file_exists($dbFile) ? filesize($dbFile) : 0) . ' bytes)';
    }
}

// ----- 2. Uploads archive -----
$uploadsDir = PICT_ROOT . '/uploads';
$uploadsFile = $backupDir . '/uploads-' . $timestamp . '.tar.gz';

if (is_dir($uploadsDir) && function_exists('exec')) {
    $cmd = sprintf(
        'tar -czf %s -C %s . 2>&1',
        escapeshellarg($uploadsFile),
        escapeshellarg(PICT_ROOT)
    );
    // tar -C <root> . would archive the whole tree; we want just uploads:
    $cmd = sprintf(
        'tar -czf %s -C %s uploads 2>&1',
        escapeshellarg($uploadsFile),
        escapeshellarg(PICT_ROOT)
    );
    @exec($cmd, $execOut2, $execCode2);
    if (($execCode2 ?? 1) === 0 && is_file($uploadsFile)) {
        $log[] = 'Uploads archived: ' . basename($uploadsFile) . ' (' . filesize($uploadsFile) . ' bytes)';
    } else {
        @unlink($uploadsFile);
        $log[] = 'Uploads archive failed (code ' . (int) ($execCode2 ?? -1) . ').';
    }
} else {
    $log[] = 'Uploads archive skipped (tar not available).';
}

// ----- 3. Retention -----
$prune = function (string $dir, string $prefix, string $ext, int $keep) use (&$log): void {
    $files = glob($dir . '/' . $prefix . '*.' . $ext) ?: [];
    if (count($files) <= $keep) return;
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($files, $keep) as $old) {
        if (@unlink($old)) {
            $log[] = 'Pruned old backup: ' . basename($old);
        }
    }
};
$prune($backupDir, 'db-', 'sql.gz', $retention);
$prune($backupDir, 'uploads-', 'tar.gz', $retention);

$log[] = '=== backup complete ===';

$logPath = PICT_ROOT . '/logs/backup.log';
if (!is_dir(dirname($logPath))) { @mkdir(dirname($logPath), 0775, true); }
@file_put_contents($logPath, implode("\n", $log) . "\n\n", FILE_APPEND | LOCK_EX);

if ($isCli) {
    echo implode("\n", $log) . "\n";
}