<?php
/**
 * Partner photo endpoint.
 * A signed-in partner may only stream their own photo.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';
require_once PICT_ROOT . '/includes/photo-handler.php';

$pid = partner_id();
if (!$pid) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not signed in.';
    exit;
}

stream_partner_photo($pid);