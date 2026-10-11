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
    'home'          => ['label' => 'Home',           'url' => './'],
    'about'         => ['label' => 'About',          'url' => './about-us.php'],
    'services'      => ['label' => 'Services',       'url' => './services.php'],
    'partners'      => ['label' => 'Partners',       'url' => './partners.php'],
    'why-choose-us' => ['label' => 'Why Us',         'url' => './why-choose-us.php'],
    'faq'           => ['label' => 'FAQ',            'url' => './faq.php'],
    'contact'       => ['label' => 'Contact',        'url' => './contact-us.php'],
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

<link rel="stylesheet" href="./assets/css/public.css">
</head>
<body>

<header class="site-header">
  <div class="container">
    <nav class="nav" aria-label="Primary">
      <a class="brand" href="./">
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
        <a class="btn btn-ghost btn-sm" href="../partner/register.php">
          <span class="label">Become a Partner</span>
        </a>
        <a class="btn btn-primary btn-sm" href="../partner/login.php">Partner Login</a>
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
  <script src="./assets/js/public.js"></script>
