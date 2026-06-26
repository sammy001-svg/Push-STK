<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::start();
Auth::requireLogin();

$activePeriod = in_array($_GET['period'] ?? '', ['today','7d','30d','month'])
    ? $_GET['period'] : '7d';

$stats        = getDashboardStats();
$periodStats  = getPeriodStats($activePeriod);
$chartData    = getChartDataPeriod($activePeriod);
$topCampaigns = getTopCampaigns(5, $activePeriod);
$groupPerf    = getGroupPerformance();
$recentTx     = getRecentTransactions(8);

$cur   = $periodStats['current'];
$prev  = $periodStats['previous'];

$activeCampaigns = Database::fetchAll("
    SELECT c.*, u.name AS created_by_name
    FROM campaigns c
    LEFT JOIN admin_users u ON u.id = c.created_by
    WHERE c.status IN ('running','queued','paused')
    ORDER BY c.updated_at DESC
    LIMIT 5
");

$scheduledCampaigns = Database::fetchAll("
    SELECT c.*, u.name AS created_by_name
    FROM campaigns c
    LEFT JOIN admin_users u ON u.id = c.created_by
    WHERE c.status = 'scheduled' AND c.scheduled_at > NOW()
    ORDER BY c.scheduled_at ASC
    LIMIT 5
");

$periodLabels = ['today'=>'Today','7d'=>'Last 7 Days','30d'=>'Last 30 Days','month'=>'This Month'];

$pageTitle    = 'Dashboard';
$pageSubtitle = 'Overview &rsaquo; ' . ($periodLabels[$activePeriod] ?? '');
require __DIR__ . '/templates/header.php';
?>

<!-- ─── Period Picker ─────────────────────────────────────── -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
  <div>
    <h2 style="margin:0;font-size:22px;color:var(--primary)">Analytics Overview</h2>
    <div style="font-size:13px;color:var(--text-muted);margin-top:2px" id="period-label">
      <?= $periodLabels[$activePeriod] ?>
      vs previous period
    </div>
  </div>
  <div class="period-picker" style="display:flex;gap:6px;background:var(--bg);padding:5px;border-radius:10px;border:1px solid var(--border)">
    <?php foreach (['today'=>'Today','7d'=>'7 Days','30d'=>'30 Days','month'=>'Month'] as $p => $lbl): ?>
      <button class="period-btn <?= $activePeriod === $p ? 'active' : '' ?>"
              onclick="setPeriod('<?= $p ?>')" data-period="<?= $p ?>">
        <?= $lbl ?>
      </button>
    <?php endforeach; ?>
  </div>
</div>

<!-- ─── KPI Cards ─────────────────────────────────────────── -->
<div class="grid-4 mb-3" id="kpi-cards">

  <!-- Total Sent -->
  <?php $tr = trendArrow($cur['total'], $prev['total']); ?>
  <div class="stat-card" style="--stat-color:#0D2B55;--stat-icon-bg:rgba(13,43,85,0.08)">
    <div class="stat-icon"><i class="fas fa-paper-plane"></i></div>
    <div style="flex:1">
      <div class="stat-value" id="kpi-total"><?= number_format($cur['total']) ?></div>
      <div class="stat-label">Pushes Sent</div>
      <div class="stat-change <?= $tr['dir'] === 'up' ? 'up' : ($tr['dir'] === 'down' ? 'down' : '') ?>">
        <?php if ($tr['pct'] !== null): ?>
          <i class="fas fa-arrow-<?= $tr['dir'] === 'up' ? 'up' : 'down' ?>"></i>
          <?= $tr['pct'] ?>% vs prev period
        <?php else: ?>
          <i class="fas fa-circle" style="font-size:7px"></i> No prior data
        <?php endif; ?>
      </div>
    </div>
    <div class="kpi-prev" title="Previous period"><?= number_format($prev['total']) ?> prev</div>
  </div>

  <!-- Success Rate -->
  <?php $sr = trendArrow($cur['success_rate'], $prev['success_rate']); ?>
  <div class="stat-card" style="--stat-color:#00A651;--stat-icon-bg:rgba(0,166,81,0.08)">
    <div class="stat-icon"><i class="fas fa-check-double"></i></div>
    <div style="flex:1">
      <div class="stat-value" id="kpi-rate"><?= $cur['success_rate'] ?>%</div>
      <div class="stat-label">Success Rate</div>
      <div class="stat-change <?= $sr['dir'] === 'up' ? 'up' : ($sr['dir'] === 'down' ? 'down' : '') ?>">
        <?php if ($sr['pct'] !== null): ?>
          <i class="fas fa-arrow-<?= $sr['dir'] === 'up' ? 'up' : 'down' ?>"></i>
          <?= $sr['pct'] ?>% vs prev period
        <?php else: ?>
          <i class="fas fa-circle" style="font-size:7px"></i>
          <?= $stats['success_count'] ?> total success
        <?php endif; ?>
      </div>
    </div>
    <div class="kpi-prev"><?= $cur['success'] ?> / <?= $cur['total'] ?> sent</div>
  </div>

  <!-- Revenue -->
  <?php $rr = trendArrow($cur['revenue'], $prev['revenue']); ?>
  <div class="stat-card" style="--stat-color:#8B5CF6;--stat-icon-bg:rgba(139,92,246,0.08)">
    <div class="stat-icon"><i class="fas fa-coins"></i></div>
    <div style="flex:1">
      <div class="stat-value" id="kpi-revenue" style="font-size:20px">KES <?= number_format($cur['revenue'], 2) ?></div>
      <div class="stat-label">Revenue Collected</div>
      <div class="stat-change <?= $rr['dir'] === 'up' ? 'up' : ($rr['dir'] === 'down' ? 'down' : '') ?>">
        <?php if ($rr['pct'] !== null): ?>
          <i class="fas fa-arrow-<?= $rr['dir'] === 'up' ? 'up' : 'down' ?>"></i>
          <?= $rr['pct'] ?>% vs prev period
        <?php else: ?>
          <i class="fas fa-money-bill-wave" style="font-size:9px"></i>
          All time: KES <?= number_format($stats['total_amount'], 2) ?>
        <?php endif; ?>
      </div>
    </div>
    <div class="kpi-prev">KES <?= number_format($prev['revenue'], 2) ?> prev</div>
  </div>

  <!-- Customers -->
  <div class="stat-card" style="--stat-color:#0EA5E9;--stat-icon-bg:rgba(14,165,233,0.08)">
    <div class="stat-icon"><i class="fas fa-users"></i></div>
    <div style="flex:1">
      <div class="stat-value"><?= number_format($stats['total_customers']) ?></div>
      <div class="stat-label">Total Customers</div>
      <div class="stat-change" style="color:var(--text-muted)">
        <i class="fas fa-circle" style="font-size:7px"></i>
        <?= $stats['active_campaigns'] ?> active campaign<?= $stats['active_campaigns'] !== 1 ? 's' : '' ?>
        <?php if ($stats['scheduled_campaigns']): ?>
          &bull; <?= $stats['scheduled_campaigns'] ?> scheduled
        <?php endif; ?>
      </div>
    </div>
    <div class="kpi-prev"><?= $stats['total_campaigns'] ?> total campaigns</div>
  </div>
</div>

<!-- ─── Charts Row ────────────────────────────────────────── -->
<div class="grid-2 mb-3" style="grid-template-columns:2fr 1fr">

  <!-- Send Trend Chart -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-chart-area"></i> Send Trend</div>
      <div style="display:flex;gap:14px;font-size:12px;align-items:center;flex-wrap:wrap">
        <span style="display:flex;align-items:center;gap:5px">
          <span style="width:10px;height:10px;border-radius:2px;background:#00A651;display:inline-block"></span> Success
        </span>
        <span style="display:flex;align-items:center;gap:5px">
          <span style="width:10px;height:10px;border-radius:2px;background:#DC2626;display:inline-block"></span> Failed
        </span>
        <span style="display:flex;align-items:center;gap:5px">
          <span style="width:10px;height:10px;border-radius:2px;background:#F59E0B;display:inline-block"></span> Pending
        </span>
        <span style="display:flex;align-items:center;gap:5px">
          <span style="width:22px;height:2px;background:#6366F1;display:inline-block;border-radius:2px"></span>
          <span style="width:6px;height:6px;border-radius:50%;background:#6366F1;display:inline-block;margin-left:-4px"></span>
          Rate %
        </span>
      </div>
    </div>
    <div class="card-body">
      <canvas id="txChart" height="200"></canvas>
    </div>
  </div>

  <!-- Funnel + Donut -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-chart-pie"></i> Breakdown</div>
    </div>
    <div class="card-body" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px">
      <div style="position:relative;width:150px;height:150px">
        <canvas id="rateChart" width="150" height="150"></canvas>
        <div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center">
          <div style="font-size:26px;font-weight:800;color:var(--primary)" id="donut-pct"><?= $cur['success_rate'] ?>%</div>
          <div style="font-size:10px;color:var(--text-muted)">success</div>
        </div>
      </div>

      <!-- Funnel stats -->
      <div style="width:100%;margin-top:18px;display:flex;flex-direction:column;gap:8px">
        <?php foreach ([
          ['Sent',    $cur['total'],   '#0D2B55', 100],
          ['Success', $cur['success'], '#00A651', $cur['total'] > 0 ? round($cur['success']/$cur['total']*100) : 0],
          ['Failed',  $cur['failed'],  '#DC2626', $cur['total'] > 0 ? round($cur['failed']/$cur['total']*100)  : 0],
          ['Pending', $cur['pending'], '#F59E0B', $cur['total'] > 0 ? round($cur['pending']/$cur['total']*100) : 0],
        ] as [$label, $val, $color, $pct]): ?>
          <div>
            <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px">
              <span style="color:var(--text-muted)"><?= $label ?></span>
              <span style="font-weight:700;color:<?= $color ?>"><?= number_format($val) ?></span>
            </div>
            <div class="progress progress-sm">
              <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- ─── Top Campaigns + Group Performance ─────────────────── -->
<div class="grid-2 mb-3">

  <!-- Top Campaigns -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-trophy" style="color:#F59E0B"></i> Top Campaigns</div>
      <a href="<?= APP_URL ?>/campaigns/index.php" class="btn btn-outline-secondary btn-sm">All Campaigns</a>
    </div>
    <?php if (empty($topCampaigns)): ?>
      <div class="empty-state" style="padding:40px">
        <div class="empty-icon"><i class="fas fa-bullhorn"></i></div>
        <h3>No campaigns yet</h3>
      </div>
    <?php else: ?>
      <div class="table-wrapper">
        <table class="table">
          <thead><tr>
            <th>Campaign</th>
            <th style="text-align:center">Sent</th>
            <th style="text-align:center">Rate</th>
            <th style="text-align:right">Revenue</th>
          </tr></thead>
          <tbody>
          <?php foreach ($topCampaigns as $i => $c): ?>
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <div style="width:22px;height:22px;border-radius:50%;background:<?= ['#F59E0B','#94A3B8','#CD7F32'][$i] ?? 'var(--bg)' ?>;
                       display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#fff;flex-shrink:0">
                    <?= $i + 1 ?>
                  </div>
                  <div>
                    <a href="<?= APP_URL ?>/campaigns/view.php?id=<?= $c['id'] ?>"
                       style="font-weight:600;font-size:13px;color:var(--primary)"><?= e($c['name']) ?></a>
                    <div style="font-size:11px;color:var(--text-muted)"><?= statusBadge($c['status']) ?></div>
                  </div>
                </div>
              </td>
              <td style="text-align:center;font-weight:700"><?= number_format($c['sent_count']) ?></td>
              <td style="text-align:center">
                <span style="font-weight:700;color:<?= (float)$c['success_rate'] >= 80 ? 'var(--success)' : ((float)$c['success_rate'] >= 50 ? '#F59E0B' : 'var(--danger)') ?>">
                  <?= $c['success_rate'] ?>%
                </span>
              </td>
              <td style="text-align:right;font-weight:600">KES <?= number_format((float)$c['total_amount']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- Group Performance -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-layer-group" style="color:var(--secondary)"></i> Group Performance</div>
      <a href="<?= APP_URL ?>/customers/groups.php" class="btn btn-outline-secondary btn-sm">Manage</a>
    </div>
    <div class="card-body">
      <?php if (empty($groupPerf)): ?>
        <div class="empty-state" style="padding:20px;text-align:center">
          <p style="color:var(--text-muted)">No group data yet</p>
        </div>
      <?php else: ?>
        <?php
        $maxRev = max(array_column($groupPerf, 'revenue') ?: [1]);
        foreach ($groupPerf as $g):
          $barPct = $maxRev > 0 ? round($g['revenue'] / $maxRev * 100) : 0;
        ?>
        <div style="margin-bottom:14px">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px">
            <div style="display:flex;align-items:center;gap:7px">
              <span style="width:10px;height:10px;border-radius:50%;background:<?= e($g['color']) ?>;display:inline-block;flex-shrink:0"></span>
              <span style="font-size:13px;font-weight:600"><?= e($g['name']) ?></span>
              <span style="font-size:11px;color:var(--text-muted)"><?= $g['customer_count'] ?> members</span>
            </div>
            <span style="font-size:12px;font-weight:700;color:var(--primary)">
              KES <?= number_format((float)$g['revenue']) ?>
            </span>
          </div>
          <div class="progress progress-sm">
            <div class="progress-bar" style="width:<?= $barPct ?>%;background:<?= e($g['color']) ?>"></div>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
            <?= number_format($g['tx_count']) ?> pushes &bull; <?= number_format($g['success_count']) ?> succeeded
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ─── Active Campaigns + Recent Transactions ─────────────── -->
<div class="grid-2 mb-3" style="grid-template-columns:1fr 1fr">

  <!-- Active Campaigns -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-bolt"></i> Active Campaigns</div>
      <a href="<?= APP_URL ?>/campaigns/index.php" class="btn btn-outline-secondary btn-sm">View All</a>
    </div>
    <div class="card-body p-0">
      <?php if (empty($activeCampaigns)): ?>
        <div class="empty-state" style="padding:40px">
          <div class="empty-icon"><i class="fas fa-bullhorn"></i></div>
          <h3>No active campaigns</h3>
          <p>Create and launch a campaign to start sending</p>
          <a href="<?= APP_URL ?>/campaigns/create.php" class="btn btn-secondary btn-sm" style="margin-top:12px">
            <i class="fas fa-plus"></i> New Campaign
          </a>
        </div>
      <?php else: ?>
        <div class="table-wrapper">
          <table class="table">
            <thead><tr>
              <th>Campaign</th>
              <th>Progress</th>
              <th>Status</th>
              <th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($activeCampaigns as $c): ?>
              <?php $pct = $c['total_recipients'] > 0 ? round(($c['sent_count']/$c['total_recipients'])*100) : 0; ?>
              <tr>
                <td>
                  <div style="font-weight:600;font-size:13px"><?= e($c['name']) ?></div>
                  <div style="font-size:11px;color:var(--text-muted)"><?= number_format($c['total_recipients']) ?> recipients</div>
                </td>
                <td style="min-width:120px">
                  <div style="display:flex;align-items:center;gap:8px">
                    <div class="progress progress-sm" style="flex:1">
                      <div class="progress-bar bg-success" style="width:<?= $pct ?>%"></div>
                    </div>
                    <span style="font-size:11px;color:var(--text-muted);white-space:nowrap"><?= $pct ?>%</span>
                  </div>
                </td>
                <td><?= statusBadge($c['status']) ?></td>
                <td>
                  <a href="<?= APP_URL ?>/campaigns/view.php?id=<?= $c['id'] ?>" class="btn btn-outline-primary btn-sm btn-icon">
                    <i class="fas fa-eye"></i>
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Recent Transactions (live) -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">
        <i class="fas fa-receipt"></i> Recent Transactions
        <span id="tx-live-dot" style="width:8px;height:8px;border-radius:50%;background:var(--success);display:inline-block;margin-left:6px;animation:pulse 2s infinite"></span>
      </div>
      <a href="<?= APP_URL ?>/transactions/index.php" class="btn btn-outline-secondary btn-sm">View All</a>
    </div>
    <div id="tx-feed" class="card-body p-0">
      <?php if (empty($recentTx)): ?>
        <div class="empty-state" style="padding:40px">
          <div class="empty-icon"><i class="fas fa-receipt"></i></div>
          <h3>No transactions yet</h3>
        </div>
      <?php else: ?>
        <div class="table-wrapper">
          <table class="table">
            <thead><tr><th>Customer</th><th>Amount</th><th>Status</th><th>Time</th></tr></thead>
            <tbody>
            <?php foreach ($recentTx as $tx): ?>
              <tr>
                <td>
                  <div class="customer-cell">
                    <div class="customer-avatar"><?= strtoupper(substr($tx['customer_name'] ?? $tx['phone'], 0, 1)) ?></div>
                    <div>
                      <div class="customer-name"><?= e($tx['customer_name'] ?? 'Unknown') ?></div>
                      <div class="customer-phone"><?= e($tx['phone']) ?></div>
                    </div>
                  </div>
                </td>
                <td style="font-weight:600">KES <?= number_format((float)$tx['amount'], 2) ?></td>
                <td><?= statusBadge($tx['status']) ?></td>
                <td style="font-size:12px;color:var(--text-muted)"><?= timeAgo($tx['initiated_at']) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ─── Scheduled Campaigns ───────────────────────────────── -->
<?php if (!empty($scheduledCampaigns)): ?>
<div class="card mb-3">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-calendar-alt" style="color:#6D28D9"></i> Upcoming Scheduled Campaigns</div>
    <a href="<?= APP_URL ?>/campaigns/index.php?status=scheduled" class="btn btn-outline-secondary btn-sm">View All</a>
  </div>
  <div class="table-wrapper">
    <table class="table">
      <thead><tr>
        <th>Campaign</th><th>Recipients</th><th>Amount</th><th>Launch Time</th><th>Countdown</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($scheduledCampaigns as $c): ?>
        <tr>
          <td><a href="<?= APP_URL ?>/campaigns/view.php?id=<?= $c['id'] ?>" style="font-weight:600;color:var(--primary)"><?= e($c['name']) ?></a></td>
          <td><?= number_format($c['total_recipients']) ?></td>
          <td style="font-weight:600">KES <?= number_format((float)$c['amount'], 2) ?></td>
          <td style="font-size:13px"><?= date('D j M, g:ia', strtotime($c['scheduled_at'])) ?></td>
          <td><span class="schedule-countdown badge badge-purple" data-target="<?= $c['scheduled_at'] ?>">—</span></td>
          <td><a href="<?= APP_URL ?>/campaigns/view.php?id=<?= $c['id'] ?>" class="btn btn-light btn-sm btn-icon"><i class="fas fa-eye"></i></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ─── Quick Actions ─────────────────────────────────────── -->
<div class="card">
  <div class="card-header"><div class="card-title"><i class="fas fa-bolt"></i> Quick Actions</div></div>
  <div class="card-body">
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <a href="<?= APP_URL ?>/campaigns/create.php" class="btn btn-secondary btn-lg">
        <i class="fas fa-rocket"></i> Launch Campaign
      </a>
      <a href="<?= APP_URL ?>/customers/import.php" class="btn btn-outline-primary btn-lg">
        <i class="fas fa-file-import"></i> Import Customers
      </a>
      <a href="<?= APP_URL ?>/customers/add.php" class="btn btn-outline-primary btn-lg">
        <i class="fas fa-user-plus"></i> Add Customer
      </a>
      <a href="<?= APP_URL ?>/transactions/index.php" class="btn btn-light btn-lg">
        <i class="fas fa-download"></i> View Reports
      </a>
    </div>
  </div>
</div>

<!-- ─── Styles ────────────────────────────────────────────── -->
<style>
.period-btn {
  padding: 6px 14px;
  border: none;
  background: transparent;
  border-radius: 7px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  color: var(--text-muted);
  transition: all 0.15s;
}
.period-btn.active, .period-btn:hover {
  background: #fff;
  color: var(--primary);
  box-shadow: 0 1px 4px rgba(0,0,0,0.1);
}
.kpi-prev {
  font-size: 11px;
  color: var(--text-muted);
  position: absolute;
  bottom: 14px;
  right: 18px;
}
.stat-card { position: relative; }
.stat-change.up   { color: var(--success); }
.stat-change.down { color: var(--danger);  }
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50%       { opacity: 0.3; }
}
</style>

<script>
const APP_URL = '<?= APP_URL ?>';
let txChart, rateChart;
let activePeriod = '<?= $activePeriod ?>';

// ── Build/rebuild charts ───────────────────────────────────
function buildCharts(chart) {
  const labels  = chart.labels;
  const success = chart.success;
  const failed  = chart.failed;
  const pending = chart.pending;

  // Compute daily success rate; null when no data to avoid a misleading 0% point
  const succRate = labels.map((_, i) => {
    const tot = (success[i] || 0) + (failed[i] || 0) + (pending[i] || 0);
    return tot > 0 ? Math.round((success[i] || 0) / tot * 1000) / 10 : null;
  });

  if (txChart) txChart.destroy();
  const ctx = document.getElementById('txChart').getContext('2d');
  txChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels,
      datasets: [
        { label:'Success', data:success, backgroundColor:'rgba(0,166,81,0.85)',   borderRadius:4, borderSkipped:false, yAxisID:'count' },
        { label:'Failed',  data:failed,  backgroundColor:'rgba(220,38,38,0.75)',  borderRadius:4, borderSkipped:false, yAxisID:'count' },
        { label:'Pending', data:pending, backgroundColor:'rgba(245,158,11,0.65)', borderRadius:4, borderSkipped:false, yAxisID:'count' },
        {
          label: 'Success Rate %',
          data: succRate,
          type: 'line',
          yAxisID: 'rate',
          borderColor: '#6366F1',
          backgroundColor: 'rgba(99,102,241,0.08)',
          borderWidth: 2,
          pointRadius: 3,
          pointHoverRadius: 5,
          fill: true,
          tension: 0.35,
          spanGaps: true,
          order: 0,
        },
      ]
    },
    options: {
      responsive:true, maintainAspectRatio:true,
      plugins:{ legend:{ display:false } },
      scales:{
        x:{ stacked:true, grid:{ display:false }, ticks:{ font:{ size:11 }, maxRotation:45 } },
        count:{ stacked:true, grid:{ color:'#F1F5F9' }, ticks:{ font:{ size:11 } }, beginAtZero:true, position:'left' },
        rate:{
          position: 'right',
          beginAtZero: true,
          max: 100,
          grid: { drawOnChartArea: false },
          ticks: {
            font: { size: 10 },
            callback: v => v + '%',
          }
        }
      }
    }
  });

  if (rateChart) rateChart.destroy();
  const rCtx = document.getElementById('rateChart').getContext('2d');
  const curSuccess = <?= json_encode($cur['success']) ?>;
  const curFailed  = <?= json_encode($cur['failed'])  ?>;
  const curPending = <?= json_encode($cur['pending']) ?>;
  rateChart = new Chart(rCtx, {
    type: 'doughnut',
    data: {
      datasets:[{
        data: [curSuccess, curFailed, curPending],
        backgroundColor:['#00A651','#DC2626','#F59E0B'],
        borderWidth:0, hoverOffset:4,
      }]
    },
    options:{ cutout:'72%', responsive:true, plugins:{ legend:{ display:false } } }
  });
}

// ── Period switch via AJAX ─────────────────────────────────
function setPeriod(period) {
  activePeriod = period;

  // Update button styles
  document.querySelectorAll('.period-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.period === period);
  });

  const labels = { today:'Today', '7d':'Last 7 Days', '30d':'Last 30 Days', month:'This Month' };
  document.getElementById('period-label').textContent = (labels[period] || period) + ' vs previous period';

  fetch(APP_URL + '/api/dashboard_stats.php?period=' + period, { credentials:'same-origin' })
    .then(r => r.json())
    .then(d => {
      if (!d.success) return;
      const s = d.stats, t = d.trends;

      // Update KPI cards
      document.getElementById('kpi-total').textContent   = s.total.toLocaleString();
      document.getElementById('kpi-rate').textContent    = s.success_rate + '%';
      document.getElementById('kpi-revenue').textContent = 'KES ' + Math.round(s.revenue).toLocaleString();
      document.getElementById('donut-pct').textContent   = s.success_rate + '%';

      // Rebuild chart
      buildCharts(d.chart);

      // Update URL without reload
      history.replaceState({}, '', '?period=' + period);
    })
    .catch(() => {});
}

// ── Countdown tickers ─────────────────────────────────────
(function() {
  function tickAll() {
    document.querySelectorAll('.schedule-countdown[data-target]').forEach(el => {
      const diff = new Date(el.dataset.target).getTime() - Date.now();
      if (diff <= 0) { el.textContent = 'launching…'; return; }
      const h = Math.floor(diff / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000)   / 1000);
      el.textContent = (h ? h + 'h ' : '') + m + 'm ' + s + 's';
    });
  }
  tickAll();
  setInterval(tickAll, 1000);
})();

// ── Initial chart render ───────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  buildCharts(<?= json_encode($chartData) ?>);
});
</script>

<?php require __DIR__ . '/templates/footer.php'; ?>
