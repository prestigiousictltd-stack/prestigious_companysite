<?php
/**
 * Permission matrix and access-control helpers.
 * The three guards are already isolated at the folder/session level.
 * This file provides capability checks used within each portal.
 */

declare(strict_types=1);

/**
 * Capabilities per role.
 * Add new capabilities here as modules are built; do not hard-code
 * role checks inside views.
 */
function permission_matrix(): array
{
    return [
        'SUPER_ADMIN' => [
            'partner.view','partner.approve','partner.reject','partner.suspend',
            'partner.edit','partner.reset_password','partner.commission_rate.manage',
            'admin.create','admin.edit','admin.disable','admin.reset_password',
            'station.manage','location.manage','catalogue.manage',
            'import.upload','import.confirm','import.cancel',
            'nin.view','nin.correct',
            'ledger.view','ledger.adjust',
            'withdrawal.view','withdrawal.approve','withdrawal.reject','withdrawal.pay',
            'daily_report.view_all','daily_report.review','daily_report.approve','daily_report.return','daily_report.archive',
            'report.export','analytics.view',
            'settings.manage','audit.view',
        ],
        'ADMIN' => [
            'daily_report.create','daily_report.edit_own','daily_report.submit',
            'daily_report.view_own','daily_report.print_own',
        ],
        'PARTNER' => [
            'enrolment.view_own','financial.view_own',
            'withdrawal.request','withdrawal.view_own',
            'bank.manage_own','profile.manage_own',
        ],
    ];
}

function permission_can(string $role, string $capability): bool
{
    $m = permission_matrix();
    return in_array($capability, $m[$role] ?? [], true);
}

function permission_require(string $role, string $capability): void
{
    if (!permission_can($role, $capability)) {
        http_response_code(403);
        audit_log('PERMISSION_DENIED', null, null, "Role=$role cap=$capability");
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Access denied.';
        exit;
    }
}