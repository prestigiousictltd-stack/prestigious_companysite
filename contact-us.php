<?php
require_once __DIR__ . '/bootstrap/init.php';

$page_title       = 'Contact Us';
$page_description = 'Contact Prestigious ICT Investment Limited for service enquiries, partner applications or general questions.';
$active_nav       = 'contact';

$errors = [];
$success = false;

if (is_post()) {
    csrf_require();

    $data = [
        'name'    => trim((string) ($_POST['name'] ?? '')),
        'email'   => strtolower(trim((string) ($_POST['email'] ?? ''))),
        'phone'   => trim((string) ($_POST['phone'] ?? '')),
        'subject' => trim((string) ($_POST['subject'] ?? '')),
        'message' => trim((string) ($_POST['message'] ?? '')),
        'website' => (string) ($_POST['website'] ?? ''), // honeypot
    ];

    // Honeypot: real users never fill this in.
    if ($data['website'] !== '') {
        $success = true; // pretend success
    } else {
        $errors = validate($data, [
            'name'    => ['required', 'string', 'min_len:2', 'max_len:150'],
            'email'   => ['required', 'email', 'max_len:190'],
            'phone'   => ['max_len:30'],
            'subject' => ['required', 'string', 'min_len:2', 'max_len:190'],
            'message' => ['required', 'string', 'min_len:10', 'max_len:5000'],
        ]);

        if (!$errors) {
            // Throttle: no more than 3 enquiries per IP per 15 minutes.
            try {
                $stmt = db()->prepare('
                    SELECT COUNT(*) FROM audit_logs
                    WHERE action_code = :code
                      AND ip_address = :ip
                      AND created_at >= (NOW() - INTERVAL 15 MINUTE)
                ');
                $stmt->execute([':code' => 'PUBLIC_CONTACT_ENQUIRY', ':ip' => client_ip()]);
                $recent = (int) $stmt->fetchColumn();
            } catch (Throwable $e) {
                $recent = 0;
            }

            if ($recent >= 3) {
                $errors['_global'] = 'You have sent several enquiries recently. Please wait a few minutes before trying again.';
            } else {
                $companyEmail = (string) setting('company_email', '');
                $intro = '<p>New enquiry from the public website.</p>'
                       . '<p><strong>Name:</strong> ' . e($data['name']) . '<br>'
                       . '<strong>Email:</strong> ' . e($data['email']) . '<br>'
                       . '<strong>Phone:</strong> ' . e($data['phone'] ?: '—') . '<br>'
                       . '<strong>Subject:</strong> ' . e($data['subject']) . '</p>'
                       . '<p><strong>Message:</strong></p>'
                       . '<p style="white-space:pre-wrap">' . e($data['message']) . '</p>';

                if ($companyEmail !== '') {
                    send_mail($companyEmail, 'Website enquiry: ' . $data['subject'], $intro);
                }

                audit_log(
                    'PUBLIC_CONTACT_ENQUIRY',
                    'contact',
                    null,
                    'Enquiry: ' . mb_substr($data['subject'], 0, 80),
                    null,
                    null,
                    ['role' => 'PUBLIC', 'id' => null, 'label' => $data['email']]
                );

                notify_all_superadmins(
                    'PUBLIC_ENQUIRY',
                    'New website enquiry',
                    $data['name'] . ' <' . $data['email'] . '> — ' . $data['subject'],
                    '/superadmin/notifications.php',
                    'contact',
                    null
                );

                $success = true;
                old_clear();
            }
        }

        if (!$success) {
            flash_old($_POST);
        }
    }
}

$companyEmail = (string) setting('company_email', '');
$companyPhone = (string) setting('company_phone', '');
$companyAddr  = (string) setting('company_address', '');
$mapEmbed     = (string) setting('company_map_embed', '');

require PICT_ROOT . '/includes/public/includes/header-public.php';
?>

<section class="page-head">
  <div class="container">
    <div class="breadcrumbs"><a href="./">Home</a><span>/</span>Contact Us</div>
    <h1>Contact Us</h1>
    <p>We respond to enquiries within normal business hours.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="contact-grid">

      <aside class="contact-info">
        <?php if ($companyAddr !== ''): ?>
          <div class="contact-info__item">
            <div class="icon"><?= public_service_icon_svg('map-pin', 20) ?></div>
            <div>
              <h4>Address</h4>
              <p><?= nl2br(e($companyAddr)) ?></p>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($companyPhone !== ''): ?>
          <div class="contact-info__item">
            <div class="icon"><?= public_service_icon_svg('phone', 20) ?></div>
            <div>
              <h4>Phone</h4>
              <p><a href="tel:<?= e(preg_replace('/\s+/', '', $companyPhone)) ?>" style="color:var(--ink)"><?= e($companyPhone) ?></a></p>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($companyEmail !== ''): ?>
          <div class="contact-info__item">
            <div class="icon"><?= public_service_icon_svg('mail', 20) ?></div>
            <div>
              <h4>Email</h4>
              <p><a href="mailto:<?= e($companyEmail) ?>" style="color:var(--ink)"><?= e($companyEmail) ?></a></p>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($mapEmbed !== ''): ?>
          <div class="card" style="padding:0;overflow:hidden">
            <?= $mapEmbed /* trusted admin-provided iframe */ ?>
          </div>
        <?php endif; ?>
      </aside>

      <div class="form-card">
        <?php if ($success): ?>
          <div class="alert alert-success">
            Thank you for reaching out. We have received your message and will respond as soon as possible.
          </div>
          <div class="text-center">
            <a class="btn btn-ghost" href="/">Back to Home</a>
          </div>
        <?php else: ?>

          <?php if (!empty($errors['_global'])): ?>
            <div class="alert alert-danger"><?= e($errors['_global']) ?></div>
          <?php endif; ?>

          <h2 style="font-size:22px;margin-bottom:6px">Send us a message</h2>
          <p class="muted" style="font-size:14px;margin-bottom:22px">
            Fields marked <span style="color:#dc2626">*</span> are required.
          </p>

          <form id="contact-form" method="post" novalidate>
            <?= csrf_field() ?>

            <!-- honeypot -->
            <div style="position:absolute;left:-9999px" aria-hidden="true">
              <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="name">Full name <span style="color:#dc2626">*</span></label>
                <input class="form-control" type="text" id="name" name="name"
                       value="<?= e(old('name')) ?>" required autocomplete="name">
                <?php if (!empty($errors['name'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['name']) ?></div><?php endif; ?>
              </div>
              <div class="form-group">
                <label class="form-label" for="email">Email <span style="color:#dc2626">*</span></label>
                <input class="form-control" type="email" id="email" name="email"
                       value="<?= e(old('email')) ?>" required autocomplete="email">
                <?php if (!empty($errors['email'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['email']) ?></div><?php endif; ?>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label class="form-label" for="phone">Phone</label>
                <input class="form-control" type="tel" id="phone" name="phone"
                       value="<?= e(old('phone')) ?>" autocomplete="tel">
                <?php if (!empty($errors['phone'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['phone']) ?></div><?php endif; ?>
              </div>
              <div class="form-group">
                <label class="form-label" for="subject">Subject <span style="color:#dc2626">*</span></label>
                <input class="form-control" type="text" id="subject" name="subject"
                       value="<?= e(old('subject')) ?>" required>
                <?php if (!empty($errors['subject'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['subject']) ?></div><?php endif; ?>
              </div>
            </div>

            <div class="form-group">
              <label class="form-label" for="message">Message <span style="color:#dc2626">*</span></label>
              <textarea class="form-control" id="message" name="message" required><?= e(old('message')) ?></textarea>
              <?php if (!empty($errors['message'])): ?><div class="alert alert-danger" style="margin:6px 0 0"><?= e($errors['message']) ?></div><?php endif; ?>
            </div>

            <button class="btn btn-primary btn-lg btn-block" type="submit">Send Message</button>

            <p class="muted" style="font-size:12.5px;text-align:center;margin-top:14px">
              By submitting this form you agree to our
              <a href="./privacy-policy.php">Privacy Policy</a>.
            </p>
          </form>
        <?php endif; ?>
      </div>

    </div>
  </div>
</section>

<?php old_clear(); require PICT_ROOT . '/includes/public/includes/footer-public.php'; ?>