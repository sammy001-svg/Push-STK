<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::start();

// Already logged in → go to dashboard
if (Auth::isLoggedIn()) {
    header('Location: ' . APP_URL . '/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!$email || !$password) {
        $error = 'Please enter your email and password.';
    } else {
        $result = Auth::login($email, $password);
        if ($result['success']) {
            header('Location: ' . APP_URL . '/dashboard.php');
            exit;
        } else {
            $error = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Login — <?= APP_NAME ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css"/>
</head>
<body>
<div class="login-page">
  <div class="login-card">
    <div class="login-logo">
      <div class="logo-mark">💸</div>
      <h2><?= APP_NAME ?></h2>
      <p>M-Pesa Bulk STK Push Dashboard</p>
    </div>

    <?php if ($error): ?>
      <div class="alert alert-danger" style="margin-bottom:20px">
        <i class="fas fa-exclamation-circle"></i> <?= e($error) ?>
      </div>
    <?php endif; ?>

    <form method="POST" action="">
      <div class="form-group">
        <label class="form-label" for="email">Email Address</label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-envelope" style="color:#94a3b8"></i></span>
          <input
            type="email"
            id="email"
            name="email"
            class="form-control"
            placeholder="admin@bulkstk.co.ke"
            value="<?= e($_POST['email'] ?? '') ?>"
            required
            autofocus
          />
        </div>
      </div>

      <div class="form-group">
        <label class="form-label" for="password">
          Password
          <a href="#" style="float:right;font-size:12px;font-weight:500">Forgot password?</a>
        </label>
        <div class="input-group">
          <span class="input-group-text"><i class="fas fa-lock" style="color:#94a3b8"></i></span>
          <input
            type="password"
            id="password"
            name="password"
            class="form-control"
            placeholder="Enter your password"
            required
          />
          <button type="button" class="btn btn-light" style="border-radius:0 8px 8px 0;border:1.5px solid var(--border);border-left:none"
                  onclick="const f=document.getElementById('password');f.type=f.type==='password'?'text':'password';this.querySelector('i').className=f.type==='password'?'fas fa-eye':'fas fa-eye-slash'">
            <i class="fas fa-eye"></i>
          </button>
        </div>
      </div>

      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:22px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text-muted)">
          <input type="checkbox" name="remember" style="accent-color:var(--secondary);width:16px;height:16px"/>
          Remember me
        </label>
      </div>

      <button type="submit" class="btn btn-primary w-100 btn-lg" style="background:var(--primary);justify-content:center">
        <i class="fas fa-sign-in-alt"></i>
        Sign In
      </button>
    </form>

    <div style="margin-top:28px;padding-top:22px;border-top:1px solid var(--border);text-align:center">
      <p style="font-size:12px;color:var(--text-muted)">
        Default: <code>admin@bulkstk.co.ke</code> / <code>Admin@2024</code>
      </p>
    </div>

    <div style="margin-top:20px;text-align:center">
      <p style="font-size:12px;color:#94A3B8">
        &copy; <?= date('Y') ?> <?= APP_NAME ?> &mdash; Powered by Safaricom Daraja API
      </p>
    </div>
  </div>
</div>

<script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body>
</html>
