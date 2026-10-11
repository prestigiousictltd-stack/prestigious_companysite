<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
require_once PICT_ROOT . '/includes/financial.php';
$sa = superadmin_require_login();

$page_title = 'Financial Ledger';
$active_nav = 'ledger';
$errors = [];

if (is_post()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'adjust') {
        $pid = (int) ($_POST['partner_id'] ?? 0);
        $amount = trim((string) ($_POST['amount'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));
        $errors = validate(['partner_id' => $pid, 'amount' => $amount, 'description' => $desc],
            ['partner_id' => ['required','int'], 'amount' => ['required','decimal'], 'description' => ['required','string','min:3','max:500']]);
        if (!$errors) {
            $amt = round((float) $amount, 2);
            if ($amt == 0.0) { $errors['amount'] = 'Amount cannot be zero.'; }
        }
        if (!$errors) {
            db()->beginTransaction();
            try {
                $lock = db()->prepare("SELECT id FROM partners WHERE id = :pid FOR UPDATE");
                $lock->execute([':pid' => $pid]);
                if (!$lock->fetchColumn()) throw new RuntimeException('Partner missing.');
                $type = ((float) $amount >= 0) ? 'ADJUSTMENT' : 'ADJUSTMENT';
                ledger_record($pid, 'ADJUSTMENT', (float) $amount, 'MANUAL', $desc, [], [], 'SUPER_ADMIN', (int) $sa['id']);
                db()->commit();
                audit_log('FINANCIAL_ADJUSTMENT', 'partners', $pid, 'Adjustment ' . format_money((float) $amount) . ': ' . $desc);
                flash('success', 'Ledger adjustment recorded.');
                redirect('/superadmin/ledger.php');
            } catch (Throwable $e) {
                db()->rollBack();
                $errors['_global'] = 'Adjustment failed: ' . $e->getMessage();
            }
        }
    }
}

$fPartner = (int) ($_GET['partner'] ?? 0);
$fType = (string) ($_GET['type'] ?? '');

$where = ['1=1']; $params = [];
if ($fPartner > 0) { $where[] = 't.partner_id = :pid'; $params[':pid'] = $fPartner; }
if ($fType !== '') { $where[] = 't.type = :tp'; $params[':tp'] = $fType; }
$whereSql = implode(' AND ', $where);

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 40; $offset = ($page - 1) * $perPage;

$c = db()->prepare("SELECT COUNT(*) FROM partner_financial_transactions t WHERE $whereSql");
$c->execute($params);
$total = (int) $c->fetchColumn();
$pages = max(1, (int) ceil($total / $perPage));

$stmt = db()->prepare("
    SELECT t.*, p.full_name, p.partner_code
    FROM partner_financial_transactions t
    JOIN partners p ON p.id = t.partner_id
    WHERE $whereSql
    ORDER BY t.created_at DESC, t.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$partners = db()->query("SELECT id, partner_code, full_name FROM partners ORDER BY full_name")->fetchAll();

require PICT_ROOT . '/includes/header-superadmin.php';
?>
<div class="page-head"><div><h1>Financial Ledger</h1><p>Append-only. Balances are derived from this table — never edited directly.</p></div></div>

<?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="card mb-3">
  <h2>Record Adjustment</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="adjust">
    <div class="form-row--3 form-row">
      <div class="form-group"><label class="form-label">Partner</label>
        <select class="form-control" name="partner_id" required>
          <option value="">— Select —</option>
          <?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['full_name']) ?> (<?= e($p['partner_code']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Amount (signed)</label>
        <input class="form-control" type="number" step="0.01" name="amount" required placeholder="e.g. 5000.00 or -2500.00">
      </div>
      <div class="form-group"><label class="form-label">Description</label>
        <input class="form-control" type="text" name="description" maxlength="500" required>
      </div>
    </div>
    <button class="btn btn-grape" type="submit" onclick="return confirm('Record this adjustment? This is audited.');">Record Adjustment</button>
  </form>
</div>

<div class="filter-bar">
  <form method="get">
    <div class="form-group"><label class="form-label">Partner</label>
      <select class="form-control" name="partner"><option value="">All</option>
        <?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $fPartner === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['full_name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><label class="form-label">Type</label>
      <select class="form-control" name="type"><option value="">All</option>
        <?php foreach (['COMMISSION_EARNED','WITHDRAWAL_RESERVED','WITHDRAWAL_PAID','WITHDRAWAL_REVERSED','ADJUSTMENT','REVERSAL'] as $t): ?>
          <option value="<?= $t ?>"<?= $fType === $t ? ' selected' : '' ?>><?= $t ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group"><button class="btn btn-grape" type="submit">Filter</button>
      <a class="btn btn-outline" href="/superadmin/ledger.php">Reset</a></div>
  </form>
</div>

<div class="card">
  <?php if (empty($rows)): ?><div class="empty"><p>No transactions.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl tbl--compact">
        <thead><tr>
          <th>Date</th><th>Transaction ID</th><th>Partner</th><th>Type</th>
          <th>Reference</th><th class="num">Amount</th>
          <th class="num">Prev</th><th class="num">New</th>
          <th>By</th>
        </tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
              <td><span class="masked"><?= e($r['transaction_id']) ?></span></td>
              <td><?= e($r['full_name']) ?><br><span class="muted" style="font-size:11.5px"><?= e($r['partner_code']) ?></span></td>
              <td><span class="badge badge--inactive"><?= e(str_replace('_',' ', $r['type'])) ?></span></td>
              <td><span class="masked"><?= e((string) $r['reference']) ?></span></td>
              <td class="num"><?= e(format_money((float) $r['amount'])) ?></td>
              <td class="num muted"><?= e(format_money((float) $r['previous_balance'])) ?></td>
              <td class="num"><strong><?= e(format_money((float) $r['new_balance'])) ?></strong></td>
              <td><?= e($r['created_by_role']) ?> <?= $r['created_by_id'] ? '#' . (int) $r['created_by_id'] : '' ?></td>
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