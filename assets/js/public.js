/* Prestigious ICT Investment Limited — Public site behaviour */
(function () {
  'use strict';

  // Mobile nav toggle
  document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.querySelector('.nav__toggle');
    var menu = document.getElementById('primary-menu');
    if (toggle && menu) {
      toggle.addEventListener('click', function () {
        var open = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!open));
        menu.classList.toggle('is-open', !open);
      });
    }

    // Header shadow on scroll
    var header = document.querySelector('.site-header');
    if (header) {
      var onScroll = function () {
        header.classList.toggle('is-scrolled', window.scrollY > 8);
      };
      onScroll();
      window.addEventListener('scroll', onScroll, { passive: true });
    }

    // Reveal on scroll
    var reveals = document.querySelectorAll('.reveal');
    if (reveals.length && 'IntersectionObserver' in window) {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) {
          if (e.isIntersecting) {
            e.target.classList.add('is-visible');
            io.unobserve(e.target);
          }
        });
      }, { threshold: 0.12 });
      reveals.forEach(function (el) { io.observe(el); });
    } else {
      reveals.forEach(function (el) { el.classList.add('is-visible'); });
    }

    // Contact form: minimal client-side hint (server is authoritative)
    var contact = document.getElementById('contact-form');
    if (contact) {
      contact.addEventListener('submit', function (ev) {
        var btn = contact.querySelector('button[type=submit]');
        if (btn) {
          btn.disabled = true;
          btn.textContent = 'Sending…';
        }
      });
    }
  });
})();