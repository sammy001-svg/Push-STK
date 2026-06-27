<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::start();
Auth::requireLogin();

// ── Period filter ────────────────────────────────────────────
$validPeriods = ['today', '7d', '30d', 'month', 'custom'];
$period      = in_array($_GET['period'] ?? '', $validPeriods) ? $_GET['period'] : '30d';
$customStart = trim($_GET['start_date'] ?? '');
$customEnd   = trim($_GET['end_date']   ?? '');
if ($period === 'custom' && (!$customStart || !$customEnd)) $period = '30d';

[$start, $end] = array_slice(periodDates($period, $customStart, $customEnd), 0, 2);

$periodLabels = [
    'today'  => 'Today',
    '7d'     => 'Last 7 days',
    '30d'    => 'Last 30 days',
    'month'  => 'This month',
    'custom' => 'Custom range',
];

// ── Campaign sort ────────────────────────────────────────────
$sortAllow = [
    'name'             => 'c.name',
    'created_at'       => 'c.created_at',
    'total_recipients' => 'c.total_recipients',
    'success_rate'     => 'success_rate',
    'revenue'          => 'revenue',
    'status'           => 'c.status',
];
$sortKey  = array_key_exists($_GET['sort'] ?? '', $sortAllow) ? ($_GET['sort'] ?? '') : 'created_at';
$sortExpr = $sortAllow[$sortKey];
$dir      = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';
$oppDir   = $dir === 'ASC' ? 'DESC' : 'ASC';

$_sortIcon = fn($col) => $col !== $sortKey
    ? '<i class="fas fa-sort" style="opacity:.3;margin-left:4px;font-size:10px"></i>'
    : '<i class="fas fa-sort-' . ($dir === 'ASC' ? 'up' : 'down') . '" style="color:var(--secondary);margin-left:4px;font-size:10px"></i>';

$_sortHref = fn($col) => '?' . http_build_query(array_merge(
    array_filter(['period' => $period, 'start_date' => $customStart, 'end_date' => $customEnd]),
    ['sort' => $col, 'dir' => ($col === $sortKey ? $oppDir : 'DESC')]
));

// ── Campaign list ────────────────────────────────────────────
$campaignRows = Database::fetchAll("
    SELECT
        c.id, c.name, c.amount, c.status,
        c.total_recipients,
        c.sent_count,
        c.success_count,
        c.failed_count,
        c.cancelled_count,
        c.pending_count,
        c.created_at,
        c.started_at,
        c.completed_at,
        u.name AS created_by,
        ROUND(c.success_count * c.amount, 2)                                                AS revenue,
        CASE WHEN c.sent_count > 0
             THEN ROUND(c.success_count / c.sent_count * 100, 1) ELSE 0 END                 AS success_rate,
        CASE WHEN c.started_at IS NOT NULL AND c.completed_at IS NOT NULL
             THEN TIMESTAMPDIFF(SECOND, c.started_at, c.completed_at) ELSE NULL END         AS duration_secs
    FROM campaigns c
    LEFT JOIN admin_users u ON u.id = c.created_by
    WHERE c.created_at BETWEEN ? AND ?
    ORDER BY {$sortExpr} {$dir}
", [$start, $end]);

// ── Period KPIs ──────────────────────────────────────────────
$totalCampaigns = count($campaignRows);
$totalSent      = array_sum(array_column($campaignRows, 'sent_count'));
$totalSuccess   = array_sum(array_column($campaignRows, 'success_count'));
$totalFailed    = array_sum(array_column($campaignRows, 'failed_count'));
$totalRevenue   = array_sum(array_column($campaignRows, 'revenue'));
$avgSuccessRate = $totalSent > 0 ? round($totalSuccess / $totalSent * 100, 1) : 0;

// ── Group performance for period ─────────────────────────────
$groupPerf = [];
try {
    $groupPerf = Database::fetchAll("
        SELECT
            g.name,
            g.color,
            COUNT(DISTINCT cu.id)                                                       AS customer_count,
            COUNT(t.id)                                                                 AS tx_count,
            COALESCE(SUM(t.status = 'success'), 0)                                     AS success_count,
            COALESCE(SUM(CASE WHEN t.status = 'success' THEN t.amount ELSE 0 END), 0)  AS revenue,
            CASE WHEN COUNT(t.id) > 0
                 THEN ROUND(SUM(t.status = 'success') / COUNT(t.id) * 100, 1)
                 ELSE 0 END                                                             AS success_rate
        FROM customer_groups g
        LEFT JOIN customers cu ON cu.group_name = g.name AND cu.status = 1
        LEFT JOIN transactions t ON t.customer_id = cu.id
               AND t.initiated_at BETWEEN ? AND ?
        GROUP BY g.id
        ORDER BY revenue DESC
        LIMIT 10
    ", [$start, $end]);
} catch (Throwable) {}

// ── CSV export ───────────────────────────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="campaign_report_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Campaign', 'Status', 'Created', 'Total Recipients', 'Sent', 'Successful', 'Failed', 'Cancelled', 'Success Rate %', 'Revenue (KES)', 'Amount Each (KES)', 'Duration', 'Created By']);
    foreach ($campaignRows as $c) {
        $dur = $c['duration_secs'] !== null
            ? ($c['duration_secs'] < 60 ? $c['duration_secs'] . 's'
             : ($c['duration_secs'] < 3600 ? round($c['duration_secs'] / 60) . ' min'
             : round($c['duration_secs'] / 3600, 1) . ' hrs'))
            : '';
        fputcsv($out, [
            $c['name'],
            $c['status'],
            date('Y-m-d H:i', strtotime($c['created_at'])),
            $c['total_recipients'],
            $c['sent_count'],
            $c['success_count'],
            $c['failed_count'],
            $c['cancelled_count'],
            $c['success_rate'],
            number_format((float)$c['revenue'], 2),
            number_format((float)$c['amount'], 2),
            $dur,
            $c['created_by'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle    = 'Reports';
$pageSubtitle = 'Reports &rsaquo; Overview &rsaquo; ' . ($periodLabels[$period] ?? '');
require __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1><i class="fas fa-chart-bar" style="color:var(--secondary);margin-right:8px"></i>Reports</h1>
      <p>Campaign performance overview for <strong><?= e($periodLabels[$period]) ?></strong></p>
    </div>
    <a href="?<?= http_build_query(array_filter(array_merge($_GET, ['export' => 'csv']))) ?>"
       class="btn btn-outline-primary">
      <i class="fas fa-file-csv"></i> Export Campaigns CSV
    </a>
  </div>
</div>

<!-- ─── Period Picker ──────────────────────────────────────── -->
<div class="card mb-3" style="padding:14px 20px">
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
    <?php foreach (['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'month' => 'This month'] as $p => $lbl): ?>
      <a href="?period=<?= $p ?>&sort=<?= e($sortKey) ?>&dir=<?= e($dir) ?>"
         class="btn btn-sm <?= $period === $p ? 'btn-secondary' : 'btn-light' ?>">
        <?= $lbl ?>
      </a>
    <?php endforeach; ?>
    <button type="button" class="btn btn-sm <?= $period === 'custom' ? 'btn-secondary' : 'btn-light' ?>"
            onclick="toggleCustom()">
      <i class="fas fa-calendar-alt"></i> Custom
    </button>
    <div id="custom-range" style="display:<?= $period === 'custom' ? 'flex' : 'none' ?>;gap:8px;align-items:center;flex-wrap:wrap">
      <input type="date" id="inp-start" class="form-control form-control-sm" value="<?= e($customStart) ?>" style="width:140px"/>
      <span style="color:var(--text-muted)">to</span>
      <input type="date" id="inp-end"   class="form-control form-control-sm" value="<?= e($customEnd) ?>"  style="width:140px"/>
      <button type="button" class="btn btn-sm btn-primary" onclick="applyCustom()">Apply</button>
    </div>
  </div>
</div>

<!-- ─── KPI Cards ─────────────────────────────────────────── -->
<div class="grid-4 mb-3">
  <div class="stat-card" style="--stat-color:var(--primary);--stat-icon-bg:rgba(13,43,85,0.08)">
    <div class="stat-icon"><i class="fas fa-bullhorn"></i></div>
    <div>
      <div class="stat-value"><?= number_format($totalCampaigns) ?></div>
      <div class="stat-label">Campaigns</div>
    </div>
  </div>
  <div class="stat-card" style="--stat-color:var(--success);--stat-icon-bg:rgba(0,166,81,0.08)">
    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
    <div>
      <div class="stat-value"><?= number_format($totalSuccess) ?></div>
      <div class="stat-label">Successful Pushes</div>
      <div class="stat-change" style="color:var(--success)">
        <i class="fas fa-chart-line"></i> <?= $avgSuccessRate ?>% avg success rate
      </div>
    </div>
  </div>
  <div class="stat-card" style="--stat-color:var(--danger);--stat-icon-bg:rgba(220,38,38,0.08)">
    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
    <div>
      <div class="stat-value"><?= number_format($totalFailed) ?></div>
      <div class="stat-label">Failed Pushes</div>
      <?php if ($totalSent > 0): ?>
        <div class="stat-change down"><i class="fas fa-arrow-down"></i> <?= round($totalFailed/$totalSent*100,1) ?>% fail rate</div>
      <?php endif; ?>
    </div>
  </div>
  <div class="stat-card" style="--stat-color:#8B5CF6;--stat-icon-bg:rgba(139,92,246,0.08)">
    <div class="stat-icon"><i class="fas fa-coins"></i></div>
    <div>
      <div class="stat-value" style="font-size:18px">KES <?= number_format($totalRevenue, 2) ?></div>
      <div class="stat-label">Revenue Collected</div>
    </div>
  </div>
</div>

<!-- ─── Campaign Comparison Table ─────────────────────────── -->
<div class="card mb-3">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-table"></i> Campaign Comparison</div>
    <div style="font-size:13px;color:var(--text-muted)"><?= number_format($totalCampaigns) ?> campaign<?= $totalCampaigns !== 1 ? 's' : '' ?></div>
  </div>

  <?php if (empty($campaignRows)): ?>
    <div class="empty-state">
      <div class="empty-icon"><i class="fas fa-bullhorn"></i></div>
      <h3>No campaigns in this period</h3>
      <p>Try a wider date range or <a href="<?= APP_URL ?>/campaigns/create.php">create a campaign</a>.</p>
    </div>
  <?php else: ?>
    <div class="table-wrapper">
      <table class="table">
        <thead>
          <tr>
            <th><a href="<?= $_sortHref('name') ?>" style="color:inherit;text-decoration:none;display:flex;align-items:center">Campaign <?= $_sortIcon('name') ?></a></th>
            <th><a href="<?= $_sortHref('status') ?>" style="color:inherit;text-decoration:none;display:flex;align-items:center">Status <?= $_sortIcon('status') ?></a></th>
            <th style="text-align:right"><a href="<?= $_sortHref('total_recipients') ?>" style="color:inherit;text-decoration:none">Recipients <?= $_sortIcon('total_recipients') ?></a></th>
            <th style="text-align:center">Outcome</th>
            <th style="text-align:right"><a href="<?= $_sortHref('success_rate') ?>" style="color:inherit;text-decoration:none">Rate <?= $_sortIcon('success_rate') ?></a></th>
            <th style="text-align:right"><a href="<?= $_sortHref('revenue') ?>" style="color:inherit;text-decoration:none">Revenue <?= $_sortIcon('revenue') ?></a></th>
            <th style="text-align:right"><a href="<?= $_sortHref('created_at') ?>" style="color:inherit;text-decoration:none">Date <?= $_sortIcon('created_at') ?></a></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($campaignRows as $c):
            $sent    = (int)$c['sent_count'];
            $succ    = (int)$c['success_count'];
            $fail    = (int)$c['failed_count'];
            $rate    = (float)$c['success_rate'];
            $rateClr = $rate >= 80 ? 'var(--success)' : ($rate >= 50 ? '#F59E0B' : 'var(--danger)');
            $dur     = $c['duration_secs'] !== null
                ? ($c['duration_secs'] < 60 ? $c['duration_secs'] . 's'
                 : ($c['duration_secs'] < 3600 ? round($c['duration_secs']/60) . ' min'
                 : round($c['duration_secs']/3600,1) . ' hrs'))
                : null;
        ?>
          <tr>
            <td>
              <div style="font-weight:600;max-width:220px">
                <a href="<?= APP_URL ?>/campaigns/view.php?id=<?= $c['id'] ?>"
                   style="color:var(--primary);text-decoration:none">
                  <?= e(mb_strimwidth($c['name'], 0, 35, '…')) ?>
                </a>
              </div>
              <?php if ($dur): ?>
                <div style="font-size:11px;color:var(--text-muted);margin-top:2px">
                  <i class="fas fa-clock" style="font-size:10px"></i> <?= $dur ?>
                </div>
              <?php endif; ?>
            </td>
            <td><?= statusBadge($c['status']) ?></td>
            <td style="text-align:right;font-weight:600"><?= number_format((int)$c['total_recipients']) ?></td>
            <td style="min-width:140px">
              <?php if ($sent > 0): ?>
                <?php $succPct = round($succ/$sent*100); $failPct = min(100-$succPct, round($fail/$sent*100)); ?>
                <div style="display:flex;height:6px;border-radius:3px;overflow:hidden;background:#F1F5F9;gap:1px;margin-bottom:4px">
                  <div style="width:<?= $succPct ?>%;background:var(--success)"></div>
                  <div style="width:<?= $failPct ?>%;background:var(--danger)"></div>
                </div>
                <div style="font-size:11px;color:var(--text-muted)">
                  <span style="color:var(--success);font-weight:600"><?= number_format($succ) ?></span> ok &nbsp;
                  <span style="color:var(--danger)"><?= number_format($fail) ?></span> fail
                </div>
              <?php else: ?>
                <span style="color:var(--text-muted);font-size:12px">—</span>
              <?php endif; ?>
            </td>
            <td style="text-align:right;font-weight:700;color:<?= $rateClr ?>">
              <?= $sent > 0 ? $rate . '%' : '—' ?>
            </td>
            <td style="text-align:right;font-weight:600;white-space:nowrap">
              KES <?= number_format((float)$c['revenue'], 2) ?>
            </td>
            <td style="text-align:right;font-size:12px;color:var(--text-muted);white-space:nowrap">
              <?= date('d M Y', strtotime($c['created_at'])) ?>
            </td>
            <td>
              <div style="display:flex;gap:6px">
                <a href="<?= APP_URL ?>/campaigns/view.php?id=<?= $c['id'] ?>"
                   class="btn btn-sm btn-light" title="View campaign">
                  <i class="fas fa-eye"></i>
                </a>
                <a href="<?= APP_URL ?>/campaigns/report.php?id=<?= $c['id'] ?>"
                   class="btn btn-sm btn-light" title="Printable report">
                  <i class="fas fa-file-alt"></i>
                </a>
                <a href="<?= APP_URL ?>/transactions/index.php?campaign_id=<?= $c['id'] ?>&export=csv"
                   class="btn btn-sm btn-light" title="Export transactions CSV">
                  <i class="fas fa-file-csv"></i>
                </a>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr style="background:#F8FAFC;font-weight:700">
            <td colspan="2" style="color:var(--text-muted)">Totals for period</td>
            <td style="text-align:right"><?= number_format($totalSent) ?> sent</td>
            <td style="font-size:12px">
              <span style="color:var(--success)"><?= number_format($totalSuccess) ?> ok</span> &nbsp;
              <span style="color:var(--danger)"><?= number_format($totalFailed) ?> fail</span>
            </td>
            <td style="text-align:right;color:<?= $avgSuccessRate >= 80 ? 'var(--success)' : ($avgSuccessRate >= 50 ? '#F59E0B' : 'var(--danger)') ?>">
              <?= $avgSuccessRate ?>%
            </td>
            <td style="text-align:right">KES <?= number_format($totalRevenue, 2) ?></td>
            <td colspan="2"></td>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>
</div>

<!-- ─── Group Performance ───────────────────────────────────── -->
<?php if (!empty($groupPerf)): ?>
<div class="card mb-3">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-layer-group"></i> Group Performance</div>
    <div style="font-size:13px;color:var(--text-muted)">transactions in <?= e($periodLabels[$period]) ?></div>
  </div>
  <div class="table-wrapper">
    <table class="table">
      <thead>
        <tr>
          <th>Group</th>
          <th style="text-align:right">Customers</th>
          <th style="text-align:right">Transactions</th>
          <th style="text-align:right">Successful</th>
          <th style="text-align:center">Success Rate</th>
          <th style="text-align:right">Revenue</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($groupPerf as $g):
          $gRate    = (float)$g['success_rate'];
          $gRateClr = $gRate >= 80 ? 'var(--success)' : ($gRate >= 50 ? '#F59E0B' : 'var(--danger)');
      ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:8px">
              <span style="width:10px;height:10px;border-radius:50%;background:<?= e($g['color']) ?>;flex-shrink:0;display:inline-block"></span>
              <span style="font-weight:600"><?= e($g['name']) ?></span>
            </div>
          </td>
          <td style="text-align:right"><?= number_format((int)$g['customer_count']) ?></td>
          <td style="text-align:right"><?= number_format((int)$g['tx_count']) ?></td>
          <td style="text-align:right;color:var(--success);font-weight:600"><?= number_format((int)$g['success_count']) ?></td>
          <td style="text-align:center">
            <?php if ($g['tx_count'] > 0): ?>
              <div style="display:flex;align-items:center;gap:8px;justify-content:center">
                <div style="width:80px;height:6px;border-radius:3px;background:#F1F5F9;overflow:hidden">
                  <div style="width:<?= $gRate ?>%;height:100%;background:<?= $gRateClr ?>"></div>
                </div>
                <span style="font-weight:700;color:<?= $gRateClr ?>;font-size:13px"><?= $gRate ?>%</span>
              </div>
            <?php else: ?>
              <span style="color:var(--text-muted)">—</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;font-weight:600">KES <?= number_format((float)$g['revenue'], 2) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- ─── Quick Links ─────────────────────────────────────────── -->
<div class="grid-3">
  <a href="<?= APP_URL ?>/transactions/index.php?date_from=<?= urlencode(date('Y-m-d', strtotime($start))) ?>&date_to=<?= urlencode(date('Y-m-d', strtotime($end))) ?>"
     class="card" style="text-decoration:none;color:inherit;display:block;padding:20px;transition:box-shadow .2s"
     onmouseover="this.style.boxShadow='0 4px 20px rgba(0,0,0,.1)'" onmouseout="this.style.boxShadow=''">
    <div style="display:flex;align-items:center;gap:14px">
      <div style="width:44px;height:44px;border-radius:10px;background:rgba(13,43,85,0.08);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:20px">
        <i class="fas fa-receipt"></i>
      </div>
      <div>
        <div style="font-weight:700;font-size:15px">Transaction Log</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Filterable per-push history</div>
      </div>
      <i class="fas fa-chevron-right" style="margin-left:auto;color:var(--text-muted)"></i>
    </div>
  </a>

  <a href="<?= APP_URL ?>/campaigns/index.php"
     class="card" style="text-decoration:none;color:inherit;display:block;padding:20px;transition:box-shadow .2s"
     onmouseover="this.style.boxShadow='0 4px 20px rgba(0,0,0,.1)'" onmouseout="this.style.boxShadow=''">
    <div style="display:flex;align-items:center;gap:14px">
      <div style="width:44px;height:44px;border-radius:10px;background:rgba(0,166,81,0.08);display:flex;align-items:center;justify-content:center;color:var(--success);font-size:20px">
        <i class="fas fa-bullhorn"></i>
      </div>
      <div>
        <div style="font-weight:700;font-size:15px">All Campaigns</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px">Manage and launch campaigns</div>
      </div>
      <i class="fas fa-chevron-right" style="margin-left:auto;color:var(--text-muted)"></i>
    </div>
  </a>

  <a href="<?= APP_URL ?>/transactions/index.php?status=failed"
     class="card" style="text-decoration:none;color:inherit;display:block;padding:20px;transition:box-shadow .2s"
     onmouseover="this.style.boxShadow='0 4px 20px rgba(0,0,0,.1)'" onmouseout="this.style.boxShadow=''">
    <div style="display:flex;align-items:center;gap:14px">
      <div style="width:44px;height:44px;border-radius:10px;background:rgba(220,38,38,0.08);display:flex;align-items:center;justify-content:center;color:var(--danger);font-size:20px">
        <i class="fas fa-times-circle"></i>
      </div>
      <div>
        <div style="font-weight:700;font-size:15px">Failed Transactions</div>
        <div style="font-size:12px;color:var(--text-muted);margin-top:2px">All failed pushes across campaigns</div>
      </div>
      <i class="fas fa-chevron-right" style="margin-left:auto;color:var(--text-muted)"></i>
    </div>
  </a>
</div>

<script>
function toggleCustom() {
  const el = document.getElementById('custom-range');
  el.style.display = el.style.display === 'none' ? 'flex' : 'none';
}
function applyCustom() {
  const s = document.getElementById('inp-start').value;
  const e = document.getElementById('inp-end').value;
  if (!s || !e) { alert('Please select both start and end dates.'); return; }
  window.location = '?period=custom&start_date=' + encodeURIComponent(s)
    + '&end_date=' + encodeURIComponent(e)
    + '&sort=<?= urlencode($sortKey) ?>&dir=<?= urlencode($dir) ?>';
}
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
