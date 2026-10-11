<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/admin-auth.php';

if (is_post()) {
    csrf_require();
    admin_logout(true);
    flash('success', 'You have been signed out.');
    redirect('admin/login.php');
}

admin_logout(true);
flash('success', 'You have been signed out.');
redirect('admin/login.php');