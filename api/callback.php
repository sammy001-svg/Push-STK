<?php
/**
 * M-Pesa STK Push Callback Handler
 * Safaricom POSTs the payment result here when the user responds.
 * Must return HTTP 200 with {"ResultCode":0} — Safaricom retries on failure.
 */

error_reporting(E_ALL);
ini_set('log_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$rawInput = file_get_contents('php://input');
error_log('[M-Pesa Callback] Received: ' . $rawInput);

function ack(): void {
    http_response_code(200);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accept service request successfully.']);
    exit;
}

$callbackData = json_decode($rawInput, true);
if (empty($callbackData) || json_last_error() !== JSON_ERROR_NONE) {
    error_log('[M-Pesa Callback] Invalid JSON');
    ack();
}

try {
    $body          = $callbackData['Body']['stkCallback'] ?? [];
    $checkoutReqId = $body['CheckoutRequestID'] ?? null;
    $resultCode    = (int)($body['ResultCode']  ?? -1);
    $resultDesc    = $body['ResultDesc']         ?? '';

    if (!$checkoutReqId) ack();

    // Extract metadata (only present on success, ResultCode=0)
    $meta = [];
    foreach ($body['CallbackMetadata']['Item'] ?? [] as $item) {
        $meta[$item['Name']] = $item['Value'] ?? null;
    }

    $mpesaReceipt = $meta['MpesaReceiptNumber'] ?? null;
    $transDate    = isset($meta['TransactionDate']) ? (string)$meta['TransactionDate'] : null;

    // Map ResultCode → status
    // 0=success, 1032=user cancelled, all others (incl. 1037 unreachable) → failed so they can be retried
    $status = match (true) {
        $resultCode === 0    => 'success',
        $resultCode === 1032 => 'cancelled',
        default              => 'failed',
    };

    // ── Update transactions ───────────────────────────────────
    Database::query("
        UPDATE transactions
           SET result_code        = ?,
               result_description = ?,
               mpesa_receipt      = COALESCE(?, mpesa_receipt),
               transaction_date   = COALESCE(?, transaction_date),
               status             = ?,
               raw_callback       = ?,
               completed_at       = NOW()
         WHERE checkout_request_id = ?
           AND status IN ('initiated','pending')
    ", [
        $resultCode, $resultDesc,
        $mpesaReceipt, $transDate,
        $status, $rawInput,
        $checkoutReqId,
    ]);

    // ── Update campaign_recipients ────────────────────────────
    Database::query("
        UPDATE campaign_recipients
           SET status        = ?,
               result_code   = ?,
               result_desc   = ?,
               mpesa_receipt = COALESCE(?, mpesa_receipt),
               completed_at  = NOW()
         WHERE checkout_request_id = ?
           AND status IN ('sent','processing')
    ", [
        $status, $resultCode, $resultDesc,
        $mpesaReceipt,
        $checkoutReqId,
    ]);

    // ── Update campaign aggregate counters ────────────────────
    $recipient = Database::fetchOne(
        "SELECT campaign_id FROM campaign_recipients WHERE checkout_request_id = ? LIMIT 1",
        [$checkoutReqId]
    );

    // Fallback: look up via transactions table
    if (!$recipient) {
        $tx = Database::fetchOne(
            "SELECT campaign_id FROM transactions WHERE checkout_request_id = ? LIMIT 1",
            [$checkoutReqId]
        );
        if ($tx) $recipient = $tx;
    }

    if ($recipient && $recipient['campaign_id']) {
        $cid   = (int)$recipient['campaign_id'];
        $stats = Database::fetchOne("
            SELECT
                SUM(status IN ('sent','success','failed','timeout','cancelled')) AS sent_count,
                SUM(status = 'success')                                          AS success_count,
                SUM(status IN ('failed','timeout','cancelled'))                  AS failed_count,
                SUM(status IN ('pending','processing','sent'))                   AS pending_count,
                SUM(status = 'cancelled')                                        AS cancelled_count
            FROM campaign_recipients WHERE campaign_id = ?
        ", [$cid]);

        $campaignUpdate = [
            'sent_count'      => (int)$stats['sent_count'],
            'success_count'   => (int)$stats['success_count'],
            'failed_count'    => (int)$stats['failed_count'],
            'pending_count'   => (int)$stats['pending_count'],
            'cancelled_count' => (int)$stats['cancelled_count'],
        ];

        // Auto-complete campaign when nothing is pending/processing/sent
        $campaign = Database::fetchOne("SELECT status FROM campaigns WHERE id = ?", [$cid]);
        if ($campaign && (int)$stats['pending_count'] === 0
                      && in_array($campaign['status'], ['running', 'paused'])) {
            $campaignUpdate['status']       = 'completed';
            $campaignUpdate['completed_at'] = date('Y-m-d H:i:s');
        }

        Database::update('campaigns', $campaignUpdate, 'id = ?', [$cid]);
    }

    error_log("[M-Pesa Callback] Processed: checkout={$checkoutReqId} status={$status} receipt={$mpesaReceipt}");

} catch (Throwable $e) {
    error_log('[M-Pesa Callback Error] ' . $e->getMessage() . ' ' . $e->getTraceAsString());
}

ack();
