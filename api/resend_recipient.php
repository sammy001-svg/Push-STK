<?php
/**
 * Resend a single STK push to one campaign recipient.
 * Used by the "Resend" button on the campaign view page.
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
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 401);
}

$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$recipientId = (int)($input['recipient_id'] ?? 0);

if (!$recipientId) {
    jsonResponse(['success' => false, 'message' => 'Recipient ID required.']);
}

// Load recipient + campaign in one query
$recipient = Database::fetchOne("
    SELECT cr.*,
           c.amount          AS camp_amount,
           c.account_ref     AS camp_account_ref,
           c.transaction_desc AS camp_desc,
           c.status          AS camp_status
    FROM campaign_recipients cr
    JOIN campaigns c ON c.id = cr.campaign_id
    WHERE cr.id = ?
", [$recipientId]);

if (!$recipient) {
    jsonResponse(['success' => false, 'message' => 'Recipient not found.']);
}

if (!in_array($recipient['status'], ['failed', 'timeout', 'cancelled'])) {
    jsonResponse(['success' => false, 'message' => 'Recipient is not in a retryable state. Current status: ' . $recipient['status']]);
}

$maxRetries = (int)getSetting('max_retries', (string)MAX_RETRIES);
if ((int)$recipient['retry_count'] > $maxRetries) {
    jsonResponse(['success' => false, 'message' => "Max retries ({$maxRetries}) exceeded for this recipient."]);
}

// Fire the STK push
$mpesa  = new Mpesa();
$result = $mpesa->stkPush(
    $recipient['phone'],
    (float)$recipient['camp_amount'],
    $recipient['camp_account_ref'],
    $recipient['camp_desc']
);

if ($result['success']) {
    $merchantId = $result['merchant_request_id'];
    $checkoutId = $result['checkout_request_id'];

    Database::update('campaign_recipients', [
        'status'              => 'sent',
        'merchant_request_id' => $merchantId,
        'checkout_request_id' => $checkoutId,
        'result_code'         => null,
        'result_desc'         => null,
        'mpesa_receipt'       => null,
        'error_message'       => null,
        'retry_count'         => (int)$recipient['retry_count'] + 1,
        'sent_at'             => date('Y-m-d H:i:s'),
        'completed_at'        => null,
    ], 'id = ?', [$recipientId]);

    Database::insert('transactions', [
        'campaign_id'          => $recipient['campaign_id'],
        'recipient_id'         => $recipientId,
        'customer_id'          => $recipient['customer_id'],
        'phone'                => $recipient['phone'],
        'amount'               => $recipient['camp_amount'],
        'account_ref'          => $recipient['camp_account_ref'],
        'description'          => $recipient['camp_desc'],
        'merchant_request_id'  => $merchantId,
        'checkout_request_id'  => $checkoutId,
        'response_code'        => $result['response_code']        ?? '0',
        'response_description' => $result['response_description'] ?? '',
        'customer_message'     => $result['customer_message']     ?? '',
        'status'               => 'pending',
    ]);

    // If campaign was completed, re-open it as running (1 push just went out)
    if ($recipient['camp_status'] === 'completed') {
        Database::update('campaigns', [
            'status'      => 'running',
            'completed_at' => null,
        ], 'id = ?', [$recipient['campaign_id']]);
    }

    logActivity(Auth::userId(), 'recipient_resend', 'campaigns',
        "Resent STK to recipient #{$recipientId} ({$recipient['phone']}) in campaign {$recipient['campaign_id']}");

    jsonResponse([
        'success' => true,
        'message' => 'STK prompt sent. Waiting for customer response.',
        'status'  => 'sent',
    ]);

} else {
    jsonResponse([
        'success' => false,
        'message' => $result['message'] ?? 'Failed to send STK push.',
    ]);
}
