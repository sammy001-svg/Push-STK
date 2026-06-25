<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::start();
Auth::requireLogin();

// Export CSV
if (($_GET['export'] ?? '') === 'csv') {
    $campaignId = (int)($_GET['campaign_id'] ?? 0);
    $status     = $_GET['status'] ?? '';
    $where      = ['1=1'];
    $params     = [];
    if ($campaignId) { $where[] = 't.campaign_id = ?'; $params[] = $campaignId; }
    if ($status)     { $where[] = 't.status = ?'; $params[]     = $status; }

    $rows = Database::fetchAll("
        SELECT t.phone, c.name AS customer_name, t.amount, t.account_ref, t.description,
               t.status, t.mpesa_receipt, t.result_description, t.initiated_at, t.completed_at,
               camp.name AS campaign_name
        FROM transactions t
        LEFT JOIN customers c    ON c.id    = t.customer_id
        LEFT JOIN campaigns camp ON camp.id = t.campaign_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY t.initiated_at DESC
        LIMIT 50000
    ", $params);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="transactions_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Campaign', 'Customer Name', 'Phone', 'Amount (KES)', 'Account Ref', 'Status', 'M-Pesa Receipt', 'Result', 'Initiated At', 'Completed At']);
    foreach ($rows as $row) {
        fputcsv($out, [
            $row['campaign_name'] ?? '',
            $row['customer_name'] ?? '',
            $row['phone'],
            $row['amount'],
            $row['account_ref'],
            $row['status'],
            $row['mpesa_receipt'] ?? '',
            $row['result_description'] ?? '',
            $row['initiated_at'],
            $row['completed_at'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// Filters
$search     = trim($_GET['q']           ?? '');
$status     = $_GET['status']           ?? '';
$campaignId = (int)($_GET['campaign_id'] ?? 0);
$dateFrom   = $_GET['date_from']        ?? '';
$dateTo     = $_GET['date_to']          ?? '';
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 25;
$offset     = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($search) {
    $like    = "%{$search}%";
    $where[] = '(t.phone LIKE ? OR c.name LIKE ? OR t.mpesa_receipt LIKE ?)';
    $params  = array_merge($params, [$like, $like, $like]);
}
if ($status)     { $where[] = 't.status = ?';      $params[] = $status; }
if ($campaignId) { $where[] = 't.campaign_id = ?'; $params[] = $campaignId; }
if ($dateFrom)   { $where[] = 'DATE(t.initiated_at) >= ?'; $params[] = $dateFrom; }
if ($dateTo)     { $where[] = 'DATE(t.initiated_at) <= ?'; $params[] = $dateTo; }

$whereStr = implode(' AND ', $where);
$total    = Database::count("SELECT COUNT(*) FROM transactions t LEFT JOIN customers c ON c.id = t.customer_id WHERE {$whereStr}", $params);
$txList   = Database::fetchAll("
    SELECT t.*, c.name AS customer_name, camp.name AS campaign_name
    FROM transactions t
    LEFT JOIN customers c    ON c.id    = t.customer_id
    LEFT JOIN campaigns camp ON camp.id = t.campaign_id
    WHERE {$whereStr}
    ORDER BY t.initiated_at DESC
    LIMIT {$perPage} OFFSET {$offset}
", $params);
$totalPages = (int)ceil($total / $perPage);

// Summary stats
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

$campaigns = Database::fetchAll("SELECT id, name FROM campaigns ORDER BY created_at DESC LIMIT 100");

$pageTitle    = 'Transactions';
$pageSubtitle = 'Reports &rsaquo; Transactions';
require __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1><i class="fas fa-receipt" style="color:var(--secondary);margin-right:8px"></i>Transactions</h1>
      <p>Complete STK push transaction history</p>
    </div>
    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-outline-primary">
      <i class="fas fa-file-csv"></i> Export CSV
    </a>
  </div>
</div>

<!-- Summary Stats -->
<div class="grid-4 mb-3">
  <div class="stat-card" style="--stat-color:var(--primary);--stat-icon-bg:rgba(13,43,85,0.08)">
    <div class="stat-icon"><i class="fas fa-paper-plane"></i></div>
    <div>
      <div class="stat-value"><?= number_format($summaryStats['total'] ?? 0) ?></div>
      <div class="stat-label">Total Pushes</div>
    </div>
  </div>
  <div class="stat-card" style="--stat-color:var(--success);--stat-icon-bg:rgba(0,166,81,0.08)">
    <div class="stat-icon"><i class="fas fa-check-circle"></i></div>
    <div>
      <div class="stat-value"><?= number_format($summaryStats['success'] ?? 0) ?></div>
      <div class="stat-label">Successful</div>
    </div>
  </div>
  <div class="stat-card" style="--stat-color:var(--danger);--stat-icon-bg:rgba(220,38,38,0.08)">
    <div class="stat-icon"><i class="fas fa-times-circle"></i></div>
    <div>
      <div class="stat-value"><?= number_format($summaryStats['failed'] ?? 0) ?></div>
      <div class="stat-label">Failed</div>
    </div>
  </div>
  <div class="stat-card" style="--stat-color:#8B5CF6;--stat-icon-bg:rgba(139,92,246,0.08)">
    <div class="stat-icon"><i class="fas fa-coins"></i></div>
    <div>
      <div class="stat-value">KES <?= number_format((float)($summaryStats['total_amount'] ?? 0)) ?></div>
      <div class="stat-label">Total Collected</div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body" style="padding:16px 22px">
    <form method="GET" action="">
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div style="flex:1;min-width:180px">
          <label class="form-label" style="margin-bottom:5px">Search</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-search" style="color:#94a3b8"></i></span>
            <input type="text" name="q" class="form-control" placeholder="Phone, name, receipt…" value="<?= e($search) ?>"/>
          </div>
        </div>
        <div style="min-width:150px">
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
          <label class="form-label" style="margin-bottom:5px">From Date</label>
          <input type="date" name="date_from" class="form-control" value="<?= e($dateFrom) ?>"/>
        </div>
        <div style="min-width:130px">
          <label class="form-label" style="margin-bottom:5px">To Date</label>
          <input type="date" name="date_to" class="form-control" value="<?= e($dateTo) ?>"/>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
          <a href="?" class="btn btn-light">Clear</a>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-table"></i> Transaction Log</div>
    <div style="font-size:13px;color:var(--text-muted)">
      Showing <?= min($offset+1,$total) ?>–<?= min($offset+$perPage,$total) ?> of <?= number_format($total) ?>
    </div>
  </div>

  <?php if (empty($txList)): ?>
    <div class="empty-state">
      <div class="empty-icon"><i class="fas fa-receipt"></i></div>
      <h3>No transactions found</h3>
      <p>Try adjusting your filters or launch a campaign to start sending</p>
    </div>
  <?php else: ?>
    <div class="table-wrapper">
      <table class="table">
        <thead>
          <tr>
            <th>#</th>
            <th>Customer</th>
            <th>Phone</th>
            <th>Campaign</th>
            <th>Amount</th>
            <th>Status</th>
            <th>M-Pesa Receipt</th>
            <th>Description</th>
            <th>Date &amp; Time</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($txList as $i => $tx): ?>
          <tr>
            <td style="color:var(--text-muted);font-size:12px"><?= $offset + $i + 1 ?></td>
            <td>
              <div class="customer-cell">
                <div class="customer-avatar"><?= strtoupper(substr($tx['customer_name'] ?? $tx['phone'], 0, 1)) ?></div>
                <div>
                  <div class="customer-name"><?= e($tx['customer_name'] ?? 'Unknown') ?></div>
                </div>
              </div>
            </td>
            <td style="font-weight:600"><?= e($tx['phone']) ?></td>
            <td>
              <?php if ($tx['campaign_name']): ?>
                <a href="<?= APP_URL ?>/campaigns/view.php?id=<?= $tx['campaign_id'] ?>" style="font-size:13px;color:var(--primary)">
                  <?= e(mb_strimwidth($tx['campaign_name'], 0, 25, '…')) ?>
                </a>
              <?php else: ?>
                <span style="color:var(--text-muted)">—</span>
              <?php endif; ?>
            </td>
            <td style="font-weight:700">KES <?= number_format((float)$tx['amount'], 2) ?></td>
            <td><?= statusBadge($tx['status']) ?></td>
            <td>
              <?php if ($tx['mpesa_receipt']): ?>
                <code style="font-size:12px;background:var(--bg);padding:3px 7px;border-radius:5px"><?= e($tx['mpesa_receipt']) ?></code>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td style="font-size:12px;color:var(--text-muted);max-width:160px">
              <?= e(mb_strimwidth($tx['result_description'] ?? $tx['response_description'] ?? '—', 0, 50, '…')) ?>
            </td>
            <td style="font-size:12px;color:var(--text-muted);white-space:nowrap">
              <?= date('d M Y', strtotime($tx['initiated_at'])) ?>
              <div><?= date('H:i:s', strtotime($tx['initiated_at'])) ?></div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="card-footer" style="display:flex;justify-content:center">
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>" class="page-link"><i class="fas fa-chevron-left"></i></a>
          <?php endif; ?>
          <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" class="page-link <?= $i===$page?'active':'' ?>"><?= $i ?></a>
          <?php endfor; ?>
          <?php if ($page < $totalPages): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>" class="page-link"><i class="fas fa-chevron-right"></i></a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
