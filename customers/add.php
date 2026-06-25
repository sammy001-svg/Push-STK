<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Mpesa.php';

Auth::start();
Auth::requireLogin();

$errors = [];
$data   = ['name' => '', 'phone' => '', 'email' => '', 'account_number' => '', 'group_name' => '', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $data = [
        'name'           => trim($_POST['name']           ?? ''),
        'phone'          => trim($_POST['phone']          ?? ''),
        'email'          => trim($_POST['email']          ?? ''),
        'account_number' => trim($_POST['account_number'] ?? ''),
        'group_name'     => trim($_POST['group_name']     ?? ''),
        'notes'          => trim($_POST['notes']          ?? ''),
    ];

    // Validation
    if (!$data['name'])  $errors[] = 'Full name is required.';
    if (!$data['phone']) $errors[] = 'Phone number is required.';
    elseif (!Mpesa::isValidPhone($data['phone'])) $errors[] = 'Please enter a valid Kenyan phone number (e.g., 0712345678).';

    if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }

    if (empty($errors)) {
        $formatted = Mpesa::formatPhone($data['phone']);

        // Check duplicate
        $existing = Database::fetchOne("SELECT id FROM customers WHERE phone_formatted = ?", [$formatted]);
        if ($existing) {
            $errors[] = "A customer with phone {$formatted} already exists.";
        } else {
            $id = Database::insert('customers', [
                'name'           => $data['name'],
                'phone'          => $data['phone'],
                'phone_formatted'=> $formatted,
                'email'          => $data['email']          ?: null,
                'account_number' => $data['account_number'] ?: null,
                'group_name'     => $data['group_name']     ?: null,
                'notes'          => $data['notes']          ?: null,
                'status'         => 1,
            ]);
            logActivity(Auth::userId(), 'customer_create', 'customers', "Created customer: {$data['name']} ({$formatted})");
            flash('success', "Customer '{$data['name']}' added successfully.");
            redirect(APP_URL . '/customers/index.php');
        }
    }
}

$groups    = Database::fetchAll("SELECT name AS group_name, color FROM customer_groups ORDER BY name ASC");
$pageTitle = 'Add Customer';
$pageSubtitle = 'Customers &rsaquo; Add New';
require __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
  <div style="display:flex;align-items:center;gap:12px">
    <a href="<?= APP_URL ?>/customers/index.php" class="btn btn-light btn-sm">
      <i class="fas fa-arrow-left"></i>
    </a>
    <div>
      <h1><i class="fas fa-user-plus" style="color:var(--secondary);margin-right:8px"></i>Add Customer</h1>
      <p>Add a new STK push recipient</p>
    </div>
  </div>
</div>

<div style="max-width:700px">
  <div class="card">
    <div class="card-header">
      <div class="card-title"><i class="fas fa-user"></i> Customer Information</div>
    </div>
    <div class="card-body">

      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <i class="fas fa-exclamation-circle"></i>
          <div>
            <?php foreach ($errors as $err): ?>
              <div><?= e($err) ?></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <form method="POST" action="">
        <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>

        <div class="grid-2">
          <div class="form-group">
            <label class="form-label" for="name">Full Name <span class="required">*</span></label>
            <input type="text" id="name" name="name" class="form-control <?= in_array('Full name is required.', $errors) ? 'is-invalid' : '' ?>"
                   placeholder="e.g. John Doe" value="<?= e($data['name']) ?>" required/>
          </div>
          <div class="form-group">
            <label class="form-label" for="phone">Phone Number <span class="required">*</span></label>
            <div class="input-group">
              <span class="input-group-text">🇰🇪</span>
              <input type="tel" id="phone" name="phone" class="form-control" data-phone
                     placeholder="0712 345 678" value="<?= e($data['phone']) ?>" required/>
            </div>
            <div class="form-hint">Kenyan number: 07xx, 01xx, or 254xxx format</div>
          </div>
        </div>

        <div class="grid-2">
          <div class="form-group">
            <label class="form-label" for="email">Email Address</label>
            <input type="email" id="email" name="email" class="form-control"
                   placeholder="john@example.com" value="<?= e($data['email']) ?>"/>
          </div>
          <div class="form-group">
            <label class="form-label" for="account_number">Account / Reference Number</label>
            <input type="text" id="account_number" name="account_number" class="form-control"
                   placeholder="e.g. ACC-001" value="<?= e($data['account_number']) ?>"/>
            <div class="form-hint">Used as M-Pesa account reference if set</div>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="group_name">Group</label>
          <select id="group_name" name="group_name" class="form-select">
            <option value="">— No group —</option>
            <?php foreach ($groups as $g): ?>
              <option value="<?= e($g['group_name']) ?>" <?= $data['group_name'] === $g['group_name'] ? 'selected' : '' ?>>
                <?= e($g['group_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-hint">Group customers to target specific segments in campaigns.
            <a href="<?= APP_URL ?>/customers/groups.php">Manage groups &rarr;</a>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="notes">Notes</label>
          <textarea id="notes" name="notes" class="form-control" rows="3"
                    placeholder="Optional notes about this customer…"><?= e($data['notes']) ?></textarea>
        </div>

        <div style="display:flex;gap:12px;margin-top:8px">
          <button type="submit" class="btn btn-secondary btn-lg">
            <i class="fas fa-save"></i> Save Customer
          </button>
          <a href="<?= APP_URL ?>/customers/index.php" class="btn btn-light btn-lg">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../templates/footer.php'; ?>
