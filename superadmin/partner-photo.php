<?php
/**
 * Super Admin partner photo endpoint.
 * Any active Super Admin may stream any partner's photo.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/photo-handler.php';

superadmin_require_login();

$pid = (int) ($_GET['partner_id'] ?? 0);
if ($pid <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'partner_id is required.';
    exit;
}

stream_partner_photo($pid);