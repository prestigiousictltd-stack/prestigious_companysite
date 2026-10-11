<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$page_title = 'Notifications';
$active_nav = 'notifications';
$sid = (int) $sa['id'];

if (is_post() && ($_POST['action'] ?? '') === 'mark_all_read') {
    csrf_require();
    db()->prepare("UPDATE notifications SET is_read=1, read_at=:ts WHERE recipient_role='SUPER_ADMIN' AND recipient_id=:id AND is_read=0")
        ->execute([':ts' => now_dt(), ':id' => $sid]);
    flash('success', 'All marked as read.');
    redirect('/superadmin/notifications.php');
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30; $offset = ($page - 1) * $perPage;

$total = (int) db()->query("SELECT COUNT(*) FROM notifications WHERE recipient_role='SUPER_ADMIN' AND recipient_id=" . $sid)->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare("SELECT * FROM notifications WHERE recipient_role='SUPER_ADMIN' AND recipient_id=:id ORDER BY created_at DESC LIMIT $perPage OFFSET $offset");
$stmt->execute([':id' => $sid]);
$rows = $stmt->fetchAll();

require PICT_ROOT . '/includes/header-superadmin.php';
?>
<div class="page-head"><div><h1>Notifications</h1><p><?= number_format($total) ?> total.</p></div>
  <div class="page-head__actions">
    <form method="post" style="display:inline">
      <?= csrf_field() ?><input type="hidden" name="action" value="mark_all_read">
      <button class="btn btn-outline" type="submit">Mark all as read</button>
    </form>
  </div>
</div>

<div class="card">
  <?php if (empty($rows)): ?><div class="empty"><p>No notifications.</p></div>
  <?php else: foreach ($rows as $n): ?>
    <div style="padding:14px 0;border-bottom:1px solid var(--line);<?= $n['is_read'] ? 'opacity:.7' : '' ?>">
      <div class="flex-between mb-2">
        <strong style="font-size:14.5px"><?= e($n['title']) ?></strong>
        <span class="muted" style="font-size:12.5px"><?= e(date('d M Y H:i', strtotime($n['created_at']))) ?></span>
      </div>
      <?php if (!empty($n['body'])): ?><p style="margin:0 0 8px;font-size:14px"><?= nl2br(e($n['body'])) ?></p><?php endif; ?>
      <?php if (!empty($n['link_url'])): ?><?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
  <?php if ($pages > 1): ?>
    <ul class="pagination">
      <?php if ($page > 1): ?><li><a href="?page=<?= $page - 1 ?>">Previous</a></li><?php endif; ?>
      <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
        <li><?= $i === $page ? '<span class="active">' . $i . '</span>' : '<a href="?page=' . $i . '">' . $i . '</a>' ?></li>
      <?php endfor; ?>
      <?php if ($page < $pages): ?><li><a href="?page=<?= $page + 1 ?>">Next</a></li><?php endif; ?>
    </ul>
  <?php endif; ?>
</div>
<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>