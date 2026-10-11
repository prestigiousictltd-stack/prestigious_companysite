<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();
$page_title = 'Service Catalogue'; $active_nav = 'catalogue-services';

if (is_post()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $name = trim((string) ($_POST['service_name'] ?? ''));
        $code = trim((string) ($_POST['service_code'] ?? ''));
        $price = trim((string) ($_POST['default_unit_price'] ?? ''));
        if ($name !== '') {
            try {
                $stmt = db()->prepare("INSERT INTO services (service_name, service_code, default_unit_price, is_active, created_at) VALUES (:n,:c,:p,1,:ca)");
                $stmt->execute([':n' => $name, ':c' => $code ?: null, ':p' => $price !== '' ? (float) $price : null, ':ca' => now_dt()]);
                flash('success', 'Service added.');
            } catch (Throwable $e) { flash('error', 'Service name already exists.'); }
        }
        redirect('/superadmin/catalogue-services.php');
    }
    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        db()->prepare("UPDATE services SET is_active = 1 - is_active, updated_at = :ua WHERE id = :id")->execute([':ua' => now_dt(), ':id' => $id]);
        redirect('/superadmin/catalogue-services.php');
    }
}

$rows = db()->query("SELECT * FROM services ORDER BY service_name")->fetchAll();
require PICT_ROOT . '/includes/header-superadmin.php';
?>
<div class="page-head"><div><h1>Service Catalogue</h1><p>Drives the Services Rendered Out and Accounts Receivable fields in the Daily Report.</p></div></div>

<div class="card mb-3">
  <h2>Add Service</h2>
  <form method="post" class="form-row--3 form-row">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="form-group"><label class="form-label">Service Name</label><input class="form-control" type="text" name="service_name" required maxlength="150"></div>
    <div class="form-group"><label class="form-label">Code</label><input class="form-control" type="text" name="service_code" maxlength="40"></div>
    <div class="form-group"><label class="form-label">Default Price</label><input class="form-control" type="number" step="0.01" name="default_unit_price"></div>
    <div class="form-group"><button class="btn btn-grape" type="submit">Add</button></div>
  </form>
</div>

<div class="card">
  <h2>All Services</h2>
  <div style="overflow-x:auto">
    <table class="tbl">
      <thead><tr><th>Name</th><th>Code</th><th class="num">Default Price</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($rows as $s): ?>
          <tr>
            <td><strong><?= e($s['service_name']) ?></strong></td>
            <td><?= e((string) $s['service_code']) ?: '<span class="muted">—</span>' ?></td>
            <td class="num"><?= $s['default_unit_price'] !== null ? e(format_money((float) $s['default_unit_price'])) : '<span class="muted">—</span>' ?></td>
            <td><span class="badge badge--<?= $s['is_active'] ? 'active' : 'inactive' ?>"><?= $s['is_active'] ? 'Active' : 'Inactive' ?></span></td>
            <td class="text-right">
              <form method="post" style="display:inline">
                <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <button class="btn btn-outline btn-sm" type="submit"><?= $s['is_active'] ? 'Deactivate' : 'Activate' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>