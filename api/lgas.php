<?php
/**
 * Public LGA lookup endpoint.
 * GET /api/lgas.php?state_id=<int>
 * Returns JSON: { ok: true, lgas: [{id, name}, ...] }
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap/init.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$stateId = (int) ($_GET['state_id'] ?? 0);

if ($stateId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'state_id is required']);
    exit;
}

try {
    $stmt = db()->prepare('SELECT id, name FROM lgas WHERE state_id = :sid AND is_active = 1 ORDER BY name');
    $stmt->execute([':sid' => $stateId]);
    $rows = $stmt->fetchAll();
    echo json_encode(['ok' => true, 'lgas' => $rows]);
} catch (Throwable $e) {
    error_log('[api/lgas] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Lookup failed']);
}