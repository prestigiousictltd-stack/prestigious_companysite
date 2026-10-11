<?php
/**
 * Append-only audit logging.
 * Never write NIN numbers, passwords, or bank account numbers here.
 */

declare(strict_types=1);

/**
 * Write an audit row.
 * $actor: ['role' => 'PARTNER|ADMIN|SUPER_ADMIN|SYSTEM|PUBLIC', 'id' => int|null, 'label' => string|null]
 */
function audit_log(
    string $actionCode,
    ?string $entityType = null,
    ?int $entityId = null,
    ?string $description = null,
    ?array $before = null,
    ?array $after = null,
    ?array $actor = null
): void {
    try {
        $actor = $actor ?? audit_current_actor();

        $beforeJson = $before !== null ? json_encode($before, JSON_UNESCAPED_UNICODE) : null;
        $afterJson  = $after  !== null ? json_encode($after,  JSON_UNESCAPED_UNICODE) : null;

        // Enforce maximum sizes
        if ($description !== null) {
            $description = mb_substr($description, 0, 1000);
        }

        $stmt = db()->prepare('
            INSERT INTO audit_logs
                (actor_role, actor_id, actor_label, action_code, entity_type, entity_id,
                 description, before_data, after_data, ip_address, user_agent, created_at)
            VALUES
                (:role, :aid, :alabel, :code, :etype, :eid,
                 :desc, :before, :after, :ip, :ua, :ts)
        ');
        $stmt->execute([
            ':role'   => $actor['role'],
            ':aid'    => $actor['id'],
            ':alabel' => $actor['label'] !== null ? mb_substr($actor['label'], 0, 190) : null,
            ':code'   => mb_substr($actionCode, 0, 80),
            ':etype'  => $entityType !== null ? mb_substr($entityType, 0, 80) : null,
            ':eid'    => $entityId,
            ':desc'   => $description,
            ':before' => $beforeJson,
            ':after'  => $afterJson,
            ':ip'     => client_ip(),
            ':ua'     => user_agent(),
            ':ts'     => now_dt(),
        ]);
    } catch (Throwable $e) {
        error_log('[audit] write failed: ' . $e->getMessage());
    }
}

/** Determine current actor from session state. */
function audit_current_actor(): array
{
    if (!empty($_SESSION['partner_id'])) {
        return ['role' => 'PARTNER', 'id' => (int) $_SESSION['partner_id'], 'label' => $_SESSION['partner_name'] ?? null];
    }
    if (!empty($_SESSION['admin_id'])) {
        return ['role' => 'ADMIN', 'id' => (int) $_SESSION['admin_id'], 'label' => $_SESSION['admin_name'] ?? null];
    }
    if (!empty($_SESSION['superadmin_id'])) {
        return ['role' => 'SUPER_ADMIN', 'id' => (int) $_SESSION['superadmin_id'], 'label' => $_SESSION['superadmin_name'] ?? null];
    }
    return ['role' => 'PUBLIC', 'id' => null, 'label' => null];
}