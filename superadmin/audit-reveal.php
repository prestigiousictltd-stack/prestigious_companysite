<?php
/**
 * Records a reveal of sensitive data (bank account). Returns 204.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

if (!is_post()) { http_response_code(405); exit; }
csrf_require();

$entity = (string) ($_POST['entity'] ?? '');
$id     = (int) ($_POST['id'] ?? 0);

audit_log('SENSITIVE_DATA_REVEAL', $entity ?: null, $id ?: null, 'Revealed sensitive data.');
http_response_code(204);