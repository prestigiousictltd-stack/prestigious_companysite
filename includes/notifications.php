<?php
/**
 * Cross-portal notification dispatcher.
 * Only writes to the database. Delivery channels (email, SMS) are the
 * caller's responsibility.
 */

declare(strict_types=1);

function notify(
    string $recipientRole,
    int $recipientId,
    string $type,
    string $title,
    ?string $body = null,
    ?string $linkUrl = null,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    try {
        $stmt = db()->prepare('
            INSERT INTO notifications
                (recipient_role, recipient_id, type, title, body, link_url,
                 related_entity_type, related_entity_id, is_read, created_at)
            VALUES
                (:role, :rid, :type, :title, :body, :link,
                 :etype, :eid, 0, :ts)
        ');
        $stmt->execute([
            ':role'  => $recipientRole,
            ':rid'   => $recipientId,
            ':type'  => mb_substr($type, 0, 60),
            ':title' => mb_substr($title, 0, 190),
            ':body'  => $body,
            ':link'  => $linkUrl !== null ? mb_substr($linkUrl, 0, 500) : null,
            ':etype' => $entityType,
            ':eid'   => $entityId,
            ':ts'    => now_dt(),
        ]);
    } catch (Throwable $e) {
        error_log('[notify] failed: ' . $e->getMessage());
    }
}

function notify_all_superadmins(
    string $type,
    string $title,
    ?string $body = null,
    ?string $linkUrl = null,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    try {
        $ids = db()->query("SELECT id FROM super_admins WHERE status = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            notify('SUPER_ADMIN', (int) $id, $type, $title, $body, $linkUrl, $entityType, $entityId);
        }
    } catch (Throwable $e) {
        error_log('[notify_all_superadmins] failed: ' . $e->getMessage());
    }
}

function notify_all_admins(
    string $type,
    string $title,
    ?string $body = null,
    ?string $linkUrl = null,
    ?string $entityType = null,
    ?int $entityId = null
): void {
    try {
        $ids = db()->query("SELECT id FROM admins WHERE status = 'ACTIVE'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            notify('ADMIN', (int) $id, $type, $title, $body, $linkUrl, $entityType, $entityId);
        }
    } catch (Throwable $e) {
        error_log('[notify_all_admins] failed: ' . $e->getMessage());
    }
}