<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/admin-auth.php';
$admin = admin_require_login();

$page_title = 'Notifications';
$active_nav = 'notifications';
$adminId = (int) $admin['id'];

// Mark all read
if (is_post() && ($_POST['action'] ?? '') === 'mark_all_read') {
    csrf_require();
    $stmt = db()->prepare("UPDATE notifications SET is_read = 1, read_at = :ts WHERE recipient_role = 'ADMIN' AND recipient_id = :id AND is_read = 0");
    $stmt->execute([':ts' => now_dt(), ':id' => $adminId]);
    flash('success', 'All notifications marked as read.');
    redirect('/admin/notifications.php');
}

// Single mark read
if (is_post() && ($_POST['action'] ?? '') === 'mark_read') {
    csrf_require();
    $nid = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare("UPDATE notifications SET is_read = 1, read_at = :ts WHERE id = :nid AND recipient_role = 'ADMIN' AND recipient_id = :aid");
    $stmt->execute([':ts' => now_dt(), ':nid' => $nid, ':aid' => $adminId]);
    redirect('/admin/notifications.php');
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$stmt = db()->prepare("SELECT COUNT(*) FROM notifications WHERE recipient_role = 'ADMIN' AND recipient_id = :id");
$stmt->execute([':id' => $adminId]);
$total = (int) $stmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare("
    SELECT id, type, title, body, link_url, is_read, created_at
    FROM notifications
    WHERE recipient_role = 'ADMIN' AND recipient_id = :id
    ORDER BY created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute([':id' => $adminId]);
$notifications = $stmt->fetchAll();

require PICT_ROOT . '/includes/header-admin.php';
?>

<div class="page-head">
  <div>
    <h1>Notifications</h1>
    <p><?= number_format($total) ?> notification<?= $total === 1 ? '' : 's' ?>.</p>
  </div>
  <div class="page-head__actions">
    <form method="post" style="display:inline">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="mark_all_read">
      <button class="btn btn-outline" type="submit">Mark all as read</button>
    </form>
  </div>
</div>

<div class="card">
  <?php if (empty($notifications)): ?>
    <div class="empty">
      <h3>No notifications</h3>
      <p>You will see updates here when they arrive.</p>
    </div>
  <?php else: ?>
    <?php foreach ($notifications as $n): ?>
      <div style="padding:16px 0;border-bottom:1px solid var(--line);<?= $n['is_read'] ? 'opacity:.7' : '' ?>">
        <div class="flex-between mb-2">
          <strong style="font-size:15px"><?= e($n['title']) ?></strong>
          <span class="muted" style="font-size:12.5px"><?= e(date('d M Y H:i', strtotime($n['created_at']))) ?></span>
        </div>
        <?php if (!empty($n['body'])): ?>
          <p style="margin:0 0 8px;font-size:14px;color:var(--text)"><?= nl2br(e($n['body'])) ?></p>
        <?php endif; ?>
        <div style="display:flex;gap:8px">
          <?php if (!empty($n['link_url'])): ?>
            <a class="btn btn-ghost btn-sm" href="..<?= e($n['link_url']) ?>">Open</a>
          <?php endif; ?>
          <?php if (!$n['is_read']): ?>
            <form method="post" style="display:inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="mark_read">
              <input type="hidden" name="id" value="<?= (int) $n['id'] ?>">
              <button class="btn btn-outline btn-sm" type="submit">Mark read</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
      <ul class="pagination">
        <?php if ($page > 1): ?><li><a href="?page=<?= $page - 1 ?>">Previous</a></li><?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <li><?= $i === $page ? '<span class="active">' . $i . '</span>' : '<a href="?page=' . $i . '">' . $i . '</a>' ?></li>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><li><a href="?page=<?= $page + 1 ?>">Next</a></li><?php endif; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-admin.php'; ?>