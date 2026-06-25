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
    if ($id > 0) {
        Database::query("UPDATE customers SET status = 0 WHERE id = ?", [$id]);
        flash('success', 'Customer deactivated successfully.');
        logActivity(Auth::userId(), 'customer_delete', 'customers', "Deactivated customer ID {$id}");
    }
    redirect(APP_URL . '/customers/index.php');
}

// Search / filter
$search    = trim($_GET['q']     ?? '');
$group     = trim($_GET['group'] ?? '');
$status    = $_GET['status']     ?? '1';
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 20;
$offset    = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($search) {
    $where[]  = '(name LIKE ? OR phone LIKE ? OR phone_formatted LIKE ? OR email LIKE ?)';
    $like     = "%{$search}%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
}
if ($group === '__none__') {
    $where[] = "(group_name IS NULL OR group_name = '')";
} elseif ($group) {
    $where[]  = 'group_name = ?';
    $params[] = $group;
}
if ($status !== '') {
    $where[]  = 'status = ?';
    $params[] = (int)$status;
}

$whereStr = implode(' AND ', $where);

$total     = Database::count("SELECT COUNT(*) FROM customers WHERE {$whereStr}", $params);
$customers = Database::fetchAll(
    "SELECT * FROM customers WHERE {$whereStr} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
);
$groups    = Database::fetchAll("SELECT DISTINCT group_name FROM customers WHERE group_name IS NOT NULL AND group_name != '' ORDER BY group_name");
$totalPages = (int)ceil($total / $perPage);

$pageTitle    = 'Customers';
$pageSubtitle = 'Manage &rsaquo; Customers';
require __DIR__ . '/../templates/header.php';
?>

<!-- Page Header -->
<div class="page-header">
  <div style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <h1><i class="fas fa-users" style="color:var(--secondary);margin-right:8px"></i>Customers</h1>
      <p>Manage STK push recipients &mdash; <?= number_format($total) ?> records found</p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
      <a href="<?= APP_URL ?>/customers/import.php" class="btn btn-outline-primary">
        <i class="fas fa-file-import"></i> Import CSV
      </a>
      <a href="<?= APP_URL ?>/customers/add.php" class="btn btn-secondary">
        <i class="fas fa-user-plus"></i> Add Customer
      </a>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body" style="padding:16px 22px">
    <form method="GET" action="">
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end">
        <div style="flex:1;min-width:200px">
          <label class="form-label" style="margin-bottom:5px">Search</label>
          <div class="input-group">
            <span class="input-group-text"><i class="fas fa-search" style="color:#94a3b8"></i></span>
            <input type="text" name="q" class="form-control" placeholder="Name, phone, email…" value="<?= e($search) ?>"/>
          </div>
        </div>
        <div style="min-width:160px">
          <label class="form-label" style="margin-bottom:5px">Group</label>
          <select name="group" class="form-select">
            <option value="">All Groups</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= e($g['group_name']) ?>" <?= $group === $g['group_name'] ? 'selected' : '' ?>>
                <?= e($g['group_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="min-width:130px">
          <label class="form-label" style="margin-bottom:5px">Status</label>
          <select name="status" class="form-select">
            <option value=""  <?= $status === '' ? 'selected' : '' ?>>All</option>
            <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Active</option>
            <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inactive</option>
          </select>
        </div>
        <div style="display:flex;gap:8px">
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-filter"></i> Filter
          </button>
          <a href="?" class="btn btn-light">Clear</a>
        </div>
      </div>
    </form>
  </div>
</div>

<!-- Customers Table -->
<div class="card">
  <div class="card-header">
    <div class="card-title"><i class="fas fa-list"></i> Customer List</div>
    <div style="font-size:13px;color:var(--text-muted)">
      Showing <?= min($offset + 1, $total) ?>–<?= min($offset + $perPage, $total) ?> of <?= number_format($total) ?>
    </div>
  </div>
  <div class="table-wrapper">
    <?php if (empty($customers)): ?>
      <div class="empty-state">
        <div class="empty-icon"><i class="fas fa-users-slash"></i></div>
        <h3>No customers found</h3>
        <p>Try adjusting your filters or add new customers</p>
        <div style="margin-top:16px;display:flex;gap:10px;justify-content:center">
          <a href="<?= APP_URL ?>/customers/add.php" class="btn btn-secondary btn-sm">
            <i class="fas fa-plus"></i> Add Customer
          </a>
          <a href="<?= APP_URL ?>/customers/import.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-file-import"></i> Import CSV
          </a>
        </div>
      </div>
    <?php else: ?>
      <table class="table">
        <thead>
          <tr>
            <th><input type="checkbox" id="check-all" onchange="document.querySelectorAll('.row-check').forEach(c=>c.checked=this.checked)" style="accent-color:var(--secondary)"/></th>
            <th>Customer</th>
            <th>Phone</th>
            <th>Account #</th>
            <th>Group</th>
            <th>Status</th>
            <th>Added</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($customers as $c): ?>
          <tr>
            <td><input type="checkbox" class="row-check" value="<?= $c['id'] ?>" style="accent-color:var(--secondary)"/></td>
            <td>
              <div class="customer-cell">
                <div class="customer-avatar"><?= strtoupper(substr($c['name'], 0, 1)) ?></div>
                <div>
                  <div class="customer-name"><?= e($c['name']) ?></div>
                  <?php if ($c['email']): ?>
                    <div class="customer-phone"><?= e($c['email']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td>
              <div style="font-weight:600"><?= e($c['phone_formatted']) ?></div>
              <?php if ($c['phone'] !== $c['phone_formatted']): ?>
                <div style="font-size:11px;color:var(--text-muted)"><?= e($c['phone']) ?></div>
              <?php endif; ?>
            </td>
            <td style="color:var(--text-muted)"><?= e($c['account_number'] ?: '—') ?></td>
            <td>
              <?php if ($c['group_name']): ?>
                <span class="badge badge-primary" style="font-size:11px"><?= e($c['group_name']) ?></span>
              <?php else: ?>
                <span style="color:var(--text-muted)">—</span>
              <?php endif; ?>
            </td>
            <td>
              <?php if ($c['status']): ?>
                <span class="badge badge-success">Active</span>
              <?php else: ?>
                <span class="badge badge-secondary">Inactive</span>
              <?php endif; ?>
            </td>
            <td style="font-size:12px;color:var(--text-muted)"><?= date('d M Y', strtotime($c['created_at'])) ?></td>
            <td>
              <div class="actions">
                <a href="<?= APP_URL ?>/customers/edit.php?id=<?= $c['id'] ?>" class="btn btn-outline-primary btn-sm btn-icon" title="Edit">
                  <i class="fas fa-edit"></i>
                </a>
                <form method="POST" action="" style="display:inline" onsubmit="return confirm('Deactivate this customer?')">
                  <input type="hidden" name="action" value="delete"/>
                  <input type="hidden" name="id" value="<?= $c['id'] ?>"/>
                  <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
                  <button type="submit" class="btn btn-danger btn-sm btn-icon" title="Deactivate">
                    <i class="fas fa-ban"></i>
                  </button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
    <div class="card-footer" style="display:flex;justify-content:center">
      <div class="pagination">
        <?php if ($page > 1): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="page-link">
            <i class="fas fa-chevron-left"></i>
          </a>
        <?php else: ?>
          <span class="page-link disabled"><i class="fas fa-chevron-left"></i></span>
        <?php endif; ?>

        <?php
        $start = max(1, $page - 2);
        $end   = min($totalPages, $page + 2);
        for ($i = $start; $i <= $end; $i++): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"
             class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>

        <?php if ($page < $totalPages): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="page-link">
            <i class="fas fa-chevron-right"></i>
          </a>
        <?php else: ?>
          <span class="page-link disabled"><i class="fas fa-chevron-right"></i></span>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
