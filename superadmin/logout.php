<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';

if (is_post()) {
    csrf_require();
    superadmin_logout(true);
    flash('success', 'You have been signed out.');
    redirect('/superadmin/login.php');
}

superadmin_logout(true);
flash('success', 'You have been signed out.');
redirect('/superadmin/login.php');