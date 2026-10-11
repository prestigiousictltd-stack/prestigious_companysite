<?php
require_once __DIR__ . '/bootstrap/init.php';
$page_title       = 'Services';
$page_description = 'NIN enrolment, NIN verification, digital services, education e-services and technology solutions.';
$active_nav       = 'services';
$services = public_services();
require PICT_ROOT . '/includes/public/includes/header-public.php';
?>

<section class="page-head">
  <div class="container">
    <div class="breadcrumbs"><a href="./">Home</a><span>/</span>Services</div>
    <h1>Our Services</h1>
    <p>Five service areas delivered through trained partner stations across Nigeria.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="grid grid--3">
      <?php foreach ($services as $s): ?>
        <a class="card reveal" href="./services/<?= e($s['slug']) ?>.php" style="color:inherit">
          <div class="card__icon"><?= public_service_icon_svg($s['icon']) ?></div>
          <h3><?= e($s['title']) ?></h3>
          <p><?= e($s['short']) ?></p>
          <span class="card__link">Learn more &rarr;</span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section section--soft">
  <div class="container">
    <div class="cta-band reveal">
      <div>
        <h2>Not sure which service you need?</h2>
        <p>Tell us what you are trying to accomplish and we will point you in the right direction.</p>
      </div>
      <div class="cta-band__actions">
        <a class="btn btn-primary btn-lg" href="./contact-us.php">Contact Us</a>
        <a class="btn btn-outline btn-lg" href="./faq.php">Read FAQ</a>
      </div>
    </div>
  </div>
</section>

<?php require PICT_ROOT . '/includes/public/includes/footer-public.php'; ?>