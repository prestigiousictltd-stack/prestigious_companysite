<?php
/**
 * Global sub-account review queue for the Super Admin.
 * Lists every sub-account across every partner with filters.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$sa = superadmin_require_login();

$page_title = 'Sub-Accounts';
$active_nav = 'sub-accounts';

$fStatus  = strtoupper(trim((string) ($_GET['status'] ?? '')));
$fPartner = (int) ($_GET['partner'] ?? 0);
$fSearch  = trim((string) ($_GET['q'] ?? ''));

$where  = ['1=1'];
$params = [];

if (in_array($fStatus, ['PENDING','ACTIVE','INACTIVE','REJECTED'], true)) {
    $where[] = 's.status = :st';
    $params[':st'] = $fStatus;
}
if ($fPartner > 0) {
    $where[] = 's.partner_id = :pid';
    $params[':pid'] = $fPartner;
}
if ($fSearch !== '') {
    $like = '%' . $fSearch . '%';
    $where[] = '(s.full_name   LIKE :q_sub_name
                  OR s.email      LIKE :q_sub_email
                  OR s.nin_number LIKE :q_sub_nin
                  OR p.full_name  LIKE :q_partner_name
                  OR p.partner_code LIKE :q_partner_code)';
    $params[':q_sub_name']     = $like;
    $params[':q_sub_email']    = $like;
    $params[':q_sub_nin']      = $like;
    $params[':q_partner_name'] = $like;
    $params[':q_partner_code'] = $like;
}
$whereSql = implode(' AND ', $where);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$cStmt = db()->prepare("
    SELECT COUNT(*)
    FROM partner_sub_accounts s
    JOIN partners p ON p.id = s.partner_id
    WHERE $whereSql
");
$cStmt->execute($params);
$total = (int) $cStmt->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare("
    SELECT s.*,
           p.full_name AS partner_name, p.partner_code, p.nin_number AS partner_nin,
           (SELECT commission_percentage FROM sub_account_commission_rates
             WHERE sub_account_id = s.id AND status = 'ACTIVE'
               AND effective_from <= CURDATE()
               AND (effective_to IS NULL OR effective_to >= CURDATE())
             ORDER BY effective_from DESC, id DESC LIMIT 1) AS current_pct
    FROM partner_sub_accounts s
    JOIN partners p ON p.id = s.partner_id
    WHERE $whereSql
    ORDER BY
      CASE s.status
        WHEN 'PENDING'  THEN 1
        WHEN 'ACTIVE'   THEN 2
        WHEN 'INACTIVE' THEN 3
        WHEN 'REJECTED' THEN 4
        ELSE 5
      END,
      s.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Status counts for summary cards
$counts = db()->query("
    SELECT
      SUM(status = 'PENDING')  AS pending,
      SUM(status = 'ACTIVE')   AS active,
      SUM(status = 'INACTIVE') AS inactive,
      SUM(status = 'REJECTED') AS rejected,
      COUNT(*)                 AS total
    FROM partner_sub_accounts
")->fetch();

// Count records waiting for backfill
$backfillPending = (int) db()->query("
    SELECT COUNT(*)
    FROM partner_enrolment_records r
    JOIN partner_sub_accounts s
         ON s.nin_number = r.partner_nin_raw AND s.status = 'ACTIVE'
    WHERE (r.sub_account_id IS NULL OR r.sub_account_id <> s.id)
      AND (r.partner_id IS NULL OR r.partner_id = s.partner_id)
      AND r.commission_amount > 0
")->fetchColumn();


// Partner list for the filter dropdown
$partners = db()->query("SELECT id, full_name, partner_code FROM partners ORDER BY full_name")->fetchAll();

require PICT_ROOT . '/includes/header-superadmin.php';

$badge = function (string $s): string {
    $cls = match ($s) {
        'ACTIVE'   => 'badge--active',
        'PENDING'  => 'badge--pending',
        'INACTIVE' => 'badge--inactive',
        'REJECTED' => 'badge--rejected',
        default    => 'badge--inactive',
    };
    return '<span class="badge ' . $cls . '">' . e($s) . '</span>';
};
?>

<div class="page-head">
  <div>
    <h1>Sub-Accounts</h1>
    <p><?= number_format($total) ?> sub-account<?= $total === 1 ? '' : 's' ?> matching your filter.</p>
  </div>
    <div class="page-head__actions">
    <?php if ($backfillPending > 0): ?>
      <a class="btn btn-warn" href="<?= e(root_url('/superadmin/sub-account-backfill.php')) ?>">
        Backfill (<?= number_format($backfillPending) ?>)
      </a>
    <?php else: ?>
      <a class="btn btn-outline" href="<?= e(root_url('/superadmin/sub-account-backfill.php')) ?>">
        Backfill Historical
      </a>
    <?php endif; ?>
    <?php if ((int) $counts['pending'] > 0): ?>
      <a class="btn btn-grape" href="?status=PENDING">Review <?= number_format((int) $counts['pending']) ?> Pending</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($backfillPending > 0): ?>
  <div class="alert alert-warn" style="display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap">
    <div>
      <strong><?= number_format($backfillPending) ?></strong>
      enrolment record<?= $backfillPending === 1 ? '' : 's' ?>
      can be attached to active sub-accounts but <?= $backfillPending === 1 ? 'has' : 'have' ?> not been yet.
    </div>
    <a class="btn btn-warn btn-sm" href="<?= e(root_url('/superadmin/sub-account-backfill.php')) ?>">
      Review &amp; Run Backfill
    </a>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat stat--pending">
    <div class="stat__label">Pending</div>
    <div class="stat__value"><?= number_format((int) $counts['pending']) ?></div>
  </div>
  <div class="stat stat--ok">
    <div class="stat__label">Active</div>
    <div class="stat__value"><?= number_format((int) $counts['active']) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Inactive</div>
    <div class="stat__value"><?= number_format((int) $counts['inactive']) ?></div>
  </div>
  <div class="stat stat--err">
    <div class="stat__label">Rejected</div>
    <div class="stat__value"><?= number_format((int) $counts['rejected']) ?></div>
  </div>
  <div class="stat">
    <div class="stat__label">Total</div>
    <div class="stat__value"><?= number_format((int) $counts['total']) ?></div>
  </div>
</div>

<div class="filter-bar">
  <form method="get">
    <div class="form-group">
      <label class="form-label">Search</label>
      <input class="form-control" type="text" name="q" value="<?= e($fSearch) ?>" placeholder="Name, NIN, email or partner">
    </div>
    <div class="form-group">
      <label class="form-label">Status</label>
      <select class="form-control" name="status">
        <option value="">All statuses</option>
        <?php foreach (['PENDING','ACTIVE','INACTIVE','REJECTED'] as $s): ?>
          <option value="<?= $s ?>"<?= $fStatus === $s ? ' selected' : '' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Parent Partner</label>
      <select class="form-control" name="partner">
        <option value="">All partners</option>
        <?php foreach ($partners as $p): ?>
          <option value="<?= (int) $p['id'] ?>"<?= $fPartner === (int) $p['id'] ? ' selected' : '' ?>>
            <?= e($p['full_name']) ?> (<?= e($p['partner_code']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <button class="btn btn-grape" type="submit">Filter</button>
      <a class="btn btn-outline" href="<?= e(root_url('/superadmin/sub-accounts.php')) ?>">Reset</a>
    </div>
  </form>
</div>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="empty">
      <h3>No sub-accounts found</h3>
      <p>Try adjusting your filters.</p>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr>
            <th>Sub-Account</th>
            <th>NIN</th>
            <th>Parent Partner</th>
            <th class="num">Rate</th>
            <th>Status</th>
            <th>Submitted</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <strong><?= e($r['full_name']) ?></strong>
                <?php if (!empty($r['email'])): ?>
                  <br><span class="muted" style="font-size:12px"><?= e($r['email']) ?></span>
                <?php endif; ?>
              </td>
                            <td>
                <span id="sub-nin-<?= (int) $r['id'] ?>"
                      class="masked"
                      data-full="<?= e($r['nin_number']) ?>"
                      data-masked="<?= e(mask_nin($r['nin_number'])) ?>">
                  <?= e(mask_nin($r['nin_number'])) ?>
                </span>
                <button type="button"
                        class="btn btn-outline btn-sm"
                        style="padding:2px 8px;font-size:11px;margin-left:6px"
                        id="sub-nin-btn-<?= (int) $r['id'] ?>"
                        onclick="pictReveal('sub-nin-<?= (int) $r['id'] ?>', 'sub-nin-btn-<?= (int) $r['id'] ?>', 'sub_account_nin', <?= (int) $r['id'] ?>)">
                  Reveal
                </button>
              </td>
              <td>
                <a href="<?= e(root_url('/superadmin/partner-view.php')) ?>?id=<?= (int) $r['partner_id'] ?>">
                  <?= e($r['partner_name']) ?>
                </a>
                <br><span class="muted" style="font-size:12px"><?= e($r['partner_code']) ?></span>
              </td>
              <td class="num">
                <?= $r['current_pct'] !== null
                    ? e(number_format((float) $r['current_pct'], 2)) . '%'
                    : '<span class="muted">—</span>' ?>
              </td>
              <td><?= $badge((string) $r['status']) ?></td>
              <td><?= e(date('d M Y', strtotime($r['created_at']))) ?></td>
              <td class="text-right">
                <a class="btn btn-outline btn-sm"
                   href="<?= e(root_url('/superadmin/sub-account-view.php')) ?>?id=<?= (int) $r['id'] ?>">
                  <?= $r['status'] === 'PENDING' ? 'Review' : 'View' ?>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($pages > 1): ?>
      <ul class="pagination">
        <?php
        $qs = http_build_query(['status' => $fStatus, 'partner' => $fPartner, 'q' => $fSearch]);
        ?>
        <?php if ($page > 1): ?><li><a href="?<?= e($qs) ?>&page=<?= $page - 1 ?>">Previous</a></li><?php endif; ?>
        <?php for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++): ?>
          <li><?= $i === $page ? '<span class="active">' . $i . '</span>' : '<a href="?' . e($qs) . '&page=' . $i . '">' . $i . '</a>' ?></li>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><li><a href="?<?= e($qs) ?>&page=<?= $page + 1 ?>">Next</a></li><?php endif; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>