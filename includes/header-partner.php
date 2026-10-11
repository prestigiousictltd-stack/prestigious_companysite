<?php
/**
 * Partner portal layout header.
 * Requires: partner is already authenticated.
 * Expected before include: $active_nav (string), $page_title (string)
 */

if (!defined('PICT_BOOTSTRAPPED')) { exit; }

$partner = partner_current();
if (!$partner) { redirect('/partner/login.php'); }

$companyShort = (string) setting('company_short_name', 'Prestigious ICT');
$initials = strtoupper(mb_substr($partner['full_name'], 0, 1) . (mb_strpos($partner['full_name'], ' ') !== false ? mb_substr($partner['full_name'], mb_strpos($partner['full_name'], ' ') + 1, 1) : ''));

static $unreadCount = null;
if ($unreadCount === null) {
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_role='PARTNER' AND recipient_id=:id AND is_read=0");
        $stmt->execute([':id' => (int) $partner['id']]);
        $unreadCount = (int) $stmt->fetchColumn();
    } catch (Throwable $e) { $unreadCount = 0; }
}

static $partnerPendingSubAccounts = null;
if ($partnerPendingSubAccounts === null) {
    try {
        $stmt = db()->prepare("SELECT COUNT(*) FROM partner_sub_accounts WHERE partner_id = :id AND status = 'PENDING'");
        $stmt->execute([':id' => (int) $partner['id']]);
        $partnerPendingSubAccounts = (int) $stmt->fetchColumn();
    } catch (Throwable $e) { $partnerPendingSubAccounts = 0; }
}

$navItems = [
    ['key' => 'dashboard',    'label' => 'Dashboard',           'href' => '../partner/dashboard.php',
     'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/></svg>'],
   
    ['key' => 'sub-accounts', 'label' => 'Sub-Accounts',        'href' => '../partner/sub-accounts.php',
     'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="7" r="3"/><circle cx="5" cy="17" r="3"/><circle cx="19" cy="17" r="3"/><path d="M12 10v4"/><path d="M12 14 5 15"/><path d="M12 14l7 1"/></svg>'],

     ['key' => 'enrolments',   'label' => 'NIN Enrolment Records','href' => '../partner/enrolments.php',
     'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 10a2 2 0 0 0-2 2v3.5a2.5 2.5 0 0 0 5 0V12a2 2 0 0 0-2-2z"/><path d="M6 12a6 6 0 0 1 12 0v2"/><path d="M4 12a8 8 0 0 1 16 0v3"/></svg>'],
    ['key' => 'financials',   'label' => 'Funding / Commission', 'href' => '../partner/financials.php',
     'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 14 3-3 4 4 5-6"/></svg>'],
    ['key' => 'withdrawals',  'label' => 'Withdrawals',         'href' => '../partner/withdrawals.php',
     'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="13" rx="2"/><path d="M3 10h18"/><circle cx="17" cy="14" r="1"/></svg>'],
    ['key' => 'bank-details', 'label' => 'Bank Details',         'href' => '../partner/bank-details.php',
     'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10 12 3l9 7"/><path d="M5 10v10h14V10"/><path d="M9 14v6M15 14v6"/></svg>'],
    ['key' => 'profile',      'label' => 'Profile',              'href' => '../partner/profile.php',
     'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>'],
    ['key' => 'notifications','label' => 'Notifications',        'href' => '../partner/notifications.php',
     'badge' => $unreadCount > 0 ? (string) $unreadCount : null,
     'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>'],
    ['key' => 'change-password','label' => 'Change Password',    'href' => '../partner/change-password.php',
     'icon' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>'],
   

     ];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($page_title ?? 'Partner') ?> &middot; <?= e($companyShort) ?> Partner</title>
<link rel="stylesheet" href="./assets/css/partner.css">
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
        <span>Partner Portal</span>
      </span>
    </div>
    <ul class="admin-sidebar__nav">
      <?php foreach ($navItems as $item): ?>
        <li>
          <a href="./<?= e($item['href']) ?>"<?= ($active_nav ?? '') === $item['key'] ? ' class="is-active"' : '' ?>>
            <?= $item['icon'] ?>
            <span><?= e($item['label']) ?></span>
            <?php if (!empty($item['badge'])): ?><span class="admin-sidebar__badge"><?= e($item['badge']) ?></span><?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
    <div class="admin-sidebar__footer">
      <a href="../partner/logout.php">Sign Out</a>
      <a href="../" target="_blank" rel="noopener">View Public Site &rarr;</a>
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
        <span class="admin-topbar__user-name">
          <?= e($partner['full_name']) ?>
          <span class="muted">&middot; <?= e($partner['partner_code']) ?></span>
        </span>
        <span class="admin-topbar__avatar" aria-hidden="true"><?= e($initials) ?></span>
        <form method="post" action="<?= e(root_url('/partner/logout.php')) ?>" class="admin-topbar__logout-form">
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