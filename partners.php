<?php
require_once __DIR__ . '/bootstrap/init.php';
$page_title       = 'Become a Partner';
$page_description = 'Join the Prestigious ICT partner network and access live enrolment reporting, commission statements and withdrawal tools.';
$active_nav       = 'partners';
require PICT_ROOT . '/includes/public/includes/header-public.php';
?>

<section class="page-head">
  <div class="container">
    <div class="breadcrumbs"><a href="./">Home</a><span>/</span>Partners</div>
    <h1>Become a Partner</h1>
    <p>Run a service station under the Prestigious ICT platform, with the tools and reporting you need to operate professionally.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="section-head section-head--left reveal" style="max-width:780px">
      <span class="eyebrow">The partner opportunity</span>
      <h2>Operate a station. Get paid for the work you do.</h2>
      <p>
        Approved partners operate a service station under the Prestigious ICT platform. Every
        enrolment you process is reported into your Partner Portal, and your commission is
        calculated and credited automatically each reporting period.
      </p>
    </div>

    <div class="grid grid--3">
      <div class="card reveal">
        <div class="card__icon"><?= public_service_icon_svg('chart') ?></div>
        <h3>Live reporting</h3>
        <p>See your enrolment statistics period by period, filterable by month, year and station.</p>
      </div>
      <div class="card reveal">
        <div class="card__icon"><?= public_service_icon_svg('file') ?></div>
        <h3>Commission statements</h3>
        <p>Every commission earned is recorded in your financial ledger and viewable from your portal.</p>
      </div>
      <div class="card reveal">
        <div class="card__icon"><?= public_service_icon_svg('mail') ?></div>
        <h3>Withdrawal tools</h3>
        <p>Request withdrawals from your available balance and track them from request to payment.</p>
      </div>
    </div>

    <div class="hr" style="margin:56px 0"></div>

    <div class="section-head reveal" style="max-width:780px">
      <span class="eyebrow">How it works</span>
      <h2>Four steps from application to active partner</h2>
    </div>

    <div class="grid grid--4">
      <div class="card reveal">
        <div class="card__icon" style="background:linear-gradient(135deg,#0b5ed7,#0847a8)">1</div>
        <h3>Apply</h3>
        <p>Complete the registration form with your details, location and NIN number.</p>
      </div>
      <div class="card reveal">
        <div class="card__icon" style="background:linear-gradient(135deg,#0b5ed7,#0847a8)">2</div>
        <h3>Review</h3>
        <p>Our team reviews your application. You will be notified by email once a decision is made.</p>
      </div>
      <div class="card reveal">
        <div class="card__icon" style="background:linear-gradient(135deg,#0b5ed7,#0847a8)">3</div>
        <h3>Onboarding</h3>
        <p>Approved partners complete their bank details and station information in the portal.</p>
      </div>
      <div class="card reveal">
        <div class="card__icon" style="background:linear-gradient(135deg,#0b5ed7,#0847a8)">4</div>
        <h3>Operate</h3>
        <p>Begin delivering services. Enrolment statistics and commissions appear in your portal automatically.</p>
      </div>
    </div>

    <div class="hr" style="margin:56px 0"></div>

    <div class="grid grid--2" style="gap:56px;align-items:center">
      <div class="reveal">
        <h2>What you will need</h2>
        <ul>
          <li>A valid National Identification Number (NIN).</li>
          <li>A reachable email address and phone number.</li>
          <li>A service location — state, LGA and address.</li>
          <li>Bank account details for receiving commission payments.</li>
        </ul>
        <p class="muted" style="font-size:14px">
          Additional details may be requested during onboarding. Applications from partners with
          existing technology, education or identity-services experience are welcome.
        </p>
      </div>
      <div class="reveal">
        <div class="card">
          <h3>Ready to apply?</h3>
          <p>
            Registration takes a few minutes. You will be notified by email once our team has
            reviewed your application.
          </p>
          <a class="btn btn-primary" href="./partner/register.php">Start your application</a>
          <a class="btn btn-ghost" href="./contact-us.php" style="margin-top:10px">Ask a question first</a>
        </div>
      </div>
    </div>
  </div>
</section>

<?php require PICT_ROOT . '/includes/public/includes/footer-public.php'; ?>