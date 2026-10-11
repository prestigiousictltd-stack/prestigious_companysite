<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/superadmin-auth.php';
$sa = superadmin_require_login();

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT * FROM partners WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $id]);
$p = $stmt->fetch();
if (!$p) { flash('error', 'Partner not found.'); redirect('/superadmin/partners.php'); }

$page_title = 'Edit Partner';
$active_nav = 'partners';
$states = db()->query("SELECT id, name FROM states WHERE is_active=1 ORDER BY name")->fetchAll();
$errors = [];

if (is_post()) {
    csrf_require();
    $data = [
        'full_name' => trim((string) ($_POST['full_name'] ?? '')),
        'email'     => strtolower(trim((string) ($_POST['email'] ?? ''))),
        'phone'     => trim((string) ($_POST['phone'] ?? '')),
        'partner_type' => strtoupper((string) ($_POST['partner_type'] ?? 'INDIVIDUAL')),
        'business_name' => trim((string) ($_POST['business_name'] ?? '')),
        'address'   => trim((string) ($_POST['address'] ?? '')),
        'state_id'  => (int) ($_POST['state_id'] ?? 0),
        'lga_id'    => (int) ($_POST['lga_id'] ?? 0),
    ];

    $errors = validate($data, [
        'full_name' => ['required', 'string', 'min_len:2', 'max_len:150'],
        'email'     => ['required', 'email', 'max_len:190'],
        'phone'     => ['required', 'phone_ng', 'max_len:20'],
        'state_id'  => ['required', 'int'],
        'lga_id'    => [ 'int'],
    ]);

        if (!$errors && (int) $data['lga_id'] > 0) {
        $chk = db()->prepare("SELECT 1 FROM lgas WHERE id=:lid AND state_id=:sid");
        $chk->execute([':lid' => $data['lga_id'], ':sid' => $data['state_id']]);
        if (!$chk->fetchColumn()) { $errors['lga_id'] = 'LGA does not belong to the selected state.'; }
    }

    if (!$errors) {
        $chk = db()->prepare("SELECT 1 FROM partners WHERE email = :e AND id <> :id");
        $chk->execute([':e' => $data['email'], ':id' => $id]);
        if ($chk->fetchColumn()) { $errors['email'] = 'Email already used by another partner.'; }
    }

    if (!$errors) {
        $stmt = db()->prepare("
            UPDATE partners SET
                full_name = :name, email = :email, phone = :phone,
                partner_type = :ptype, business_name = :bname, address = :addr,
                state_id = :sid, lga_id = :lid, updated_at = :ua
            WHERE id = :id
        ");
               $stmt->execute([
            ':name' => $data['full_name'], ':email' => $data['email'], ':phone' => $data['phone'],
            ':ptype' => $data['partner_type'], ':bname' => $data['business_name'] !== '' ? $data['business_name'] : null,
            ':addr' => $data['address'] !== '' ? $data['address'] : null,
            ':sid' => $data['state_id'],
            ':lid' => (int) $data['lga_id'] > 0 ? (int) $data['lga_id'] : null,
            ':ua' => now_dt(), ':id' => $id,
        ]);
        audit_log('PARTNER_EDIT', 'partners', $id, 'Partner details edited.',
            ['full_name' => $p['full_name'], 'email' => $p['email']],
            ['full_name' => $data['full_name'], 'email' => $data['email']]);
        flash('success', 'Partner updated.');
        redirect('/superadmin/partner-view.php?id=' . $id);
    }
}

require PICT_ROOT . '/includes/header-superadmin.php';
?>

<div class="page-head">
  <div><h1>Edit Partner</h1><p><?= e($p['full_name']) ?> &middot; <?= e($p['partner_code']) ?></p></div>
  <div class="page-head__actions"><a class="btn btn-outline" href="./partner-view.php?id=<?= $id ?>">Cancel</a></div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card card--pad-lg" style="max-width:820px">
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="form-row">
      <div class="form-group"><label class="form-label">Full Name</label>
        <input class="form-control" type="text" name="full_name" value="<?= e((string) ($_POST['full_name'] ?? $p['full_name'])) ?>" required maxlength="150"></div>
      <div class="form-group"><label class="form-label">Phone</label>
        <input class="form-control" type="tel" name="phone" value="<?= e((string) ($_POST['phone'] ?? $p['phone'])) ?>" required maxlength="20"></div>
    </div>
    <div class="form-group"><label class="form-label">Email</label>
      <input class="form-control" type="email" name="email" value="<?= e((string) ($_POST['email'] ?? $p['email'])) ?>" required maxlength="190"></div>

    <div class="form-row">
      <div class="form-group"><label class="form-label">Partner Type</label>
        <select class="form-control" name="partner_type">
          <option value="INDIVIDUAL"<?= ($p['partner_type'] === 'INDIVIDUAL') ? ' selected' : '' ?>>Individual</option>
          <option value="BUSINESS"<?= ($p['partner_type'] === 'BUSINESS') ? ' selected' : '' ?>>Business</option>
        </select>
      </div>
      <div class="form-group"><label class="form-label">Business Name</label>
        <input class="form-control" type="text" name="business_name" value="<?= e((string) ($_POST['business_name'] ?? $p['business_name'])) ?>" maxlength="190"></div>
    </div>

    <div class="form-group"><label class="form-label">Address</label>
      <input class="form-control" type="text" name="address" value="<?= e((string) ($_POST['address'] ?? $p['address'])) ?>" maxlength="255"></div>

    <div class="form-row">
      <div class="form-group"><label class="form-label">State</label>
        <select class="form-control" name="state_id" id="state_id">
          <?php foreach ($states as $s): ?>
            <option value="<?= (int) $s['id'] ?>"<?= (int) $p['state_id'] === (int) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
            <div class="form-group">
        <label class="form-label">LGA <span class="muted" style="font-weight:400;font-size:12px">(optional)</span></label>
        <select class="form-control" name="lga_id" id="lga_id"
                data-selected="<?= (int) $p['lga_id'] > 0 ? (int) $p['lga_id'] : '' ?>">
          <option value="">— Select State first —</option>
        </select>
      </div>

    <button class="btn btn-grape" type="submit">Save Changes</button>
  </form>
</div>

<script>
(function(){
  var stateSel = document.getElementById('state_id');
  var lgaSel   = document.getElementById('lga_id');
  var lgasUrl  = <?= json_encode(root_url('/api/lgas.php')) ?>;

  function loadLgas(stateId, preselect) {
    if (!lgaSel) return;
    if (!stateId) {
      lgaSel.innerHTML = '<option value="">— Select State first —</option>';
      return;
    }
    lgaSel.innerHTML = '<option value="">Loading…</option>';
    fetch(lgasUrl + '?state_id=' + encodeURIComponent(stateId))
      .then(function(r){ return r.json(); })
      .then(function(d){
        if (!d.ok) { lgaSel.innerHTML = '<option value="">— Unavailable —</option>'; return; }
        var h = '<option value="">— Select LGA —</option>';
        d.lgas.forEach(function(l){
          var sel = (preselect && String(preselect) === String(l.id)) ? ' selected' : '';
          h += '<option value="' + l.id + '"' + sel + '>' + l.name.replace(/</g,'&lt;') + '</option>';
        });
        lgaSel.innerHTML = h;
      })
      .catch(function(){ lgaSel.innerHTML = '<option value="">— Load failed —</option>'; });
  }

  var preselect = lgaSel ? (lgaSel.getAttribute('data-selected') || '') : '';
  if (stateSel && lgaSel) {
    if (stateSel.value) loadLgas(stateSel.value, preselect);
    stateSel.addEventListener('change', function(){ loadLgas(stateSel.value, ''); });
  }
})();
</script>

<?php require PICT_ROOT . '/includes/footer-superadmin.php'; ?>