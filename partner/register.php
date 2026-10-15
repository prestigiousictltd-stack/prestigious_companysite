<?php
/**
 * Partner registration.
 * Publicly accessible. Uses the public header for continuity.
 */

require_once dirname(__DIR__) . '/bootstrap/init.php';
require_once PICT_ROOT . '/includes/partner-auth.php';

if (partner_logged_in()) {
    redirect('/partner/dashboard.php');
}

if (!setting_bool('partner_registration_open', true)) {
    flash('info', 'Partner registration is currently closed. Please check back later.');
    redirect('/partners.php');
}

$errors = [];
$submitted = false;

// Load states for the initial form render
$states = db()->query('SELECT id, name FROM states WHERE is_active = 1 ORDER BY name')->fetchAll();

if (is_post()) {
    csrf_require();

    $data = [
    'full_name'           => trim((string) ($_POST['full_name'] ?? '')),
    'email'               => strtolower(trim((string) ($_POST['email'] ?? ''))),
    'phone'               => trim((string) ($_POST['phone'] ?? '')),
    'nin_number'          => preg_replace('/\D/', '', (string) ($_POST['nin_number'] ?? '')),
    'partner_type'        => strtoupper((string) ($_POST['partner_type'] ?? 'INDIVIDUAL')),
    'business_name'       => trim((string) ($_POST['business_name'] ?? '')),
    'address'             => trim((string) ($_POST['address'] ?? '')),
    'state_id'            => (int) ($_POST['state_id'] ?? 0),
    'lga_id'              => (int) ($_POST['lga_id'] ?? 0),
    'password'            => (string) ($_POST['password'] ?? ''),
    'password_confirmation' => (string) ($_POST['password_confirmation'] ?? ''),
];

    if (!in_array($data['partner_type'], ['INDIVIDUAL', 'BUSINESS'], true)) {
        $data['partner_type'] = 'INDIVIDUAL';
    }

        $errors = validate($data, [
        'full_name'             => ['required', 'min_len:2', 'max_len:150'],
        'email'                 => ['required', 'email', 'max_len:190'],
        'phone'                 => ['required', 'phone_ng', 'max_len:20'],
        'nin_number'            => ['required', 'nin'],
        'partner_type'          => ['required', 'in:INDIVIDUAL,BUSINESS'],
        'business_name'         => ['max_len:190'],
        'address'               => ['max_len:255'],
        'state_id'              => ['required', 'int'],
        'lga_id'                => ['int'],
        'password'              => ['required', 'password', 'same:password_confirmation'],
        'password_confirmation' => ['required'],
    ], [
        'full_name' => 'Full Name', 'email' => 'Email Address', 'phone' => 'Phone Number',
        'nin_number' => 'NIN Number', 'state_id' => 'State', 'lga_id' => 'LGA',
        'password' => 'Password',
    ]);

    if ($data['partner_type'] === 'BUSINESS' && $data['business_name'] === '') {
        $errors['business_name'] = 'Business Name is required for business partners.';
    }

       // Verify state / lga relationship — only when an LGA was actually chosen.
    if (empty($errors['state_id']) && $data['lga_id'] > 0) {
        $stmt = db()->prepare('SELECT 1 FROM lgas WHERE id = :lid AND state_id = :sid AND is_active = 1 LIMIT 1');
        $stmt->execute([':lid' => $data['lga_id'], ':sid' => $data['state_id']]);
        if (!$stmt->fetchColumn()) {
            $errors['lga_id'] = 'The selected LGA does not belong to the selected State.';
        }
    }

    // Uniqueness
    if (empty($errors['email'])) {
        $stmt = db()->prepare('SELECT 1 FROM partners WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $data['email']]);
        if ($stmt->fetchColumn()) {
            $errors['email'] = 'An account already exists for this email address.';
        }
    }
    if (empty($errors['nin_number'])) {
        $stmt = db()->prepare('SELECT 1 FROM partners WHERE nin_number = :n LIMIT 1');
        $stmt->execute([':n' => $data['nin_number']]);
        if ($stmt->fetchColumn()) {
            $errors['nin_number'] = 'This NIN is already registered.';
        }
    }

      // Required profile image
    $profileImagePath = null;
    $file = $_FILES['profile_image'] ?? null;
    $hasFile = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

    if (!$hasFile) {
        $errors['profile_image'] = 'Profile Image is required.';
    } else {
        switch ((int) $file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                $errors['profile_image'] = 'Image is too large. Maximum size is 2 MB.';
                break;
            case UPLOAD_ERR_PARTIAL:
                $errors['profile_image'] = 'Upload was interrupted. Please try again.';
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
            case UPLOAD_ERR_CANT_WRITE:
                $errors['profile_image'] = 'Server could not store the upload. Contact support.';
                break;
            default:
                $errors['profile_image'] = 'Upload failed. Please try again.';
                break;
        }

        if (empty($errors['profile_image'])) {
            if ($file['size'] > 2 * 1024 * 1024) {
                $errors['profile_image'] = 'Image must be 2 MB or smaller.';
            } else {
                $allowedExt = [
                    'jpg'  => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png'  => 'image/png',
                    'webp' => 'image/webp',
                ];
                $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
                if (!isset($allowedExt[$ext])) {
                    $errors['profile_image'] = 'Only JPG, PNG and WEBP images are accepted.';
                } else {
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = (string) $finfo->file((string) $file['tmp_name']);
                    if ($mime !== $allowedExt[$ext]) {
                        $errors['profile_image'] = 'File type does not match the file extension.';
                    } else {
                        // Basic image dimension sanity check
                        $dimInfo = @getimagesize((string) $file['tmp_name']);
                        if ($dimInfo === false) {
                            $errors['profile_image'] = 'File is not a readable image.';
                        } elseif ($dimInfo[0] < 100 || $dimInfo[1] < 100) {
                            $errors['profile_image'] = 'Image must be at least 100×100 pixels.';
                        } else {
                            $dir = PICT_ROOT . '/uploads/profiles';
                            if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
                            $storedName = 'p_' . bin2hex(random_bytes(8)) . '.' . $ext;
                            $target = $dir . '/' . $storedName;
                            if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
                                $errors['profile_image'] = 'Could not save the uploaded image.';
                            } else {
                                @chmod($target, 0644);
                                $profileImagePath = '/uploads/profiles/' . $storedName;
                            }
                        }
                    }
                }
            }
        }
    }

        // ---- Rules and Regulations consent ----
    $rulesConsent     = (string) ($_POST['rules_consent'] ?? '');
    $rulesModalAgreed = (string) ($_POST['rules_modal_agreed'] ?? '');
    $rulesVersion     = (string) ($_POST['rules_version'] ?? '');
    $rulesHash        = (string) ($_POST['rules_hash'] ?? '');

    if ($rulesConsent !== '1') {
        $errors['rules_consent'] = 'You must read and accept the 10 Rules and Regulations before registering.';
    } elseif ($rulesModalAgreed !== '1') {
        $errors['rules_consent'] = 'Please open the rules using the button above and click "I have read these rules".';
    } elseif ($rulesVersion !== partner_rules_version() || $rulesHash !== partner_rules_hash()) {
        $errors['rules_consent'] = 'The rules have been updated. Please open and read the current version, then try again.';
    }
    if (!$errors) {
        try {
            db()->beginTransaction();

            // Generate a unique partner code (retry on collision)
            $code = '';
            for ($i = 0; $i < 5; $i++) {
                $code = auth_generate_partner_code();
                $chk = db()->prepare('SELECT 1 FROM partners WHERE partner_code = :c LIMIT 1');
                $chk->execute([':c' => $code]);
                if (!$chk->fetchColumn()) break;
            }

            $stmt = db()->prepare('
                INSERT INTO partners
                  (partner_code, full_name, email, phone, nin_number, password_hash, must_change_pwd,
                   partner_type, business_name, address, profile_image_path,
                   state_id, lga_id, status, created_at)
                VALUES
                  (:code, :name, :email, :phone, :nin, :pwd, 0,
                   :ptype, :bname, :addr, :img,
                   :sid, :lid, :status, :ca)
            ');
            $stmt->execute([
                ':code'   => $code,
                ':name'   => $data['full_name'],
                ':email'  => $data['email'],
                ':phone'  => $data['phone'],
                ':nin'    => $data['nin_number'],
                ':pwd'    => auth_hash($data['password']),
                ':ptype'  => $data['partner_type'],
                ':bname'  => $data['business_name'] !== '' ? $data['business_name'] : null,
                ':addr'   => $data['address'] !== '' ? $data['address'] : null,
                ':img'    => $profileImagePath,
                ':sid'    => $data['state_id'],
                               ':lid'    => $data['lga_id'] > 0 ? $data['lga_id'] : null,
                ':status' => 'PENDING',
                ':ca'     => now_dt(),
            ]);
            $partnerId = (int) db()->lastInsertId();

                        // Record the consent in the audit trail (existing table, no new schema).
            audit_log(
                'PARTNER_RULES_ACCEPTED',
                'partners',
                $partnerId,
                'Accepted NIN Partner Rules v' . partner_rules_version()
                    . ' (hash ' . substr(partner_rules_hash(), 0, 12) . '…)',
                null,
                null,
                ['role' => 'PUBLIC', 'id' => null, 'label' => $data['email']]
            );
            db()->commit();

            audit_log(
                'PARTNER_REGISTERED',
                'partners',
                $partnerId,
                'New partner registration: ' . $data['full_name'],
                null,
                null,
                ['role' => 'PUBLIC', 'id' => null, 'label' => $data['email']]
            );

            notify_all_superadmins(
                'PARTNER_REGISTRATION',
                'New partner registration',
                $data['full_name'] . ' (' . $data['email'] . ') submitted a partner application.',
                '/superadmin/partners.php?status=PENDING',
                'partners',
                $partnerId
            );

            // Confirmation email (non-blocking on failure)
            $intro = '<p>Hello ' . e($data['full_name']) . ',</p>'
                   . '<p>Thank you for applying to become a partner of ' . e((string) setting('company_name', 'Prestigious ICT Investment Limited')) . '.</p>'
                   . '<p>Your application has been received and is <strong>pending review</strong>. You will receive another email once a decision has been made.</p>';
            send_branded_mail($data['email'], 'Partner application received', $intro);

            old_clear();
            flash('success', 'Your application has been received. You will be notified by email once it has been reviewed.');
            redirect('/partner/login.php');
        } catch (Throwable $ex) {
            if (db()->inTransaction()) db()->rollBack();
            error_log('[partner register] ' . $ex->getMessage());
            $errors['_global'] = 'Could not complete registration. Please try again.';
        }
    }

    flash_old($_POST);
}

$page_title       = 'Become a Partner';
$page_description = 'Register as a partner of Prestigious ICT Investment Limited.';
$active_nav       = 'partners';



/**
 * Public site header.
 * Variables expected (all optional):
 *   $page_title       string  (defaults to company name)
 *   $page_description string  (defaults to company tagline)
 *   $active_nav       string  key of the current nav item
 */

if (!isset($page_title) || $page_title === '') {
    $page_title = (string) setting('company_name', 'Prestigious ICT Investment Limited');
}
if (!isset($page_description)) {
    $page_description = (string) setting('company_tagline', 'Technology. Identity. Trust.');
}
$companyName  = (string) setting('company_name', 'Prestigious ICT Investment Limited');
$companyShort = (string) setting('company_short_name', 'Prestigious ICT');
$tagline      = (string) setting('company_tagline', 'Technology. Identity. Trust.');
$active       = $active_nav ?? '';
$logoPath     = (string) setting('company_logo_path', '');

$navItems = [
    'home'          => ['label' => 'Home',           'url' => '../'],
    'about'         => ['label' => 'About',          'url' => '../about-us.php'],
    'services'      => ['label' => 'Services',       'url' => '../services.php'],
    'partners'      => ['label' => 'Partners',       'url' => '../partners.php'],
    'why-choose-us' => ['label' => 'Why Us',         'url' => '../why-choose-us.php'],
    'faq'           => ['label' => 'FAQ',            'url' => '../faq.php'],
    'contact'       => ['label' => 'Contact',        'url' => '../contact-us.php'],
];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?= e($page_description) ?>">
<meta name="theme-color" content="#0b5ed7">
<title><?= e($page_title === $companyName ? $companyName . ' — ' . $tagline : $page_title . ' — ' . $companyName) ?></title>

<meta property="og:type" content="website">
<meta property="og:title" content="<?= e($page_title) ?>">
<meta property="og:description" content="<?= e($page_description) ?>">
<meta property="og:site_name" content="<?= e($companyName) ?>">

<link rel="icon" href="../assets/img/favicon.svg" type="image/svg+xml">

<link rel="stylesheet" href="<?= e(url('/assets/css/public.css')) ?>">
</head>
<body>

<header class="site-header">
  <div class="container">
    <nav class="nav" aria-label="Primary">
      <a class="brand" href="../">
        <span class="brand__mark" aria-hidden="true"><?= e(mb_substr($companyShort, 0, 1)) ?></span>
        <span class="brand__text">
          <span class="brand__name"><?= e($companyShort) ?></span>
          <span class="brand__tag"><?= e($tagline) ?></span>
        </span>
      </a>

      <button class="nav__toggle" type="button" aria-label="Menu" aria-expanded="false" aria-controls="primary-menu">
        <span></span><span></span><span></span>
      </button>

      <ul class="nav__menu" id="primary-menu">
        <?php foreach ($navItems as $key => $item): ?>
          <li>
            <a href="<?= e($item['url']) ?>"<?= $active === $key ? ' class="is-active"' : '' ?>>
              <?= e($item['label']) ?>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>

      <div class="nav__actions">
        <a class="btn btn-ghost btn-sm" href="./register.php">
          <span class="label">Become a Partner</span>
        </a>
        <a class="btn btn-primary btn-sm" href="./login.php">Partner Login</a>
      </div>
    </nav>
  </div>
</header>

<?php if ($msg = flash_get('success')): ?>
  <div class="container" style="padding-top:20px">
    <div class="alert alert-success"><?= e($msg) ?></div>
  </div>
<?php endif; ?>
<?php if ($msg = flash_get('error')): ?>
  <div class="container" style="padding-top:20px">
    <div class="alert alert-danger"><?= e($msg) ?></div>
  </div>
<?php endif; ?>
<?php if ($msg = flash_get('info')): ?>
  <div class="container" style="padding-top:20px">
    <div class="alert alert-info"><?= e($msg) ?></div>
  </div>
<?php endif; ?>

<main>
 <script src="<?= e(url('/assets/js/public.js')) ?>"></script>


<section class="page-head">
  <div class="container">
    <div class="breadcrumbs"><a href="../">Home</a><span>/</span><a href="../partners.php">Partners</a><span>/</span>Register</div>
    <h1>Partner Registration</h1>
    <p>Fill in your details below. Your application will be reviewed by our team before your account is activated.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="reg-layout">

      <?php if (!empty($errors['_global'])): ?>
        <div class="alert alert-danger"><?= e($errors['_global']) ?></div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data" novalidate>
        <?= csrf_field() ?>

        <div class="reg-section">
          <h2>Contact Information</h2>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="full_name">Full Name <span class="req">*</span></label>
              <input class="form-control" type="text" id="full_name" name="full_name" value="<?= e(old('full_name')) ?>" maxlength="150" required>
              <?php if (!empty($errors['full_name'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['full_name']) ?></div><?php endif; ?>
            </div>
            <div class="form-group">
              <label class="form-label" for="phone">Phone Number <span class="req">*</span></label>
              <input class="form-control" type="tel" id="phone" name="phone" value="<?= e(old('phone')) ?>" maxlength="20" placeholder="e.g. 08012345678" required>
              <?php if (!empty($errors['phone'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['phone']) ?></div><?php endif; ?>
            </div>
          </div>
          <div class="form-group">
            <label class="form-label" for="email">Email Address <span class="req">*</span></label>
            <input class="form-control" type="email" id="email" name="email" value="<?= e(old('email')) ?>" maxlength="190" required>
            <?php if (!empty($errors['email'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['email']) ?></div><?php endif; ?>
          </div>
          <div class="form-group">
            <label class="form-label" for="nin_number">NIN Number <span class="req">*</span></label>
            <input class="form-control" type="text" id="nin_number" name="nin_number" value="<?= e(old('nin_number')) ?>" maxlength="11" inputmode="numeric" pattern="\d{11}" placeholder="11 digits" required>
            <div class="form-hint">Your National Identification Number (11 digits). This is stored securely and masked in the system.</div>
            <?php if (!empty($errors['nin_number'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['nin_number']) ?></div><?php endif; ?>
          </div>
        </div>

        <div class="reg-section">
          <h2>Location &amp; Business</h2>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="state_id">State <span class="req">*</span></label>
              <select class="form-control" id="state_id" name="state_id" required>
                <option value="">— Select State —</option>
                <?php foreach ($states as $s): ?>
                  <option value="<?= (int) $s['id'] ?>"<?= (string) old('state_id') === (string) $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!empty($errors['state_id'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['state_id']) ?></div><?php endif; ?>
            </div>
                       <div class="form-group">
              <label class="form-label" for="lga_id">LGA <span class="muted" style="font-weight:400;font-size:12px">(optional)</span></label>
              <select class="form-control" id="lga_id" name="lga_id"
                      data-selected="<?= ctype_digit((string) old('lga_id')) ? (int) old('lga_id') : '' ?>">
                <option value="">— Select State first —</option>
              </select>
              <?php if (!empty($errors['lga_id'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['lga_id']) ?></div><?php endif; ?>
            </div>

          <!-- HIDDEN: Business registration is temporarily disabled. Only
               Individual is presented to the registrant. To re-enable later,
               remove the style="display:none" attribute on this row and
               restore the BUSINESS option below. -->
          <div class="form-row" style="display:none">
            <div class="form-group">
              <label class="form-label" for="partner_type">Partner Type</label>
              <select class="form-control" id="partner_type" name="partner_type">
                <option value="INDIVIDUAL" selected>Individual</option>
              </select>
            </div>
            <div class="form-group" id="business-name-group" style="display:none">
              <label class="form-label" for="business_name">Business Name</label>
              <input class="form-control" type="text" id="business_name" name="business_name" maxlength="190">
            </div>
          </div>

          <div class="form-group">
            <label class="form-label" for="address">Service Address</label>
            <input class="form-control" type="text" id="address" name="address" value="<?= e(old('address')) ?>" maxlength="255" placeholder="Street, area, landmark">
            <?php if (!empty($errors['address'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['address']) ?></div><?php endif; ?>
          </div>

                    <div class="form-group">
            <label class="form-label" for="profile_image">Profile Image <span class="req">*</span></label>
            <input class="form-control" type="file" id="profile_image" name="profile_image"
                   accept="image/jpeg,image/png,image/webp" required>
                        <div class="form-hint">
              JPG, PNG or WEBP. Required — this is used to verify your identity during approval.
              Maximum size: <?= (int) setting('upload_max_size_mb', 2) ?> MB
              (also limited by the server to <?= e((string) min(
                  (int) ini_get('upload_max_filesize'),
                  (int) ini_get('post_max_size')
              )) ?> MB).
            </div> <?php if (!empty($errors['profile_image'])): ?>
              <div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['profile_image']) ?></div>
            <?php endif; ?>
          </div>
</div>
        <div class="reg-section">
          <h2>Account Security</h2>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="password">Password <span class="req">*</span></label>
              <input class="form-control" type="password" id="password" name="password" required >
              <div class="form-hint">Minimum <?= (int) setting('security_password_min_length', 8) ?> characters, with upper case, lower case and a number.</div>
              <?php if (!empty($errors['password'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['password']) ?></div><?php endif; ?>
            </div>
            <div class="form-group">
              <label class="form-label" for="password_confirmation">Confirm Password <span class="req">*</span></label>
              <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" required >
            </div>
          </div>
        </div>

        <!-- RULES AND REGULATIONS CONSENT -->
        <div class="reg-section" id="rules-consent-section">
          <h2>Rules and Regulations <span style="color:#dc2626">*</span></h2>

          <p style="font-size:14px;color:var(--text);margin:0 0 14px">
            Every partner operating under
            <strong><?= e((string) setting('company_name', 'Prestigious ICT Investment Limited')) ?></strong>
            is required to read and accept the
            <strong>10 Rules and Regulations Governing NIN Enrolment Partners</strong>
            before registration.
          </p>

          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px;align-items:center">
            <button type="button" class="btn btn-outline" id="open-rules-btn">
              Read the 10 Rules and Regulations
            </button>
            <span class="muted" style="font-size:13px">
              Version <?= e(partner_rules_version()) ?>
              &middot; effective <?= e(partner_rules_effective_date()) ?>
            </span>
          </div>

          <input type="hidden" name="rules_modal_agreed" id="rules_modal_agreed"
                 value="<?= e(old('rules_modal_agreed')) ?>">
          <input type="hidden" name="rules_version" value="<?= e(partner_rules_version()) ?>">
          <input type="hidden" name="rules_hash"    value="<?= e(partner_rules_hash()) ?>">

          <div class="form-group" style="margin:0">
            <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;font-size:14px;line-height:1.5">
              <input type="checkbox" id="rules_consent" name="rules_consent" value="1"
                     <?= old('rules_consent') === '1' ? 'checked' : '' ?>
                     style="margin-top:3px;flex-shrink:0">
              <span>
                I confirm that I have read, understood, and agree to the
                10 Rules and Regulations Governing NIN Enrolment Partners.
                I understand that failure to comply may result in action
                under the partnership agreement and applicable law.
              </span>
            </label>
            <?php if (!empty($errors['rules_consent'])): ?>
              <div class="alert alert-danger" style="margin:8px 0 0"><?= e($errors['rules_consent']) ?></div>
            <?php endif; ?>
          </div>
        </div>
            
        <div class="reg-section" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
          <div>
            <p style="margin:0;font-size:13.5px;color:var(--muted)">By registering you agree to our <a href="../terms-and-conditions.php">Terms</a> and <a href="../privacy-policy.php">Privacy Policy</a>.</p>
          </div>
          <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a class="btn btn-outline" href="../partners.php">Cancel</a>
            <button class="btn btn-primary btn-lg" type="submit">Submit Application</button>
          </div>
        </div>

      </form>
    </div>
  </div>
</section>

<script>
(function(){
  var stateSel = document.getElementById('state_id');
  var lgaSel = document.getElementById('lga_id');
  var typeSel = document.getElementById('partner_type');
  var bizGroup = document.getElementById('business-name-group');

  function loadLgas(stateId, preselect) {
    lgaSel.innerHTML = '<option value="">Loading…</option>';
    if (!stateId) {
      lgaSel.innerHTML = '<option value="">— Select State first —</option>';
      return;
    }
   fetch(<?= json_encode(root_url('/api/lgas.php')) ?> + '?state_id=' + encodeURIComponent(stateId))
      .then(function(r){ return r.json(); })
      .then(function(data){
        if (!data.ok) { lgaSel.innerHTML = '<option value="">— Unavailable —</option>'; return; }
        var html = '<option value="">— Select LGA —</option>';
        data.lgas.forEach(function(l){
          var sel = (preselect && String(preselect) === String(l.id)) ? ' selected' : '';
          html += '<option value="' + l.id + '"' + sel + '>' + l.name.replace(/</g,'&lt;') + '</option>';
        });
        lgaSel.innerHTML = html;
      })
      .catch(function(){ lgaSel.innerHTML = '<option value="">— Load failed —</option>'; });
  }

  if (stateSel && lgaSel) {
    var preselect = lgaSel.getAttribute('data-selected') || '';
    if (stateSel.value) loadLgas(stateSel.value, preselect);
    stateSel.addEventListener('change', function(){ loadLgas(stateSel.value, ''); });
  }

  function toggleBiz(){
    if (!typeSel || !bizGroup) return;
    bizGroup.style.display = (typeSel.value === 'BUSINESS') ? '' : 'none';
  }
  if (typeSel) {
    toggleBiz();
    typeSel.addEventListener('change', toggleBiz);
  }
})();
</script>

</main>

<!-- RULES MODAL -->
<div id="rules-modal" style="display:none;position:fixed;inset:0;z-index:2000;background:rgba(15,23,42,.55)">
  <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:min(720px,92vw);max-height:86vh;background:#fff;border-radius:12px;display:flex;flex-direction:column;box-shadow:0 24px 60px rgba(0,0,0,.4)">
    <div style="padding:18px 24px;border-bottom:1px solid #e5e7eb;display:flex;justify-content:space-between;align-items:center;gap:12px">
      <h2 style="margin:0;font-size:17px"><?= e(partner_rules()['title']) ?></h2>
      <button type="button" id="rules-close-x"
              style="border:0;background:transparent;font-size:24px;line-height:1;cursor:pointer;color:#6b7280"
              aria-label="Close">&times;</button>
    </div>

    <div id="rules-body" style="padding:20px 24px;overflow-y:auto;flex:1">
      <p style="font-size:13.5px;color:#6b7280;margin:0 0 20px">
        Version <?= e(partner_rules_version()) ?>
        &middot; effective <?= e(partner_rules_effective_date()) ?>
      </p>

      <?php foreach (partner_rules()['rules'] as $rule): ?>
        <div style="margin-bottom:18px">
          <div style="display:flex;gap:12px;align-items:flex-start">
            <span style="flex-shrink:0;width:28px;height:28px;border-radius:50%;background:#0b5ed7;color:#fff;font-weight:700;font-size:13px;display:flex;align-items:center;justify-content:center">
              <?= (int) $rule['n'] ?>
            </span>
            <div>
              <div style="font-weight:700;color:#0f172a;margin-bottom:4px"><?= e($rule['title']) ?></div>
              <div style="font-size:14px;color:#374151;line-height:1.6"><?= e($rule['body']) ?></div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <div style="margin-top:24px;padding:14px 16px;background:#f7f9fc;border-left:3px solid #0b5ed7;border-radius:6px;font-size:13.5px;color:#374151;line-height:1.65">
        <strong style="color:#0f172a">Declaration of compliance.</strong>
        <?= e(partner_rules()['declaration']) ?>
      </div>
    </div>

    <div style="padding:16px 24px;border-top:1px solid #e5e7eb;display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap">
      <button type="button" id="rules-close-btn" class="btn btn-outline">Close</button>
      <button type="button" id="rules-agree-btn" class="btn btn-primary">
        I have read these rules &mdash; enable consent
      </button>
    </div>
  </div>
</div>

<script>
(function () {
  var modal        = document.getElementById('rules-modal');
  var openBtn      = document.getElementById('open-rules-btn');
  var closeX       = document.getElementById('rules-close-x');
  var closeBtn     = document.getElementById('rules-close-btn');
  var agreeBtn     = document.getElementById('rules-agree-btn');
  var hiddenAgreed = document.getElementById('rules_modal_agreed');
  var checkbox     = document.getElementById('rules_consent');
  if (!modal || !openBtn) return;

  function showModal() {
    modal.style.display = 'block';
    document.body.style.overflow = 'hidden';
    document.getElementById('rules-body').scrollTop = 0;
  }
  function hideModal() {
    modal.style.display = 'none';
    document.body.style.overflow = '';
  }

  openBtn.addEventListener('click', showModal);
  closeX.addEventListener('click', hideModal);
  closeBtn.addEventListener('click', hideModal);
  modal.addEventListener('click', function (e) { if (e.target === modal) hideModal(); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && modal.style.display === 'block') hideModal();
  });

  agreeBtn.addEventListener('click', function () {
    hiddenAgreed.value = '1';
    if (checkbox) { checkbox.disabled = false; checkbox.checked = true; checkbox.focus(); }
    hideModal();
  });

  // Checkbox stays disabled until the user has opened the modal and agreed
  if (hiddenAgreed.value !== '1' && checkbox) {
    checkbox.disabled = true;
  }
})();
</script>
          
<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">

      <div>
        <div class="footer-brand">
          <span class="brand__mark" aria-hidden="true"><?= e(mb_substr($companyShort, 0, 1)) ?></span>
          <span><?= e($companyShort) ?></span>
        </div>
        <p class="footer-about">
          <?= e($companyName) ?> provides technology and digital services across Nigeria, including
          NIN enrolment, NIN verification, education e-services and partner-operated service stations.
        </p>
        <?php $socials = [
          'Facebook'  => (string) setting('company_social_facebook', ''),
          'X'         => (string) setting('company_social_x', ''),
          'LinkedIn'  => (string) setting('company_social_linkedin', ''),
          'Instagram' => (string) setting('company_social_instagram', ''),
        ]; ?>
        <?php if (array_filter($socials)): ?>
          <div class="socials" style="display:flex;gap:8px">
            <?php foreach ($socials as $label => $href): if ($href === '') continue; ?>
              <a href="<?= e($href) ?>" target="_blank" rel="noopener" aria-label="<?= e($label) ?>"
                 style="display:inline-flex;width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.08);align-items:center;justify-content:center">
                <?= e(mb_substr($label, 0, 1)) ?>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div>
        <h4>Services</h4>
        <a href="../services/nin-enrolment.php">NIN Enrolment</a>
        <a href="../services/nin-verification.php">NIN Verification</a>
        <a href="../services/digital-services.php">Digital Services</a>
        <a href="../services/education-services.php">Education / e-Services</a>
        <a href="../services/technology-solutions.php">Technology Solutions</a>
      </div>

      <div>
        <h4>Company</h4>
        <a href="../about-us.php">About Us</a>
        <a href="../partners.php">Partner With Us</a>
        <a href="../why-choose-us.php">Why Choose Us</a>
        <a href="../faq.php">FAQ</a>
        <a href="../contact-us.php">Contact</a>
      </div>

      <div>
        <h4>Contact</h4>
        <div class="footer-contact">
          <?php if ($addr = setting('company_address')): ?>
            <div><strong>Address</strong><br><?= nl2br(e($addr)) ?></div>
          <?php endif; ?>
          <?php if ($phone = setting('company_phone')): ?>
            <div style="margin-top:8px"><strong>Phone</strong><br>
              <a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>" style="display:inline;padding:0"><?= e($phone) ?></a>
            </div>
          <?php endif; ?>
          <?php if ($email = setting('company_email')): ?>
            <div style="margin-top:8px"><strong>Email</strong><br>
              <a href="mailto:<?= e($email) ?>" style="display:inline;padding:0"><?= e($email) ?></a>
            </div>
          <?php endif; ?>
        </div>
      </div>

    </div>

    <div class="footer-bottom">
      <div>&copy; <?= date('Y') ?> <?= e($companyName) ?>. All rights reserved.</div>
      <div>
        <a href="../privacy-policy.php">Privacy Policy</a>
        &nbsp;·&nbsp;
        <a href="../terms-and-conditions.php">Terms &amp; Conditions</a>
        &nbsp;·&nbsp;
        <a href="../login.php">Sign In</a>
      </div>
    </div>
  </div>
</footer>

<script src="<?= e(url('/assets/vendor/bootstrap/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(url('/assets/js/public.js')) ?>"></script>
</body>
</html>
