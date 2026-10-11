<?php
/**
 * Partner's own sub-account list.
 * Shows every sub-account belonging to the authenticated partner,
 * grouped by status, with a per-bucket balance.
 */
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';
require_once PICT_ROOT . '/includes/sub-account.php';
$partner = partner_require_login();

$page_title = 'Sub-Accounts';
$active_nav = 'sub-accounts';
$pid = (int) $partner['id'];

// Load the sub-accounts
$stmt = db()->prepare("
    SELECT * FROM partner_sub_accounts
    WHERE partner_id = :pid
    ORDER BY
      CASE status
        WHEN 'PENDING'  THEN 1
        WHEN 'ACTIVE'   THEN 2
        WHEN 'INACTIVE' THEN 3
        WHEN 'REJECTED' THEN 4
        ELSE 5
      END,
      full_name ASC
");
$stmt->execute([':pid' => $pid]);
$rows = $stmt->fetchAll();

// Aggregate stats for the summary cards
$stats = partner_aggregate_bucket_stats($pid);

require PICT_ROOT . '/includes/header-partner.php';

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
    <p>
      Individuals or devices whose NIN appears on the CSV uploads and earns commission under your account.
      Each sub-account has its own rate and its own balance.
    </p>
  </div>
  <div class="page-head__actions">
    <a class="btn btn-primary" href="<?= e(root_url('/partner/sub-account-add.php')) ?>">+ Add Sub-Account</a>
  </div>
</div>

<div class="stat-grid">
  <div class="stat stat--purple">
    <div class="stat__label">Total Combined Balance</div>
    <div class="stat__value"><?= e(format_money($stats['total_balance'])) ?></div>
    <div class="stat__sub">Own NIN + all sub-accounts</div>
  </div>
  <div class="stat">
    <div class="stat__label">Own NIN Balance</div>
    <div class="stat__value"><?= e(format_money($stats['own_balance'])) ?></div>
    <div class="stat__sub">Withdrawn as "My own balance"</div>
  </div>
  <div class="stat stat--ok">
    <div class="stat__label">Active Sub-Accounts</div>
    <div class="stat__value"><?= number_format((int) $stats['sub_count']) ?></div>
  </div>
  <div class="stat stat--pending">
    <div class="stat__label">Pending Approval</div>
    <div class="stat__value"><?= number_format((int) $stats['pending_count']) ?></div>
  </div>
</div>

<?php if ((int) $stats['pending_count'] > 0): ?>
  <div class="alert alert-info">
    <strong><?= number_format((int) $stats['pending_count']) ?> sub-account<?= (int) $stats['pending_count'] === 1 ? '' : 's' ?></strong>
    awaiting Super Admin approval. Commission from the NINs will start accruing once approved.
  </div>
<?php endif; ?>

<div class="card">
  <?php if (empty($rows)): ?>
    <div class="empty">
      <h3>No sub-accounts yet</h3>
      <p>Add a sub-account to give an individual or device the ability to earn under your account.</p>
      <a class="btn btn-primary" href="<?= e(root_url('/partner/sub-account-add.php')) ?>">+ Add Sub-Account</a>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead>
          <tr>
            <th>Name</th>
            <th>NIN</th>
            <th>Status</th>
            <th class="num">Balance</th>
            <th class="num">Total Earned</th>
            <th>Added</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
              $balance = sub_account_balance((int) $r['id']);
              $earned  = sub_account_total_commission((int) $r['id']);
          ?>
            <tr>
              <td><strong><?= e($r['full_name']) ?></strong></td>
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
                        onclick="pictReveal('sub-nin-<?= (int) $r['id'] ?>', 'sub-nin-btn-<?= (int) $r['id'] ?>')">
                  Reveal
                </button>
              </td>
              <td><?= $badge((string) $r['status']) ?></td>
              <td class="num"><?= e(format_money($balance)) ?></td>
              <td class="num"><?= e(format_money($earned)) ?></td>
              <td><?= e(date('d M Y', strtotime($r['created_at']))) ?></td>
              <td class="text-right">
                <a class="btn btn-outline btn-sm"
                   href="<?= e(root_url('/partner/sub-account-view.php')) ?>?id=<?= (int) $r['id'] ?>">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require PICT_ROOT . '/includes/footer-partner.php'; ?>