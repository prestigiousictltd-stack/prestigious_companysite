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

<style>
/* ---------- Rules and Regulations card ---------- */
.rules-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-top: 4px solid #0b5ed7;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 4px 22px rgba(11, 94, 215, .10);
}
.rules-card__head {
    padding: 22px 26px 18px;
    background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
    border-bottom: 1px solid #e5e7eb;
}
.rules-card__eyebrow {
    display: inline-block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    color: #b91c1c;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 999px;
    padding: 4px 10px;
    margin-bottom: 10px;
}
.rules-card__title {
    margin: 0 0 6px;
    font-size: 19px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.3;
}
.rules-card__sub {
    margin: 0;
    font-size: 13.5px;
    color: #4b5563;
    line-height: 1.6;
}
.rules-card__sub strong { color: #0f172a; }

.rules-card__list {
    padding: 6px 26px 0;
}
.rule {
    display: flex;
    gap: 14px;
    padding: 16px 0;
    border-bottom: 1px dashed #e5e7eb;
}
.rule:last-child { border-bottom: 0; }
.rule__num {
    flex-shrink: 0;
    width: 30px;
    height: 30px;
    border-radius: 50%;
    background: #0b5ed7;
    color: #fff;
    font-weight: 700;
    font-size: 13px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 6px rgba(11, 94, 215, .30);
}
.rule__title {
    font-weight: 800;
    color: #0f172a;
    font-size: 13px;
    letter-spacing: .04em;
    text-transform: uppercase;
    margin: 4px 0 6px;
}
.rule__body {
    margin: 0;
    font-size: 13.5px;
    color: #4b5563;
    line-height: 1.65;
}

.rules-card__declaration {
    margin: 14px 26px 0;
    padding: 14px 16px;
    background: #f1f5f9;
    border-left: 3px solid #0b5ed7;
    border-radius: 6px;
    font-size: 13px;
    color: #374151;
    line-height: 1.7;
}
.rules-card__declaration strong { color: #0f172a; }

.rules-card__consent {
    margin-top: 18px;
    padding: 18px 26px 22px;
    border-top: 1px solid #e5e7eb;
    background: #fafbfc;
}
.consent-box {
    display: flex;
    gap: 12px;
    align-items: flex-start;
    cursor: pointer;
    padding: 14px 16px;
    background: #ffffff;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    transition: border-color .15s, background .15s, box-shadow .15s;
}
.consent-box:hover {
    border-color: #0b5ed7;
    box-shadow: 0 2px 10px rgba(11, 94, 215, .12);
}
.consent-box input[type="checkbox"] {
    margin-top: 3px;
    width: 18px;
    height: 18px;
    flex-shrink: 0;
    accent-color: #0b5ed7;
    cursor: pointer;
}
.consent-box span {
    font-size: 13.5px;
    color: #374151;
    line-height: 1.6;
}
.consent-box span strong { color: #0f172a; }

.consent-box.is-invalid {
    border-color: #dc2626;
    background: #fef2f2;
    animation: consentShake .35s ease;
}
@keyframes consentShake {
    0%, 100% { transform: translateX(0); }
    25%      { transform: translateX(-4px); }
    75%      { transform: translateX(4px); }
}
.consent-error {
    display: none;
    margin: 10px 0 0;
    font-size: 13px;
    font-weight: 600;
    color: #b91c1c;
}
.consent-error.is-visible { display: block; }

/* Rules button contrast fix (kept from earlier) */
#open-rules-btn {
    background-color: #ffffff;
    color: #0b5ed7;
    border: 2px solid #0b5ed7;
    font-weight: 600;
}
#open-rules-btn:hover,
#open-rules-btn:focus-visible {
    background-color: #0b5ed7;
    color: #ffffff;
    outline: 3px solid #93c5fd;
    outline-offset: 2px;
}
</style>
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

      <form method="post" enctype="multipart/form-data" novalidate id="partner-register-form">
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

          <!-- HIDDEN: Business registration is temporarily disabled. -->
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
            </div>
            <?php if (!empty($errors['profile_image'])): ?>
              <div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['profile_image']) ?></div>
            <?php endif; ?>
          </div>
        </div>

        <div class="reg-section">
          <h2>Account Security</h2>
          <div class="form-row">
            <div class="form-group">
              <label class="form-label" for="password">Password <span class="req">*</span></label>
              <input class="form-control" type="password" id="password" name="password" required>
              <div class="form-hint">Minimum <?= (int) setting('security_password_min_length', 8) ?> characters, with upper case, lower case and a number.</div>
              <?php if (!empty($errors['password'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['password']) ?></div><?php endif; ?>
            </div>
            <div class="form-group">
              <label class="form-label" for="password_confirmation">Confirm Password <span class="req">*</span></label>
              <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" required>
            </div>
          </div>
        </div>

        <!-- =========================================================
             RULES AND REGULATIONS — displayed inline, before the
             Cancel / Submit buttons. Consent is enforced only in the
             browser (UI); nothing about the rules is submitted to or
             validated by the server.
             ========================================================= -->
        <div class="reg-section" id="rules-section">
          <div class="rules-card">
            <div class="rules-card__head">
              <span class="rules-card__eyebrow">Mandatory &mdash; Read Before Submitting</span>
              <h2 class="rules-card__title">10 Rules and Regulations Governing NIN Enrolment Partners</h2>
              <p class="rules-card__sub">
                Every partner operating under
                <strong>PRESTIGIOUS ICT INVESTMENT LIMITED</strong>
                is required to read, understand, and accept the following rules.
                These rules form part of your operational obligations as an
                NIN Enrolment Partner.
              </p>
            </div>

            <div class="rules-card__list">
              <div class="rule">
                <span class="rule__num">1</span>
                <div>
                  <p class="rule__title">No Infraction of Any Kind</p>
                  <p class="rule__body">Partners must not engage in any act, omission, or conduct that violates the Nigeria Data Protection Act, NIMC regulations, the partnership agreement, or any applicable Nigerian law. Any infraction, whether intentional or negligent, shall be treated as a breach of the partnership.</p>
                </div>
              </div>

              <div class="rule">
                <span class="rule__num">2</span>
                <div>
                  <p class="rule__title">No Extortion, Bribery, or Corruption</p>
                  <p class="rule__body">Partners must not demand, solicit, or accept unauthorised payments, gifts, or favours from enrollees or any other party during NIN enrolment or verification. All charges must be limited to officially approved fees, and every transaction must be receipted.</p>
                </div>
              </div>

              <div class="rule">
                <span class="rule__num">3</span>
                <div>
                  <p class="rule__title">Strict Confidentiality and Data Protection</p>
                  <p class="rule__body">All enrollee data &mdash; including biometric, demographic, and contact information &mdash; is confidential and remains the property of NIMC and the Federal Republic of Nigeria. Partners must safeguard this data, never copy, retain, share, or transmit it outside authorised channels, and must report any suspected data breach immediately.</p>
                </div>
              </div>

              <div class="rule">
                <span class="rule__num">4</span>
                <div>
                  <p class="rule__title">Prohibition of Identity Fraud and Duplicate Enrolment</p>
                  <p class="rule__body">Partners must not enrol any individual using false, forged, or borrowed identity documents. No partner shall create, facilitate, or participate in duplicate, multiple, or ghost enrolments. Any suspicion of identity fraud must be stopped and reported at once.</p>
                </div>
              </div>

              <div class="rule">
                <span class="rule__num">5</span>
                <div>
                  <p class="rule__title">Authorised Use of Enrolment Equipment and Systems</p>
                  <p class="rule__body">Enrolment kits, credentials, and system access provided by the company or NIMC must be used strictly for authorised NIN enrolment activities. Equipment must not be moved, lent, duplicated, or operated outside the approved location without prior written approval.</p>
                </div>
              </div>

              <div class="rule">
                <span class="rule__num">6</span>
                <div>
                  <p class="rule__title">Accuracy and Integrity of Enrolment Records</p>
                  <p class="rule__body">Partners must capture enrollee data accurately and honestly. Falsifying, altering, omitting, or fabricating enrolment records &mdash; including names, dates of birth, addresses, and biometrics &mdash; is strictly prohibited and will attract full legal and contractual consequences.</p>
                </div>
              </div>

              <div class="rule">
                <span class="rule__num">7</span>
                <div>
                  <p class="rule__title">Compliance with NIMC Authorisation and Company Directives</p>
                  <p class="rule__body">Partners must operate only under a valid, current NIMC authorisation and must follow all directives, circulars, and updates issued by NIMC or by PRESTIGIOUS ICT INVESTMENT LIMITED. Failure to keep authorisation current will result in immediate suspension of operations.</p>
                </div>
              </div>

              <div class="rule">
                <span class="rule__num">8</span>
                <div>
                  <p class="rule__title">Prohibition of Unauthorised Collaboration and Transfer of Authority</p>
                  <p class="rule__body">Partners shall not sub-contract, franchise, or transfer their enrolment privileges to any third party. Collaboration with unauthorised agents, touts, or cyber caf&eacute;s for NIN-related services is strictly prohibited.</p>
                </div>
              </div>

              <div class="rule">
                <span class="rule__num">9</span>
                <div>
                  <p class="rule__title">Transparency</p>
                  <p class="rule__body">Partners must display approved fees, maintain accurate daily records, issue receipts for all transactions, and cooperate fully with audits, inspections, and investigations conducted by NIMC, the company, or any lawful authority.</p>
                </div>
              </div>

              <div class="rule">
                <span class="rule__num">10</span>
                <div>
                  <p class="rule__title">Mandatory Reporting and Consequences of Violation</p>
                  <p class="rule__body">Partners must promptly report any violation, suspicious activity, equipment malfunction, or security incident to the company. Violation of any of these rules may result in suspension, termination of the partnership, forfeiture of deposits, and referral to law enforcement or NIMC for further action.</p>
                </div>
              </div>
            </div>

            <div class="rules-card__declaration">
              <strong>Declaration of Compliance.</strong>
              All subordinate partners operating under PRESTIGIOUS ICT INVESTMENT LIMITED
              shall be required to read, understand, and comply with these rules as part
              of their operational responsibilities. Failure to comply may result in action
              under the partnership agreement and applicable law.
            </div>

            <div class="rules-card__consent">
              <label class="consent-box" id="consent-box" for="rules_consent">
                <input type="checkbox" id="rules_consent" required>
                <span>
                  I confirm that I have read, understood, and agree to the
                  <strong>10 Rules and Regulations Governing NIN Enrolment Partners</strong>.
                  I understand that failure to comply may result in action under the
                  partnership agreement and applicable law.
                </span>
              </label>
              <p class="consent-error" id="consent-error" role="alert">
                You must read and accept the 10 Rules and Regulations before submitting your application.
              </p>
            </div>
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

/* --------------------------------------------------------------
   Rules consent — UI-only enforcement.
   The form has novalidate, so `required` on the checkbox is
   ignored by the browser; we block submission here instead.
   -------------------------------------------------------------- */
(function () {
  var form   = document.getElementById('partner-register-form');
  var cb     = document.getElementById('rules_consent');
  var box    = document.getElementById('consent-box');
  var errMsg = document.getElementById('consent-error');
  var section = document.getElementById('rules-section');
  if (!form || !cb || !box) return;

  form.addEventListener('submit', function (e) {
    if (!cb.checked) {
      e.preventDefault();
      e.stopPropagation();
      box.classList.add('is-invalid');
      if (errMsg) errMsg.classList.add('is-visible');
      if (section) {
        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
      cb.focus({ preventScroll: true });
      return false;
    }
  }, true); // useCapture so we beat other submit listeners

  cb.addEventListener('change', function () {
    if (cb.checked) {
      box.classList.remove('is-invalid');
      if (errMsg) errMsg.classList.remove('is-visible');
    }
  });
})();
</script>

</main>

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
