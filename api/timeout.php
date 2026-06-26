<?php
/**
 * M-Pesa STK Push Timeout URL Handler
 * Called by Safaricom when their system times out processing the transaction.
 * (Distinct from the user not responding — that comes through callback.php as ResultCode 1032/1037.)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$rawInput = file_get_contents('php://input');
error_log('[M-Pesa Timeout] ' . $rawInput);

$data       = json_decode($rawInput, true) ?? [];
$checkoutId = $data['Body']['stkCallback']['CheckoutRequestID'] ?? null;

if ($checkoutId) {
    try {
        Database::query(
            "UPDATE transactions SET status='failed', completed_at=NOW()
              WHERE checkout_request_id=? AND status IN ('initiated','pending')",
            [$checkoutId]
        );
        Database::query(
            "UPDATE campaign_recipients SET status='failed', result_desc='STK push timed out — use Recovery Centre to retry', completed_at=NOW()
              WHERE checkout_request_id=? AND status IN ('sent','processing')",
            [$checkoutId]
        );

        // Recalculate campaign counters
        $recipient = Database::fetchOne(
            "SELECT campaign_id FROM campaign_recipients WHERE checkout_request_id = ? LIMIT 1",
            [$checkoutId]
        );
        if ($recipient && $recipient['campaign_id']) {
            $cid   = (int)$recipient['campaign_id'];
            $stats = Database::fetchOne("
                SELECT
                    SUM(status IN ('sent','success','failed','timeout','cancelled')) AS sent_count,
                    SUM(status = 'success')                                          AS success_count,
                    SUM(status IN ('failed','timeout','cancelled'))                  AS failed_count,
                    SUM(status IN ('pending','processing','sent'))                   AS pending_count
                FROM campaign_recipients WHERE campaign_id = ?
            ", [$cid]);

            $upd = [
                'sent_count'    => (int)$stats['sent_count'],
                'success_count' => (int)$stats['success_count'],
                'failed_count'  => (int)$stats['failed_count'],
                'pending_count' => (int)$stats['pending_count'],
            ];

            $campaign = Database::fetchOne("SELECT status FROM campaigns WHERE id = ?", [$cid]);
            if ($campaign && (int)$stats['pending_count'] === 0
                          && in_array($campaign['status'], ['running', 'paused'])) {
                $upd['status']       = 'completed';
                $upd['completed_at'] = date('Y-m-d H:i:s');
            }

            Database::update('campaigns', $upd, 'id = ?', [$cid]);
        }

    } catch (Throwable $e) {
        error_log('[M-Pesa Timeout Error] ' . $e->getMessage());
    }
}

http_response_code(200);
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accept service request successfully.']);
