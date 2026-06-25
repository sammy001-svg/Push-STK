<?php
/**
 * BulkSTK Pro – One-Click Installer
 * Run this once at: http://localhost/Push STK/install.php
 * DELETE this file after installation.
 */

if (file_exists(__DIR__ . '/install.lock')) {
    die('<h2 style="font-family:sans-serif;color:red">Already installed. Delete install.php and install.lock for security.</h2>');
}

$step    = (int)($_GET['step'] ?? 1);
$errors  = [];
$success = [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1"/>
  <title>BulkSTK Pro — Installer</title>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'Segoe UI',sans-serif;background:#F0F4F8;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px}
    .card{background:#fff;border-radius:14px;max-width:640px;width:100%;padding:40px;box-shadow:0 8px 32px rgba(0,0,0,0.12)}
    h1{font-size:24px;color:#0D2B55;margin-bottom:6px}
    p{color:#64748B;font-size:14px;margin-bottom:24px}
    .step-header{display:flex;align-items:center;gap:16px;margin-bottom:28px}
    .step-badge{background:#0D2B55;color:#fff;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;flex-shrink:0}
    .form-group{margin-bottom:16px}
    label{display:block;font-size:13px;font-weight:600;color:#1E293B;margin-bottom:6px}
    input[type=text],input[type=password]{width:100%;padding:10px 14px;border:1.5px solid #E2E8F0;border-radius:8px;font-size:14px;outline:none;transition:border-color 0.2s}
    input:focus{border-color:#00A651;box-shadow:0 0 0 3px rgba(0,166,81,0.12)}
    .btn{display:inline-flex;align-items:center;gap:8px;padding:11px 24px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;border:none;text-decoration:none}
    .btn-primary{background:#0D2B55;color:#fff}
    .btn-success{background:#00A651;color:#fff;font-size:16px;padding:14px 32px;width:100%;justify-content:center}
    .alert{padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px}
    .alert-danger{background:#FEE2E2;color:#DC2626;border-left:4px solid #DC2626}
    .alert-success{background:#DCFCE7;color:#15803D;border-left:4px solid #00A651}
    .check-item{display:flex;align-items:center;gap:10px;padding:10px;background:#F8FAFC;border-radius:8px;margin-bottom:8px;font-size:14px}
    .check-ok{color:#00A651;font-size:18px}
    .check-fail{color:#DC2626;font-size:18px}
    pre{background:#1E293B;color:#E2E8F0;padding:16px;border-radius:8px;font-size:13px;overflow-x:auto;margin-top:12px}
    hr{border:none;border-top:1px solid #E2E8F0;margin:24px 0}
  </style>
</head>
<body>
<div class="card">
  <div style="text-align:center;margin-bottom:28px">
    <div style="background:linear-gradient(135deg,#00A651,#007A3D);width:64px;height:64px;border-radius:16px;display:inline-flex;align-items:center;justify-content:center;font-size:30px;margin-bottom:12px">💸</div>
    <h1>BulkSTK Pro</h1>
    <p>M-Pesa Bulk STK Push Dashboard — Installer</p>
  </div>

<?php

// ─── Step 1: Requirements Check ──────────────────────────────────────────────
if ($step === 1): ?>
  <div class="step-header">
    <div class="step-badge">1</div>
    <div><strong style="font-size:16px;color:#0D2B55">System Requirements</strong><br/><span style="font-size:13px;color:#64748B">Checking your environment</span></div>
  </div>

  <?php
  $checks = [
    'PHP 8.0+'       => version_compare(PHP_VERSION, '8.0.0', '>='),
    'PDO Extension'  => extension_loaded('pdo'),
    'PDO MySQL'      => extension_loaded('pdo_mysql'),
    'cURL Extension' => extension_loaded('curl'),
    'JSON Extension' => extension_loaded('json'),
    'OpenSSL'        => extension_loaded('openssl'),
    'uploads/ writable' => is_writable(__DIR__ . '/uploads'),
  ];
  $allPassed = !in_array(false, $checks, true);
  ?>

  <?php foreach ($checks as $check => $pass): ?>
    <div class="check-item">
      <span class="<?= $pass ? 'check-ok' : 'check-fail' ?>"><?= $pass ? '✔' : '✘' ?></span>
      <span><?= $check ?></span>
      <?php if (!$pass): ?><span style="margin-left:auto;font-size:12px;color:#DC2626">FAILED</span><?php endif; ?>
    </div>
  <?php endforeach; ?>

  <?php if (!$allPassed): ?>
    <div class="alert alert-danger" style="margin-top:16px">
      Please fix the failed requirements before proceeding.
    </div>
  <?php else: ?>
    <div class="alert alert-success" style="margin-top:16px">✔ All requirements met. Ready to install!</div>
    <a href="?step=2" class="btn btn-success" style="margin-top:8px">Continue →</a>
  <?php endif; ?>

<?php
// ─── Step 2: Database Configuration ─────────────────────────────────────────
elseif ($step === 2):

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host   = trim($_POST['db_host']   ?? 'localhost');
    $name   = trim($_POST['db_name']   ?? 'mpesa_bulk_stk');
    $user   = trim($_POST['db_user']   ?? 'root');
    $pass   = $_POST['db_pass']        ?? '';

    try {
      $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
      ]);
      // Create DB
      $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
      $pdo->exec("USE `{$name}`");

      // Run schema
      $sql = file_get_contents(__DIR__ . '/database/schema.sql');
      // Remove USE statement since we've already selected DB
      $sql = preg_replace('/^USE\s+\w+;/m', '', $sql);
      $sql = preg_replace('/^CREATE DATABASE.*?;/m', '', $sql);
      foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt) $pdo->exec($stmt);
      }

      // Update config file
      $configContent = "<?php\ndefine('DB_HOST', " . var_export($host, true) . ");\ndefine('DB_NAME', " . var_export($name, true) . ");\ndefine('DB_USER', " . var_export($user, true) . ");\ndefine('DB_PASS', " . var_export($pass, true) . ");\ndefine('DB_CHARSET', 'utf8mb4');\ndefine('APP_NAME', 'BulkSTK Pro');\ndefine('APP_URL', 'http://' . \$_SERVER['HTTP_HOST'] . '/" . basename(__DIR__) . "');\ndefine('APP_VERSION', '1.0.0');\ndefine('SESSION_TIMEOUT', 7200);\ndefine('TIMEZONE', 'Africa/Nairobi');\ndate_default_timezone_set(TIMEZONE);\n";
      file_put_contents(__DIR__ . '/config/database.php', $configContent);

      // Create lock file
      file_put_contents(__DIR__ . '/install.lock', date('Y-m-d H:i:s'));

      header('Location: ?step=3');
      exit;

    } catch (PDOException $e) {
      $errors[] = 'Database error: ' . $e->getMessage();
    }
  }
?>
  <div class="step-header">
    <div class="step-badge">2</div>
    <div><strong style="font-size:16px;color:#0D2B55">Database Setup</strong><br/><span style="font-size:13px;color:#64748B">Configure your MySQL connection</span></div>
  </div>

  <?php foreach ($errors as $e): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
  <?php endforeach; ?>

  <form method="POST">
    <div class="form-group">
      <label>Database Host</label>
      <input type="text" name="db_host" value="localhost" placeholder="localhost"/>
    </div>
    <div class="form-group">
      <label>Database Name</label>
      <input type="text" name="db_name" value="mpesa_bulk_stk" placeholder="mpesa_bulk_stk"/>
    </div>
    <div class="form-group">
      <label>Database Username</label>
      <input type="text" name="db_user" value="root" placeholder="root"/>
    </div>
    <div class="form-group">
      <label>Database Password</label>
      <input type="password" name="db_pass" placeholder="Leave blank if none"/>
    </div>
    <button type="submit" class="btn btn-success">Install Database →</button>
  </form>

<?php
// ─── Step 3: Success ─────────────────────────────────────────────────────────
elseif ($step === 3): ?>
  <div style="text-align:center">
    <div style="font-size:60px;margin-bottom:16px">🎉</div>
    <h1 style="color:#00A651;font-size:28px">Installation Complete!</h1>
    <p style="margin-bottom:24px">BulkSTK Pro has been installed successfully.</p>

    <div class="alert alert-success">
      <strong>Default Login Credentials:</strong><br/>
      Email: <code>admin@bulkstk.co.ke</code><br/>
      Password: <code>password</code>
    </div>

    <div class="alert alert-danger" style="margin-top:16px">
      ⚠️ <strong>Important Security Steps:</strong>
      <ol style="text-align:left;margin-top:8px;padding-left:20px;line-height:1.8">
        <li>Delete <code>install.php</code> from your server</li>
        <li>Change the default admin password immediately</li>
        <li>Configure your M-Pesa API credentials in Settings</li>
        <li>Set up your HTTPS callback URL</li>
      </ol>
    </div>

    <a href="index.php" class="btn btn-success" style="margin-top:16px">Open Dashboard →</a>
  </div>
<?php endif; ?>

</div>
</body>
</html>
