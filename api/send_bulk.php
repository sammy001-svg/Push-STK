<?php
/**
 * Bulk STK Push Batch Processor
 * Called repeatedly by the frontend to send batches of STK pushes.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mpesa.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Mpesa.php';

set_time_limit(120);

header('Content-Type: application/json');

Auth::start();
if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 401);
}
verifyCsrf();
session_write_close(); // Release session lock before slow Daraja API calls

$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$campaignId = (int)($input['campaign_id'] ?? $_POST['campaign_id'] ?? 0);

if (!$campaignId) {
    jsonResponse(['success' => false, 'message' => 'Campaign ID required.']);
}

$campaign = Database::fetchOne("SELECT * FROM campaigns WHERE id = ?", [$campaignId]);
if (!$campaign) {
    jsonResponse(['success' => false, 'message' => 'Campaign not found.']);
}
if (!in_array($campaign['status'], ['queued', 'running', 'paused', 'draft'])) {
    jsonResponse(['success' => false, 'message' => 'Campaign is not in a sendable state.']);
}

// Batch-fetch all settings in one query (avoids 4–6 individual DB queries in Mpesa constructor)
$cfg = getSettings(
    ['batch_size', 'mpesa_callback_url', 'mpesa_env', 'max_retries',
     'mpesa_consumer_key', 'mpesa_consumer_secret', 'mpesa_shortcode', 'mpesa_passkey'],
    [
        'batch_size'            => (string)BATCH_SIZE,
        'mpesa_callback_url'    => MPESA_CALLBACK_URL,
        'mpesa_env'             => MPESA_ENV,
        'max_retries'           => (string)MAX_RETRIES,
        'mpesa_consumer_key'    => MPESA_CONSUMER_KEY,
        'mpesa_consumer_secret' => MPESA_CONSUMER_SECRET,
        'mpesa_shortcode'       => MPESA_SHORTCODE,
        'mpesa_passkey'         => MPESA_PASSKEY,
    ]
);
$batchSize  = max(1, min(10, (int)$cfg['batch_size']));
$maxRetries = (int)$cfg['max_retries'];

// Fetch next batch of pending recipients
$batch = Database::fetchAll("
    SELECT cr.*, c.name AS customer_name, c.account_number
    FROM campaign_recipients cr
    LEFT JOIN customers c ON c.id = cr.customer_id
    WHERE cr.campaign_id = ? AND cr.status = 'pending'
    ORDER BY cr.retry_count ASC, cr.id ASC
    LIMIT ?
", [$campaignId, $batchSize]);

// No pending recipients left → check if we're truly done
if (empty($batch)) {
    $remaining = Database::count(
        "SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending'",
        [$campaignId]
    );

    if ($remaining === 0) {
        // All dispatched; compute final tally
        $stats = Database::fetchOne("
            SELECT
                SUM(status='success')                           AS success_count,
                SUM(status IN ('failed','timeout','cancelled')) AS failed_count,
                COUNT(*)                                        AS total
            FROM campaign_recipients
            WHERE campaign_id = ?
        ", [$campaignId]);

        Database::update('campaigns', [
            'status'        => 'completed',
            'success_count' => (int)$stats['success_count'],
            'failed_count'  => (int)$stats['failed_count'],
            'sent_count'    => (int)$stats['total'],
            'pending_count' => 0,
            'completed_at'  => date('Y-m-d H:i:s'),
        ], 'id = ?', [$campaignId]);

        $campaign = Database::fetchOne("SELECT * FROM campaigns WHERE id = ?", [$campaignId]);
        jsonResponse(buildStatusResponse($campaign, true, []));
    }
}

// Mark batch as 'processing' so a concurrent request won't pick the same rows
$ids = array_column($batch, 'id');
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    Database::query(
        "UPDATE campaign_recipients SET status='processing' WHERE id IN ({$placeholders})",
        $ids
    );
}

// Ensure campaign is marked running
if ($campaign['status'] !== 'running') {
    Database::update('campaigns', [
        'status'     => 'running',
        'started_at' => $campaign['started_at'] ?? date('Y-m-d H:i:s'),
    ], 'id = ?', [$campaignId]);
}

// Sanity-check: callback URL must be HTTPS in production
$callbackUrl = $cfg['mpesa_callback_url'];
$env         = $cfg['mpesa_env'];
if ($env === 'production' && !str_starts_with($callbackUrl, 'https://')) {
    jsonResponse([
        'success' => false,
        'message' => 'Callback URL must be HTTPS in production mode. '
                   . 'Go to Settings → M-Pesa, run ngrok and click "Auto-Detect ngrok", then save.',
    ]);
}

// Build Mpesa instance from already-fetched credentials (no extra DB queries)
$mpesa = new Mpesa([
    'consumer_key'    => $cfg['mpesa_consumer_key'],
    'consumer_secret' => $cfg['mpesa_consumer_secret'],
    'shortcode'       => $cfg['mpesa_shortcode'],
    'passkey'         => $cfg['mpesa_passkey'],
    'callback_url'    => $callbackUrl,
    'env'             => $env,
]);

// Fetch access token once — shared across all parallel handles
$token = $mpesa->getAccessToken();
if (!$token) {
    jsonResponse(['success' => false, 'message' => 'Failed to get M-Pesa access token.']);
}

// ── Build parallel cURL handles (one per recipient) ──────────
$mh      = curl_multi_init();
$handles = [];
foreach ($batch as $i => $recipient) {
    $accountRef = $campaign['account_ref'];
    if (!empty($recipient['account_number'])) {
        $accountRef = substr($recipient['account_number'], 0, 12);
    }
    $ch = $mpesa->buildStkPushHandle(
        $recipient['phone'],
        (float)$recipient['amount'],
        $accountRef,
        $campaign['transaction_desc'],
        $token
    );
    curl_multi_add_handle($mh, $ch);
    $handles[$i] = [
        'ch'         => $ch,
        'recipient'  => $recipient,
        'accountRef' => $accountRef,
    ];
}

// ── Fire all STK pushes simultaneously ───────────────────────
$active = null;
do {
    curl_multi_exec($mh, $active);
    if ($active) curl_multi_select($mh);
} while ($active > 0);

// ── Process results ───────────────────────────────────────────
$recent           = [];
$firstError       = null;
$batchSentOk      = 0; // successfully dispatched to Daraja
$batchFailedFinal = 0; // permanently failed (exceeded max retries)

foreach ($handles as $i => $item) {
    $raw       = curl_multi_getcontent($item['ch']);
    $result    = Mpesa::parseStkPushResponse($raw);
    $recipient = $item['recipient'];
    $phone     = $recipient['phone'];
    $accountRef = $item['accountRef'];
    $desc      = $campaign['transaction_desc'];
    $amount    = (float)$recipient['amount'];

    curl_multi_remove_handle($mh, $item['ch']);
    curl_close($item['ch']);

    if ($result['success']) {
        $merchantId   = $result['merchant_request_id'];
        $checkoutId   = $result['checkout_request_id'];
        $responseCode = $result['response_code'] ?? '0';

        Database::update('campaign_recipients', [
            'status'              => 'sent',
            'merchant_request_id' => $merchantId,
            'checkout_request_id' => $checkoutId,
            'sent_at'             => date('Y-m-d H:i:s'),
        ], 'id = ?', [$recipient['id']]);

        Database::insert('transactions', [
            'campaign_id'          => $campaignId,
            'recipient_id'         => $recipient['id'],
            'customer_id'          => $recipient['customer_id'],
            'phone'                => $phone,
            'amount'               => $amount,
            'account_ref'          => $accountRef,
            'description'          => $desc,
            'merchant_request_id'  => $merchantId,
            'checkout_request_id'  => $checkoutId,
            'response_code'        => $responseCode,
            'response_description' => $result['response_description'] ?? '',
            'customer_message'     => $result['customer_message'] ?? '',
            'status'               => 'pending',
        ]);

        $recent[] = ['phone' => $phone, 'name' => $recipient['customer_name'] ?? '', 'amount' => $amount, 'status' => 'sent'];
        $batchSentOk++;

    } else {
        $errMsg     = $result['message'] ?? 'STK push failed';
        if ($firstError === null) $firstError = $errMsg;
        $retryCount = (int)$recipient['retry_count'] + 1;

        if ($retryCount <= $maxRetries) {
            Database::update('campaign_recipients', [
                'status'        => 'pending',
                'retry_count'   => $retryCount,
                'error_message' => $errMsg,
            ], 'id = ?', [$recipient['id']]);
        } else {
            Database::update('campaign_recipients', [
                'status'        => 'failed',
                'result_desc'   => $errMsg,
                'completed_at'  => date('Y-m-d H:i:s'),
            ], 'id = ?', [$recipient['id']]);

            Database::insert('transactions', [
                'campaign_id'          => $campaignId,
                'recipient_id'         => $recipient['id'],
                'customer_id'          => $recipient['customer_id'],
                'phone'                => $phone,
                'amount'               => $amount,
                'account_ref'          => $accountRef,
                'description'          => $desc,
                'status'               => 'failed',
                'response_description' => $errMsg,
            ]);
            $batchFailedFinal++;
        }

        $recent[] = ['phone' => $phone, 'name' => $recipient['customer_name'] ?? '', 'amount' => $amount, 'status' => 'failed', 'error' => $errMsg];
    }
}
curl_multi_close($mh);

// ── Atomic incremental counter update (no full table scan) ───
// "sent" recipients still count as pending until their callback arrives,
// so only permanent failures reduce pending_count at dispatch time.
if ($batchSentOk > 0 || $batchFailedFinal > 0) {
    Database::query("
        UPDATE campaigns SET
            sent_count    = sent_count + ?,
            failed_count  = failed_count + ?,
            pending_count = GREATEST(pending_count - ?, 0)
        WHERE id = ?
    ", [$batchSentOk, $batchFailedFinal, $batchFailedFinal, $campaignId]);
}

// Re-read campaign row (single PK lookup, not a table scan)
$campaign = Database::fetchOne("SELECT * FROM campaigns WHERE id = ?", [$campaignId]);
$isDone   = (int)$campaign['pending_count'] === 0;

$batchErrors = [];
foreach ($recent as $r) {
    if (!empty($r['error']) && !in_array($r['error'], $batchErrors)) {
        $batchErrors[] = $r['error'];
    }
}

jsonResponse(buildStatusResponse($campaign, $isDone, $recent, $batchErrors, $firstError));

// ─── Helper ──────────────────────────────────────────────────
function buildStatusResponse(array $c, bool $done, array $recent, array $errors = [], ?string $firstError = null): array {
    return [
        'success'       => true,
        'done'          => $done,
        'total'         => (int)$c['total_recipients'],
        'sent_count'    => (int)$c['sent_count'],
        'success_count' => (int)$c['success_count'],
        'failed_count'  => (int)$c['failed_count'],
        'pending_count' => (int)$c['pending_count'],
        'status'        => $c['status'],
        'recent'        => $recent,
        'errors'        => $errors,
        'first_error'   => $firstError,
    ];
}
