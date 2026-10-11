<?php
/**
 * Application configuration.
 * Never commit real credentials here — the file is safe to commit as-is.
 * Environment overrides: APP_ENV, APP_URL, APP_DEBUG, APP_TIMEZONE.
 */

$detectedUrl = 'http://localhost';
if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = strtolower(explode(',', $_SERVER['HTTP_X_FORWARDED_PROTO'])[0]);
    }
    $detectedUrl = $scheme . '://' . $_SERVER['HTTP_HOST'];
}

return [
    'name'            => 'Prestigious ICT Investment Limited',
    'short_name'      => 'Prestigious ICT',
    'env'             => getenv('APP_ENV') ?: 'production',
    'debug'           => false,
    'url'             => rtrim(getenv('APP_URL') ?: $detectedUrl, '/'),
    'base_path'       => getenv('APP_BASE_PATH') ?: '',                // '' at domain root, '/app' in a subdirectory
    'timezone'        => getenv('APP_TIMEZONE') ?: 'Africa/Lagos',
    'root_path'       => dirname(__DIR__),
    'log_path'        => dirname(__DIR__) . '/logs',
    'uploads_path'    => dirname(__DIR__) . '/uploads',
    'charset'         => 'UTF-8',
    'version'         => '1.0.0',
];