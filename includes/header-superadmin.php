<?php
if (!defined('PICT_BOOTSTRAPPED')) { exit; }
$sa = superadmin_current();
if (!$sa) { redirect('../superadmin/login.php'); }
$companyShort = (string) setting('company_short_name', 'Prestigious ICT');
$initials = strtoupper(mb_substr($sa['full_name'], 0, 1) . (mb_strpos($sa['full_name'], ' ') !== false ? mb_substr($sa['full_name'], mb_strpos($sa['full_name'], ' ') + 1, 1) : ''));

static $saUnread = null;
if ($saUnread === null) {
    try {
        $s = db()->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_role='SUPER_ADMIN' AND recipient_id=:id AND is_read=0");
        $s->execute([':id' => (int) $sa['id']]);
        $saUnread = (int) $s->fetchColumn();
    } catch (Throwable $e) { $saUnread = 0; }
}

static $saPendingPartners = null;
if ($saPendingPartners === null) {
    try {
        $saPendingPartners = (int) db()->query("SELECT COUNT(*) FROM partners WHERE status='PENDING'")->fetchColumn();
    } catch (Throwable $e) { $saPendingPartners = 0; }
}

static $saPendingWithdrawals = null;
if ($saPendingWithdrawals === null) {
    try {
        $saPendingWithdrawals = (int) db()->query("SELECT COUNT(*) FROM withdrawals WHERE status='PENDING'")->fetchColumn();
    } catch (Throwable $e) { $saPendingWithdrawals = 0; }
}

static $saPendingReports = null;
if ($saPendingReports === null) {
    try {
        $saPendingReports = (int) db()->query("SELECT COUNT(*) FROM daily_reports WHERE status='SUBMITTED'")->fetchColumn();
    } catch (Throwable $e) { $saPendingReports = 0; }
}
static $saPendingSubAccounts = null;
if ($saPendingSubAccounts === null) {
    try {
        $saPendingSubAccounts = (int) db()->query("SELECT COUNT(*) FROM partner_sub_accounts WHERE status = 'PENDING'")->fetchColumn();
    } catch (Throwable $e) { $saPendingSubAccounts = 0; }
}


$nav = [
    ['group' => 'Overview', 'items' => [
        ['key' => 'dashboard', 'label' => 'Dashboard', 'href' => '../superadmin/dashboard.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>'],
        ['key' => 'analytics', 'label' => 'Analytics', 'href' => '../superadmin/analytics.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 14 3-3 4 4 5-6"/></svg>'],
    ]],
    ['group' => 'Partners', 'items' => [
        ['key' => 'partners', 'label' => 'Partner Management', 'href' => '../superadmin/partners.php', 'badge' => $saPendingPartners > 0 ? (string) $saPendingPartners : null,
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>'],
        ['key' => 'commission-rates', 'label' => 'Commission Rates', 'href' => '../superadmin/commission-rates.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 5 5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>'],
        ['key' => 'stations', 'label' => 'Stations', 'href' => '../superadmin/stations.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>'],
            ['key' => 'aggregators', 'label' => 'Aggregators', 'href' => root_url('/superadmin/aggregators.php'),
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M2 8h20"/><path d="M2 14h20"/><path d="M6 4v4M18 4v4M6 16v4M18 16v4"/></svg>'],
         
                 ['key' => 'sub-accounts', 'label' => 'Sub-Accounts', 'href' => root_url('/superadmin/sub-accounts.php'), 'badge' => $saPendingSubAccounts > 0 ? (string) $saPendingSubAccounts : null,
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="7" r="3"/><circle cx="5" cy="17" r="3"/><circle cx="19" cy="17" r="3"/><path d="M12 10v4"/><path d="M12 14 5 15"/><path d="M12 14l7 1"/></svg>'],
         ]],
    ['group' => 'Operations', 'items' => [
        ['key' => 'imports', 'label' => 'CSV Import', 'href' => '../superadmin/imports.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 10l5-5 5 5"/><path d="M12 5v12"/></svg>'],
        ['key' => 'nin-records', 'label' => 'NIN Records', 'href' => '../superadmin/nin-records.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 10a2 2 0 0 0-2 2v3.5a2.5 2.5 0 0 0 5 0V12a2 2 0 0 0-2-2z"/><path d="M6 12a6 6 0 0 1 12 0v2"/><path d="M4 12a8 8 0 0 1 16 0v3"/></svg>'],
        ['key' => 'ledger', 'label' => 'Financial Ledger', 'href' => '../superadmin/ledger.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>'],
        ['key' => 'withdrawals', 'label' => 'Withdrawals', 'href' => '../superadmin/withdrawals.php', 'badge' => $saPendingWithdrawals > 0 ? (string) $saPendingWithdrawals : null,
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><circle cx="17" cy="14" r="1"/></svg>'],
    ]],
    ['group' => 'Reports', 'items' => [
        ['key' => 'daily-reports', 'label' => 'Daily Reports', 'href' => '../superadmin/daily-reports.php', 'badge' => $saPendingReports > 0 ? (string) $saPendingReports : null,
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M8 13h8M8 17h8M8 9h2"/></svg>'],
        ['key' => 'report-centre', 'label' => 'Report Centre', 'href' => '../superadmin/report-centre.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>'],
    ]],
    ['group' => 'Governance', 'items' => [
        ['key' => 'admins', 'label' => 'Admin Accounts', 'href' => '../superadmin/admins.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>'],
        ['key' => 'catalogue-services', 'label' => 'Service Catalogue', 'href' => '../superadmin/catalogue-services.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v4H3z"/><path d="M3 9h18v12H3z"/></svg>'],
        ['key' => 'catalogue-expenses', 'label' => 'Expense Categories', 'href' => '../superadmin/catalogue-expenses.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M3 12h18M3 18h12"/></svg>'],
        ['key' => 'catalogue-staff', 'label' => 'Staff', 'href' => '../superadmin/catalogue-staff.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="7" r="4"/><path d="M2 21a7 7 0 0 1 14 0"/><path d="M17 11a3 3 0 1 0 0-6"/></svg>'],
        ['key' => 'settings', 'label' => 'System Settings', 'href' => '../superadmin/settings.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>'],
        ['key' => 'audit-logs', 'label' => 'Audit Log', 'href' => '../superadmin/audit-logs.php',
         'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16H4z"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>'],
    ]],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($page_title ?? 'Super Admin') ?> &middot; <?= e($companyShort) ?> Super Admin</title>
<link rel="stylesheet" href="./assets/css/superadmin.css">
</head>
<body>
<div class="admin-shell">
                  <aside class="admin-sidebar" id="admin-sidebar">
                        <div class="admin-sidebar__brand">
                             
                         <span>    <div class="logo-icon">
                <img 
                    src="../assets/img/company_logo.jpg" 
                    alt="Company Logo"
                    class="brand-img"
                ></span>
                </div>   
                              <span class="admin-sidebar__name">
                                  <b><?= e($companyShort) ?></b>
                                  <span>Super Admin</span>
                              </span>
                        </div>

                        <?php foreach ($nav as $group): ?>
                          <div class="admin-sidebar__group"><?= e($group['group']) ?></div>
                          <ul class="admin-sidebar__nav">
                            <?php foreach ($group['items'] as $item): ?>
                              <li>
                                <a href="<?= e($item['href']) ?>"<?= ($active_nav ?? '') === $item['key'] ? ' class="is-active"' : '' ?>>
                                      <?= $item['icon'] ?>
                                      <span><?= e($item['label']) ?></span>
                                      <?php if (!empty($item['badge'])): ?><span class="admin-sidebar__badge"><?= e($item['badge']) ?></span><?php endif; ?>
                                </a>
                              </li>
                            <?php endforeach; ?>
                          </ul>
                        <?php endforeach; ?>
                        <div class="admin-sidebar__footer">
                          <a href="./notifications.php">Notifications<?= $saUnread > 0 ? ' (' . $saUnread . ')' : '' ?></a>
                          <a href="./profile.php">My Profile</a>
                          <a href="./change-password.php">Change Password</a>
                          <a href="./logout.php">Sign Out</a>
                        </div>

 <div class="admin-sidebar__backdrop" id="admin-sidebar-backdrop"></div>
                  </aside>
 

  <div class="admin-main">
       <header class="admin-topbar">
      <button class="admin-topbar__toggle" type="button" aria-label="Menu" id="admin-menu-toggle">
        <span></span><span></span><span></span>
      </button>
      <h1 class="admin-topbar__title"><?= e($page_title ?? 'Dashboard') ?></h1>
      <div class="admin-topbar__user">
        <span class="admin-topbar__user-name"><?= e($sa['full_name']) ?></span>
        <span class="admin-topbar__avatar" aria-hidden="true"><?= e($initials) ?></span>
        <form method="post" action="<?= e(root_url('/superadmin/logout.php')) ?>" class="admin-topbar__logout-form">
          <?= csrf_field() ?>
          <button type="submit" class="admin-topbar__logout" title="Sign out">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
              <path d="m16 17 5-5-5-5"/>
              <path d="M21 12H9"/>
            </svg>
            <span>Sign Out</span>
          </button>
        </form>
      </div>
    </header>
              
    <main class="admin-content">
      <?php if ($msg = flash_get('success')): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
      <?php if ($msg = flash_get('error')): ?><div class="alert alert-danger"><?= e($msg) ?></div><?php endif; ?>
      <?php if ($msg = flash_get('info')): ?><div class="alert alert-info"><?= e($msg) ?></div><?php endif; ?>
      <?php if ($msg = flash_get('warn')): ?><div class="alert alert-warn"><?= e($msg) ?></div><?php endif; ?>