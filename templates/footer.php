  </div><!-- /.page-content -->
</div><!-- /.main-content -->
</div><!-- /.app-wrapper -->

<!-- ─── Toast Container ──────────────────────────────────── -->
<div class="toast-container" id="toast-container"></div>

<!-- ─── Main JS ──────────────────────────────────────────── -->
<script src="<?= APP_URL ?>/assets/js/main.js?v=<?= filemtime(__DIR__ . '/../assets/js/main.js') ?>"></script>

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

// Topbar user dropdown
function toggleUserMenu() {
  const dd  = document.getElementById('topbar-dropdown');
  const chv = document.getElementById('topbar-chevron');
  if (!dd) return;
  const open = dd.style.display === 'block';
  dd.style.display  = open ? 'none' : 'block';
  if (chv) chv.style.transform = open ? '' : 'rotate(180deg)';
}
document.addEventListener('click', function(e) {
  const btn = document.getElementById('topbar-user-btn');
  const dd  = document.getElementById('topbar-dropdown');
  if (btn && dd && !btn.contains(e.target)) {
    dd.style.display = 'none';
    const chv = document.getElementById('topbar-chevron');
    if (chv) chv.style.transform = '';
  }
});
</script>
</body>
</html>
