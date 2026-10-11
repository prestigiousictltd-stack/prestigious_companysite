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
        <a href="./services/nin-enrolment.php">NIN Enrolment</a>
        <a href="./services/nin-verification.php">NIN Verification</a>
        <a href="./services/digital-services.php">Digital Services</a>
        <a href="./services/education-services.php">Education / e-Services</a>
        <a href="./services/technology-solutions.php">Technology Solutions</a>
      </div>

      <div>
        <h4>Company</h4>
        <a href="./about-us.php">About Us</a>
        <a href="./partners.php">Partner With Us</a>
        <a href="./why-choose-us.php">Why Choose Us</a>
        <a href="./faq.php">FAQ</a>
        <a href="./contact-us.php">Contact</a>
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
        <a href="./privacy-policy.php">Privacy Policy</a>
        &nbsp;·&nbsp;
        <a href="./terms-and-conditions.php">Terms &amp; Conditions</a>
        &nbsp;·&nbsp;
        <a href="./login.php">Sign In</a>
      </div>
    </div>
  </div>
</footer>

<script src="./assets/vendor/bootstrap/bootstrap.bundle.min.js"></script>
<script src="./assets/js/public.js"></script>
<script src="<?= e(url('/assets/js/password-toggle.js')) ?>"></script>
</body>
</html>