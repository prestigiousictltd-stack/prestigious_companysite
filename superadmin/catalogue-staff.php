<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();
$page_title = 'Staff Catalogue'; $active_nav = 'catalogue-staff';

if (is_post()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $n = trim((string) ($_POST['display_name'] ?? ''));
        $c = trim((string) ($_POST['staff_code'] ?? ''));
        if ($n !== '') {
            try {
                $stmt = db()->prepare("INSERT INTO staff (display_name, staff_code, is_active, created_at) VALUES (:n, :c, 1, :ca)");
                $stmt->execute([':n' => $n, ':c' => $c ?: null, ':ca' => now_dt()]);
                flash('success', 'Staff member added.');
            } catch (Throwable $e) { flash('error', 'Name already exists.'); }
        }
        redirect('/superadmin/catalogue-staff.php');
    }
    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare("UPDATE staff SET is_active = 1 - is_active, updated_at = :ua WHERE id = :id")->execute([':ua' => now_dt(), ':id' => $id]);
        redirect('/superadmin/catalogue-staff.php');
    }
}

$rows = db()->query("SELECT * FROM staff ORDER BY display_name")->fetchAll();
require PICT_ROOT . '/includes/header-superadmin.php';
?>
<div class="page-head"><div><h1>Staff Catalogue</h1><p>Names used in User Performance and Accounts Receivable sections.</p></div></div>

<div class="card mb-3">
  <h2>Add Staff</h2>
  <form method="post" class="form-row--3 form-row">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="form-group"><label class="form-label">Display Name</label><input class="form-control" type="text" name="display_name" required maxlength="120"></div>
    <div class="form-group"><label class="form-label">Staff Code</label><input class="form-control" type="text" name="staff_code" maxlength="40"></div>
    <div class="form-group"><button class="btn btn-grape" type="submit" style="margin-top:24px">Add</button></div>
  </form>
</div>

<div class="card">
  <h2>All Staff</h2>
  <table class="tbl">
    <thead><tr><th>Name</th><th>Code</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e($r['display_name']) ?></strong></td>
          <td><?= e((string) $r['staff_code']) ?: '<span class="muted">—</span>' ?></td>
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