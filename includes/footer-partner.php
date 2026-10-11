    </main>
  </div>
</div>
<script>
(function(){
  var t = document.getElementById('admin-menu-toggle');
  var s = document.getElementById('admin-sidebar');
  var b = document.getElementById('admin-sidebar-backdrop');
  if (t && s) t.addEventListener('click', function(){ s.classList.toggle('is-open'); });
  if (b && s) b.addEventListener('click', function(){ s.classList.remove('is-open'); });
})();
</script>
<?php if (!empty($partner_scripts) && is_array($partner_scripts)): ?>
  <?php foreach ($partner_scripts as $src): ?>
    <script src="<?= e($src) ?>"></script>
  <?php endforeach; ?>
<?php endif; ?>
<script src="<?= e(url('/assets/js/password-toggle.js')) ?>"></script>
<?php if (!empty($partner_scripts) && is_array($partner_scripts)): ?>
  <?php foreach ($partner_scripts as $src): ?>
    <script src="<?= e($src) ?>"></script>
  <?php endforeach; ?>
<?php endif; ?>

<script>
/**
 * Partner portal — reveal/hide for masked fields.
 *
 * Same signature as the Super Admin version, but without the audit call,
 * because a partner is only ever viewing their own data.
 *
 * Usage from a button:
 *   onclick="pictReveal('span-id', 'btn-id')"
 */
window.pictReveal = function (spanId, btnId) {
  var span = document.getElementById(spanId);
  var btn  = document.getElementById(btnId);
  if (!span || !btn) {
    console.warn('pictReveal: span or button not found', spanId, btnId);
    return;
  }

  var full   = span.getAttribute('data-full')   || '';
  var masked = span.getAttribute('data-masked') || '';
  var shown  = span.getAttribute('data-revealed') === '1';

  if (!shown) {
    span.textContent = full;
    span.setAttribute('data-revealed', '1');
    btn.textContent = 'Hide';
  } else {
    span.textContent = masked;
    span.removeAttribute('data-revealed');
    btn.textContent = 'Reveal';
  }
};
</script>
</body>
</html>