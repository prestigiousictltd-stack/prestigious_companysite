<?php
require_once __DIR__ . '/bootstrap/init.php';
$page_title       = 'Frequently Asked Questions';
$page_description = 'Answers to common questions about our services and the partner programme.';
$active_nav       = 'faq';

$faqs = [
    ['q' => 'What services does Prestigious ICT Investment Limited provide?',
     'a' => 'We provide NIN enrolment, NIN verification, digital services, education-related e-services, and the technology platform that supports our partner station network.'],
    ['q' => 'How do I find a service station near me?',
     'a' => 'Contact us through the contact page with your location and we will direct you to the nearest partner station offering the service you need.'],
    ['q' => 'How do I become a partner?',
     'a' => 'Complete the registration form on the Become a Partner page. You will need a valid NIN, an email address, a phone number and your service location. Applications are reviewed by our team and you will be notified by email.'],
    ['q' => 'How long does partner approval take?',
     'a' => 'Applications are reviewed by our team as they come in. We will notify you by email as soon as a decision has been made.'],
    ['q' => 'What documents do I need for NIN enrolment?',
     'a' => 'You will need a valid means of identification and any supporting documents required by the enrolment process. Requirements can vary; we recommend contacting your nearest station for guidance before your visit.'],
    ['q' => 'How does partner commission work?',
     'a' => 'Every approved partner has an agreed commission percentage. When enrolment records are imported into the platform for a reporting period, commission is calculated and credited automatically. Historical records keep the commission rate that applied at the time they were created.'],
    ['q' => 'When can partners withdraw their commission?',
     'a' => 'Partners can request a withdrawal at any time from their available balance. Requests are reviewed and processed by our team, and the status of each request is visible in the Partner Portal.'],
    ['q' => 'Is my personal data safe?',
     'a' => 'Yes. We store identity data securely, restrict access by role, mask NIN values in routine displays, and log sensitive actions for audit purposes. Please read our Privacy Policy for full details.'],
    ['q' => 'How do I contact support?',
     'a' => 'You can use the contact form on our website, or reach us by phone or email using the details in the footer.'],
];
require PICT_ROOT . '/includes/public/includes/header-public.php';
?>

<section class="page-head">
  <div class="container">
    <div class="breadcrumbs"><a href="./">Home</a><span>/</span>FAQ</div>
    <h1>Frequently Asked Questions</h1>
    <p>Quick answers to questions we are often asked.</p>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="faq">
      <?php foreach ($faqs as $i => $f): ?>
        <details class="faq__item"<?= $i === 0 ? ' open' : '' ?>>
          <summary><?= e($f['q']) ?></summary>
          <p><?= e($f['a']) ?></p>
        </details>
      <?php endforeach; ?>
    </div>

    <div class="text-center mt-4">
      <p class="muted">Did not find what you were looking for?</p>
      <a class="btn btn-primary" href="./contact-us.php">Contact Us</a>
    </div>
  </div>
</section>

<?php require PICT_ROOT . '/includes/public/includes/footer-public.php'; ?>