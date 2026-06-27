<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::start();
Auth::requireLogin();

// ── Validate sort params ─────────────────────────────────────
$allowedSorts = ['initiated_at', 'amount', 'status', 'customer_name'];
$sort   = in_array($_GET['sort'] ?? '', $allowedSorts) ? $_GET['sort'] : 'initiated_at';
$dir    = strtoupper($_GET['dir'] ?? '') === 'ASC' ? 'ASC' : 'DESC';
$oppDir = $dir === 'DESC' ? 'ASC' : 'DESC';

// ── Filters ──────────────────────────────────────────────────
$search     = trim($_GET['q']           ?? '');
$status     = $_GET['status']           ?? '';
$campaignId = (int)($_GET['campaign_id'] ?? 0);
$dateFrom   = $_GET['date_from']        ?? '';
$dateTo     = $_GET['date_to']          ?? '';
$perPage    = in_array((int)($_GET['per'] ?? 25), [25, 50, 100]) ? (int)($_GET['per'] ?? 25) : 25;
$page       = max(1, (int)($_GET['page'] ?? 1));
$offset     = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($search) {
    $like    = "%{$search}%";
    $where[] = '(t.phone LIKE ? OR c.name LIKE ? OR t.mpesa_receipt LIKE ?)';
    $params  = array_merge($params, [$like, $like, $like]);
}
if ($status)     { $where[] = 't.status = ?';                  $params[] = $status; }
if ($campaignId) { $where[] = 't.campaign_id = ?';             $params[] = $campaignId; }
if ($dateFrom)   { $where[] = 'DATE(t.initiated_at) >= ?';     $params[] = $dateFrom; }
if ($dateTo)     { $where[] = 'DATE(t.initiated_at) <= ?';     $params[] = $dateTo; }

$whereStr = implode(' AND ', $where);
$sortExpr = $sort === 'customer_name' ? 'c.name' : "t.{$sort}";

// ── CSV export (respects ALL active filters) ─────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $rows = Database::fetchAll("
        SELECT t.phone, c.name AS customer_name, t.amount, t.account_ref, t.description,
               t.status, t.mpesa_receipt, t.result_description, t.initiated_at, t.completed_at,
               camp.name AS campaign_name
        FROM transactions t
        LEFT JOIN customers c    ON c.id    = t.customer_id
        LEFT JOIN campaigns camp ON camp.id = t.campaign_id
        WHERE {$whereStr}
        ORDER BY {$sortExpr} {$dir}
        LIMIT 100000
    ", $params);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="transactions_' . date('Ymd_His') . '.csv"');
    // UTF-8 BOM for Excel compatibility
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Campaign', 'Customer Name', 'Phone', 'Amount (KES)', 'Account Ref', 'Description', 'Status', 'M-Pesa Receipt', 'Result', 'Initiated At', 'Completed At']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['campaign_name']     ?? '',
            $row['customer_name']     ?? '',
            $row['phone'],
            number_format((float)$row['amount'], 2),
            $row['account_ref'],
            $row['description']       ?? '',
            $row['status'],
            $row['mpesa_receipt']     ?? '',
            $row['result_description'] ?? '',
            $row['initiated_at'],
            $row['completed_at']      ?? '',
        ]);
    }
    fclose($out);
    exit;
}

$total    = Database::count("SELECT COUNT(*) FROM transactions t LEFT JOIN customers c ON c.id = t.customer_id WHERE {$whereStr}", $params);
// Explicit column list — raw_callback is a LONGTEXT column that can be several KB per row.
// Excluding it from the paginated list cuts fetch time and memory significantly.
$txList   = Database::fetchAll("
    SELECT
        t.id, t.campaign_id, t.customer_id, t.phone, t.amount,
        t.account_ref, t.description, t.merchant_request_id, t.checkout_request_id,
        t.response_code, t.response_description, t.result_code, t.result_description,
        t.mpesa_receipt, t.transaction_date, t.status, t.initiated_at, t.completed_at,
        c.name AS customer_name,
        camp.name AS campaign_name
    FROM transactions t
    LEFT JOIN customers c    ON c.id    = t.customer_id
    LEFT JOIN campaigns camp ON camp.id = t.campaign_id
    WHERE {$whereStr}
    ORDER BY {$sortExpr} {$dir}
    LIMIT {$perPage} OFFSET {$offset}
", $params);
$totalPages = (int)ceil($total / $perPage);

// ── Summary stats (same filters) ────────────────────────────
$summaryStats = Database::fetchOne("
    SELECT
        COUNT(*) AS total,
        SUM(t.status='success') AS success,
        SUM(t.status='failed')  AS failed,
        SUM(t.status='pending') AS pending,
        SUM(CASE WHEN t.status='success' THEN t.amount ELSE 0 END) AS total_amount
    FROM transactions t
    LEFT JOIN customers c ON c.id = t.customer_id
    WHERE {$whereStr}
", $params);

$totalTx   = (int)($summaryStats['total']        ?? 0);
$succTx    = (int)($summaryStats['success']       ?? 0);
$failTx    = (int)($summaryStats['failed']        ?? 0);
$revenue   = (float)($summaryStats['total_amount'] ?? 0);
$succRate  = $totalTx > 0 ? round($succTx / $totalTx * 100, 1) : 0;

$campaigns = Database::fetchAll("SELECT id, name FROM campaigns ORDER BY created_at DESC LIMIT 200");

// ── Build sort URL helper ────────────────────────────────────
function sortUrl(string $col, string $currentSort, string $currentDir, string $oppDir): string {
    $newDir = ($col === $currentSort) ? $oppDir : 'DESC';
    return '?' . http_build_query(array_merge($_GET, ['sort' => $col, 'dir' => $newDir, 'page' => 1]));
}
function sortIcon(string $col, string $currentSort, string $currentDir): string {
    if ($col !== $currentSort) return '<i class="fas fa-sort" style="opacity:.3;margin-left:4px;font-size:10px"></i>';
    $icon = $currentDir === 'ASC' ? 'fa-sort-up' : 'fa-sort-down';
    return "<i class=\"fas {$icon}\" style=\"color:var(--secondary);margin-left:4px;font-size:10px\"></i>";
}

$activeFilters = array_filter([$search, $status, $campaignId, $dateFrom, $dateTo]);
$hasFilters    = !empty($activeFilters);

$pageTitle    = 'Transactions';
$pageSubtitle = 'Reports &rsaquo; Transactions';
require __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1><i class="fas fa-receipt" style="color:var(--secondary);margin-right:8px"></i>Transactions</h1>
      <p>Complete STK push transaction history<?= $hasFilters ? ' <span style="color:var(--secondary);font-weight:600">(filtered)</span>' : '' ?></p>
    </div>
    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-outline-primary">
      <i class="fas fa-file-csv"></i> Export CSV<?= $hasFilters ? ' (filtered)' : '' ?>
    </a>
  </div>
</div>

<!-- ─── Summary Stats ─────────────────────────────────────── -->
<div class="grid-4 mb-3">
  <div class="stat-card" style="--stat-color:var(--primary);--stat-icon-bg:rgba(13,43,85,0.08)">
    <div class="stat-icon"><i class="fas fa-paper-plane"></i></div>
    <div>
      <div class="stat-value"><?= number_format($totalTx) ?></div>
      <div class="stat-label">Total Pushes</div>
    </div>
  </div>
  <div class="stat-card" style="--stat-color:var(--success);--stat-icon-bg:rgba(0,166,81,0.08)">
    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
    <div>
      <div class="stat-value"><?= number_format($succTx) ?></div>
      <div class="stat-label">Successful</div>
      <div class="stat-change" style="color:var(--success)">
        <i class="fas fa-chart-line"></i> <?= $succRate ?>% success rate
      </div>
    </div>
  </div>
  <div class="stat-card" style="--stat-color:var(--danger);--stat-icon-bg:rgba(220,38,38,0.08)">
    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
    <div>
      <div class="stat-value"><?= number_format($failTx) ?></div>
      <div class="stat-label">Failed</div>
      <?php if ($totalTx > 0): ?>
        <div class="stat-change down">
          <i class="fas fa-arrow-down"></i> <?= round($failTx/$totalTx*100,1) ?>% failure rate
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="stat-card" style="--stat-color:#8B5CF6;--stat-icon-bg:rgba(139,92,246,0.08)">
    <div class="stat-icon"><i class="fas fa-coins"></i></div>
    <div>
      <div class="stat-value" style="font-size:18px">KES <?= number_format($revenue, 2) ?></div>
      <div class="stat-label">Total Collected</div>
    </div>
  </div>
</div>

<!-- ─── Filters ───────────────────────────────────────────── -->
<div class="card mb-3">
  <div class="card-body" style="padding:16px 22px">
    <form method="GET" action="" id="filter-form">
      <input type="hidden" name="sort" value="<?= e($sort) ?>"/>
      <input type="hidden" name="dir"  value="<?= e($dir) ?>"/>
      <input type="hidden" name="per"  value="<?= $perPage ?>"/>

      <!-- Quick date presets -->
      <div style="display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap;align-items:center">
        <span style="font-size:12px;color:var(--text-muted);font-weight:600">Quick:</span>
        <?php foreach ([
          'today'  => ['Today',        date('Y-m-d'),                         date('Y-m-d')],
          '7d'     => ['Last 7 days',  date('Y-m-d', strtotime('-6 days')),   date('Y-m-d')],
          '30d'    => ['Last 30 days', date('Y-m-d', strtotime('-29 days')),  date('Y-m-d')],
          'month'  => ['This month',   date('Y-m-01'),                        date('Y-m-d')],
        ] as $key => [$label, $from, $to]):
          $active = ($dateFrom === $from && $dateTo === $to);
        ?>
          <button type="button"
                  class="btn btn-sm <?= $active ? 'btn-secondary' : 'btn-light' ?>"
                  onclick="setDatePreset('<?= $from ?>','<?= $to ?>')">
            <?= $label ?>
          </button>
        <?php endforeach; ?>
        <?php if ($dateFrom || $dateTo): ?>
          <button type="button" class="btn btn-sm btn-light" onclick="setDatePreset('','')">
            <i class="fas fa-times"></i> Clear dates
          </button>
        <?php endif; ?>
      </div>

      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div style="flex:1;min-width:180px">
          <label class="form-label" style="margin-bottom:5px">Search</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-search" style="color:#94a3b8"></i></span>
            <input type="text" name="q" class="form-control" placeholder="Phone, name, receipt…" value="<?= e($search) ?>"/>
          </div>
        </div>
        <div style="min-width:160px">
          <label class="form-label" style="margin-bottom:5px">Campaign</label>
          <select name="campaign_id" class="form-select">
            <option value="">All Campaigns</option>
            <?php foreach ($campaigns as $c): ?>
              <option value="<?= $c['id'] ?>" <?= $campaignId === (int)$c['id'] ? 'selected' : '' ?>>
                <?= e(mb_strimwidth($c['name'], 0, 30, '…')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="min-width:130px">
          <label class="form-label" style="margin-bottom:5px">Status</label>
          <select name="status" class="form-select">
            <option value="">All Statuses</option>
            <?php foreach (['success','failed','pending','timeout','initiated'] as $s): ?>
              <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="min-width:130px">
          <label class="form-label" style="margin-bottom:5px">From</label>
          <input type="date" name="date_from" id="inp-date-from" class="form-control" value="<?= e($dateFrom) ?>"/>
        </div>
        <div style="min-width:130px">
          <label class="form-label" style="margin-bottom:5px">To</label>
          <input type="date" name="date_to" id="inp-date-to" class="form-control" value="<?= e($dateTo) ?>"/>
        </div>
        <div>
          <label class="form-label" style="margin-bottom:5px">Per page</label>
          <select name="per" class="form-select" onchange="this.form.submit()" style="min-width:80px">
            <?php foreach ([25,50,100] as $n): ?>
              <option value="<?= $n ?>" <?= $perPage===$n?'selected':'' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
          <a href="?" class="btn btn-light">Clear</a>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ─── Table ─────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-table"></i> Transaction Log</div>
    <div style="font-size:13px;color:var(--text-muted)">
      <?php if ($totalTx === 0): ?>
        No results
      <?php else: ?>
        <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $total)) ?>
        of <?= number_format($total) ?>
        <?php if ($totalPages > 1): ?>&nbsp;· Page <?= $page ?> of <?= $totalPages ?><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php if (empty($txList)): ?>
    <div class="empty-state">
      <div class="empty-icon"><i class="fas fa-receipt"></i></div>
      <h3>No transactions found</h3>
      <p><?= $hasFilters ? 'Try adjusting your filters.' : 'Launch a campaign to start sending.' ?></p>
      <?php if ($hasFilters): ?>
        <a href="?" class="btn btn-light btn-sm" style="margin-top:12px"><i class="fas fa-times"></i> Clear filters</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="table-wrapper">
      <table class="table">
        <thead>
          <tr>
            <th style="width:44px">#</th>
            <th>
              <a href="<?= sortUrl('customer_name', $sort, $dir, $oppDir) ?>" style="color:inherit;display:flex;align-items:center">
                Customer <?= sortIcon('customer_name', $sort, $dir) ?>
              </a>
            </th>
            <th>Campaign</th>
            <th>
              <a href="<?= sortUrl('amount', $sort, $dir, $oppDir) ?>" style="color:inherit;display:flex;align-items:center">
                Amount <?= sortIcon('amount', $sort, $dir) ?>
              </a>
            </th>
            <th>
              <a href="<?= sortUrl('status', $sort, $dir, $oppDir) ?>" style="color:inherit;display:flex;align-items:center">
                Status <?= sortIcon('status', $sort, $dir) ?>
              </a>
            </th>
            <th>M-Pesa Receipt</th>
            <th>Result</th>
            <th>
              <a href="<?= sortUrl('initiated_at', $sort, $dir, $oppDir) ?>" style="color:inherit;display:flex;align-items:center">
                Date &amp; Time <?= sortIcon('initiated_at', $sort, $dir) ?>
              </a>
            </th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($txList as $i => $tx): ?>
          <tr>
            <td style="color:var(--text-muted);font-size:12px"><?= number_format($offset + $i + 1) ?></td>
            <td>
              <div class="customer-cell">
                <div class="customer-avatar"><?= strtoupper(substr($tx['customer_name'] ?? $tx['phone'], 0, 1)) ?></div>
                <div>
                  <div class="customer-name"><?= e($tx['customer_name'] ?? 'Unknown') ?></div>
                  <div class="customer-phone"><?= e($tx['phone']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <?php if ($tx['campaign_name']): ?>
                <a href="<?= APP_URL ?>/campaigns/view.php?id=<?= $tx['campaign_id'] ?>"
                   style="font-size:13px;color:var(--primary);font-weight:500">
                  <?= e(mb_strimwidth($tx['campaign_name'], 0, 25, '…')) ?>
                </a>
              <?php else: ?>
                <span style="color:var(--text-muted)">—</span>
              <?php endif; ?>
            </td>
            <td style="font-weight:700;white-space:nowrap">KES <?= number_format((float)$tx['amount'], 2) ?></td>
            <td><?= statusBadge($tx['status']) ?></td>
            <td>
              <?php if ($tx['mpesa_receipt']): ?>
                <code style="font-size:12px;background:var(--bg);padding:3px 7px;border-radius:5px;cursor:pointer"
                      title="Click to copy"
                      onclick="navigator.clipboard?.writeText('<?= e($tx['mpesa_receipt']) ?>').then(()=>Toast.success('Receipt copied','Copied'))"
                ><?= e($tx['mpesa_receipt']) ?></code>
              <?php else: ?>
                <span style="color:var(--text-muted)">—</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;color:var(--text-muted);max-width:160px">
              <?= e(mb_strimwidth($tx['result_description'] ?? $tx['response_description'] ?? '—', 0, 55, '…')) ?>
            </td>
            <td style="font-size:12px;white-space:nowrap">
              <span style="color:var(--text)"><?= date('d M Y', strtotime($tx['initiated_at'])) ?></span>
              <div style="color:var(--text-muted)"><?= date('H:i:s', strtotime($tx['initiated_at'])) ?></div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- ─── Pagination ─────────────────────────────────────── -->
    <?php if ($totalPages > 1): ?>
      <div class="card-footer" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <div style="font-size:13px;color:var(--text-muted)">
          Page <?= $page ?> of <?= $totalPages ?>
          &nbsp;·&nbsp;<?= number_format($total) ?> results
        </div>
        <div class="pagination">
          <!-- First -->
          <?php if ($page > 2): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="page-link" title="First">1</a>
            <?php if ($page > 3): ?><span class="page-link" style="pointer-events:none;border:none;color:var(--text-muted)">…</span><?php endif; ?>
          <?php endif; ?>

          <!-- Prev -->
          <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>" class="page-link"><i class="fas fa-chevron-left"></i></a>
          <?php endif; ?>

          <!-- Window -->
          <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
               class="page-link <?= $i===$page?'active':'' ?>"><?= $i ?></a>
          <?php endfor; ?>

          <!-- Next -->
          <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>" class="page-link"><i class="fas fa-chevron-right"></i></a>
          <?php endif; ?>

          <!-- Last -->
          <?php if ($page < $totalPages - 1): ?>
            <?php if ($page < $totalPages - 2): ?><span class="page-link" style="pointer-events:none;border:none;color:var(--text-muted)">…</span><?php endif; ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $totalPages])) ?>" class="page-link" title="Last"><?= $totalPages ?></a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<script>
function setDatePreset(from, to) {
  document.getElementById('inp-date-from').value = from;
  document.getElementById('inp-date-to').value   = to;
  document.getElementById('filter-form').submit();
}
</script>

<?php require __DIR__ . '/../templates/footer.php'; ?>
