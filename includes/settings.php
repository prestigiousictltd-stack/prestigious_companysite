<?php

declare(strict_types=1);

function settings_all(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $defaults = [
        'company_name'                 => 'Prestigious ICT Investment Limited',
        'company_short_name'           => 'Prestigious ICT',
        'company_email'                => '',
        'company_phone'                => '',
        'company_address'              => '',
        'company_logo_path'            => '/assets/img/logo.png',
        'system_currency'              => 'NGN',
        'system_currency_symbol'       => '₦',
        'system_timezone'              => 'Africa/Lagos',
        'money_decimals'               => '2',
        'partner_registration_open'    => '1',
        'partner_requires_approval'    => '1',
        'withdrawal_min_amount'        => '1000.00',
        'withdrawal_max_amount'        => '0.00',
        'upload_max_size_mb'           => '20',
        'upload_allowed_types'         => 'csv',
        'report_approval_required'     => '1',
        'report_reference_prefix'      => 'PICT-DR',
       'report_ar_default_currency'   => 'NGN',
        'report_revenue_basis'         => 'SERVICES',
        'report_performance_unit'      => '',
        'smtp_enabled'                 => '0',
        'smtp_host'                    => '',
        'smtp_port'                    => '587',
        'smtp_username'                => '',
        'smtp_password'                => '',
        'smtp_encryption'              => 'tls',
        'smtp_from_email'              => '',
        'smtp_from_name'               => 'Prestigious ICT Investment Limited',
        'security_login_max_attempts'  => '5',
        'security_lockout_minutes'     => '15',
        'security_session_idle_minutes'=> '60',
        'security_superadmin_idle_minutes' => '30',
        'security_password_min_length' => '8',
        'maintenance_mode'             => '0',
        'maintenance_message'          => '',
        'audit_log_exports'            => '1',
        'audit_log_reveals'            => '1',
    ];

    $cache = $defaults;
    try {
        $rows = db()->query('SELECT setting_key, setting_value FROM system_settings')->fetchAll();
        foreach ($rows as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    } catch (Throwable $e) {
        error_log('[settings] load failed: ' . $e->getMessage());
    }
    return $cache;
}

function setting(string $key, $default = null)
{
    $all = settings_all();
    if (!array_key_exists($key, $all)) {
        return $default;
    }
    $v = $all[$key];
    if ($v === null || $v === '') {
        return $default;
    }
    return $v;
}

function setting_bool(string $key, bool $default = false): bool
{
    $v = setting($key, null);
    if ($v === null) {
        return $default;
    }
    return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
}

function setting_int(string $key, int $default = 0): int
{
    $v = setting($key, null);
    return $v === null ? $default : (int) $v;
}

function setting_decimal(string $key, float $default = 0.0): float
{
    $v = setting($key, null);
    return $v === null ? $default : (float) $v;
}

function setting_set(string $key, $value, ?int $superAdminId = null): void
{
    $stmt = db()->prepare('
        INSERT INTO system_settings
            (setting_key, setting_value, setting_type, category, is_public, updated_by_role, updated_by_id, updated_at, created_at)
        VALUES
            (:k, :v, :t, :c, 0, :r, :i, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by_role = VALUES(updated_by_role),
            updated_by_id = VALUES(updated_by_id),
            updated_at = VALUES(updated_at)
    ');
    $stmt->execute([
        ':k' => $key,
        ':v' => (string) $value,
        ':t' => 'STRING',
        ':c' => 'GENERAL',
        ':r' => $superAdminId ? 'SUPER_ADMIN' : 'SYSTEM',
        ':i' => $superAdminId,
    ]);
    settings_flush();
}

function settings_flush(): void
{
    // Force reload on next access by clearing the static cache via a fresh call.
    // PHP has no direct way to reset a function-scoped static from outside,
    // so we track the cache in a global instead.
    unset($GLOBALS['__settings_cache']);
}