<?php
require_once __DIR__ . '/bootstrap/init.php';
$page_title       = 'Privacy Policy';
$page_description = 'How Prestigious ICT Investment Limited collects, uses and protects personal data.';
$companyName = (string) setting('company_name', 'Prestigious ICT Investment Limited');
require PICT_ROOT . '/includes/public/includes/header-public.php';
?>

<section class="page-head">
  <div class="container">
    <div class="breadcrumbs"><a href="./">Home</a><span>/</span>Privacy Policy</div>
    <h1>Privacy Policy</h1>
    <p>How we collect, use and protect personal data.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="legal">
      <p class="muted"><em>Last updated: <?= date('F Y') ?></em></p>

      <h2>1. Introduction</h2>
      <p>
        <?= e($companyName) ?> ("we", "our", "us") is committed to handling personal data
        responsibly. This policy explains what we collect, why we collect it, how we
        protect it, and the rights available to individuals whose data we process.
      </p>

      <h2>2. Data we collect</h2>
      <p>Depending on how you interact with us, we may collect:</p>
      <ul>
        <li><strong>Contact information</strong> — name, email address, phone number and message content when you use the contact form.</li>
        <li><strong>Partner application data</strong> — full name, email, phone, National Identification Number (NIN), location (state and LGA), business name and bank account details.</li>
        <li><strong>Enrolment reporting data</strong> — aggregated enrolment statistics reported by partner stations for defined periods.</li>
        <li><strong>Usage data</strong> — IP address, user agent and log information recorded when you access our platform.</li>
      </ul>

      <h2>3. How we use it</h2>
      <ul>
        <li>To respond to enquiries submitted through our website.</li>
        <li>To review and process partner applications.</li>
        <li>To calculate and pay partner commission.</li>
        <li>To maintain security and audit trails for sensitive operations.</li>
        <li>To comply with applicable legal obligations.</li>
      </ul>

      <h2>4. How we protect it</h2>
      <ul>
        <li>Access is restricted by role across three separate portals.</li>
        <li>Identity values such as NIN and bank account numbers are masked in routine displays.</li>
        <li>Sensitive actions are logged in an append-only audit trail.</li>
        <li>Transport is encrypted with HTTPS.</li>
        <li>Passwords are stored only as one-way hashes.</li>
      </ul>

      <h2>5. Sharing</h2>
      <p>
        We do not sell personal data. We may share data with service providers who help us
        operate the platform (for example hosting and email delivery), and where required by
        law or by a competent authority.
      </p>

      <h2>6. Retention</h2>
      <p>
        We retain data for as long as necessary for the purpose it was collected, for the
        duration of any partner relationship, and for any period required by applicable law.
      </p>

      <h2>7. Your rights</h2>
      <p>
        Depending on applicable law, you may have the right to access, correct or request
        deletion of your personal data. To exercise these rights, contact us using the
        details below.
      </p>

      <h2>8. Cookies and sessions</h2>
      <p>
        Our portal uses session cookies to authenticate you and protect against cross-site
        request forgery. These cookies are necessary for the platform to function. We do not
        use tracking or advertising cookies on the public website.
      </p>

      <h2>9. Changes to this policy</h2>
      <p>
        We may update this policy from time to time. The "Last updated" date at the top
        reflects the most recent revision.
      </p>

      <h2>10. Contact</h2>
      <p>
        For privacy questions or to exercise your rights, contact us:
      </p>
      <ul>
        <?php if ($e = setting('company_email')): ?><li>Email: <a href="mailto:<?= e($e) ?>"><?= e($e) ?></a></li><?php endif; ?>
        <?php if ($p = setting('company_phone')): ?><li>Phone: <?= e($p) ?></li><?php endif; ?>
        <?php if ($a = setting('company_address')): ?><li>Address: <?= e($a) ?></li><?php endif; ?>
      </ul>
    </div>
  </div>
</section>

<?php require PICT_ROOT . '/includes/public/includes/footer-public.php'; ?>