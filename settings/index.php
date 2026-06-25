<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mpesa.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::start();
Auth::requireLogin();
Auth::requireRole('super_admin', 'admin');

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $section = $_POST['section'] ?? '';

    if ($section === 'mpesa') {
        $keys = ['mpesa_env','mpesa_consumer_key','mpesa_consumer_secret','mpesa_shortcode','mpesa_passkey','mpesa_callback_url'];
        foreach ($keys as $key) {
            saveSetting($key, trim($_POST[$key] ?? ''), Auth::userId());
        }
        logActivity(Auth::userId(), 'settings_update', 'settings', 'Updated M-Pesa settings');
        $success = 'M-Pesa API settings saved.';
    }

    if ($section === 'general') {
        $keys = ['app_name','company_name','company_email','company_phone'];
        foreach ($keys as $key) {
            saveSetting($key, trim($_POST[$key] ?? ''), Auth::userId());
        }
        $success = 'General settings saved.';
    }

    if ($section === 'processing') {
        $batchSize = max(1, min(10, (int)($_POST['batch_size'] ?? 5)));
        $maxRetries = max(0, min(5, (int)($_POST['max_retries'] ?? 2)));
        $stkTimeout = max(30, min(120, (int)($_POST['stk_timeout'] ?? 55)));
        saveSetting('batch_size',  (string)$batchSize,  Auth::userId());
        saveSetting('max_retries', (string)$maxRetries, Auth::userId());
        saveSetting('stk_timeout', (string)$stkTimeout, Auth::userId());
        $success = 'Processing settings saved.';
    }

    if ($section === 'password') {
        $current  = $_POST['current_password']  ?? '';
        $newPass  = $_POST['new_password']       ?? '';
        $confirm  = $_POST['confirm_password']   ?? '';

        $user = Database::fetchOne("SELECT * FROM admin_users WHERE id = ?", [Auth::userId()]);
        if (!password_verify($current, $user['password'])) {
            $errors[] = 'Current password is incorrect.';
        } elseif (strlen($newPass) < 8) {
            $errors[] = 'New password must be at least 8 characters.';
        } elseif ($newPass !== $confirm) {
            $errors[] = 'New passwords do not match.';
        } else {
            Database::update('admin_users', ['password' => password_hash($newPass, PASSWORD_BCRYPT)], 'id = ?', [Auth::userId()]);
            $success = 'Password changed successfully.';
        }
    }
}

// Load current settings
$settings = [];
$rows = Database::fetchAll("SELECT setting_key, setting_value FROM settings");
foreach ($rows as $r) $settings[$r['setting_key']] = $r['setting_value'];
$s = fn($k, $d='') => $settings[$k] ?? $d;

// Test M-Pesa connection
$connectionStatus = null;
if ($_GET['test_mpesa'] ?? false) {
    require_once __DIR__ . '/../includes/Mpesa.php';
    $mpesa = new Mpesa();
    $token = $mpesa->getAccessToken();
    $connectionStatus = $token ? 'success' : 'failed';
}

$pageTitle    = 'Settings';
$pageSubtitle = 'System &rsaquo; Settings';
require __DIR__ . '/../templates/header.php';
?>

<div class="page-header">
  <h1><i class="fas fa-cog" style="color:var(--secondary);margin-right:8px"></i>Settings</h1>
  <p>Configure the application and M-Pesa API credentials</p>
</div>

<?php if ($success): ?>
  <div class="alert alert-success mb-3" data-auto-dismiss="4000">
    <i class="fas fa-check-circle"></i> <?= e($success) ?>
  </div>
<?php endif; ?>
<?php if ($errors): ?>
  <div class="alert alert-danger mb-3">
    <i class="fas fa-exclamation-circle"></i>
    <div><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
  </div>
<?php endif; ?>

<div class="grid-2" style="grid-template-columns:1fr 1fr;align-items:start">

  <!-- M-Pesa API Settings -->
  <div>
    <div class="card mb-3">
      <div class="card-header">
        <div class="card-title"><i class="fas fa-mobile-alt"></i> M-Pesa API Configuration</div>
        <a href="?test_mpesa=1" class="btn btn-outline-primary btn-sm" id="test-btn" onclick="this.textContent='Testing…'">
          <i class="fas fa-plug"></i> Test Connection
        </a>
      </div>
      <div class="card-body">

        <?php if ($connectionStatus !== null): ?>
          <div class="alert alert-<?= $connectionStatus === 'success' ? 'success' : 'danger' ?> mb-3">
            <i class="fas fa-<?= $connectionStatus === 'success' ? 'check-circle' : 'times-circle' ?>"></i>
            <?= $connectionStatus === 'success' ? 'M-Pesa API connected successfully!' : 'Connection failed. Check your credentials.' ?>
          </div>
        <?php endif; ?>

        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
          <input type="hidden" name="section"    value="mpesa"/>

          <div class="form-group">
            <label class="form-label">Environment</label>
            <select name="mpesa_env" class="form-select">
              <option value="sandbox"    <?= $s('mpesa_env') === 'sandbox'    ? 'selected' : '' ?>>Sandbox (Testing)</option>
              <option value="production" <?= $s('mpesa_env') === 'production' ? 'selected' : '' ?>>Production (Live)</option>
            </select>
            <div class="form-hint">Use Sandbox for testing, Production for live transactions</div>
          </div>

          <div class="form-group">
            <label class="form-label">Consumer Key</label>
            <input type="text" name="mpesa_consumer_key" class="form-control"
                   placeholder="Your Daraja Consumer Key" value="<?= e($s('mpesa_consumer_key')) ?>"/>
          </div>

          <div class="form-group">
            <label class="form-label">Consumer Secret</label>
            <input type="password" name="mpesa_consumer_secret" class="form-control"
                   placeholder="Your Daraja Consumer Secret" value="<?= e($s('mpesa_consumer_secret')) ?>"
                   autocomplete="new-password"/>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Business Short Code</label>
              <input type="text" name="mpesa_shortcode" class="form-control"
                     placeholder="e.g. 174379" value="<?= e($s('mpesa_shortcode', '174379')) ?>"/>
            </div>
            <div class="form-group">
              <label class="form-label">Lipa Na M-Pesa Passkey</label>
              <input type="password" name="mpesa_passkey" class="form-control"
                     placeholder="Your Online Passkey" value="<?= e($s('mpesa_passkey')) ?>"
                     autocomplete="new-password"/>
            </div>
          </div>

          <div class="form-group">
            <label class="form-label">Callback URL</label>
            <div style="display:flex;gap:8px;align-items:center">
              <input type="url" name="mpesa_callback_url" id="callback_url_input" class="form-control"
                     placeholder="https://yourdomain.com/api/callback.php"
                     value="<?= e($s('mpesa_callback_url', APP_URL . '/api/callback.php')) ?>"/>
              <button type="button" class="btn btn-light btn-sm" style="white-space:nowrap"
                      onclick="detectNgrok()" id="ngrok-detect-btn">
                <i class="fas fa-search"></i> Auto-Detect ngrok
              </button>
            </div>
            <div class="form-hint" id="callback-hint">
              Must be a publicly accessible HTTPS URL. For local testing run
              <code>C:\ngrok\ngrok.exe http 80</code> then click Auto-Detect.
            </div>
          </div>

          <button type="submit" class="btn btn-secondary">
            <i class="fas fa-save"></i> Save M-Pesa Settings
          </button>
        </form>
      </div>
    </div>

    <!-- Processing Settings -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fas fa-sliders-h"></i> Processing Settings</div>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
          <input type="hidden" name="section"    value="processing"/>

          <div class="form-group">
            <label class="form-label">Batch Size (1–10)</label>
            <input type="number" name="batch_size" class="form-control" min="1" max="10"
                   value="<?= e($s('batch_size', '5')) ?>"/>
            <div class="form-hint">Number of STK pushes sent per batch. Lower values are safer (default: 5)</div>
          </div>

          <div class="form-group">
            <label class="form-label">Max Retries (0–5)</label>
            <input type="number" name="max_retries" class="form-control" min="0" max="5"
                   value="<?= e($s('max_retries', '2')) ?>"/>
            <div class="form-hint">How many times to retry a failed STK push</div>
          </div>

          <div class="form-group">
            <label class="form-label">STK Timeout (30–120 seconds)</label>
            <input type="number" name="stk_timeout" class="form-control" min="30" max="120"
                   value="<?= e($s('stk_timeout', '55')) ?>"/>
            <div class="form-hint">Seconds to wait for M-Pesa callback before marking as timeout</div>
          </div>

          <button type="submit" class="btn btn-secondary">
            <i class="fas fa-save"></i> Save Processing Settings
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Right column -->
  <div>
    <!-- General Settings -->
    <div class="card mb-3">
      <div class="card-header">
        <div class="card-title"><i class="fas fa-building"></i> General Settings</div>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
          <input type="hidden" name="section"    value="general"/>

          <div class="form-group">
            <label class="form-label">App Name</label>
            <input type="text" name="app_name" class="form-control" value="<?= e($s('app_name', APP_NAME)) ?>"/>
          </div>
          <div class="form-group">
            <label class="form-label">Company Name</label>
            <input type="text" name="company_name" class="form-control" value="<?= e($s('company_name')) ?>" placeholder="Your Company Ltd"/>
          </div>
          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Contact Email</label>
              <input type="email" name="company_email" class="form-control" value="<?= e($s('company_email')) ?>"/>
            </div>
            <div class="form-group">
              <label class="form-label">Contact Phone</label>
              <input type="text" name="company_phone" class="form-control" value="<?= e($s('company_phone')) ?>"/>
            </div>
          </div>
          <button type="submit" class="btn btn-secondary">
            <i class="fas fa-save"></i> Save General Settings
          </button>
        </form>
      </div>
    </div>

    <!-- Change Password -->
    <div class="card mb-3">
      <div class="card-header">
        <div class="card-title"><i class="fas fa-key"></i> Change Password</div>
      </div>
      <div class="card-body">
        <form method="POST">
          <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>"/>
          <input type="hidden" name="section"    value="password"/>

          <div class="form-group">
            <label class="form-label">Current Password</label>
            <input type="password" name="current_password" class="form-control" required autocomplete="current-password"/>
          </div>
          <div class="form-group">
            <label class="form-label">New Password</label>
            <input type="password" name="new_password" class="form-control" minlength="8" required autocomplete="new-password"/>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm New Password</label>
            <input type="password" name="confirm_password" class="form-control" required autocomplete="new-password"/>
          </div>
          <button type="submit" class="btn btn-primary">
            <i class="fas fa-key"></i> Change Password
          </button>
        </form>
      </div>
    </div>

    <!-- System Info -->
    <div class="card">
      <div class="card-header">
        <div class="card-title"><i class="fas fa-server"></i> System Information</div>
      </div>
      <div class="card-body">
        <table style="width:100%;font-size:13px">
          <?php foreach ([
            ['App Version',   APP_VERSION],
            ['PHP Version',   PHP_VERSION],
            ['Database',      DB_NAME],
            ['Environment',   ucfirst($s('mpesa_env', MPESA_ENV))],
            ['Server Time',   date('d M Y H:i:s')],
            ['Timezone',      TIMEZONE],
          ] as [$key, $val]): ?>
            <tr>
              <td style="color:var(--text-muted);padding:7px 0;border-bottom:1px solid var(--border)"><?= $key ?></td>
              <td style="font-weight:600;text-align:right;padding:7px 0;border-bottom:1px solid var(--border)"><?= e($val) ?></td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
async function detectNgrok() {
  const btn  = document.getElementById('ngrok-detect-btn');
  const hint = document.getElementById('callback-hint');
  const orig = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner spinner-sm"></span> Detecting…';

  try {
    const res = await fetch('<?= APP_URL ?>/api/detect_ngrok.php', {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(r => r.json());

    if (res.success) {
      document.getElementById('callback_url_input').value = res.callback_url;
      hint.innerHTML = '<span style="color:#059669"><i class="fas fa-check-circle"></i> ngrok detected: <strong>' + res.callback_url + '</strong> — click Save M-Pesa Settings to apply.</span>';
    } else {
      hint.innerHTML = '<span style="color:#DC2626"><i class="fas fa-times-circle"></i> ' + (res.message || 'ngrok not found.') + ' Make sure ngrok is running (<code>C:\\ngrok\\ngrok.exe http 80</code>).</span>';
    }
  } catch(e) {
    hint.innerHTML = '<span style="color:#DC2626"><i class="fas fa-times-circle"></i> Network error detecting ngrok.</span>';
  }

  btn.disabled = false;
  btn.innerHTML = orig;
}
</script>
<?php require __DIR__ . '/../templates/footer.php'; ?>
