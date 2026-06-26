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

set_time_limit(120); // M-Pesa calls can be slow; default 30s is too tight for a full batch

header('Content-Type: application/json');

Auth::start();
if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 401);
}
session_write_close(); // Release session lock before slow Daraja API calls

$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$campaignId = (int)($input['campaign_id'] ?? $_POST['campaign_id'] ?? 0);

if (!$campaignId) {
    jsonResponse(['success' => false, 'message' => 'Campaign ID required.']);
}

// Load campaign
$campaign = Database::fetchOne("SELECT * FROM campaigns WHERE id = ?", [$campaignId]);
if (!$campaign) {
    jsonResponse(['success' => false, 'message' => 'Campaign not found.']);
}
if (!in_array($campaign['status'], ['queued', 'running', 'paused', 'draft'])) {
    jsonResponse(['success' => false, 'message' => 'Campaign is not in a sendable state.']);
}

// Load all needed settings in one query instead of one per key
$cfg = getSettings(
    ['batch_size', 'mpesa_callback_url', 'mpesa_env', 'max_retries'],
    [
        'batch_size'         => (string)BATCH_SIZE,
        'mpesa_callback_url' => MPESA_CALLBACK_URL,
        'mpesa_env'          => MPESA_ENV,
        'max_retries'        => (string)MAX_RETRIES,
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

// If no pending recipients left → mark complete
if (empty($batch)) {
    $remaining = Database::count(
        "SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'pending'",
        [$campaignId]
    );

    if ($remaining === 0) {
        // Final tally
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

// Mark batch as 'processing'
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

// Quick sanity-check: if callback URL is still HTTP in production, fail fast
$callbackUrl = $cfg['mpesa_callback_url'];
$env         = $cfg['mpesa_env'];
if ($env === 'production' && !str_starts_with($callbackUrl, 'https://')) {
    jsonResponse([
        'success' => false,
        'message' => 'Callback URL must be HTTPS in production mode. '
                   . 'Go to Settings → M-Pesa, run ngrok and click "Auto-Detect ngrok", then save.',
    ]);
}

// Send STK pushes
$mpesa  = new Mpesa();
$recent = [];
$firstError = null; // Surface the first Daraja error prominently

foreach ($batch as $recipient) {
    $phone      = $recipient['phone'];
    $amount     = (float)$recipient['amount'];
    $accountRef = $campaign['account_ref'];
    $desc       = $campaign['transaction_desc'];

    // Use customer's account_number if available
    if (!empty($recipient['account_number'])) {
        $accountRef = substr($recipient['account_number'], 0, 12);
    }

    $result = $mpesa->stkPush($phone, $amount, $accountRef, $desc);

    if ($result['success']) {
        $merchantId  = $result['merchant_request_id'];
        $checkoutId  = $result['checkout_request_id'];
        $responseCode = $result['response_code'] ?? '0';

        // Update recipient
        Database::update('campaign_recipients', [
            'status'              => 'sent',
            'merchant_request_id' => $merchantId,
            'checkout_request_id' => $checkoutId,
            'sent_at'             => date('Y-m-d H:i:s'),
        ], 'id = ?', [$recipient['id']]);

        // Log transaction
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

        $recent[] = [
            'phone'   => $phone,
            'name'    => $recipient['customer_name'] ?? '',
            'amount'  => $amount,
            'status'  => 'sent',
        ];

    } else {
        $errMsg     = $result['message'] ?? 'STK push failed';
        if ($firstError === null) $firstError = $errMsg;
        $retryCount = (int)$recipient['retry_count'] + 1;

        if ($retryCount <= $maxRetries) {
            // Put back as pending for retry
            Database::update('campaign_recipients', [
                'status'        => 'pending',
                'retry_count'   => $retryCount,
                'error_message' => $errMsg,
            ], 'id = ?', [$recipient['id']]);
        } else {
            // Mark permanently failed
            Database::update('campaign_recipients', [
                'status'        => 'failed',
                'result_desc'   => $errMsg,
                'completed_at'  => date('Y-m-d H:i:s'),
            ], 'id = ?', [$recipient['id']]);

            Database::insert('transactions', [
                'campaign_id'  => $campaignId,
                'recipient_id' => $recipient['id'],
                'customer_id'  => $recipient['customer_id'],
                'phone'        => $phone,
                'amount'       => $amount,
                'account_ref'  => $accountRef,
                'description'  => $desc,
                'status'       => 'failed',
                'response_description' => $errMsg,
            ]);
        }

        $recent[] = [
            'phone'   => $phone,
            'name'    => $recipient['customer_name'] ?? '',
            'amount'  => $amount,
            'status'  => 'failed',
            'error'   => $errMsg,
        ];
    }
}

// Update campaign aggregate counters
$stats = Database::fetchOne("
    SELECT
        SUM(status IN ('sent','success','failed','timeout','cancelled')) AS sent_count,
        SUM(status='success')                                            AS success_count,
        SUM(status IN ('failed','timeout','cancelled'))                  AS failed_count,
        SUM(status='pending')                                            AS pending_count
    FROM campaign_recipients
    WHERE campaign_id = ?
", [$campaignId]);

Database::update('campaigns', [
    'sent_count'    => (int)$stats['sent_count'],
    'success_count' => (int)$stats['success_count'],
    'failed_count'  => (int)$stats['failed_count'],
    'pending_count' => (int)$stats['pending_count'],
], 'id = ?', [$campaignId]);

// Reload for fresh data
$campaign = Database::fetchOne("SELECT * FROM campaigns WHERE id = ?", [$campaignId]);
$isDone   = (int)$campaign['pending_count'] === 0;

// Collect unique error messages from this batch to surface to UI
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
