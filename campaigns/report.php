<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mpesa.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::start();
Auth::requireLogin();

$id       = (int)($_GET['id'] ?? 0);
$campaign = Database::fetchOne("
    SELECT c.*, u.name AS created_by_name
    FROM campaigns c
    LEFT JOIN admin_users u ON u.id = c.created_by
    WHERE c.id = ?
", [$id]);

if (!$campaign) {
    flash('error', 'Campaign not found.');
    redirect(APP_URL . '/campaigns/index.php');
}

// Full recipient status breakdown
$breakdown = Database::fetchOne("
    SELECT
        COUNT(*)                            AS total,
        SUM(status = 'success')             AS success,
        SUM(status = 'failed')              AS failed,
        SUM(status = 'cancelled')           AS cancelled,
        SUM(status = 'timeout')             AS timeout,
        SUM(status IN ('pending','processing','sent')) AS pending,
        SUM(CASE WHEN status='success' THEN amount ELSE 0 END) AS collected
    FROM campaign_recipients
    WHERE campaign_id = ?
", [$id]);

$total     = (int)$breakdown['total'];
$success   = (int)$breakdown['success'];
$failed    = (int)$breakdown['failed'];
$cancelled = (int)$breakdown['cancelled'];
$timeout   = (int)$breakdown['timeout'];
$pending   = (int)$breakdown['pending'];
$collected = (float)$breakdown['collected'];
$succRate  = $total > 0 ? round($success / $total * 100, 1) : 0;

// Hourly distribution of successful transactions
$hourly = Database::fetchAll("
    SELECT HOUR(completed_at) AS hr, COUNT(*) AS cnt, SUM(amount) AS amt
    FROM campaign_recipients
    WHERE campaign_id = ? AND status = 'success' AND completed_at IS NOT NULL
    GROUP BY hr ORDER BY hr
", [$id]);

// Successful transactions list (up to 500 for the report)
$successList = Database::fetchAll("
    SELECT cr.phone, c.name AS customer_name, cr.amount, cr.mpesa_receipt,
           cr.completed_at, cr.result_desc
    FROM campaign_recipients cr
    LEFT JOIN customers c ON c.id = cr.customer_id
    WHERE cr.campaign_id = ? AND cr.status = 'success'
    ORDER BY cr.completed_at ASC
    LIMIT 500
", [$id]);

// Failed/cancelled list (up to 200)
$failedList = Database::fetchAll("
    SELECT cr.phone, c.name AS customer_name, cr.amount,
           cr.result_desc, cr.error_message, cr.status, cr.retry_count
    FROM campaign_recipients cr
    LEFT JOIN customers c ON c.id = cr.customer_id
    WHERE cr.campaign_id = ? AND cr.status IN ('failed','cancelled','timeout')
    ORDER BY cr.id ASC
    LIMIT 200
", [$id]);

$duration = '';
if ($campaign['started_at'] && $campaign['completed_at']) {
    $secs = strtotime($campaign['completed_at']) - strtotime($campaign['started_at']);
    $duration = $secs < 60 ? "{$secs}s"
              : ($secs < 3600 ? round($secs/60) . ' min' : round($secs/3600, 1) . ' hrs');
}

$pageTitle = 'Report — ' . $campaign['name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
  <style>
    :root {
      --primary: #0D2B55;
      --secondary: #00A651;
      --danger: #DC2626;
      --warning: #F59E0B;
      --text: #1E293B;
      --muted: #64748B;
      --border: #E2E8F0;
      --bg: #F8FAFC;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: Inter, sans-serif; color: var(--text); background: var(--bg); font-size: 14px; }

    /* ── Screen controls ── */
    .screen-bar {
      background: var(--primary);
      color: #fff;
      padding: 12px 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      flex-wrap: wrap;
    }
    .screen-bar a, .screen-bar button {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; border-radius: 8px; font-size: 13px; font-weight: 600;
      cursor: pointer; text-decoration: none; border: none;
    }
    .btn-back    { background: rgba(255,255,255,.15); color: #fff; }
    .btn-print   { background: var(--secondary);     color: #fff; }
    .btn-csv     { background: rgba(255,255,255,.1); color: #fff; border:1px solid rgba(255,255,255,.25); }

    /* ── Report container ── */
    .report { max-width: 900px; margin: 28px auto 60px; padding: 0 20px; }

    /* ── Header ── */
    .rpt-header {
      background: linear-gradient(135deg, var(--primary) 0%, #1a4080 100%);
      color: #fff; border-radius: 12px; padding: 32px 36px; margin-bottom: 24px;
    }
    .rpt-header .tag { font-size: 11px; opacity: .6; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
    .rpt-header h1   { font-size: 24px; font-weight: 800; margin-bottom: 4px; }
    .rpt-header p    { opacity: .7; font-size: 13px; }
    .meta-grid { display: grid; grid-template-columns: repeat(3,1fr); gap: 16px; margin-top: 24px; }
    .meta-item label { font-size: 10px; opacity:.55; text-transform:uppercase; letter-spacing:.5px; display:block; margin-bottom:2px; }
    .meta-item span  { font-size: 14px; font-weight: 600; }

    /* ── Stat cards ── */
    .stat-row { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 24px; }
    .stat-box {
      background: #fff; border-radius: 10px; border: 1px solid var(--border);
      padding: 18px; text-align: center;
      border-top: 3px solid var(--color, var(--primary));
    }
    .stat-box .val { font-size: 28px; font-weight: 800; color: var(--color, var(--primary)); line-height: 1.1; }
    .stat-box .lbl { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: .5px; margin-top: 4px; }

    /* ── Section ── */
    .section      { background: #fff; border-radius: 10px; border: 1px solid var(--border); margin-bottom: 20px; overflow: hidden; }
    .section-head { padding: 14px 20px; border-bottom: 1px solid var(--border); font-weight: 700; font-size: 14px; color: var(--primary); display:flex; align-items:center; gap:8px; }
    .section-body { padding: 20px; }

    /* ── Progress visual ── */
    .progress-stack { height: 12px; border-radius: 6px; background: var(--border); overflow: hidden; display: flex; margin-bottom: 10px; }
    .progress-stack div { height: 100%; }

    /* ── Table ── */
    table { width: 100%; border-collapse: collapse; font-size: 13px; }
    th { background: var(--bg); color: var(--muted); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; padding: 10px 12px; text-align: left; border-bottom: 2px solid var(--border); }
    td { padding: 10px 12px; border-bottom: 1px solid var(--border); vertical-align: middle; }
    tr:last-child td { border-bottom: none; }
    tbody tr:hover { background: #F8FAFC; }
    code { font-size: 12px; background: var(--bg); padding: 2px 6px; border-radius: 4px; font-family: monospace; }

    /* ── Chart container ── */
    .chart-wrap { display: grid; grid-template-columns: 160px 1fr; gap: 20px; align-items: center; }

    /* ── Print styles ── */
    @media print {
      .screen-bar { display: none !important; }
      body { background: #fff; font-size: 12px; }
      .report { max-width: 100%; margin: 0; padding: 0; }
      .rpt-header { border-radius: 0; }
      .section { border-radius: 0; page-break-inside: avoid; }
      table { font-size: 11px; }
      .stat-box .val { font-size: 22px; }
      @page { margin: 15mm; }
    }

    @media (max-width: 640px) {
      .stat-row  { grid-template-columns: 1fr 1fr; }
      .meta-grid { grid-template-columns: 1fr 1fr; }
      .chart-wrap { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>

<!-- Screen-only controls -->
<div class="screen-bar">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="<?= APP_URL ?>/campaigns/view.php?id=<?= $id ?>" class="btn-back">
      <i class="fas fa-arrow-left"></i> Back to Campaign
    </a>
    <span style="font-size:14px;font-weight:700;opacity:.85"><?= e($campaign['name']) ?> — Report</span>
  </div>
  <div style="display:flex;gap:8px">
    <a href="<?= APP_URL ?>/transactions/index.php?campaign_id=<?= $id ?>&export=csv" class="btn-csv">
      <i class="fas fa-file-csv"></i> Export CSV
    </a>
    <button onclick="window.print()" class="btn-print">
      <i class="fas fa-print"></i> Print / Save PDF
    </button>
  </div>
</div>

<div class="report">

  <!-- ── Header ── -->
  <div class="rpt-header">
    <div class="tag">Campaign Report</div>
    <h1><?= e($campaign['name']) ?></h1>
    <p><?= e($campaign['description'] ?? 'Bulk M-Pesa STK Push Campaign') ?></p>
    <div class="meta-grid">
      <div class="meta-item">
        <label>Amount per recipient</label>
        <span>KES <?= number_format((float)$campaign['amount'], 2) ?></span>
      </div>
      <div class="meta-item">
        <label>Account reference</label>
        <span><?= e($campaign['account_ref']) ?></span>
      </div>
      <div class="meta-item">
        <label>Status</label>
        <span><?= ucfirst($campaign['status']) ?></span>
      </div>
      <?php if ($campaign['started_at']): ?>
      <div class="meta-item">
        <label>Started</label>
        <span><?= date('d M Y, H:i', strtotime($campaign['started_at'])) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($campaign['completed_at']): ?>
      <div class="meta-item">
        <label>Completed</label>
        <span><?= date('d M Y, H:i', strtotime($campaign['completed_at'])) ?></span>
      </div>
      <?php endif; ?>
      <?php if ($duration): ?>
      <div class="meta-item">
        <label>Duration</label>
        <span><?= $duration ?></span>
      </div>
      <?php endif; ?>
      <div class="meta-item">
        <label>Created by</label>
        <span><?= e($campaign['created_by_name'] ?? 'Unknown') ?></span>
      </div>
      <div class="meta-item">
        <label>Report generated</label>
        <span><?= date('d M Y, H:i') ?></span>
      </div>
    </div>
  </div>

  <!-- ── Summary stats ── -->
  <div class="stat-row">
    <div class="stat-box" style="--color:var(--primary)">
      <div class="val"><?= number_format($total) ?></div>
      <div class="lbl">Total Recipients</div>
    </div>
    <div class="stat-box" style="--color:var(--secondary)">
      <div class="val"><?= number_format($success) ?></div>
      <div class="lbl">Successful</div>
    </div>
    <div class="stat-box" style="--color:var(--danger)">
      <div class="val"><?= number_format($failed + $cancelled + $timeout) ?></div>
      <div class="lbl">Failed / Cancelled</div>
    </div>
    <div class="stat-box" style="--color:#8B5CF6">
      <div class="val" style="font-size:20px">KES <?= number_format($collected, 2) ?></div>
      <div class="lbl">Revenue Collected</div>
    </div>
  </div>

  <!-- ── Outcome breakdown ── -->
  <div class="section">
    <div class="section-head"><i class="fas fa-chart-pie" style="color:var(--secondary)"></i> Outcome Breakdown</div>
    <div class="section-body">
      <div class="chart-wrap">
        <canvas id="breakdownChart" width="160" height="160"></canvas>
        <div>
          <?php
          $rows = [
            ['Successful',  $success,              '#00A651'],
            ['Failed',      $failed,               '#DC2626'],
            ['Cancelled',   $cancelled,            '#F59E0B'],
            ['Timed Out',   $timeout,              '#94A3B8'],
            ['Still Pending', $pending,            '#0EA5E9'],
          ];
          foreach ($rows as [$lbl, $val, $clr]):
            if ($total === 0) continue;
            $pct = round($val / $total * 100, 1);
          ?>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
              <div style="display:flex;align-items:center;gap:8px">
                <span style="width:12px;height:12px;border-radius:3px;background:<?= $clr ?>;display:inline-block;flex-shrink:0"></span>
                <span style="font-size:13px"><?= $lbl ?></span>
              </div>
              <div style="text-align:right">
                <span style="font-weight:700;color:<?= $clr ?>"><?= number_format($val) ?></span>
                <span style="color:var(--muted);margin-left:6px;font-size:12px"><?= $pct ?>%</span>
              </div>
            </div>
            <div class="progress-stack" style="height:6px;margin-bottom:12px">
              <div style="width:<?= $pct ?>%;background:<?= $clr ?>"></div>
            </div>
          <?php endforeach; ?>
          <div style="border-top:1px solid var(--border);padding-top:12px;margin-top:4px;display:flex;justify-content:space-between;font-weight:700">
            <span>Success Rate</span>
            <span style="color:<?= $succRate >= 80 ? 'var(--secondary)' : ($succRate >= 50 ? 'var(--warning)' : 'var(--danger)') ?>">
              <?= $succRate ?>%
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if (!empty($hourly)): ?>
  <!-- ── Hourly activity ── -->
  <div class="section">
    <div class="section-head"><i class="fas fa-clock" style="color:var(--secondary)"></i> Hourly Activity</div>
    <div class="section-body">
      <canvas id="hourlyChart" height="80"></canvas>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($successList)): ?>
  <!-- ── Successful transactions ── -->
  <div class="section">
    <div class="section-head">
      <i class="fas fa-check-circle" style="color:var(--secondary)"></i>
      Successful Payments (<?= number_format($success) ?>)
      <?php if ($success > 500): ?>
        <span style="font-size:11px;color:var(--muted);font-weight:400;margin-left:4px">— showing first 500</span>
      <?php endif; ?>
    </div>
    <table>
      <thead><tr>
        <th>#</th><th>Customer</th><th>Phone</th>
        <th style="text-align:right">Amount</th>
        <th>M-Pesa Receipt</th><th>Completed</th>
      </tr></thead>
      <tbody>
      <?php foreach ($successList as $i => $r): ?>
        <tr>
          <td style="color:var(--muted)"><?= $i + 1 ?></td>
          <td style="font-weight:600"><?= e($r['customer_name'] ?? '—') ?></td>
          <td><?= e($r['phone']) ?></td>
          <td style="text-align:right;font-weight:700">KES <?= number_format((float)$r['amount'], 2) ?></td>
          <td>
            <?php if ($r['mpesa_receipt']): ?>
              <code><?= e($r['mpesa_receipt']) ?></code>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td style="color:var(--muted)">
            <?= $r['completed_at'] ? date('H:i:s', strtotime($r['completed_at'])) : '—' ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <?php if (!empty($failedList)): ?>
  <!-- ── Failed / cancelled ── -->
  <div class="section">
    <div class="section-head">
      <i class="fas fa-times-circle" style="color:var(--danger)"></i>
      Failed &amp; Cancelled (<?= number_format($failed + $cancelled + $timeout) ?>)
      <?php if (($failed + $cancelled + $timeout) > 200): ?>
        <span style="font-size:11px;color:var(--muted);font-weight:400;margin-left:4px">— showing first 200</span>
      <?php endif; ?>
    </div>
    <table>
      <thead><tr>
        <th>#</th><th>Customer</th><th>Phone</th>
        <th style="text-align:right">Amount</th>
        <th>Status</th><th>Reason</th><th>Retries</th>
      </tr></thead>
      <tbody>
      <?php foreach ($failedList as $i => $r): ?>
        <tr>
          <td style="color:var(--muted)"><?= $i + 1 ?></td>
          <td style="font-weight:600"><?= e($r['customer_name'] ?? '—') ?></td>
          <td><?= e($r['phone']) ?></td>
          <td style="text-align:right;font-weight:700">KES <?= number_format((float)$r['amount'], 2) ?></td>
          <td>
            <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;
                  background:<?= $r['status']==='failed'?'#FEE2E2':($r['status']==='cancelled'?'#FEF3C7':'#F1F5F9') ?>;
                  color:<?= $r['status']==='failed'?'#DC2626':($r['status']==='cancelled'?'#B45309':'#64748B') ?>">
              <?= ucfirst($r['status']) ?>
            </span>
          </td>
          <td style="color:var(--muted);font-size:12px;max-width:200px">
            <?= e(mb_strimwidth($r['result_desc'] ?? $r['error_message'] ?? '—', 0, 60, '…')) ?>
          </td>
          <td style="text-align:center;color:var(--muted)"><?= $r['retry_count'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

</div><!-- /.report -->

<script>
// Breakdown donut
(function() {
  const ctx = document.getElementById('breakdownChart');
  if (!ctx) return;
  new Chart(ctx, {
    type: 'doughnut',
    data: {
      labels: ['Successful','Failed','Cancelled','Timed Out','Pending'],
      datasets: [{
        data: [<?= $success ?>, <?= $failed ?>, <?= $cancelled ?>, <?= $timeout ?>, <?= $pending ?>],
        backgroundColor: ['#00A651','#DC2626','#F59E0B','#94A3B8','#0EA5E9'],
        borderWidth: 0, hoverOffset: 4,
      }]
    },
    options: {
      cutout: '68%',
      responsive: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${Math.round(ctx.parsed/<?= max($total,1) ?>*1000)/10}%)`
          }
        }
      }
    }
  });
})();

<?php if (!empty($hourly)): ?>
// Hourly bar chart
(function() {
  const hourlyData = <?= json_encode(array_column($hourly, 'cnt', 'hr')) ?>;
  const labels  = Array.from({length:24}, (_,i) => String(i).padStart(2,'0')+':00');
  const counts  = Array.from({length:24}, (_,i) => hourlyData[i] || 0);
  new Chart(document.getElementById('hourlyChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Successful payments',
        data: counts,
        backgroundColor: 'rgba(0,166,81,0.75)',
        borderRadius: 4,
      }]
    },
    options: {
      responsive: true,
      plugins: { legend: { display: false } },
      scales: {
        x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 45 } },
        y: { grid: { color: '#F1F5F9' }, beginAtZero: true, ticks: { font: { size: 11 } } }
      }
    }
  });
})();
<?php endif; ?>
</script>
</body>
</html>
