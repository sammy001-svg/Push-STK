<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mpesa.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::start();
Auth::requireLogin();

$id       = (int)($_GET['id'] ?? 0);
$campaign = Database::fetchOne("SELECT c.*, u.name AS created_by_name FROM campaigns c LEFT JOIN admin_users u ON u.id = c.created_by WHERE c.id = ?", [$id]);

if (!$campaign) {
    flash('error', 'Campaign not found.');
    redirect(APP_URL . '/campaigns/index.php');
}

// Recipients with pagination + search + sort
$rPage    = max(1, (int)($_GET['rp'] ?? 1));
$rPer     = 20;
$rStatus  = $_GET['rs'] ?? '';
$rSearch  = trim($_GET['rq'] ?? '');

$rSortAllow = ['status' => 'cr.status', 'completed_at' => 'cr.completed_at', 'retry_count' => 'cr.retry_count'];
$rSortKey   = array_key_exists($_GET['rsort'] ?? '', $rSortAllow) ? ($_GET['rsort'] ?? '') : '';
$rSort      = $rSortKey ? $rSortAllow[$rSortKey] : 'cr.id';
$rDir       = strtoupper($_GET['rdir'] ?? '') === 'DESC' ? 'DESC' : 'ASC';

$rWhere  = ['cr.campaign_id = ?'];
$rParams = [$id];
if ($rStatus) { $rWhere[] = 'cr.status = ?'; $rParams[] = $rStatus; }
if ($rSearch) {
    $rWhere[]  = '(c.name LIKE ? OR cr.phone LIKE ?)';
    $rParams[] = '%' . $rSearch . '%';
    $rParams[] = '%' . $rSearch . '%';
}
$rWhereStr = implode(' AND ', $rWhere);
$rOffset   = ($rPage - 1) * $rPer;

$rTotal = Database::count(
    "SELECT COUNT(*) FROM campaign_recipients cr LEFT JOIN customers c ON c.id = cr.customer_id WHERE {$rWhereStr}",
    $rParams
);
$recipients = Database::fetchAll("
    SELECT cr.*, c.name AS customer_name
    FROM campaign_recipients cr
    LEFT JOIN customers c ON c.id = cr.customer_id
    WHERE {$rWhereStr}
    ORDER BY {$rSort} {$rDir}
    LIMIT {$rPer} OFFSET {$rOffset}
", $rParams);
$rPages = (int)ceil($rTotal / $rPer);

$pct        = $campaign['total_recipients'] > 0 ? round(($campaign['sent_count']    / $campaign['total_recipients']) * 100)   : 0;
$succPct    = $campaign['total_recipients'] > 0 ? round(($campaign['success_count'] / $campaign['total_recipients']) * 100, 1) : 0;
$isRunning  = $campaign['status'] === 'running';

$succBarPct  = $campaign['total_recipients'] > 0 ? round($campaign['success_count'] / $campaign['total_recipients'] * 100) : 0;
$failBarPct  = $campaign['total_recipients'] > 0 ? round($campaign['failed_count']  / $campaign['total_recipients'] * 100) : 0;
$awaitBarPct = max(0, $pct - $succBarPct - $failBarPct);

// Per-status counts for recovery panel
$retryCounts = Database::fetchOne("
    SELECT
        SUM(status='failed')    AS failed,
        SUM(status='timeout')   AS timeout,
        SUM(status='cancelled') AS cancelled
    FROM campaign_recipients WHERE campaign_id = ?
", [$id]);
$retryableCount = (int)$retryCounts['failed'] + (int)$retryCounts['timeout'] + (int)$retryCounts['cancelled'];

$pageTitle    = e($campaign['name']);
$pageSubtitle = 'Campaigns &rsaquo; View';

$extraHead = '<style>
.recent-tx-item{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border)}
.recent-tx-item:last-child{border-bottom:none}
</style>';

require __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="<?= APP_URL ?>/campaigns/index.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left"></i></a>
    <div>
      <h1><i class="fas fa-bullhorn" style="color:var(--secondary);margin-right:8px"></i><?= e($campaign['name']) ?></h1>
      <p>
        <?= e($campaign['description'] ?? 'Bulk STK Push Campaign') ?>
        &mdash; <?= statusBadge($campaign['status']) ?>
      </p>
    </div>
  </div>
</div>

<!-- ─── Scheduled Banner ─────────────────────────────────── -->
<?php if ($campaign['status'] === 'scheduled' && $campaign['scheduled_at']): ?>
<div class="alert" style="background:#EDE9FE;border:1px solid #C4B5FD;color:#4C1D95;border-radius:12px;display:flex;align-items:center;gap:14px;margin-bottom:16px">
  <i class="fas fa-clock" style="font-size:22px;color:#6D28D9"></i>
  <div style="flex:1">
    <strong>Scheduled Launch</strong> — This campaign will automatically launch on
    <strong><?= date('D, j M Y \a\t g:ia', strtotime($campaign['scheduled_at'])) ?></strong>
    <span id="schedule-countdown" style="margin-left:8px;font-size:12px;opacity:0.75"></span>
  </div>
</div>
<script>
(function() {
  const target = new Date('<?= $campaign['scheduled_at'] ?>').getTime();
  function tick() {
    const diff = target - Date.now();
    const el   = document.getElementById('schedule-countdown');
    if (!el) return;
    if (diff <= 0) { el.textContent = '(launching…)'; location.reload(); return; }
    const d = Math.floor(diff / 86400000);
    const h = Math.floor((diff % 86400000) / 3600000);
    const m = Math.floor((diff % 3600000)  / 60000);
    const s = Math.floor((diff % 60000)    / 1000);
    el.textContent = '(' + (d ? d + 'd ' : '') + h + 'h ' + m + 'm ' + s + 's remaining)';
  }
  tick();
  setInterval(tick, 1000);
})();
</script>
<?php endif; ?>

<!-- ─── Campaign Header Banner ───────────────────────────── -->
<div class="campaign-run-header mb-3">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
      <div style="font-size:12px;opacity:0.7;text-transform:uppercase;letter-spacing:0.5px">Campaign Overview</div>
      <h2><?= e($campaign['name']) ?></h2>
      <p>KES <?= number_format((float)$campaign['amount'], 2) ?> per recipient &bull; Ref: <?= e($campaign['account_ref']) ?></p>
    </div>
    <div id="campaign-controls" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <?php if ($campaign['status'] === 'scheduled'): ?>
        <!-- Override: launch now regardless of schedule -->
        <form method="POST" action="<?= APP_URL ?>/api/campaign_status.php" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
          <input type="hidden" name="campaign_id" value="<?= $id ?>"/>
          <input type="hidden" name="action"      value="start"/>
          <button type="submit" class="btn btn-secondary btn-lg">
            <i class="fas fa-rocket"></i> Launch Now
          </button>
        </form>
        <form method="POST" action="<?= APP_URL ?>/api/campaign_status.php" style="display:inline"
              onsubmit="return confirm('Cancel the scheduled launch? Campaign will revert to draft.')">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
          <input type="hidden" name="campaign_id" value="<?= $id ?>"/>
          <input type="hidden" name="action"      value="cancel_schedule"/>
          <button type="submit" class="btn btn-danger btn-lg">
            <i class="fas fa-times"></i> Cancel Schedule
          </button>
        </form>
      <?php elseif (in_array($campaign['status'], ['draft', 'paused'])): ?>
        <button id="btn-start" class="btn btn-secondary btn-lg" onclick="startCampaign()">
          <i class="fas fa-rocket"></i>
          <?= $campaign['status'] === 'paused' ? 'Resume Campaign' : 'Launch Campaign' ?>
        </button>
      <?php endif; ?>
      <?php if ($campaign['status'] === 'running'): ?>
        <button id="btn-pause" class="btn btn-warning btn-lg" onclick="pauseCampaign()">
          <i class="fas fa-pause"></i> Pause Campaign
        </button>
      <?php endif; ?>
      <?php if ($campaign['status'] === 'completed'): ?>
        <a href="<?= APP_URL ?>/campaigns/report.php?id=<?= $id ?>" class="btn btn-secondary btn-lg">
          <i class="fas fa-print"></i> Print Report
        </a>
        <a href="<?= APP_URL ?>/transactions/index.php?campaign_id=<?= $id ?>&export=csv" class="btn btn-light btn-lg">
          <i class="fas fa-file-csv"></i> Export CSV
        </a>
      <?php endif; ?>
      <?php if (!in_array($campaign['status'], ['running'])): ?>
        <a href="<?= APP_URL ?>/campaigns/create.php?clone=<?= $id ?>" class="btn btn-light btn-lg"
           title="Duplicate this campaign's settings into a new draft">
          <i class="fas fa-copy"></i> Clone
        </a>
      <?php endif; ?>
      <?php if ($retryableCount > 0 && in_array($campaign['status'], ['completed', 'paused', 'draft'])): ?>
        <button class="btn btn-warning btn-lg" onclick="retryFailed()">
          <i class="fas fa-redo"></i> Retry Failed
          <span class="badge" style="background:rgba(0,0,0,0.2);margin-left:4px"><?= $retryableCount ?></span>
        </button>
      <?php endif; ?>
    </div>
  </div>

  <!-- Stats row -->
  <div class="campaign-stat-row">
    <div class="campaign-stat-item">
      <div class="value" id="stat-total"><?= number_format($campaign['total_recipients']) ?></div>
      <div class="label">Total Recipients</div>
    </div>
    <div class="campaign-stat-item">
      <div class="value" id="stat-success"><?= number_format($campaign['success_count']) ?></div>
      <div class="label">✅ Successful</div>
    </div>
    <div class="campaign-stat-item">
      <div class="value" id="stat-failed"><?= number_format($campaign['failed_count']) ?></div>
      <div class="label">❌ Failed</div>
    </div>
    <div class="campaign-stat-item">
      <div class="value" id="stat-pending"><?= number_format($campaign['pending_count']) ?></div>
      <div class="label">⏳ Pending</div>
    </div>
  </div>
</div>

<!-- ─── Progress Bar + Live Feed ─────────────────────────── -->
<div class="grid-2 mb-3" style="grid-template-columns:2fr 1fr;align-items:start">

  <!-- Progress -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">
        <i class="fas fa-tasks"></i> Send Progress
        <?php if ($isRunning ?? false): ?>
          <span class="live-dot" style="margin-left:4px"></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="card-body">
      <div class="progress progress-lg mb-3" style="height:22px;border-radius:12px">
        <div class="progress-multi" style="width:100%;height:100%;border-radius:12px">
          <div id="bar-success" class="seg <?= $isRunning ? 'progress-bar-sending' : '' ?>"
               style="width:<?= $succBarPct ?>%;background:var(--success)"></div>
          <div id="bar-failed"  class="seg"
               style="width:<?= $failBarPct ?>%;background:var(--danger)"></div>
          <div id="bar-awaiting" class="seg"
               style="width:<?= $awaitBarPct ?>%;background:var(--primary);opacity:.5"></div>
        </div>
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:-8px;margin-bottom:16px;font-size:12px">
        <div style="display:flex;gap:14px;color:var(--text-muted)">
          <span><span style="color:var(--success)">▬</span> Confirmed</span>
          <span><span style="color:var(--danger)">▬</span> Failed</span>
          <span><span style="color:var(--primary);opacity:.6">▬</span> Awaiting</span>
        </div>
        <span id="progress-pct" style="font-size:14px;font-weight:800;color:var(--primary)"><?= $pct ?>%</span>
      </div>

      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;text-align:center">
        <?php foreach ([
          ['Sent',      $campaign['sent_count'],    'var(--primary)',  'stat-sent'],
          ['Success',   $campaign['success_count'], 'var(--success)',  'stat-sent-success'],
          ['Failed',    $campaign['failed_count'],  'var(--danger)',   'stat-sent-failed'],
          ['Remaining', $campaign['pending_count'], 'var(--text-muted)', 'stat-sent-pending'],
        ] as [$label, $value, $color, $statId]): ?>
          <div style="padding:12px;background:var(--bg);border-radius:10px">
            <div style="font-size:22px;font-weight:800;color:<?= $color ?>" id="<?= $statId ?>"><?= number_format($value) ?></div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px"><?= $label ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if ($campaign['started_at']): ?>
        <div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border);font-size:13px;color:var(--text-muted);display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
          <span><i class="fas fa-play-circle"></i> Started: <?= date('d M Y H:i:s', strtotime($campaign['started_at'])) ?></span>
          <?php if ($campaign['completed_at']): ?>
            <span><i class="fas fa-flag-checkered"></i> Completed: <?= date('d M Y H:i:s', strtotime($campaign['completed_at'])) ?></span>
          <?php else: ?>
            <span id="campaign-status-label" style="font-weight:600;color:<?= $campaign['status'] === 'running' ? 'var(--success)' : 'var(--warning)' ?>">
              <?= ucfirst($campaign['status']) ?>…
            </span>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Live Feed -->
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-stream"></i> Live Feed</div>
      <?php if ($campaign['status'] === 'running'): ?>
        <span class="badge badge-success" style="animation:pulse 1.5s infinite">● Live</span>
      <?php endif; ?>
    </div>
    <div class="card-body p-0">
      <div id="recent-tx-list" style="padding:14px">
        <?php
        $recentRecipients = Database::fetchAll("
            SELECT cr.*, c.name AS customer_name
            FROM campaign_recipients cr
            LEFT JOIN customers c ON c.id = cr.customer_id
            WHERE cr.campaign_id = ? AND cr.status IN ('success','failed')
            ORDER BY cr.completed_at DESC
            LIMIT 8
        ", [$id]);
        foreach ($recentRecipients as $r):
          $cls = $r['status'] === 'success' ? 'badge-success' : 'badge-danger';
        ?>
          <div class="recent-tx-item">
            <div class="customer-cell">
              <div class="customer-avatar"><?= strtoupper(substr($r['customer_name'] ?? $r['phone'], 0, 1)) ?></div>
              <div>
                <div class="customer-name" style="font-size:13px"><?= e($r['customer_name'] ?? 'Unknown') ?></div>
                <div class="customer-phone"><?= e($r['phone']) ?></div>
              </div>
            </div>
            <div style="text-align:right">
              <span class="badge <?= $cls ?>"><?= $r['status'] ?></span>
              <div style="font-size:11px;color:var(--text-muted);margin-top:2px">KES <?= number_format((float)$r['amount']) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($recentRecipients)): ?>
          <div style="text-align:center;padding:20px;color:var(--text-muted);font-size:13px">
            <i class="fas fa-satellite-dish" style="font-size:24px;display:block;margin-bottom:8px;opacity:0.4"></i>
            Waiting for sends…
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- ─── Recovery Card (shown only when there are retryable recipients) ── -->
<?php if ($retryableCount > 0): ?>
<div class="card mb-3" style="border:1px solid #FDE68A;background:#FFFBEB">
  <div class="card-header" style="background:transparent;border-color:#FDE68A">
    <div class="card-title" style="color:#92400E"><i class="fas fa-exclamation-triangle" style="color:#F59E0B"></i> Recovery Centre</div>
    <button class="btn btn-warning btn-sm" onclick="retryFailed()">
      <i class="fas fa-redo"></i> Retry All (<?= $retryableCount ?>)
    </button>
  </div>
  <div class="card-body" style="padding:14px 18px">
    <div style="display:flex;gap:24px;flex-wrap:wrap;align-items:center">
      <?php if ((int)$retryCounts['failed'] > 0): ?>
        <div style="display:flex;align-items:center;gap:8px">
          <span class="badge badge-danger"><?= $retryCounts['failed'] ?> Failed</span>
          <button class="btn btn-sm btn-light" style="font-size:11px"
                  onclick="retryFailed(['failed'])">Retry</button>
        </div>
      <?php endif; ?>
      <?php if ((int)$retryCounts['timeout'] > 0): ?>
        <div style="display:flex;align-items:center;gap:8px">
          <span class="badge badge-warning"><?= $retryCounts['timeout'] ?> Timed Out</span>
          <button class="btn btn-sm btn-light" style="font-size:11px"
                  onclick="retryFailed(['timeout'])">Retry</button>
        </div>
      <?php endif; ?>
      <?php if ((int)$retryCounts['cancelled'] > 0): ?>
        <div style="display:flex;align-items:center;gap:8px">
          <span class="badge" style="background:#FEE2E2;color:#991B1B"><?= $retryCounts['cancelled'] ?> Cancelled</span>
          <button class="btn btn-sm btn-light" style="font-size:11px"
                  onclick="retryFailed(['cancelled'])">Retry</button>
        </div>
      <?php endif; ?>
      <div style="margin-left:auto;font-size:12px;color:#92400E">
        <i class="fas fa-info-circle"></i>
        Retrying resets these recipients to pending and re-opens the campaign for launch.
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ─── Recipients Table ──────────────────────────────────── -->
<?php
// Precompute base query params (no page/sort) for tab and sort links
$_rTabBase = array_filter(['id'=>$id,'rq'=>$rSearch,'rsort'=>$rSortKey,'rdir'=>($rDir==='DESC'?'DESC':'')], fn($v)=>$v!=='');
$_rPagBase = array_filter(['id'=>$id,'rs'=>$rStatus,'rq'=>$rSearch,'rsort'=>$rSortKey,'rdir'=>($rDir==='DESC'?'DESC':'')], fn($v)=>$v!=='');
$_sortIcon = fn($col) => $rSortKey === $col
    ? ($rDir === 'ASC'
        ? '<i class="fas fa-sort-up" style="font-size:10px;margin-left:2px;color:var(--primary)"></i>'
        : '<i class="fas fa-sort-down" style="font-size:10px;margin-left:2px;color:var(--primary)"></i>')
    : '<i class="fas fa-sort" style="opacity:.3;font-size:10px;margin-left:2px"></i>';
$_sortHref = fn($col) => '?' . http_build_query(array_filter(
    array_merge($_rTabBase, ['rs'=>$rStatus,'rsort'=>$col,'rdir'=>($rSortKey===$col&&$rDir==='ASC'?'DESC':'ASC')]),
    fn($v)=>$v!==''
));
?>
<div class="card">
  <div class="card-header" style="flex-wrap:wrap;gap:10px">
    <div class="card-title"><i class="fas fa-list-alt"></i> Recipients (<?= number_format($rTotal) ?>)</div>
    <form method="GET" style="display:flex;gap:6px;align-items:center">
      <input type="hidden" name="id" value="<?= $id ?>"/>
      <input type="hidden" name="rs" value="<?= e($rStatus) ?>"/>
      <?php if ($rSortKey): ?><input type="hidden" name="rsort" value="<?= e($rSortKey) ?>"/><?php endif; ?>
      <?php if ($rDir === 'DESC'): ?><input type="hidden" name="rdir" value="DESC"/><?php endif; ?>
      <input type="text" name="rq" value="<?= e($rSearch) ?>" placeholder="Search name or phone…"
             style="border:1px solid var(--border);border-radius:var(--radius);padding:5px 10px;font-size:13px;width:190px"/>
      <button type="submit" class="btn btn-sm btn-primary" style="padding:5px 10px"><i class="fas fa-search"></i></button>
      <?php if ($rSearch): ?>
        <a href="?<?= http_build_query(array_filter(array_merge($_rTabBase,['rs'=>$rStatus]),fn($v)=>$v!=='')) ?>"
           class="btn btn-sm btn-light" title="Clear search"><i class="fas fa-times"></i></a>
      <?php endif; ?>
    </form>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-left:auto">
      <?php
        $tabs = ['' => 'All', 'pending' => 'Pending', 'sent' => 'Sent', 'success' => 'Success', 'failed' => 'Failed'];
        if ((int)$retryCounts['timeout']   > 0) $tabs['timeout']   = 'Timed Out';
        if ((int)$retryCounts['cancelled'] > 0) $tabs['cancelled'] = 'Cancelled';
        foreach ($tabs as $val => $label):
      ?>
        <a href="?<?= http_build_query(array_merge($_rTabBase, array_filter(['rs'=>$val],fn($v)=>$v!==''))) ?>"
           class="btn btn-sm <?= $rStatus === $val ? 'btn-primary' : 'btn-light' ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="table-wrapper">
    <?php if (empty($recipients)): ?>
      <div class="empty-state"><div class="empty-icon"><i class="fas fa-inbox"></i></div><h3>No recipients found</h3></div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th>#</th>
            <th>Customer</th>
            <th>Phone</th>
            <th>Amount</th>
            <th><a href="<?= $_sortHref('status') ?>" style="color:inherit;text-decoration:none">Status <?= $_sortIcon('status') ?></a></th>
            <th>M-Pesa Receipt</th>
            <th>Result</th>
            <th><a href="<?= $_sortHref('completed_at') ?>" style="color:inherit;text-decoration:none">Time <?= $_sortIcon('completed_at') ?></a></th>
            <th><a href="<?= $_sortHref('retry_count') ?>" style="color:inherit;text-decoration:none">Retries <?= $_sortIcon('retry_count') ?></a></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($recipients as $i => $r): ?>
          <tr>
            <td style="color:var(--text-muted)"><?= $rOffset + $i + 1 ?></td>
            <td>
              <div class="customer-cell">
                <div class="customer-avatar"><?= strtoupper(substr($r['customer_name'] ?? $r['phone'], 0, 1)) ?></div>
                <div>
                  <div class="customer-name"><?= e($r['customer_name'] ?? 'Unknown') ?></div>
                </div>
              </div>
            </td>
            <td style="font-weight:600"><?= e($r['phone']) ?></td>
            <td>KES <?= number_format((float)$r['amount'], 2) ?></td>
            <td><?= statusBadge($r['status']) ?></td>
            <td>
              <?php if ($r['mpesa_receipt']): ?>
                <code style="font-size:12px;background:var(--bg);padding:3px 7px;border-radius:5px"><?= e($r['mpesa_receipt']) ?></code>
              <?php else: ?>
                <span style="color:var(--text-muted)">—</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;max-width:180px;color:var(--text-muted)"><?= e($r['result_desc'] ?? '—') ?></td>
            <td style="font-size:12px;color:var(--text-muted)">
              <?= $r['completed_at'] ? date('H:i:s', strtotime($r['completed_at'])) : ($r['sent_at'] ? date('H:i:s', strtotime($r['sent_at'])) : '—') ?>
            </td>
            <td style="text-align:center;font-size:13px;color:var(--text-muted)">
              <?= (int)$r['retry_count'] > 0 ? $r['retry_count'] : '—' ?>
            </td>
            <td>
              <?php if (in_array($r['status'], ['failed', 'timeout', 'cancelled'])): ?>
                <button class="btn btn-sm btn-outline-primary resend-btn"
                        title="Resend STK push"
                        data-id="<?= $r['id'] ?>"
                        onclick="resendRecipient(<?= $r['id'] ?>, this)">
                  <i class="fas fa-redo"></i>
                  <?php if ((int)$r['retry_count'] > 0): ?>
                    <span style="font-size:10px;margin-left:2px"><?= $r['retry_count'] ?>×</span>
                  <?php endif; ?>
                </button>
              <?php elseif ($r['status'] === 'sent'): ?>
                <span style="font-size:11px;color:var(--text-muted)">Pending…</span>
              <?php elseif ($r['status'] === 'pending'): ?>
                <button class="btn btn-sm btn-outline-danger cancel-btn"
                        title="Cancel this recipient — they will not receive an STK push"
                        onclick="cancelRecipient(<?= $r['id'] ?>, this)">
                  <i class="fas fa-times"></i>
                </button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php if ($rPages > 1): ?>
        <div style="display:flex;justify-content:center;padding:14px">
          <div class="pagination">
            <?php for ($i = 1; $i <= min($rPages, 10); $i++): ?>
              <a href="?<?= http_build_query(array_merge($_rPagBase, ['rp' => $i])) ?>"
                 class="page-link <?= $i === $rPage ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ─── Launch Progress Modal ─────────────────────────────── -->
<div class="modal-backdrop" id="launch-progress-modal" style="display:none;background:rgba(13,43,85,.65);backdrop-filter:blur(3px)">
  <div class="modal" style="max-width:560px;width:95vw;border-radius:16px;overflow:hidden;box-shadow:0 24px 64px rgba(0,0,0,.25)">

    <!-- Header -->
    <div id="lp-header" style="background:linear-gradient(135deg,#0D2B55 0%,#1a4080 100%);color:#fff;padding:20px 24px;display:flex;align-items:center;gap:14px">
      <div id="lp-icon" style="font-size:32px;line-height:1;flex-shrink:0">🚀</div>
      <div style="flex:1;min-width:0">
        <div id="lp-title" style="font-size:16px;font-weight:800;color:#fff">Sending STK Pushes…</div>
        <div style="font-size:12px;opacity:.65;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($campaign['name']) ?></div>
      </div>
      <button id="lp-close-btn" onclick="closeLaunchModal()"
              style="display:none;background:rgba(255,255,255,.15);border:none;color:#fff;width:32px;height:32px;border-radius:50%;cursor:pointer;font-size:18px;line-height:1;flex-shrink:0"
              title="Close">&times;</button>
    </div>

    <div style="padding:22px 24px">

      <!-- Progress bar -->
      <div style="margin-bottom:20px">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
          <span id="lp-label" style="font-size:13px;color:var(--text-muted);display:flex;align-items:center;gap:6px">
            <span class="spinner spinner-sm"></span>Sending STK pushes…
          </span>
          <span id="lp-pct" style="font-size:14px;font-weight:800;color:var(--primary)">0%</span>
        </div>
        <div style="background:#E5E7EB;border-radius:20px;height:18px;overflow:hidden">
          <div id="lp-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#00A651,#059669);border-radius:20px;transition:width .5s ease;display:flex;align-items:center;justify-content:center;font-size:10px;color:#fff;font-weight:700"></div>
        </div>
      </div>

      <!-- Stats -->
      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px">
        <?php foreach ([
          ['lp-s-total',   $campaign['total_recipients'], '#0D2B55', '#F8FAFC', '#E2E8F0', 'Total'],
          ['lp-s-sent',    0,                             '#059669', '#F0FDF4', '#BBF7D0', 'Dispatched'],
          ['lp-s-pending', $campaign['pending_count'],    '#D97706', '#FFFBEB', '#FDE68A', 'Pending'],
          ['lp-s-failed',  0,                             '#DC2626', '#FFF1F2', '#FECDD3', 'Failed'],
        ] as [$elId, $val, $color, $bg, $border, $label]): ?>
          <div style="text-align:center;padding:10px 6px;background:<?= $bg ?>;border-radius:10px;border:1px solid <?= $border ?>">
            <div style="font-size:22px;font-weight:800;color:<?= $color ?>" id="<?= $elId ?>"><?= $val ?></div>
            <div style="font-size:10px;color:#6B7280;margin-top:2px;text-transform:uppercase;letter-spacing:.3px"><?= $label ?></div>
          </div>
        <?php endforeach; ?>
      </div>

      <!-- Live feed -->
      <div style="border:1px solid var(--border);border-radius:10px;overflow:hidden">
        <div style="background:#F8FAFC;padding:8px 14px;border-bottom:1px solid var(--border);font-size:12px;font-weight:600;color:#374151;display:flex;align-items:center;gap:6px">
          <span id="lp-live-dot" style="display:inline-block;width:8px;height:8px;background:#10B981;border-radius:50%;animation:pulse 1.5s infinite"></span>
          Live Activity
        </div>
        <div id="lp-feed" style="max-height:180px;overflow-y:auto">
          <div id="lp-feed-empty" style="text-align:center;padding:28px;color:var(--text-muted);font-size:13px">
            <i class="fas fa-satellite-dish" style="font-size:20px;display:block;margin-bottom:8px;opacity:.4"></i>
            Waiting for sends…
          </div>
        </div>
      </div>

      <!-- Completion banner (hidden until done) -->
      <div id="lp-done-banner" style="display:none;margin-top:16px;padding:16px;border-radius:10px;text-align:center"></div>

    </div>
  </div>
</div>

<!-- ─── Launch Issues Modal ───────────────────────────────── -->
<div class="modal-backdrop" id="launch-issue-modal">
  <div class="modal" style="max-width:520px">
    <div class="modal-header">
      <h3 class="modal-title"><i class="fas fa-exclamation-triangle" style="color:#F59E0B;margin-right:8px"></i>Cannot Launch Campaign</h3>
      <button class="modal-close" onclick="Modal.close('launch-issue-modal')">&times;</button>
    </div>
    <div class="modal-body" style="max-height:70vh;overflow-y:auto"></div>
  </div>
</div>

<?php ob_start(); ?>
<script>
const campaignId  = <?= $id ?>;
const batchDelay  = <?= BATCH_SIZE * 250 + 700 ?>;
const initStatus  = '<?= $campaign['status'] ?>';

BulkSender.init(campaignId, batchDelay);

<?php if ($campaign['status'] === 'running'): ?>
// Auto-resume if page loaded while running
window.addEventListener('DOMContentLoaded', () => {
  BulkSender.start();
});
<?php endif; ?>

async function startCampaign() {
  const btn = document.getElementById('btn-start');
  const origLabel = btn ? btn.innerHTML : '';
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner spinner-sm"></span> Checking…'; }

  try {
    // ── Pre-launch validation ──────────────────────────────
    const check = await fetch(
      (window.APP_URL || '') + '/api/launch_check.php?campaign_id=' + campaignId,
      { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }
    ).then(r => r.json());

    const errors   = (check.issues || []).filter(i => i.type === 'error');
    const warnings = (check.issues || []).filter(i => i.type === 'warning');

    if (errors.length > 0) {
      showLaunchIssues(check.issues);
      if (btn) { btn.disabled = false; btn.innerHTML = origLabel; }
      return;
    }

    if (warnings.length > 0) {
      // Show warnings but allow launch to proceed
      warnings.forEach(w => Toast.warning(w.msg, w.title));
    }

    // ── Mark campaign as running ───────────────────────────
    if (btn) btn.innerHTML = '<span class="spinner spinner-sm"></span> Launching…';
    const res = await apiFetch((window.APP_URL || '') + '/api/campaign_status.php', {
      campaign_id: campaignId,
      action: 'start'
    });
    if (!res.success) {
      Toast.error(res.message || 'Failed to start campaign.', 'Error');
      if (btn) { btn.disabled = false; btn.innerHTML = origLabel; }
      return;
    }

    // ── Start sending ──────────────────────────────────────
    if (btn) btn.style.display = 'none';
    openLaunchModal();
    BulkSender.start();

  } catch(e) {
    console.error('Launch error:', e);
    Toast.error('Network error: ' + e.message, 'Error');
    if (btn) { btn.disabled = false; btn.innerHTML = origLabel; }
  }
}

function showLaunchIssues(issues) {
  let html = '<div style="display:flex;flex-direction:column;gap:14px">';
  issues.forEach(issue => {
    const isErr = issue.type === 'error';
    const color = isErr ? '#DC2626' : '#D97706';
    const bg    = isErr ? '#FEF2F2' : '#FFFBEB';
    const border= isErr ? '#FECACA' : '#FDE68A';
    const icon  = isErr ? 'fa-times-circle' : 'fa-exclamation-triangle';
    html += `
      <div style="background:${bg};border:1px solid ${border};border-radius:10px;padding:14px">
        <div style="display:flex;align-items:center;gap:8px;font-weight:600;color:${color};margin-bottom:6px">
          <i class="fas ${icon}"></i> ${issue.title}
        </div>
        <div style="font-size:13px;color:#374151;margin-bottom:8px">${issue.msg}</div>
        ${issue.fix ? `<div style="font-size:12px;color:#6B7280;white-space:pre-line"><strong>Fix:</strong> ${issue.fix}</div>` : ''}
      </div>`;
  });
  const hasErrors = issues.some(i => i.type === 'error');
  html += `</div>
    <div style="margin-top:18px;display:flex;gap:10px;justify-content:flex-end">
      <a href="${window.APP_URL}/settings/index.php" class="btn btn-secondary btn-sm">
        <i class="fas fa-cog"></i> Open Settings
      </a>
      <button class="btn btn-light btn-sm" onclick="Modal.close('launch-issue-modal')">Close</button>
    </div>`;

  const modal = document.getElementById('launch-issue-modal');
  if (modal) {
    modal.querySelector('.modal-body').innerHTML = html;
    Modal.open('launch-issue-modal');
  }
}

async function pauseCampaign() {
  BulkSender.pause();
  const res = await apiFetch('<?= APP_URL ?>/api/campaign_status.php', {
    campaign_id: campaignId,
    action: 'pause'
  });
  if (res.success) Toast.warning('Campaign paused.', 'Paused');
}

// Extend BulkSender to also drive the progress card's mini-stats + launch modal
const origUpdate = BulkSender.updateProgress.bind(BulkSender);
BulkSender.updateProgress = function(data) {
  origUpdate(data);
  const s = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = (v || 0).toLocaleString(); };
  s('stat-sent-success', data.success_count);
  s('stat-sent-failed',  data.failed_count);
  s('stat-sent-pending', data.pending_count);
  updateLaunchModal(data);
};

// Add blinking animation for live status
const style = document.createElement('style');
style.textContent = '@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.5}}';
document.head.appendChild(style);

// ── Launch Progress Modal ─────────────────────────────────────

function openLaunchModal() {
  const modal = document.getElementById('launch-progress-modal');
  if (!modal) return;
  // Reset to initial state
  _lpSet('lp-bar',      el => { el.style.width = '0%'; el.textContent = ''; });
  _lpText('lp-pct',     '0%');
  _lpText('lp-s-sent',  '0');
  _lpText('lp-s-failed','0');
  _lpText('lp-s-pending', document.getElementById('stat-pending')?.textContent || '?');
  _lpText('lp-title',   'Sending STK Pushes…');
  _lpSet('lp-icon',     el => el.textContent = '🚀');
  _lpSet('lp-close-btn',el => el.style.display = 'none');
  _lpSet('lp-done-banner', el => el.style.display = 'none');
  _lpSet('lp-label',    el => el.innerHTML = '<span class="spinner spinner-sm"></span>&nbsp;Sending STK pushes…');
  _lpSet('lp-live-dot', el => el.style.animation = 'pulse 1.5s infinite');
  const feed = document.getElementById('lp-feed');
  if (feed) feed.innerHTML = '<div id="lp-feed-empty" style="text-align:center;padding:28px;color:var(--text-muted);font-size:13px"><i class="fas fa-satellite-dish" style="font-size:20px;display:block;margin-bottom:8px;opacity:.4"></i>Waiting for sends…</div>';
  modal.style.display = 'flex';
}

function updateLaunchModal(data) {
  const total      = data.total     || 0;
  const sentCount  = data.sent_count  || 0;
  const failed     = data.failed_count || 0;
  const pending    = data.pending_count || 0;
  const dispatched = Math.max(0, sentCount - failed);
  const pct        = total > 0 ? Math.round((sentCount / total) * 100) : 0;

  _lpSet('lp-bar', el => {
    el.style.width = pct + '%';
    el.textContent = pct > 10 ? pct + '%' : '';
  });
  _lpText('lp-pct',      pct + '%');
  _lpText('lp-s-sent',   dispatched.toLocaleString());
  _lpText('lp-s-failed', failed.toLocaleString());
  _lpText('lp-s-pending',pending.toLocaleString());

  if (!data.recent || !data.recent.length) return;
  const emptyEl = document.getElementById('lp-feed-empty');
  if (emptyEl) emptyEl.remove();
  const feed = document.getElementById('lp-feed');
  if (!feed) return;
  data.recent.forEach(tx => {
    const color  = tx.status === 'success' ? '#059669' : tx.status === 'failed' ? '#DC2626' : '#D97706';
    const icon   = tx.status === 'success' ? '✅' : tx.status === 'failed' ? '❌' : '📤';
    const item   = document.createElement('div');
    item.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:9px 14px;border-bottom:1px solid #F3F4F6';
    item.innerHTML = `
      <div style="display:flex;align-items:center;gap:9px">
        <div style="width:30px;height:30px;background:#E5E7EB;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#374151;flex-shrink:0">${(tx.name||tx.phone||'?')[0].toUpperCase()}</div>
        <div>
          <div style="font-size:13px;font-weight:600;color:#111827">${(tx.name||'Unknown').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')}</div>
          <div style="font-size:11px;color:#6B7280">${tx.phone}</div>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0;margin-left:12px">
        <div style="font-size:12px;font-weight:600;color:${color}">${icon} ${tx.status.charAt(0).toUpperCase()+tx.status.slice(1)}</div>
        <div style="font-size:11px;color:#6B7280">KES ${Number(tx.amount||0).toLocaleString()}</div>
      </div>`;
    feed.insertBefore(item, feed.firstChild);
  });
}

function completeLaunchModal(data) {
  const dispatched = Math.max(0, (data.sent_count||0) - (data.failed_count||0));
  const total      = data.total || 0;
  const failed     = data.failed_count || 0;
  const allFailed  = dispatched === 0;

  _lpText('lp-title', allFailed ? 'Campaign Finished with Errors' : 'STK Pushes Dispatched!');
  _lpSet('lp-icon',      el => el.textContent = allFailed ? '❌' : '✅');
  _lpSet('lp-close-btn', el => el.style.display = 'block');
  _lpSet('lp-live-dot',  el => { el.style.animation = 'none'; el.style.background = '#9CA3AF'; });
  _lpSet('lp-label', el => {
    el.innerHTML = allFailed
      ? '<span style="color:#DC2626">❌ All pushes failed</span>'
      : `<span style="color:#059669">✅ ${dispatched} of ${total} dispatched — awaiting confirmations</span>`;
  });

  const banner = document.getElementById('lp-done-banner');
  if (!banner) return;
  if (allFailed) {
    banner.style.cssText = 'display:block;margin-top:16px;padding:16px;border-radius:10px;background:#FFF1F2;border:1px solid #FECDD3;text-align:center';
    banner.innerHTML = `<div style="font-size:15px;font-weight:700;color:#991B1B;margin-bottom:6px">All ${failed} push${failed!==1?'es':''} failed</div><div style="font-size:13px;color:#B91C1C">Check the error message above, then use the Recovery Centre to retry.</div>`;
  } else {
    banner.style.cssText = 'display:block;margin-top:16px;padding:16px;border-radius:10px;background:#F0FDF4;border:1px solid #BBF7D0;text-align:center';
    banner.innerHTML = `
      <div style="font-size:15px;font-weight:700;color:#065F46;margin-bottom:6px">🎉 ${dispatched} STK push${dispatched!==1?'es':''} dispatched</div>
      <div style="font-size:13px;color:#047857;margin-bottom:${failed>0?12:0}px">Safaricom is processing each payment. Confirmations update automatically in the table below.</div>
      ${failed>0?`<div style="font-size:12px;color:#B45309;background:#FFFBEB;padding:8px 12px;border-radius:8px;display:inline-block">⚠️ ${failed} push${failed!==1?'es':''} failed — use Recovery Centre to retry</div>`:''}`;
  }
}

function closeLaunchModal() {
  const modal = document.getElementById('launch-progress-modal');
  if (modal) modal.style.display = 'none';
}

function _lpText(id, val) {
  const el = document.getElementById(id);
  if (el) el.textContent = val;
}
function _lpSet(id, fn) {
  const el = document.getElementById(id);
  if (el) fn(el);
}

// ── Retry Failed ─────────────────────────────────────────────
async function retryFailed(statuses = ['failed', 'timeout', 'cancelled']) {
  const label = statuses.join(', ');
  if (!confirm(`Reset all ${label} recipients to pending and re-open the campaign for launch?`)) return;

  const btns = document.querySelectorAll('[onclick^="retryFailed"]');
  btns.forEach(b => { b.disabled = true; b.innerHTML = '<span class="spinner spinner-sm"></span> Retrying…'; });

  try {
    const res = await apiFetch((window.APP_URL || '') + '/api/campaign_status.php', {
      campaign_id: campaignId,
      action: 'retry',
      statuses,
    });
    if (res.success) {
      Toast.success(res.message, 'Retry Queued');
      setTimeout(() => location.reload(), 1200);
    } else {
      Toast.error(res.message || 'Retry failed.', 'Error');
      btns.forEach(b => { b.disabled = false; b.innerHTML = '<i class="fas fa-redo"></i> Retry Failed'; });
    }
  } catch (e) {
    Toast.error('Network error.', 'Error');
    btns.forEach(b => { b.disabled = false; });
  }
}

// ── Individual Resend ─────────────────────────────────────────
async function resendRecipient(recipientId, btn) {
  btn.disabled = true;
  const orig = btn.innerHTML;
  btn.innerHTML = '<span class="spinner spinner-sm"></span>';

  try {
    const res = await apiFetch((window.APP_URL || '') + '/api/resend_recipient.php', {
      recipient_id: recipientId,
    });
    if (res.success) {
      Toast.success(res.message, 'Resent');
      // Update the row's status badge in place
      const row = btn.closest('tr');
      if (row) {
        const statusCell = row.querySelector('td:nth-child(5)');
        if (statusCell) statusCell.innerHTML = '<span class="badge badge-info">Awaiting</span>';
        btn.style.display = 'none';
        // Show "Pending…" instead
        const pendingSpan = document.createElement('span');
        pendingSpan.style.cssText = 'font-size:11px;color:var(--text-muted)';
        pendingSpan.textContent = 'Pending…';
        btn.parentNode.appendChild(pendingSpan);
      }
    } else {
      Toast.error(res.message || 'Resend failed.', 'Error');
      btn.disabled = false;
      btn.innerHTML = orig;
    }
  } catch (e) {
    Toast.error('Network error.', 'Error');
    btn.disabled = false;
    btn.innerHTML = orig;
  }
}

// ── Individual Cancel ─────────────────────────────────────────
async function cancelRecipient(recipientId, btn) {
  if (!confirm('Cancel this recipient? They will not receive an STK push.')) return;
  btn.disabled = true;
  const orig = btn.innerHTML;
  btn.innerHTML = '<span class="spinner spinner-sm"></span>';

  try {
    const res = await apiFetch((window.APP_URL || '') + '/api/cancel_recipient.php', {
      recipient_id: recipientId,
    });
    if (res.success) {
      Toast.success(res.message || 'Recipient cancelled.', 'Cancelled');
      const row = btn.closest('tr');
      if (row) {
        const statusCell = row.querySelector('td:nth-child(5)');
        if (statusCell) statusCell.innerHTML = '<span class="badge" style="background:#FEE2E2;color:#991B1B">Cancelled</span>';
        btn.style.display = 'none';
      }
      const statPending = document.getElementById('stat-pending');
      if (statPending) {
        const cur = parseInt(statPending.textContent.replace(/,/g, ''), 10) || 0;
        if (cur > 0) statPending.textContent = (cur - 1).toLocaleString();
      }
    } else {
      Toast.error(res.message || 'Cancel failed.', 'Error');
      btn.disabled = false;
      btn.innerHTML = orig;
    }
  } catch (e) {
    Toast.error('Network error.', 'Error');
    btn.disabled = false;
    btn.innerHTML = orig;
  }
}

// ── Callback poller ─────────────────────────────────────────
// After all batches are dispatched (done=true), callbacks still arrive.
// Poll campaign_status.php every 5s until success+failed = total.
let callbackPollTimer = null;

function startCallbackPoller() {
  if (callbackPollTimer) return;
  callbackPollTimer = setInterval(async () => {
    try {
      const res = await apiFetch(
        (window.APP_URL || '') + '/api/campaign_status.php',
        { campaign_id: campaignId, action: 'status' }
      );
      if (!res.success) return;

      // Update all stat display elements
      BulkSender.updateProgress({
        total:         res.total,
        sent_count:    res.sent_count,
        success_count: res.success_count,
        failed_count:  res.failed_count,
        pending_count: res.pending_count,
      });

      // Stop once all dispatched pushes have been resolved via callback
      const awaitingCallback = res.awaiting_callback ?? 1;
      if (awaitingCallback === 0 && res.done) {
        clearInterval(callbackPollTimer);
        callbackPollTimer = null;
        setTimeout(() => location.reload(), 1500);
      }
    } catch (_) {}
  }, 5000);
}

// Hook into BulkSender.onComplete to show modal completion + start poller
const origComplete = BulkSender.onComplete.bind(BulkSender);
BulkSender.onComplete = function(data) {
  origComplete(data);
  completeLaunchModal(data);
  startCallbackPoller();
};

// If page loaded with campaign already completed, no polling needed.
// If campaign was running when page loaded and BulkSender resumes, poller
// starts automatically via onComplete.
</script>
<?php $extraScripts = ob_get_clean(); ?>

<?php require __DIR__ . '/../templates/footer.php'; ?>
