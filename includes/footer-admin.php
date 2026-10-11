    </main>
  </div>
</div>
<script>
(function(){
  var t = document.getElementById('admin-menu-toggle');
  var s = document.getElementById('admin-sidebar');
  var b = document.getElementById('admin-sidebar-backdrop');
  if (t && s) {
    t.addEventListener('click', function(){ s.classList.toggle('is-open'); });
  }
  if (b && s) {
    b.addEventListener('click', function(){ s.classList.remove('is-open'); });
  }
})();
</script>
<?php if (!empty($admin_scripts) && is_array($admin_scripts)): ?>
  <?php foreach ($admin_scripts as $src): ?>
    <script src="<?= e($src) ?>"></script>
  <?php endforeach; ?>
<?php endif; ?>
<script src="<?= e(url('/assets/js/password-toggle.js')) ?>"></script>
</body>
</html>