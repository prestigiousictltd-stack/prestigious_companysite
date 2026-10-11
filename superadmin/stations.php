<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$page_title = 'Stations';
$active_nav = 'stations';
$errors = [];

if (is_post()) {
    csrf_require();
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $data = [
            'station_code' => trim((string) ($_POST['station_code'] ?? '')),
            'station_name' => trim((string) ($_POST['station_name'] ?? '')),
            'partner_id'   => (int) ($_POST['partner_id'] ?? 0),
            'state_id'     => (int) ($_POST['state_id'] ?? 0),
            'lga_id'       => (int) ($_POST['lga_id'] ?? 0),
            'address'      => trim((string) ($_POST['address'] ?? '')),
        ];
        $errors = validate($data, [
            'station_code' => ['required', 'string', 'max:40'],
            'station_name' => ['required', 'string', 'max:150'],
            'partner_id'   => ['required', 'int'],
            'state_id'     => ['required', 'int'],
            'lga_id'       => ['required', 'int'],
        ]);
        if (!$errors) {
            try {
                $stmt = db()->prepare("INSERT INTO stations (station_code, station_name, partner_id, state_id, lga_id, address, status, created_at) VALUES (:c,:n,:p,:s,:l,:a,'ACTIVE',:ca)");
                $stmt->execute([':c' => $data['station_code'], ':n' => $data['station_name'], ':p' => $data['partner_id'], ':s' => $data['state_id'], ':l' => $data['lga_id'], ':a' => $data['address'] !== '' ? $data['address'] : null, ':ca' => now_dt()]);
                $sid = (int) db()->lastInsertId();
                audit_log('STATION_CREATED', 'stations', $sid, $data['station_code']);
                flash('success', 'Station created.');
                redirect('/superadmin/stations.php');
            } catch (Throwable $e) {
                $errors['_global'] = 'Could not create station (code may already exist).';
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare("SELECT status FROM stations WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $cur = (string) $stmt->fetchColumn();
        $new = $cur === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
        db()->prepare("UPDATE stations SET status = :s, updated_at = :ua WHERE id = :id")->execute([':s' => $new, ':ua' => now_dt(), ':id' => $id]);
        flash('success', 'Station status updated.');
        redirect('/superadmin/stations.php');
    }
}

$stations = db()->query("
    SELECT s.*, p.full_name AS partner_name, st.name AS state_name, l.name AS lga_name
    FROM stations s
    JOIN partners p ON p.id = s.partner_id
    LEFT JOIN states st ON st.id = s.state_id
    LEFT JOIN lgas l ON l.id = s.lga_id
    ORDER BY s.created_at DESC LIMIT 500
")->fetchAll();
$partners = db()->query("SELECT id, partner_code, full_name FROM partners WHERE status='ACTIVE' ORDER BY full_name")->fetchAll();
$states = db()->query("SELECT id, name FROM states WHERE is_active=1 ORDER BY name")->fetchAll();

require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head"><div><h1>Stations</h1><p>Service points operated by partners.</p></div></div>

<?php if (!empty($errors['_global'])): ?><div class="alert alert-danger"><?= e($errors['_global']) ?></div><?php endif; ?>
<?php if (!empty($errors)): $first = reset($errors); if ($first !== $errors['_global'] ?? null): ?>
  <div class="alert alert-danger"><?= e($first) ?></div>
<?php endif; endif; ?>

<div class="card mb-3">
  <h2>Create Station</h2>
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="create">
    <div class="form-row">
      <div class="form-group"><label class="form-label">Station Code</label><input class="form-control" type="text" name="station_code" required maxlength="40" placeholder="e.g. ST-KN-001"></div>
      <div class="form-group"><label class="form-label">Station Name</label><input class="form-control" type="text" name="station_name" required maxlength="150"></div>
    </div>
    <div class="form-group"><label class="form-label">Partner</label>
      <select class="form-control" name="partner_id" required>
        <option value="">— Select Partner —</option>
        <?php foreach ($partners as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['full_name']) ?> (<?= e($p['partner_code']) ?>)</option><?php endforeach; ?>
      </select>
    </div>
    <div class="form-row">
      <div class="form-group"><label class="form-label">State</label>
        <select class="form-control" id="state_id" name="state_id" required>
          <option value="">— Select —</option>
          <?php foreach ($states as $s): ?><option value="<?= (int) $s['id'] ?>"><?= e($s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label class="form-label">LGA</label>
        <select class="form-control" id="lga_id" name="lga_id" required><option value="">— Select State first —</option></select>
      </div>
    </div>
    <div class="form-group"><label class="form-label">Address</label><input class="form-control" type="text" name="address" maxlength="255"></div>
    <button class="btn btn-grape" type="submit">Create Station</button>
  </form>
</div>

<div class="card">
  <h2>All Stations</h2>
  <?php if (empty($stations)): ?><div class="empty"><p>No stations yet.</p></div>
  <?php else: ?>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead><tr><th>Code</th><th>Name</th><th>Partner</th><th>Location</th><th>Status</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($stations as $s): ?>
            <tr>
              <td><span class="masked"><?= e($s['station_code']) ?></span></td>
              <td><?= e($s['station_name']) ?></td>
              <td><?= e($s['partner_name']) ?></td>
              <td><?= e((string) $s['state_name']) ?> / <?= e((string) $s['lga_name']) ?></td>
              <td><span class="badge badge--<?= strtolower($s['status']) ?>"><?= e($s['status']) ?></span></td>
              <td class="text-right">
                <form method="post" style="display:inline">
                  <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                  <button class="btn btn-outline btn-sm" type="submit"><?= $s['status'] === 'ACTIVE' ? 'Deactivate' : 'Activate' ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  var s=document.getElementById('state_id'), l=document.getElementById('lga_id');
  s.addEventListener('change', function(){
    if(!s.value){ l.innerHTML='<option value="">— Select State first —</option>'; return; }
    l.innerHTML='<option value="">Loading…</option>';
    fetch('/api/lgas.php?state_id='+encodeURIComponent(s.value)).then(r=>r.json()).then(function(d){
      var h='<option value="">— Select LGA —</option>';
      if(d.ok) d.lgas.forEach(x=>{ h+='<option value="'+x.id+'">'+x.name.replace(/</g,'&lt;')+'</option>'; });
      l.innerHTML=h;
    });
  });
})();
</script>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>