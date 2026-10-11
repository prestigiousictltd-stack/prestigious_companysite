<?php
require_once __DIR__ . '/bootstrap/init.php';
$page_title       = 'Terms & Conditions';
$page_description = 'Terms of use for the Prestigious ICT Investment Limited website and portal.';
$companyName = (string) setting('company_name', 'Prestigious ICT Investment Limited');
require PICT_ROOT . '/includes/public/includes/header-public.php';
?>

<section class="page-head">
  <div class="container">
    <div class="breadcrumbs"><a href="./">Home</a><span>/</span>Terms &amp; Conditions</div>
    <h1>Terms &amp; Conditions</h1>
    <p>Terms of use for our website and portal.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="legal">
      <p class="muted"><em>Last updated: <?= date('F Y') ?></em></p>

      <h2>1. Acceptance</h2>
      <p>
        By accessing or using this website or any part of the <?= e($companyName) ?>
        platform, you agree to these terms. If you do not agree, please do not use the
        website or platform.
      </p>

      <h2>2. Use of the platform</h2>
      <p>
        You agree to use the platform only for lawful purposes and in accordance with these
        terms and any applicable policy. You are responsible for the accuracy of information
        you submit and for keeping your login credentials confidential.
      </p>

      <h2>3. Partner accounts</h2>
      <p>
        Partner accounts are subject to review and approval. We may suspend, deactivate or
        reject any account that we believe has breached these terms, submitted false
        information, or acted in a way that is harmful to applicants, partners or the
        organisation.
      </p>

      <h2>4. Commission and withdrawals</h2>
      <p>
        Partner commission is calculated according to the commission rate that applies at
        the effective date of each reporting period and is recorded in an append-only
        financial ledger. Withdrawal requests are subject to review and are paid from the
        partner's available balance only.
      </p>

      <h2>5. Intellectual property</h2>
      <p>
        All content on the website, including text, graphics, logos and code, is owned by
        or licensed to <?= e($companyName) ?> and may not be copied, reproduced or
        distributed without our written permission.
      </p>

      <h2>6. Limitation of liability</h2>
      <p>
        To the fullest extent permitted by law, we are not liable for any indirect,
        incidental, special or consequential loss arising out of your use of the website
        or platform, or your reliance on any information it contains.
      </p>

      <h2>7. Third-party services</h2>
      <p>
        The platform may refer to services delivered by third parties. We do not control
        those services and are not responsible for their content or conduct.
      </p>

      <h2>8. Governing law</h2>
      <p>
        These terms are governed by the laws of the Federal Republic of Nigeria, and
        any dispute arising from them is subject to the exclusive jurisdiction of the
        Nigerian courts.
      </p>

      <h2>9. Changes</h2>
      <p>
        We may update these terms from time to time. The "Last updated" date at the top
        reflects the most recent revision.
      </p>

      <h2>10. Contact</h2>
      <ul>
        <?php if ($e = setting('company_email')): ?><li>Email: <a href="mailto:<?= e($e) ?>"><?= e($e) ?></a></li><?php endif; ?>
        <?php if ($p = setting('company_phone')): ?><li>Phone: <?= e($p) ?></li><?php endif; ?>
      </ul>
    </div>
  </div>
</section>

<?php require PICT_ROOT . '/includes/public/includes/footer-public.php'; ?>