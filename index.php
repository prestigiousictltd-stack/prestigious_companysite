<?php
require_once __DIR__ . '/bootstrap/init.php';

if (setting_bool('maintenance_mode', false)) {
    require PICT_ROOT . '/maintenance.php';
    exit;
}

$page_title       = (string) setting('company_name', 'Prestigious ICT Investment Limited');
$page_description = 'Professional technology and digital services across Nigeria — NIN enrolment, NIN verification, education services and partner-operated service stations.';
$active_nav       = 'home';

$services = public_services();
$companyShort = (string) setting('company_short_name', 'Prestigious ICT');

require PICT_ROOT . '/includes/public/includes/header-public.php';
?>

<!-- HERO -->
<section class="hero">
  <div class="container">
    <div class="hero__inner">
      <span class="hero__badge"><span class="dot"></span> Trusted technology and identity services across Nigeria</span>
      <h1>Technology and identity services, delivered with care.</h1>
      <p class="hero__lead">
        Prestigious ICT Investment Limited provides NIN enrolment, NIN verification, digital services
        and education e-services through a growing network of partner-operated stations.
      </p>
      <div class="hero__actions">
        <a class="btn btn-primary btn-lg" href="./services.php">Explore Services</a>
        <a class="btn btn-outline btn-lg" href="./partner/register.php">Become a Partner</a>
        <a class="btn btn-outline btn-lg" href="./contact-us.php">Contact Us</a>
      </div>

            <div class="hero__stats">
        <div class="hero__stat">
          <b>JAMB Services</b>
          <span>Registration, CBT practice and result support</span>
        </div>
        <div class="hero__stat">
          <b>Work Force Services</b>
          <span>Facilities and technical support for candidate screening</span>
        </div>
        <div class="hero__stat">
          <b>MIVA Open University</b>
          <span>Approved facilities for students in Kano</span>
        </div>
        <div class="hero__stat">
          <b>NIN Enrolment</b>
          <span>Supported through partner stations nationwide</span>
        </div>
        <div class="hero__stat">
          <b>Digital &amp; e-Services</b>
          <span>Everyday digital tasks made simple</span>
        </div>
        <div class="hero__stat">
          <b>Partner Portal</b>
          <span>Live enrolment and commission reporting</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ABOUT -->
<section class="section">
  <div class="container">
    <div class="grid grid--2" style="align-items:center;gap:56px">
      <div class="reveal">
        <span class="eyebrow">About the company</span>
        <h2>Built to make essential technology services more accessible.</h2>
        <p>
          Prestigious ICT Investment Limited is a Nigerian technology and digital services company.
          We work with a network of partners to deliver enrolment, verification and digital services
          to individuals and businesses across the country.
        </p>
        <p>
          Our platform gives partners the reporting and financial tools they need to run their
          stations professionally, while giving the organisation clear visibility over
          operations nationwide.
        </p>
        <a class="btn btn-ghost" href="./about-us.php">Learn more about us</a>
      </div>
      <div class="reveal">
        <div class="grid grid--2" style="gap:16px">
          <div class="card" style="padding:22px">
            <div class="card__icon" style="width:42px;height:42px;margin-bottom:12px">
              <?= public_service_icon_svg('chart', 20) ?>
            </div>
            <h3 style="font-size:16px">Data-driven operations</h3>
            <p style="font-size:13.5px;margin:0">Every station reports enrolment statistics by period.</p>
          </div>
          <div class="card" style="padding:22px">
            <div class="card__icon" style="width:42px;height:42px;margin-bottom:12px">
              <?= public_service_icon_svg('users', 20) ?>
            </div>
            <h3 style="font-size:16px">Partner network</h3>
            <p style="font-size:13.5px;margin:0">Trained partners operating under a shared standard.</p>
          </div>
          <div class="card" style="padding:22px">
            <div class="card__icon" style="width:42px;height:42px;margin-bottom:12px">
              <?= public_service_icon_svg('lock', 20) ?>
            </div>
            <h3 style="font-size:16px">Sensitive data handled carefully</h3>
            <p style="font-size:13.5px;margin:0">Restricted access and routine masking of identity data.</p>
          </div>
          <div class="card" style="padding:22px">
            <div class="card__icon" style="width:42px;height:42px;margin-bottom:12px">
              <?= public_service_icon_svg('map-pin', 20) ?>
            </div>
            <h3 style="font-size:16px">Nationwide coverage</h3>
            <p style="font-size:13.5px;margin:0">Stations across multiple states and LGAs.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- SERVICES -->
<section class="section section--soft">
  <div class="container">
    <div class="section-head reveal">
      <span class="eyebrow">What we do</span>
      <h2>Services</h2>
      <p>A focused set of services delivered through trained partner stations across Nigeria.</p>
    </div>

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

<!-- WHY CHOOSE US -->
<section class="section">
  <div class="container">
    <div class="section-head reveal">
      <span class="eyebrow">Why choose us</span>
            <h2>A dependable provider of essential services</h2>
      <p>Practical strengths that matter to the people and businesses we serve.</p>
    </div>

    <div class="features">
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('map-pin', 22) ?></div>
        <h3>National reach</h3>
        <p>Partner stations operating across multiple states, with reporting centralised at the organisation level.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('lock', 22) ?></div>
        <h3>Careful data handling</h3>
        <p>Identity and financial data are handled with restricted access and routine masking.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('chart', 22) ?></div>
        <h3>Clear reporting</h3>
        <p>Partners see enrolment and commission reporting in their portal, updated each reporting period.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('users', 22) ?></div>
        <h3>Trained partners</h3>
        <p>Stations are operated by partners who have been onboarded onto our platform and standards.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('clock', 22) ?></div>
        <h3>Responsive support</h3>
        <p>Partners and customers can reach us through the portal and our public contact channels.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('check', 22) ?></div>
        <h3>Consistent standards</h3>
        <p>Service pricing and turnaround expectations are set centrally and applied consistently.</p>
      </div>
    </div>
  </div>
</section>

<!-- PARTNER CTA -->
<section class="section section--tight">
  <div class="container">
    <div class="cta-band reveal">
      <div>
        <h2>Run a service station under our platform.</h2>
        <p>
          If you operate in technology, identity services, education services or a related field,
          you can apply to become a partner. Approved partners get access to the Partner Portal
          with live enrolment reporting, commission statements and withdrawal tools.
        </p>
      </div>
      <div class="cta-band__actions">
        <a class="btn btn-primary btn-lg" href="./partner/register.php">Become a Partner</a>
        <a class="btn btn-outline btn-lg" href="./partners.php">Learn More</a>
      </div>
    </div>
  </div>
</section>

<!-- CONTACT -->
<section class="section section--soft">
  <div class="container">
    <div class="section-head reveal">
      <span class="eyebrow">Get in touch</span>
      <h2>Contact us</h2>
      <p>Reach out to us for service enquiries, partner applications or general questions.</p>
    </div>

    <div class="grid grid--3">
      <?php if ($addr = setting('company_address')): ?>
        <div class="card reveal" style="text-align:center;align-items:center">
          <div class="card__icon" style="margin-left:auto;margin-right:auto">
            <?= public_service_icon_svg('map-pin') ?>
          </div>
          <h3 style="font-size:17px">Address</h3>
          <p><?= nl2br(e($addr)) ?></p>
        </div>
      <?php endif; ?>
      <?php if ($phone = setting('company_phone')): ?>
        <div class="card reveal" style="text-align:center;align-items:center">
          <div class="card__icon" style="margin-left:auto;margin-right:auto">
            <?= public_service_icon_svg('phone') ?>
          </div>
          <h3 style="font-size:17px">Phone</h3>
          <p><a href="tel:<?= e(preg_replace('/\s+/', '', $phone)) ?>"><?= e($phone) ?></a></p>
        </div>
      <?php endif; ?>
      <?php if ($email = setting('company_email')): ?>
        <div class="card reveal" style="text-align:center;align-items:center">
          <div class="card__icon" style="margin-left:auto;margin-right:auto">
            <?= public_service_icon_svg('mail') ?>
          </div>
          <h3 style="font-size:17px">Email</h3>
          <p><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></p>
        </div>
      <?php endif; ?>
    </div>

    <div class="text-center mt-4">
      <a class="btn btn-primary" href="./contact-us.php">Go to contact form</a>
    </div>
  </div>
</section>

<?php require PICT_ROOT . '/includes/public/includes/footer-public.php'; ?>