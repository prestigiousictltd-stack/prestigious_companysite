<?php
require_once __DIR__ . '/bootstrap/init.php';
$page_title       = 'About Us';
$page_description = 'Learn about Prestigious ICT Investment Limited — a Nigerian technology and digital services company.';
$active_nav       = 'about';
require PICT_ROOT . '/includes/public/includes/header-public.php';
?>


<section class="page-head">
  <div class="container">
    <div class="breadcrumbs">
      <a href="<?= e(url('/')) ?>">Home</a><span>/</span>About Us
    </div>
    <h1>About Prestigious ICT Investment Limited</h1>
    <p>A Nigerian technology company delivering ICT consultancy, software development, network and infrastructure services since 2017.</p>
  </div>
</section>

<!-- WHO WE ARE -->
<section class="section">
  <div class="container">
    <div class="prose">
      <h2>Who we are</h2>
      <p>
        Prestigious ICT Investment Limited provides a solution for your trade and industry.
        Depending on the size and field of your organisation, we have different products and
        services to meet your requirements. We provide the optimum and customised solutions
        made for your organisation.
      </p>
      <p>
        The company began its business operations as a hardware and network solutions
        provider in <strong>October 2017</strong>.
      </p>
      <p>
        Prestigious ICT Investment Limited focuses exclusively on high quality and cost-effective
        software development and implementation of services. We are advancing at a tremendous
        pace, with the involvement of skilled and experienced people working across the
        organisation.
      </p>
    </div>
  </div>
</section>

<!-- VISION AND MISSION -->
<section class="section section--soft">
  <div class="container">
    <div class="grid grid--2" style="gap:40px">

      <div class="card reveal">
        <div class="card__icon"><?= public_service_icon_svg('chart') ?></div>
        <h2>Our Vision</h2>
        <p>
          To achieve 100% customer satisfaction by delivering quality products and services at
          an affordable cost. Our forward vision is to strive to become an entity in
          technology-based corporate solutions, capable of demanding unconditional response
          from the targeted niche.
        </p>
        <p>
          We also believe that for our scope of improvisation — sky is the limit — and we are
          always ready to take our achievements to the next level. We are growing and would
          always like to remain on the growing streak.
        </p>
      </div>

      <div class="card reveal">
        <div class="card__icon"><?= public_service_icon_svg('check') ?></div>
        <h2>Our Mission</h2>
        <p>
          To achieve the reputation of a quality, high-standard and reliable solution and
          service provider company in the ICT industry.
        </p>
        <h3 style="margin-top:16px;font-size:15px">Our keys for development</h3>
        <ul style="margin:0 0 16px 0">
          <li>Desire for Excellence</li>
          <li>Trust and confidence build-up</li>
          <li>Innovation</li>
          <li>Transparency</li>
          <li>Team work</li>
        </ul>
        <h3 style="margin-top:16px;font-size:15px">We believe in</h3>
        <ul style="margin:0">
          <li>Motivation</li>
          <li>Collective responsibility and leadership</li>
          <li>Professionalism and ethics</li>
          <li>Adding value to our client needs</li>
        </ul>
      </div>

    </div>
  </div>
</section>

<!-- OUR SERVICES -->
<section class="section">
  <div class="container">
    <div class="section-head reveal">
      <span class="eyebrow">What we do</span>
      <h2>Our Services</h2>
      <p>Four practice areas covering the complete ICT lifecycle for our clients.</p>
    </div>

    <div class="grid grid--2">

      <!-- ICT Consultancy -->
      <div class="card reveal">
        <div class="card__icon"><?= public_service_icon_svg('users') ?></div>
        <h3>ICT Consultancy</h3>
        <p>
          We consult for high-end technology implementation at the client's site, or
          implementation of new technology according to the client's requirements, or
          upgrading and enhancing existing facilities at the client's end with new
          technology integrated with the existing one.
        </p>
        <p>
          Prestigious ICT Investment Limited offers a full range of consulting services to
          help analyse your business requirements for the effective implementation of
          solutions.
        </p>
        <p style="margin-bottom:6px"><strong>Our consulting services cover:</strong></p>
        <ul style="margin:0">
          <li>Strategy planning</li>
          <li>Assessment</li>
          <li>Procurement</li>
          <li>Re-engineering solutions</li>
          <li>Planning audits and best practices</li>
        </ul>
      </div>

      <!-- Software Development -->
      <div class="card reveal">
        <div class="card__icon"><?= public_service_icon_svg('cpu') ?></div>
        <h3>Software Development</h3>
        <p>
          Beside hardware and network solutions, we have design and development expertise
          across diverse platforms, best-of-breed tools and techniques, combined with
          industry best practices. Prestigious ICT Investment Limited offers scalable
          end-to-end application development and management solutions — from requirement
          analysis through to deployment and rollout.
        </p>
        <p>
          We develop software for garments production management, commercial jobs buying,
          accounting for trading, manufacturing houses and conglomerates. We aim to bring
          you the desired software at a reasonable cost.
        </p>
        <p style="margin-bottom:6px"><strong>Our application lifecycle stages:</strong></p>
        <ul style="margin:0">
          <li><strong>Application Development</strong> — end-to-end development from requirement analysis to deployment and rollout.</li>
          <li><strong>Application Maintenance</strong> — changing or enhancing software to meet changing or increasing business demands after rollout.</li>
          <li><strong>Application Support</strong> — first, second and third-line support, plus on-call support (Gold 24×7, Silver and Bronze tiers).</li>
        </ul>
      </div>

      <!-- Network & Infrastructure -->
      <div class="card reveal">
        <div class="card__icon"><?= public_service_icon_svg('globe') ?></div>
        <h3>Network &amp; Infrastructure Services</h3>
        <p>
          Growth in the solution integration (SI) services market is fuelled by the need for
          seamless business processes across an organisation's complete value chain of
          customers, partners, suppliers and employees. Our services enable clients to
          identify, develop and implement best-fit solutions equipped to meet their changing
          business requirements.
        </p>
        <p style="margin-bottom:6px"><strong>Our solution integration services offer:</strong></p>
        <ul style="margin:0 0 12px 0">
          <li>Leverage IT investments</li>
          <li>Minimise risks</li>
          <li>Maximise compatibility</li>
          <li>Maximise interoperability</li>
        </ul>
        <p>
          We provide total project management — from architectural design, integration,
          system and interface development, through to migration — backed by well-defined
          solution frameworks and extensive integration experience with tier-1 service
          providers.
        </p>
      </div>

      <!-- Managed Services -->
      <div class="card reveal">
        <div class="card__icon"><?= public_service_icon_svg('shield-check') ?></div>
        <h3>Managed Services</h3>
        <p>
          Prestigious ICT Investment Limited has the expertise and experience to manage an
          enabling infrastructure and applications, and to run outsourced operations for
          large telecom operations smoothly.
        </p>
        <p>
          Our Managed Services offering covers the entire array of IT outsourcing services
          — including networks, IT infrastructure, applications and business processes.
          This provides our customers the best of both worlds: control and flexibility over
          their information systems without either the pain or the cost of running them.
        </p>
      </div>

    </div>
  </div>
</section>

<!-- AREAS OF EXPERTISE -->
<section class="section section--soft">
  <div class="container">
    <div class="section-head reveal">
      <span class="eyebrow">Technical capability</span>
      <h2>Areas of Expertise</h2>
      <p>
        Our strength lies in a blend of professional, specialised and highly focused
        operations — combining current management practice and IT expertise into
        cost-effective, computer-aided management solutions, products and services.
      </p>
    </div>

    <div class="features">
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('cpu', 22) ?></div>
        <h3>Hardware Supply</h3>
        <p>Hardware at competitive prices, sourced through established channels.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('check', 22) ?></div>
        <h3>Desktop Maintenance &amp; Support</h3>
        <p>Ongoing maintenance and support for client desktop infrastructure.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('shield-check', 22) ?></div>
        <h3>Disaster Recovery &amp; Storage</h3>
        <p>Disaster recovery and storage management solutions for business continuity.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('users', 22) ?></div>
        <h3>Corporate Training</h3>
        <p>Structured training programmes for client personnel and teams.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('globe', 22) ?></div>
        <h3>Web-Based Solutions</h3>
        <p>Web-based support and solution development for modern organisations.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('file', 22) ?></div>
        <h3>Data Capture &amp; Delivery</h3>
        <p>Data capture operations and services delivery at scale.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('cpu', 22) ?></div>
        <h3>System Integration</h3>
        <p>Integrating systems, interfaces and services into a coherent whole.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('chart', 22) ?></div>
        <h3>IT Project Management</h3>
        <p>Full project lifecycle management backed by defined frameworks.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('check', 22) ?></div>
        <h3>Change / Request Implementation</h3>
        <p>Implementation of change requests within existing client environments.</p>
      </div>
      <div class="feature reveal">
        <div class="feature__icon"><?= public_service_icon_svg('clock', 22) ?></div>
        <h3>IT Project Support</h3>
        <p>Ongoing support for IT projects, from deployment through to stability.</p>
      </div>
    </div>
  </div>
</section>

<!-- OUR TEAM -->
<section class="section">
  <div class="container">
    <div class="grid grid--2" style="align-items:flex-start;gap:48px">

      <div class="prose reveal">
        <span class="eyebrow">Our people</span>
        <h2>Our Team</h2>
        <p>
          The company has been formed by a group of professionals with vivid experience and
          wide exposure in information technology. Our people are young qualified business
          graduates and qualified engineers from renowned universities in Nigeria.
        </p>
        <p>
          The resource personnel working in the company have been consistently providing
          reliable support services and consultancy to a wide variety of corporate houses —
          either in the capacity of executive, business partner or consultant.
        </p>
        <p>
          The bottom line of our company philosophy is building long-term business
          partnerships with our clients, where interpersonal relationships, reliability,
          assured quality and target-oriented modern technology are the major building
          blocks.
        </p>
        <p>
          We want to establish ourselves as the best choice in computing and information
          technology services, consultancy and development, by offering the full spectrum
          of services.
        </p>
      </div>

      <div class="reveal">
        <div class="card">
          <h3>Company Details</h3>
          <table class="tbl tbl--compact" style="margin-top:12px">
            <tr>
              <th style="width:170px">Registered Name</th>
              <td>Prestigious ICT Investment Limited</td>
            </tr>
            <tr>
              <th>RC Number</th>
              <td>1447127</td>
            </tr>
            <tr>
              <th>Established</th>
              <td>October 2017</td>
            </tr>
            <tr>
              <th>Industry</th>
              <td>Information &amp; Communications Technology</td>
            </tr>
            <tr>
              <th>Head Office</th>
              <td>
                Zoo Road, Sani Kabara Street,<br>
                Along Sheka 'Yar Kasuwa Road,<br>
                Kumbotso LGA, Kano
              </td>
            </tr>
            <tr>
              <th>Email</th>
              <td><a href="mailto:prestigiousict@gmail.com">prestigiousict@gmail.com</a></td>
            </tr>
            <tr>
              <th>Phone</th>
              <td>
                <a href="tel:+2348161508927">08161508927</a><br>
                <a href="tel:+2348036824417">08036824417</a>
              </td>
            </tr>
          </table>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- PARTNER CTA -->
<section class="section section--tight">
  <div class="container">
    <div class="cta-band reveal">
      <div>
        <h2>Work with us.</h2>
        <p>
          Whether you are a business looking for ICT consultancy and solutions, or an
          individual wanting to operate a service station under our platform, we would
          like to hear from you.
        </p>
      </div>
      <div class="cta-band__actions">
        <a class="btn btn-primary btn-lg" href="<?= e(url('/contact-us.php')) ?>">Contact Us</a>
        <a class="btn btn-outline btn-lg" href="<?= e(root_url('/partner/register.php')) ?>">Become a Partner</a>
      </div>
    </div>
  </div>
</section>


<?php require PICT_ROOT . '/includes/public/includes/footer-public.php'; ?>