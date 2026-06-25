<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::start();
Auth::requireLogin();

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    verifyCsrf();
    $id = (int)($_POST['id'] ?? 0);
    $campaign = Database::fetchOne("SELECT status FROM campaigns WHERE id = ?", [$id]);
    if ($campaign && !in_array($campaign['status'], ['running', 'queued'])) {
        Database::query("DELETE FROM campaigns WHERE id = ?", [$id]);
        flash('success', 'Campaign deleted.');
        logActivity(Auth::userId(), 'campaign_delete', 'campaigns', "Deleted campaign ID {$id}");
    } else {
        flash('error', 'Cannot delete a running campaign. Pause it first.');
    }
    redirect(APP_URL . '/campaigns/index.php');
}

$status = $_GET['status'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15;
$offset  = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($status) {
    $where[]  = 'c.status = ?';
    $params[] = $status;
}
$whereStr = implode(' AND ', $where);

$total     = Database::count("SELECT COUNT(*) FROM campaigns c WHERE {$whereStr}", $params);
$campaigns = Database::fetchAll("
    SELECT c.*, u.name AS created_by_name
    FROM campaigns c
    LEFT JOIN admin_users u ON u.id = c.created_by
    WHERE {$whereStr}
    ORDER BY c.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);
$totalPages = (int)ceil($total / $perPage);

$statusCounts = Database::fetchAll("
    SELECT status, COUNT(*) AS cnt FROM campaigns GROUP BY status
");
$scMap = [];
foreach ($statusCounts as $s) $scMap[$s['status']] = $s['cnt'];

$pageTitle    = 'Campaigns';
$pageSubtitle = 'Manage &rsaquo; Campaigns';
require __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1><i class="fas fa-bullhorn" style="color:var(--secondary);margin-right:8px"></i>Campaigns</h1>
      <p>Create and manage your bulk STK push campaigns</p>
    </div>
    <a href="<?= APP_URL ?>/campaigns/create.php" class="btn btn-secondary btn-lg">
      <i class="fas fa-rocket"></i> New Campaign
    </a>
  </div>
</div>

<!-- Status Filter Tabs -->
<div class="tab-list mb-3" style="border-bottom:none;margin-bottom:0">
  <a href="?" class="tab-btn <?= !$status ? 'active' : '' ?>">
    <i class="fas fa-th-list"></i> All
    <span class="nav-badge" style="background:var(--primary)"><?= $total ?></span>
  </a>
  <?php foreach ([
    'running'   => ['fas fa-bolt',          '#00A651'],
    'queued'    => ['fas fa-clock',          '#0EA5E9'],
    'scheduled' => ['fas fa-calendar-alt',   '#6D28D9'],
    'paused'    => ['fas fa-pause-circle',   '#F59E0B'],
    'completed' => ['fas fa-check-circle',   '#00A651'],
    'draft'     => ['fas fa-file-alt',       '#64748B'],
  ] as $s => [$icon, $color]): ?>
    <a href="?status=<?= $s ?>" class="tab-btn <?= $status === $s ? 'active' : '' ?>">
      <i class="<?= $icon ?>"></i> <?= ucfirst($s) ?>
      <?php if (!empty($scMap[$s])): ?>
        <span class="nav-badge" style="background:<?= $color ?>"><?= $scMap[$s] ?></span>
      <?php endif; ?>
    </a>
  <?php endforeach; ?>
</div>

<!-- Campaigns Table -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-list"></i> Campaign List</div>
    <div style="font-size:13px;color:var(--text-muted)">
      <?= number_format($total) ?> campaign<?= $total !== 1 ? 's' : '' ?>
    </div>
  </div>

  <?php if (empty($campaigns)): ?>
    <div class="empty-state">
      <div class="empty-icon"><i class="fas fa-bullhorn"></i></div>
      <h3>No campaigns found</h3>
      <p>Create your first bulk STK push campaign to get started</p>
      <a href="<?= APP_URL ?>/campaigns/create.php" class="btn btn-secondary" style="margin-top:16px">
        <i class="fas fa-rocket"></i> Create Campaign
      </a>
    </div>
  <?php else: ?>
    <div class="table-wrapper">
      <table class="table">
        <thead>
          <tr>
            <th>Campaign</th>
            <th>Amount</th>
            <th>Recipients</th>
            <th>Progress</th>
            <th>Status</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($campaigns as $c): ?>
          <?php
          $pct    = $c['total_recipients'] > 0 ? round(($c['sent_count'] / $c['total_recipients']) * 100) : 0;
          $succPct = $c['total_recipients'] > 0 ? round(($c['success_count'] / $c['total_recipients']) * 100) : 0;
          $failPct = $c['total_recipients'] > 0 ? round(($c['failed_count'] / $c['total_recipients']) * 100) : 0;
          ?>
          <tr>
            <td>
              <div style="font-weight:700;font-size:14px;color:var(--primary)"><?= e($c['name']) ?></div>
              <?php if ($c['description']): ?>
                <div style="font-size:12px;color:var(--text-muted)"><?= e(mb_strimwidth($c['description'], 0, 60, '…')) ?></div>
              <?php endif; ?>
              <?php if ($c['status'] === 'scheduled' && $c['scheduled_at']): ?>
                <div style="font-size:11px;color:#6D28D9;margin-top:3px">
                  <i class="fas fa-calendar-alt"></i>
                  <?= date('D j M, g:ia', strtotime($c['scheduled_at'])) ?>
                  — <span class="schedule-countdown" data-target="<?= $c['scheduled_at'] ?>">—</span>
                </div>
              <?php endif; ?>
              <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
                By <?= e($c['created_by_name'] ?? 'Unknown') ?>
              </div>
            </td>
            <td>
              <div style="font-weight:700">KES <?= number_format((float)$c['amount'], 2) ?></div>
              <div style="font-size:11px;color:var(--text-muted)">
                Total: KES <?= number_format((float)$c['amount'] * $c['total_recipients']) ?>
              </div>
            </td>
            <td style="text-align:center">
              <div style="font-size:20px;font-weight:800;color:var(--primary)"><?= number_format($c['total_recipients']) ?></div>
              <div style="font-size:11px;color:var(--text-muted)"><?= $c['sent_count'] ?> sent</div>
            </td>
            <td style="min-width:150px">
              <div style="display:flex;gap:6px;margin-bottom:4px;font-size:12px;color:var(--text-muted)">
                <span style="color:var(--success)">✔ <?= $c['success_count'] ?></span>
                <span style="color:var(--danger)">✘ <?= $c['failed_count'] ?></span>
                <span>⏳ <?= $c['pending_count'] ?></span>
              </div>
              <div class="progress progress-sm">
                <div class="progress-bar bg-success" style="width:<?= $succPct ?>%"></div>
                <div class="progress-bar bg-danger"  style="width:<?= $failPct ?>%"></div>
              </div>
              <div style="font-size:11px;color:var(--text-muted);margin-top:3px"><?= $pct ?>% complete</div>
            </td>
            <td><?= statusBadge($c['status']) ?></td>
            <td style="font-size:12px;color:var(--text-muted)"><?= date('d M Y H:i', strtotime($c['created_at'])) ?></td>
            <td>
              <div class="actions">
                <a href="<?= APP_URL ?>/campaigns/view.php?id=<?= $c['id'] ?>" class="btn btn-outline-primary btn-sm" title="View / Send">
                  <i class="fas fa-eye"></i>
                </a>
                <?php if (in_array($c['status'], ['draft'])): ?>
                  <a href="<?= APP_URL ?>/campaigns/create.php?edit=<?= $c['id'] ?>" class="btn btn-light btn-sm" title="Edit">
                    <i class="fas fa-edit"></i>
                  </a>
                <?php endif; ?>
                <?php if (!in_array($c['status'], ['running', 'queued'])): ?>
                  <form method="POST" style="display:inline" onsubmit="return confirm('Delete this campaign? This action cannot be undone.')">
                    <input type="hidden" name="action" value="delete"/>
                    <input type="hidden" name="id" value="<?= $c['id'] ?>"/>
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                    <button class="btn btn-danger btn-sm btn-icon" title="Delete"><i class="fas fa-trash"></i></button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
      <div class="card-footer" style="display:flex;justify-content:center">
        <div class="pagination">
          <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
               class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
          <?php endfor; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
(function() {
  function tickCountdowns() {
    document.querySelectorAll('.schedule-countdown[data-target]').forEach(el => {
      const diff = new Date(el.dataset.target).getTime() - Date.now();
      if (diff <= 0) { el.textContent = 'launching…'; return; }
      const h = Math.floor(diff / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000)   / 1000);
      el.textContent = (h ? h + 'h ' : '') + m + 'm ' + s + 's';
    });
  }
  tickCountdowns();
  setInterval(tickCountdowns, 1000);
})();
</script>
<?php require __DIR__ . '/../templates/footer.php'; ?>
