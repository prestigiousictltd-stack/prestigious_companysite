<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$page_title = 'Audit Log';
$active_nav = 'audit-logs';

$fAction = trim((string) ($_GET['action'] ?? ''));
$fRole   = strtoupper(trim((string) ($_GET['role'] ?? '')));
$fFrom   = trim((string) ($_GET['from'] ?? ''));
$fTo     = trim((string) ($_GET['to'] ?? ''));
$fSearch = trim((string) ($_GET['q'] ?? ''));

$where = ['1=1']; $params = [];
if ($fAction !== '') { $where[] = 'action_code LIKE :a'; $params[':a'] = '%' . $fAction . '%'; }
if (in_array($fRole, ['PARTNER','ADMIN','SUPER_ADMIN','SYSTEM','PUBLIC'], true)) { $where[] = 'actor_role = :r'; $params[':r'] = $fRole; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) { $where[] = 'created_at >= :f'; $params[':f'] = $fFrom . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo))   { $where[] = 'created_at <= :t'; $params[':t'] = $fTo . ' 23:59:59'; }
if ($fSearch !== '') { $where[] = '(description LIKE :q OR actor_label LIKE :q OR entity_type LIKE :q)'; $params[':q'] = '%' . $fSearch . '%'; }
$whereSql = implode(' AND ', $where);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50; $offset = ($page - 1) * $perPage;

$c = db()->prepare("SELECT COUNT(*) FROM audit_logs WHERE $whereSql");
$c->execute($params);
$total = (int) $c->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare("SELECT * FROM audit_logs WHERE $whereSql ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset");
$stmt->execute($params);
$rows = $stmt->fetchAll();

require PICT_ROOT . '/includes/header-superadmin.php';
?>
<div class="page-head"><div><h1>Audit Log</h1><p>Append-only. <?= number_format($total) ?> entries.</p></div></div>

<div class="filter-bar">
  <form method="get">
    <div class="form-group"><label class="form-label">Action Code</label><input class="form-control" type="text" name="action" value="<?= e($fAction) ?>" placeholder="e.g. LOGIN"></div>
    <div class="form-group"><label class="form-label">Role</label>
      <select class="form-control" name="role"><option value="">All</option>
        <?php foreach (['PARTNER','ADMIN','SUPER_ADMIN','SYSTEM','PUBLIC'] as $r): ?>
          <option value="<?= $r ?>"<?= $fRole === $r ? ' selected' : '' ?>><?= $r ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= e($fFrom) ?>"></div>
    <div class="form-group"><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= e($fTo) ?>"></div>
    <div class="form-group"><label class="form-label">Search</label><input class="form-control" type="text" name="q" value="<?= e($fSearch) ?>"></div>
    <div class="form-group"><button class="btn btn-grape" type="submit">Filter</button>
      <a class="btn btn-outline" href="audit-logs.php">Reset</a></div>
  </form>
</div>

<div class="card">
  <?php if (empty($rows)): ?><div class="empty"><p>No audit entries match.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Description</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e(date('d M Y H:i:s', strtotime($r['created_at']))) ?></td>
              <td><?= e($r['actor_role']) ?><?= $r['actor_id'] ? ' #' . (int) $r['actor_id'] : '' ?><br>
                <span class="muted" style="font-size:11.5px"><?= e((string) $r['actor_label']) ?></span></td>
              <td><span class="badge badge--inactive"><?= e($r['action_code']) ?></span></td>
              <td><?= e((string) $r['entity_type']) ?><?= $r['entity_id'] ? ' #' . (int) $r['entity_id'] : '' ?></td>
              <td><?= e((string) $r['description']) ?></td>
              <td><span class="muted"><?= e((string) $r['ip_address']) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if ($pages > 1): ?>
      <ul class="pagination">
        <?php $qs = $_GET; unset($qs['page']); $base = http_build_query($qs); ?>
        <?php if ($page > 1): ?><li><a href="?<?= e($base) ?>&page=<?= $page - 1 ?>">Previous</a></li><?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <li><?= $i === $page ? '<span class="active">' . $i . '</span>' : '<a href="?' . e($base) . '&page=' . $i . '">' . $i . '</a>' ?></li>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><li><a href="?<?= e($base) ?>&page=<?= $page + 1 ?>">Next</a></li><?php endif; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>