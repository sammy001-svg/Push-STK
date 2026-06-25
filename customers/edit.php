<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Mpesa.php';

Auth::start();
Auth::requireLogin();

$id       = (int)($_GET['id'] ?? 0);
$customer = Database::fetchOne("SELECT * FROM customers WHERE id = ?", [$id]);

if (!$customer) {
    flash('error', 'Customer not found.');
    redirect(APP_URL . '/customers/index.php');
}

$errors = [];
$data   = $customer;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'name'           => trim($_POST['name']           ?? ''),
        'phone'          => trim($_POST['phone']          ?? ''),
        'email'          => trim($_POST['email']          ?? ''),
        'account_number' => trim($_POST['account_number'] ?? ''),
        'group_name'     => trim($_POST['group_name']     ?? ''),
        'notes'          => trim($_POST['notes']          ?? ''),
        'status'         => (int)($_POST['status']        ?? 1),
    ];

    if (!$data['name'])  $errors[] = 'Full name is required.';
    if (!$data['phone']) $errors[] = 'Phone number is required.';
    elseif (!Mpesa::isValidPhone($data['phone'])) $errors[] = 'Please enter a valid Kenyan phone number.';

    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        $formatted = Mpesa::formatPhone($data['phone']);
        // Check duplicate excluding self
        $existing = Database::fetchOne("SELECT id FROM customers WHERE phone_formatted = ? AND id != ?", [$formatted, $id]);
        if ($existing) {
            $errors[] = "Another customer with phone {$formatted} already exists.";
        } else {
            Database::update('customers', [
                'name'            => $data['name'],
                'phone'           => $data['phone'],
                'phone_formatted' => $formatted,
                'email'           => $data['email']          ?: null,
                'account_number'  => $data['account_number'] ?: null,
                'group_name'      => $data['group_name']     ?: null,
                'notes'           => $data['notes']          ?: null,
                'status'          => $data['status'],
            ], 'id = ?', [$id]);
            logActivity(Auth::userId(), 'customer_update', 'customers', "Updated customer ID {$id}");
            flash('success', "Customer updated successfully.");
            redirect(APP_URL . '/customers/index.php');
        }
    }
}

$groups    = Database::fetchAll("SELECT name AS group_name, color FROM customer_groups ORDER BY name ASC");
$pageTitle = 'Edit Customer';
$pageSubtitle = 'Customers &rsaquo; Edit';
require __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="<?= APP_URL ?>/customers/index.php" class="btn btn-light btn-sm"><i class="fas fa-arrow-left"></i></a>
    <div>
      <h1><i class="fas fa-user-edit" style="color:var(--secondary);margin-right:8px"></i>Edit Customer</h1>
      <p>Update customer information</p>
    </div>
  </div>
</div>

<div style="max-width:700px">
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-user"></i> <?= e($customer['name']) ?></div>
      <span class="badge <?= $customer['status'] ? 'badge-success' : 'badge-secondary' ?>">
        <?= $customer['status'] ? 'Active' : 'Inactive' ?>
      </span>
    </div>
    <div class="card-body">

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <i class="fas fa-exclamation-circle"></i>
          <div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
        </div>
      <?php endif; ?>

      <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>

        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Full Name <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" value="<?= e($data['name']) ?>" required/>
          </div>
          <div class="form-group">
            <label class="form-label">Phone Number <span class="required">*</span></label>
            <div class="input-group">
              <span class="input-group-text">🇰🇪</span>
              <input type="tel" name="phone" class="form-control" data-phone value="<?= e($data['phone']) ?>" required/>
            </div>
          </div>
        </div>

        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-control" value="<?= e($data['email'] ?? '') ?>"/>
          </div>
          <div class="form-group">
            <label class="form-label">Account / Reference Number</label>
            <input type="text" name="account_number" class="form-control" value="<?= e($data['account_number'] ?? '') ?>"/>
          </div>
        </div>

        <div class="grid-2">
          <div class="form-group">
            <label class="form-label">Group</label>
            <select id="group_name" name="group_name" class="form-select">
              <option value="">— No group —</option>
              <?php foreach ($groups as $g): ?>
                <option value="<?= e($g['group_name']) ?>" <?= ($data['group_name'] ?? '') === $g['group_name'] ? 'selected' : '' ?>>
                  <?= e($g['group_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="1" <?= ($data['status'] ?? 1) == 1 ? 'selected' : '' ?>>Active</option>
              <option value="0" <?= ($data['status'] ?? 1) == 0 ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Notes</label>
          <textarea name="notes" class="form-control" rows="3"><?= e($data['notes'] ?? '') ?></textarea>
        </div>

        <div style="display:flex;gap:12px;margin-top:8px">
          <button type="submit" class="btn btn-secondary btn-lg"><i class="fas fa-save"></i> Save Changes</button>
          <a href="<?= APP_URL ?>/customers/index.php" class="btn btn-light btn-lg">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
