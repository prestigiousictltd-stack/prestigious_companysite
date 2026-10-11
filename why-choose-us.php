<?php
require_once __DIR__ . '/bootstrap/init.php';
$page_title       = 'Why Choose Us';
$page_description = 'Why individuals, businesses and partners choose Prestigious ICT Investment Limited.';
$active_nav       = 'why-choose-us';
require PICT_ROOT . '/includes/public/includes/header-public.php';
?>

<section class="page-head">
  <div class="container">
    <div class="breadcrumbs"><a href="./">Home</a><span>/</span>Why Choose Us</div>
    <h1>Why Choose Us</h1>
    <p>The practical strengths that matter to the people, businesses and partners we work with.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="features">
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('map-pin', 22) ?></div>
        <h3>Nationwide station network</h3>
        <p>Our partner stations operate across multiple states and LGAs, bringing services closer to where people live and work.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('check', 22) ?></div>
        <h3>Consistent service standards</h3>
        <p>Service pricing and turnaround expectations are set centrally and applied consistently across every station.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('lock', 22) ?></div>
        <h3>Data handled with care</h3>
        <p>Identity data is stored securely, access is restricted by role, and NIN values are masked in routine displays.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('chart', 22) ?></div>
        <h3>Transparent partner reporting</h3>
        <p>Partners see their own enrolment statistics and commission ledger. Nothing is hidden or recalculated behind the scenes.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('users', 22) ?></div>
        <h3>Trained, accountable partners</h3>
        <p>Every station is operated by a partner who has been onboarded onto the platform and agreed to our standards.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('clock', 22) ?></div>
        <h3>Responsive support</h3>
        <p>Partners and customers can reach us through the portal and our public contact channels, with prompt responses.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('cpu', 22) ?></div>
        <h3>Purpose-built platform</h3>
        <p>Our portal is designed specifically for partner-operated service stations — not adapted from generic software.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('file', 22) ?></div>
        <h3>Clear financial records</h3>
        <p>Every commission earned and every withdrawal made is recorded in an append-only ledger that partners can audit themselves.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('shield-check', 22) ?></div>
        <h3>Security by design</h3>
        <p>Role-based access, audit logging and CSRF protection are built into every part of the platform.</p>
      </div>
    </div>
  </div>
</section>

<section class="section section--soft">
  <div class="container">
    <div class="cta-band reveal">
      <div>
        <h2>Experience the difference.</h2>
        <p>Whether you are a customer or a prospective partner, we would like to hear from you.</p>
      </div>
      <div class="cta-band__actions">
        <a class="btn btn-primary btn-lg" href="./contact-us.php">Contact Us</a>
        <a class="btn btn-outline btn-lg" href="./partner/register.php">Become a Partner</a>
      </div>
    </div>
  </div>
</section>

<?php require PICT_ROOT . '/includes/public/includes/footer-public.php'; ?>