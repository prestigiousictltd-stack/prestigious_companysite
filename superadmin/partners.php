<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$page_title = 'Partner Management';
$active_nav = 'partners';

$fStatus = strtoupper(trim((string) ($_GET['status'] ?? '')));
$fSearch = trim((string) ($_GET['q'] ?? ''));
$fState  = (int) ($_GET['state'] ?? 0);

$where = ['1=1']; $params = [];
if (in_array($fStatus, ['PENDING','ACTIVE','INACTIVE','SUSPENDED','REJECTED'], true)) {
    $where[] = 'p.status = :st'; $params[':st'] = $fStatus;
}
if ($fSearch !== '') {
    $like = '%' . $fSearch . '%';
    $where[] = '(p.full_name LIKE :q_name
                  OR p.email LIKE :q_email
                  OR p.partner_code LIKE :q_code
                  OR p.nin_number LIKE :q_nin)';
    $params[':q_name']  = $like;
    $params[':q_email'] = $like;
    $params[':q_code']  = $like;
    $params[':q_nin']   = $like;
}
if ($fState > 0) { $where[] = 'p.state_id = :s'; $params[':s'] = $fState; }
$whereSql = implode(' AND ', $where);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25; $offset = ($page - 1) * $perPage;

$cStmt = db()->prepare("SELECT COUNT(*) FROM partners p WHERE $whereSql");
$cStmt->execute($params);
$total = (int) $cStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare("
    SELECT p.*, s.name AS state_name, l.name AS lga_name,
           (SELECT COUNT(*) FROM stations WHERE partner_id = p.id) AS station_count
    FROM partners p
    LEFT JOIN states s ON s.id = p.state_id
    LEFT JOIN lgas l ON l.id = p.lga_id
    WHERE $whereSql
    ORDER BY p.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$states = db()->query("SELECT id, name FROM states WHERE is_active=1 ORDER BY name")->fetchAll();

require PICT_ROOT . '/includes/header-superadmin.php';

$badge = function (string $s): string {
    $cls = match ($s) {
        'PENDING'   => 'badge--pending',
        'ACTIVE'    => 'badge--active',
        'INACTIVE'  => 'badge--inactive',
        'SUSPENDED' => 'badge--suspended',
        'REJECTED'  => 'badge--rejected',
        default     => 'badge--inactive',
    };
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
};
?>

<div class="page-head">
  <div><h1>Partner Management</h1><p><?= number_format($total) ?> partner<?= $total === 1 ? '' : 's' ?>.</p></div>
  <div class="page-head__actions">
    <a class="btn btn-outline" href="./partners.php?status=PENDING">Pending Queue<?= $fStatus !== 'PENDING' ? '' : ' ✓' ?></a>
  </div>
</div>

<div class="filter-bar">
  <form method="get">
    <div class="form-group">
      <label class="form-label">Search</label>
      <input class="form-control" type="text" name="q" value="<?= e($fSearch) ?>" placeholder="Name, email, code or NIN">
    </div>
    <div class="form-group">
      <label class="form-label">Status</label>
      <select class="form-control" name="status">
        <option value="">All statuses</option>
        <?php foreach (['PENDING','ACTIVE','INACTIVE','SUSPENDED','REJECTED'] as $s): ?>
          <option value="<?= e($s) ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= e($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">State</label>
      <select class="form-control" name="state">
        <option value="">All states</option>
        <?php foreach ($states as $s): ?>
          <option value="<?= (int) $s['id'] ?>"<?= $fState === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <button class="btn btn-grape" type="submit">Filter</button>
      <a class="btn btn-outline" href="partners.php">Reset</a>
    </div>
  </form>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="empty"><h3>No partners found</h3><p>Try adjusting your filters.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr><th>Code</th><th>Name</th><th>Contact</th><th>Location</th><th>Stations</th><th>Status</th><th>Joined</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $p): ?>
            <tr>
              <td><span class="masked"><?= e($p['partner_code']) ?></span></td>
              <?php
$thumbUrl = !empty($p['profile_image_path'])
    ? root_url('/superadmin/partner-photo.php') . '?partner_id=' . (int) $p['id']
        . '&v=' . urlencode(basename((string) $p['profile_image_path']))
    : '';
$thumbInitials = strtoupper(
    mb_substr($p['full_name'], 0, 1)
    . (mb_strpos($p['full_name'], ' ') !== false
        ? mb_substr($p['full_name'], mb_strpos($p['full_name'], ' ') + 1, 1)
        : '')
);
?>
<td>
  <div style="display:flex;gap:10px;align-items:center">
    <div style="width:40px;height:40px;border-radius:8px;overflow:hidden;flex-shrink:0;background:#eef2f8;border:1px solid var(--line);display:flex;align-items:center;justify-content:center">
      <?php if ($thumbUrl !== ''): ?>
        <img src="<?= e($thumbUrl) ?>" alt="" style="width:100%;height:100%;object-fit:cover;display:block">
      <?php else: ?>
        <span style="font-size:14px;font-weight:700;color:#6b7280"><?= e($thumbInitials) ?></span>
      <?php endif; ?>
    </div>
    <div>
      <strong><?= e($p['full_name']) ?></strong>
      <?php if ($p['business_name']): ?>
        <br><span class="muted" style="font-size:12.5px"><?= e($p['business_name']) ?></span>
      <?php endif; ?>
    </div>
  </div>
</td>
              <td><?= e($p['email']) ?><br><span class="muted" style="font-size:12.5px"><?= e($p['phone']) ?></span></td>
              <td><?= e((string) $p['state_name']) ?><br><span class="muted" style="font-size:12.5px"><?= e((string) $p['lga_name']) ?></span></td>
              <td><?= (int) $p['station_count'] ?></td>
              <td><?= $badge($p['status']) ?></td>
              <td><?= e(date('d M Y', strtotime($p['created_at']))) ?></td>
              <td class="text-right">
                <a class="btn btn-outline btn-sm" href="./partner-view.php?id=<?= (int) $p['id'] ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <ul class="pagination">
        <?php if ($page > 1): ?><li><a href="?page=<?= $page - 1 ?>&status=<?= urlencode($fStatus) ?>&q=<?= urlencode($fSearch) ?>&state=<?= $fState ?>">Previous</a></li><?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <li><?= $i === $page ? '<span class="active">' . $i . '</span>' : '<a href="?page=' . $i . '&status=' . urlencode($fStatus) . '&q=' . urlencode($fSearch) . '&state=' . $fState . '">' . $i . '</a>' ?></li>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><li><a href="?page=<?= $page + 1 ?>&status=<?= urlencode($fStatus) ?>&q=<?= urlencode($fSearch) ?>&state=<?= $fState ?>">Next</a></li><?php endif; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>