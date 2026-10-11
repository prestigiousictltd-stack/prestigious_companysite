<?php
declare(strict_types=1);

/** Detect the most likely delimiter for a CSV line. */
function csv_detect_delimiter(string $line): string
{
    $candidates = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
    foreach ($candidates as $d => $_) {
        $candidates[$d] = substr_count($line, $d);
    }
    arsort($candidates);
    $top = key($candidates);
    return $top ?: ',';
}

/** Normalise a header cell for matching. */
function csv_normalize_header(string $h): string
{
    $h = strtolower(trim($h));
    $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? '';
    return trim($h, '_');
}

/**
 * Open a CSV and return the header row + a handle.
 * Returns ['delimiter'=>string, 'headers'=>array, 'handle'=>resource].
 * Caller must fclose($handle).
 */
function csv_open(string $path): array
{
    $fp = @fopen($path, 'r');
    if (!$fp) {
        throw new RuntimeException('Cannot open uploaded file.');
    }
    $firstLine = fgets($fp);
    if ($firstLine === false) {
        fclose($fp);
        throw new RuntimeException('File is empty.');
    }
    if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
        $firstLine = substr($firstLine, 3);
    }
    $delim = csv_detect_delimiter($firstLine);
    rewind($fp);
    $headers = fgetcsv($fp, 0, $delim, '"', '\\');
    if ($headers === false) {
        fclose($fp);
        throw new RuntimeException('Could not read header row.');
    }
    if (!empty($headers[0]) && substr($headers[0], 0, 3) === "\xEF\xBB\xBF") {
        $headers[0] = substr($headers[0], 3);
    }
    $headers = array_map(fn($h) => trim((string) $h), $headers);
    return ['delimiter' => $delim, 'headers' => $headers, 'handle' => $fp];
}

/** Read one row as an associative array keyed by header name. */
function csv_read_row($handle, array $headers, string $delim): ?array
{
    $row = fgetcsv($handle, 0, $delim, '"', '\\');
    if ($row === false) {
        return null;
    }
    if (count($row) === 1 && trim((string) $row[0]) === '') {
        return null;
    }
    $out = [];
    foreach ($headers as $i => $h) {
        $out[$h] = isset($row[$i]) ? trim((string) $row[$i]) : '';
    }
    return $out;
}

/**
 * Parse a Period value.
 * Accepts "92025" (Sept 2025), "102025" (Oct 2025), "12025" (Jan 2025),
 * "012025" (Jan 2025), etc. Returns ['year'=>int,'month'=>int] or null.
 */
function csv_parse_period(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $digits = preg_replace('/\D/', '', $raw);
    if ($digits === null || $digits === '') {
        return null;
    }

    $len = strlen($digits);
    if ($len === 5) {
        $month = (int) substr($digits, 0, 1);
        $year  = (int) substr($digits, 1, 4);
    } elseif ($len === 6) {
        $month = (int) substr($digits, 0, 2);
        $year  = (int) substr($digits, 2, 4);
    } else {
        return null;
    }

    if ($month < 1 || $month > 12) {
        return null;
    }
    if ($year < 2000 || $year > 2100) {
        return null;
    }

    return ['year' => $year, 'month' => $month];
}

/** Canonical mapping targets. */
function csv_target_fields(): array
{
    return [
        'period'              => 'Period',
        'partner_nin'         => 'Partner NIN',
        'station_id'          => 'Station ID',
        'station_name'        => 'Station Name',
        'total_enrolments'    => 'Total Enrolments',
        'total_nin_generated' => 'Total NIN Generated',
        'ernew_nin'           => 'ERNEW NIN',
        'crnew_nin'           => 'CRNEW NIN',
        'female_count'        => 'Female',
        'male_count'          => 'Male',
    ];
}

/** Required target fields. */
function csv_required_fields(): array
{
    return ['period', 'partner_nin', 'total_nin_generated'];
}

/** Normalise the uploaded filename for storage. */
function csv_safe_filename(string $original): string
{
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'], true)) {
        $ext = 'csv';
    }
    return 'imp_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
}