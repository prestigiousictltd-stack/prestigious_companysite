<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';

if (is_post()) {
    csrf_require();
    partner_logout(true);
    flash('success', 'You have been signed out.');
    redirect('/partner/login.php');
}

// GET: show a confirmation-free logout (still CSRF-guarded via POST); a bare
// GET hit is treated as a direct logout for convenience, but is logged.
partner_logout(true);
flash('success', 'You have been signed out.');
redirect('/partner/login.php');