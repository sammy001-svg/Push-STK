<?php
/**
 * Pre-launch validation for a campaign.
 * Checks credentials, callback URL, OAuth connectivity, and recipient count.
 * Called by the Launch button before starting BulkSender.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mpesa.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Mpesa.php';

header('Content-Type: application/json');
Auth::start();
if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
}

$campaignId = (int)($_GET['campaign_id'] ?? 0);
$issues     = [];
$canLaunch  = true;

// ── 1. Campaign has pending recipients ───────────────────────
if ($campaignId) {
    $pending = Database::count(
        "SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending'",
        [$campaignId]
    );
    if ($pending === 0) {
        $issues[] = [
            'type'  => 'error',
            'title' => 'No pending recipients',
            'msg'   => 'This campaign has no pending recipients to send to.',
            'fix'   => 'Use the Recovery Centre to retry failed recipients, or create a new campaign.',
        ];
        $canLaunch = false;
    }
}

// ── 2. M-Pesa credentials present ────────────────────────────
$key    = getSetting('mpesa_consumer_key',    MPESA_CONSUMER_KEY);
$secret = getSetting('mpesa_consumer_secret', MPESA_CONSUMER_SECRET);
$sc     = getSetting('mpesa_shortcode',       MPESA_SHORTCODE);

if (empty($key) || empty($secret) || empty($sc)) {
    $issues[] = [
        'type'  => 'error',
        'title' => 'M-Pesa credentials missing',
        'msg'   => 'Consumer Key, Consumer Secret, or Shortcode is not set.',
        'fix'   => 'Go to Settings → M-Pesa and enter your Daraja API credentials.',
    ];
    $canLaunch = false;
}

// ── 3. Callback URL must be HTTPS in production ───────────────
$env         = getSetting('mpesa_env',          MPESA_ENV);
$callbackUrl = getSetting('mpesa_callback_url', '');

if (!str_starts_with($callbackUrl, 'https://')) {
    if ($env === 'production') {
        $issues[] = [
            'type'  => 'error',
            'title' => 'Callback URL is not HTTPS',
            'msg'   => 'Safaricom production requires an HTTPS callback URL. '
                     . 'Current value: ' . ($callbackUrl ?: '(not set)'),
            'fix'   => "Run ngrok to get a public HTTPS URL:\n"
                     . "1. Open a terminal and run: C:\\ngrok\\ngrok.exe http 80\n"
                     . "2. Go to Settings → M-Pesa → click \"Auto-Detect ngrok\"\n"
                     . "3. Save settings, then retry launching.",
        ];
        $canLaunch = false;
    } else {
        // Sandbox: warn but don't block
        $issues[] = [
            'type'  => 'warning',
            'title' => 'Callback URL not HTTPS',
            'msg'   => 'Payment results won\'t update automatically without an HTTPS callback URL.',
            'fix'   => 'Set an HTTPS ngrok URL in Settings for full end-to-end testing.',
        ];
    }
}

// ── 4. OAuth token reachability ───────────────────────────────
// Only test if credentials are present (skip if already blocked above)
if (!empty($key) && !empty($secret)) {
    try {
        // Delete cached token so we get a fresh test
        $cacheFile = sys_get_temp_dir() . '/mpesa_token_' . md5($key) . '.cache';
        if (file_exists($cacheFile)) @unlink($cacheFile);

        $mpesa = new Mpesa();
        $token = $mpesa->getAccessToken();
        if (!$token) {
            $issues[] = [
                'type'  => 'error',
                'title' => 'Cannot connect to Safaricom',
                'msg'   => 'Failed to get OAuth token. Consumer Key or Secret may be wrong.',
                'fix'   => 'Verify credentials at developer.safaricom.co.ke and update them in Settings.',
            ];
            $canLaunch = false;
        }
    } catch (Throwable $e) {
        $issues[] = [
            'type'  => 'error',
            'title' => 'M-Pesa connection error',
            'msg'   => $e->getMessage(),
            'fix'   => 'Check your internet connection and M-Pesa credentials in Settings.',
        ];
        $canLaunch = false;
    }
}

jsonResponse([
    'success'    => $canLaunch,
    'can_launch' => $canLaunch,
    'issues'     => $issues,
    'env'        => $env,
]);
