<?php
/**
 * Shared template for a single service detail page.
 * Include from a wrapper script with $service_slug set.
 */

if (!isset($service_slug)) {
    http_response_code(500);
    echo 'Service slug not set.';
    exit;
}

$services = public_services();

if (!isset($services[$service_slug])) {
    http_response_code(404);
    $page_title = 'Service not found';
    require PICT_ROOT . 'includes/public/includes/header-public.php';
    echo '<section class="section"><div class="container"><h1>Service not found</h1><p><a href="' . e(url('/services')) . '">Back to services</a></p></div></section>';
    require PICT_ROOT . 'includes/public/includes/footer-public.php';
    exit;
}

$service          = $services[$service_slug];
$page_title       = $service['title'];
$page_description = $service['short'];
$active_nav       = 'services';

/**
 * Turn the alternating [heading, para, para, heading, para, ...] array
 * into HTML. Headings are recognised by matching against a known list.
 */
$knownHeadings = [
    'What this service covers', 'How it works', 'What to bring', 'Reporting to partners',
    'Overview', 'Who it is for', 'How verification is handled', 'Data protection',
    'A broad catalogue', 'Delivered through stations', 'Consistent service standards',
    'Typical services', 'Availability',
    'Purpose-built for partners', 'Platform features', 'Continuous improvement',
];

$html = '';
foreach ($service['body'] as $i => $block) {
    if ($i === 0 || in_array($block, $knownHeadings, true)) {
        $html .= '<h2>' . e($block) . '</h2>';
    } else {
        $html .= '<p>' . e($block) . '</p>';
    }
}
?>
<link rel="stylesheet" href="../assets/css/public.css">
<?php
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

<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">

<link rel="stylesheet" href="/assets/css/public.css">
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
        <a class="btn btn-ghost btn-sm" href="/prestigious-ict/partner/register.php">
          <span class="label">Become a Partner</span>
        </a>
        <a class="btn btn-primary btn-sm" href="/prestigious-ict/partner/login.php">Partner Login</a>
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
  <script src="/assets/js/public.js"></script>


<section class="page-head">
  <div class="container">
    <div class="breadcrumbs">
      <a href="<?= e(url('./')) ?>">Home</a><span>/</span>
      <a href="<?= e(url('./services.php')) ?>">Services</a><span>/</span>
      <?= e($service['title']) ?>
    </div>
    <h1><?= e($service['title']) ?></h1>
    <p><?= e($service['summary']) ?></p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="grid grid--2" style="gap:56px">
      <div class="prose">
        <?= $html ?>
      </div>

      <aside>
        <div class="card reveal" style="position:sticky;top:100px">
          <div class="card__icon"><?= public_service_icon_svg($service['icon']) ?></div>
          <h3>Interested in this service?</h3>
          <p>Speak to us about availability in your area, or find out how to become a partner and offer this service through your own station.</p>
          <div style="display:flex;flex-direction:column;gap:10px;margin-top:8px">
            <a class="btn btn-primary" href="<?= e(url('/contact-us.php')) ?>">Contact Us</a>
            <a class="btn btn-ghost" href="<?= e(url('/partner/register.php')) ?>">Become a Partner</a>
          </div>
        </div>

       
      </aside>
    </div>
  </div>
</section>

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
        <a href="nin-enrolment.php">NIN Enrolment</a>
        <a href="nin-verification.php">NIN Verification</a>
        <a href="digital-services.php">Digital Services</a>
        <a href="education-services.php">Education / e-Services</a>
        <a href="technology-solutions.php">Technology Solutions</a>
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