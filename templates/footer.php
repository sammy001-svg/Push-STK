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
// Mobile sidebar + overlay
(function() {
  const toggle  = document.getElementById('sidebar-toggle');
  const sidebar = document.querySelector('.sidebar');

  // Inject overlay element
  const overlay = document.createElement('div');
  overlay.id = 'sidebar-overlay';
  document.body.appendChild(overlay);

  function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.style.display = 'none';
    document.body.style.overflow = '';
  }

  overlay.addEventListener('click', closeSidebar);

  // Override global toggleSidebar defined in main.js
  window.toggleSidebar = function() {
    const isOpen = sidebar.classList.toggle('open');
    if (window.innerWidth <= 1024) {
      overlay.style.display = isOpen ? 'block' : 'none';
      document.body.style.overflow = isOpen ? 'hidden' : '';
    }
  };

  const showToggle = () => {
    if (toggle) toggle.style.display = window.innerWidth <= 1024 ? 'flex' : 'none';
    if (window.innerWidth > 1024) closeSidebar();
  };
  showToggle();
  window.addEventListener('resize', showToggle);
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
