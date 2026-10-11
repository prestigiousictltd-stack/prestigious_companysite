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
<?php if (!empty($sa_scripts) && is_array($sa_scripts)): foreach ($sa_scripts as $src): ?>
  <script src="<?= e($src) ?>"></script>
<?php endforeach; endif; ?>

<script>
/**
 * Generic reveal/hide for masked fields on Super Admin pages.
 * Usage from a button:
 *   onclick="pictReveal('span-id', 'btn-id', 'entity_type', entityId)"
 */
window.pictReveal = function (spanId, btnId, entity, entityId) {
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

    fetch('<?= e(root_url('/superadmin/audit-reveal.php')) ?>', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-CSRF-Token': '<?= e(csrf_token()) ?>'
      },
      body: 'entity=' + encodeURIComponent(entity) + '&id=' + encodeURIComponent(entityId)
    }).catch(function (err) {
      console.warn('audit-reveal fetch failed', err);
    });
  } else {
    span.textContent = masked;
    span.removeAttribute('data-revealed');
    btn.textContent = 'Reveal';
  }
};
</script>
</body>
</html>