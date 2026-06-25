  </div><!-- /.page-content -->
</div><!-- /.main-content -->
</div><!-- /.app-wrapper -->

<!-- ─── Toast Container ──────────────────────────────────── -->
<div class="toast-container" id="toast-container"></div>

<!-- ─── Main JS ──────────────────────────────────────────── -->
<script src="<?= APP_URL ?>/assets/js/main.js"></script>

<!-- ─── Scheduled-campaign heartbeat ─────────────────────── -->
<script>
(function cronHeartbeat() {
  function tick() {
    fetch('<?= APP_URL ?>/api/cron.php', { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        if (d.launched && d.launched.length > 0) {
          d.launched.forEach(c => {
            if (window.Toast) Toast.success('Campaign "' + c.name + '" has launched automatically.', 'Scheduled Launch');
          });
          // Soft-reload if we are on the campaigns or dashboard page
          const p = location.pathname;
          if (p.includes('dashboard') || p.includes('campaigns/')) {
            setTimeout(() => location.reload(), 2000);
          }
        }
      })
      .catch(() => {});
  }
  tick();
  setInterval(tick, 60000);
})();
</script>

<?php if (!empty($extraScripts)) echo $extraScripts; ?>

<script>
// Show sidebar toggle on mobile
(function() {
  const toggle = document.getElementById('sidebar-toggle');
  if (window.innerWidth <= 1024 && toggle) toggle.style.display = 'flex';
  window.addEventListener('resize', () => {
    if (toggle) toggle.style.display = window.innerWidth <= 1024 ? 'flex' : 'none';
    if (window.innerWidth > 1024) {
      document.querySelector('.sidebar').classList.remove('open');
    }
  });
})();
</script>
</body>
</html>
