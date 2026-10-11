<?php
require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';
$partner = partner_require_login();

$page_title = 'My Profile';
$active_nav = 'profile';
$pid = (int) $partner['id'];

$errors = [];

if (is_post()) {
    csrf_require();
    $phone   = trim((string) ($_POST['phone'] ?? ''));
    $addr    = trim((string) ($_POST['address'] ?? ''));
    $bizName = trim((string) ($_POST['business_name'] ?? ''));

    $errors = validate([
        'phone' => $phone, 'address' => $addr, 'business_name' => $bizName,
    ], [
        'phone' => ['required', 'phone_ng', 'max_len:20'],
        'address' => ['max_len:255'],
        'business_name' => ['max_len:190'],
    ], ['phone' => 'Phone Number']);

    if ($partner['partner_type'] === 'BUSINESS' && $bizName === '') {
        $errors['business_name'] = 'Business Name is required for business accounts.';
    }

    if (!$errors) {
        $stmt = db()->prepare('
            UPDATE partners SET phone = :ph, address = :ad, business_name = :bn, updated_at = :ua
            WHERE id = :id
        ');
        $stmt->execute([
            ':ph' => $phone,
            ':ad' => $addr !== '' ? $addr : null,
            ':bn' => $bizName !== '' ? $bizName : null,
            ':ua' => now_dt(),
            ':id' => $pid,
        ]);
        audit_log('PARTNER_PROFILE_UPDATE', 'partners', $pid, 'Profile updated.', null, null,
            ['role' => 'PARTNER', 'id' => $pid, 'label' => (string) $partner['full_name']]);
        flash('success', 'Profile updated.');
        redirect('/partner/profile.php');
    }
    flash('error', reset($errors));
}

// Refresh partner for display
$stmt = db()->prepare("SELECT p.*, s.name AS state_name, l.name AS lga_name FROM partners p LEFT JOIN states s ON s.id = p.state_id LEFT JOIN lgas l ON l.id = p.lga_id WHERE p.id = :id");
$stmt->execute([':id' => $pid]);
$p = $stmt->fetch();

require PICT_ROOT . '/includes/header-partner.php';
?>

<div class="page-head">
  <div>
    <h1>My Profile</h1>
    <p>Some fields are managed by the Super Admin.</p>
  </div>
</div>

<div class="card card--pad-lg" style="max-width:720px">
  <h2>Editable Details</h2>
  <form method="post" novalidate>
    <?= csrf_field() ?>
    <div class="form-row">
      <div class="form-group">
        <label class="form-label" for="phone">Phone Number <span class="req">*</span></label>
        <input class="form-control" type="tel" id="phone" name="phone" value="<?= e($p['phone']) ?>" maxlength="20" required>
        <?php if (!empty($errors['phone'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['phone']) ?></div><?php endif; ?>
      </div>
      <div class="form-group">
        <label class="form-label" for="partner_code">Partner Code</label>
        <input class="form-control" type="text" value="<?= e($p['partner_code']) ?>" readonly>
      </div>
    </div>
    <?php if ($p['partner_type'] === 'BUSINESS'): ?>
      <div class="form-group">
        <label class="form-label" for="business_name">Business Name <span class="req">*</span></label>
        <input class="form-control" type="text" id="business_name" name="business_name" value="<?= e((string) $p['business_name']) ?>" maxlength="190">
        <?php if (!empty($errors['business_name'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['business_name']) ?></div><?php endif; ?>
      </div>
    <?php endif; ?>
    <div class="form-group">
      <label class="form-label" for="address">Service Address</label>
      <input class="form-control" type="text" id="address" name="address" value="<?= e((string) $p['address']) ?>" maxlength="255">
    </div>
    <button class="btn btn-primary" type="submit">Save Changes</button>
    <a class="btn btn-outline" href="change-password.php" style="margin-left:8px">Change Password</a>
  </form>
</div>

<div class="card card--pad-lg mt-3" style="max-width:720px">
  <h2>Account Information</h2>
  <table class="tbl tbl--compact">
    <tr><th style="width:200px">Full Name</th><td><?= e($p['full_name']) ?></td></tr>
    <tr><th>Email</th><td><?= e($p['email']) ?></td></tr>
    <tr><th>NIN</th><td><span class="masked"><?= e(mask_nin($p['nin_number'])) ?></span></td></tr>
    <tr><th>Partner Type</th><td><?= e($p['partner_type']) ?></td></tr>
    <tr><th>State</th><td><?= e((string) $p['state_name']) ?></td></tr>
       <tr><th>LGA</th><td><?= $p['lga_name'] !== null && $p['lga_name'] !== '' ? e($p['lga_name']) : '<span class="muted">—</span>' ?></td></tr>
    <tr><th>Status</th><td><span class="badge badge--<?= strtolower($p['status']) ?>"><?= e($p['status']) ?></span></td></tr>
    <tr><th>Joined</th><td><?= e(date('d M Y', strtotime($p['created_at']))) ?></td></tr>
    <?php if ($p['approved_at']): ?>
      <tr><th>Approved</th><td><?= e(date('d M Y', strtotime($p['approved_at']))) ?></td></tr>
    <?php endif; ?>
  </table>
</div>

<?php require PICT_ROOT . '/includes/footer-partner.php'; ?>