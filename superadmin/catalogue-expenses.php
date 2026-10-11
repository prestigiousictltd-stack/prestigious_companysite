<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();
$page_title = 'Expense Categories'; $active_nav = 'catalogue-expenses';

if (is_post()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $n = trim((string) ($_POST['category_name'] ?? ''));
        if ($n !== '') {
            try {
                $stmt = db()->prepare("INSERT INTO expense_categories (category_name, is_active, created_at) VALUES (:n, 1, :ca)");
                $stmt->execute([':n' => $n, ':ca' => now_dt()]);
                flash('success', 'Category added.');
            } catch (Throwable $e) { flash('error', 'Category already exists.'); }
        }
        redirect('/superadmin/catalogue-expenses.php');
    }
    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare("UPDATE expense_categories SET is_active = 1 - is_active, updated_at = :ua WHERE id = :id")->execute([':ua' => now_dt(), ':id' => $id]);
        redirect('/superadmin/catalogue-expenses.php');
    }
}

$rows = db()->query("SELECT * FROM expense_categories ORDER BY category_name")->fetchAll();
require PICT_ROOT . '/includes/header-superadmin.php';
?>
<div class="page-head"><div><h1>Expense Categories</h1><p>Used in the Daily Report Expenses Breakdown section.</p></div></div>

<div class="card mb-3">
  <h2>Add Category</h2>
  <form method="post" class="form-row">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="form-group"><label class="form-label">Category Name</label><input class="form-control" type="text" name="category_name" required maxlength="120"></div>
    <div class="form-group"><button class="btn btn-grape" type="submit" style="margin-top:24px">Add</button></div>
  </form>
</div>

<div class="card">
  <h2>All Categories</h2>
  <table class="tbl">
    <thead><tr><th>Name</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['category_name']) ?></strong></td>
          <td><span class="badge badge--<?= $r['is_active'] ? 'active' : 'inactive' ?>"><?= $r['is_active'] ? 'Active' : 'Inactive' ?></span></td>
          <td class="text-right">
            <form method="post" style="display:inline">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-outline btn-sm" type="submit"><?= $r['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>