<?php
/**
 * Scheduled Campaign Launcher + Stale Transaction Cleanup
 * Called: JS heartbeat (every 60s on any open page).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/mpesa.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

// Only process when a logged-in user's page heartbeat triggers this.
// Unauthenticated requests (bots, scanners) get an empty response.
Auth::start();
if (!Auth::isLoggedIn()) {
    echo json_encode(['launched' => [], 'checked_at' => date('Y-m-d H:i:s')]);
    exit;
}

// ── 1. Launch due scheduled campaigns ───────────────────────
$launched = [];
$due = Database::fetchAll("
    SELECT id, name, scheduled_at
    FROM campaigns
    WHERE status = 'scheduled'
      AND scheduled_at IS NOT NULL
      AND scheduled_at <= NOW()
    LIMIT 20
");

foreach ($due as $row) {
    Database::query(
        "UPDATE campaigns SET status = 'queued', started_at = NOW() WHERE id = ? AND status = 'scheduled'",
        [$row['id']]
    );
    logActivity(null, 'campaign_auto_launch', 'campaigns',
        "Scheduled campaign #{$row['id']} '{$row['name']}' auto-queued at " . date('Y-m-d H:i:s'));
    $launched[] = ['id' => $row['id'], 'name' => $row['name']];
}

// ── 2. Recover stuck 'processing' recipients ─────────────────
// Recipients left in 'processing' from an interrupted PHP request — reset to pending so they get resent.
$recoveredProcessing = 0;
$stuckProcessing = Database::fetchAll("
    SELECT id, campaign_id
    FROM campaign_recipients
    WHERE status = 'processing'
      AND sent_at IS NULL
      AND updated_at < NOW() - INTERVAL 120 SECOND
    LIMIT 200
");
if ($stuckProcessing) {
    $stuckIds = array_column($stuckProcessing, 'id');
    $phP      = implode(',', array_fill(0, count($stuckIds), '?'));
    Database::query(
        "UPDATE campaign_recipients SET status='pending' WHERE id IN ({$phP})",
        $stuckIds
    );
    $recoveredProcessing = count($stuckIds);
}

// ── 3. Mark stale 'sent' recipients as failed ────────────────
// Recipients dispatched to M-Pesa but callback never arrived after STK_TIMEOUT seconds.
// Mark as 'failed' (not 'timeout') so Recovery Centre can retry them.
$stkTimeout = (int)getSetting('stk_timeout', (string)STK_TIMEOUT);
$timedOut   = 0;

$stale = Database::fetchAll("
    SELECT cr.id, cr.campaign_id, cr.checkout_request_id
    FROM campaign_recipients cr
    WHERE cr.status = 'sent'
      AND cr.sent_at IS NOT NULL
      AND cr.sent_at < NOW() - INTERVAL ? SECOND
    LIMIT 200
", [$stkTimeout]);

if ($stale) {
    $staleIds = array_column($stale, 'id');
    $ph       = implode(',', array_fill(0, count($staleIds), '?'));
    Database::query(
        "UPDATE campaign_recipients
            SET status='failed', result_desc='No callback received — use Recovery Centre to retry', completed_at=NOW()
          WHERE id IN ({$ph})",
        $staleIds
    );

    // Mark matching transactions as failed too
    $checkoutIds = array_filter(array_column($stale, 'checkout_request_id'));
    if ($checkoutIds) {
        $ph2 = implode(',', array_fill(0, count($checkoutIds), '?'));
        Database::query(
            "UPDATE transactions SET status='failed', completed_at=NOW()
              WHERE checkout_request_id IN ({$ph2}) AND status IN ('initiated','pending')",
            $checkoutIds
        );
    }

    // Recalculate counters for affected campaigns
    $campaignIds = array_unique(array_column($stale, 'campaign_id'));
    foreach ($campaignIds as $cid) {
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

    $timedOut = count($staleIds);
}

echo json_encode([
    'launched'             => $launched,
    'recovered_processing' => $recoveredProcessing,
    'timed_out'            => $timedOut,
    'checked_at'           => date('Y-m-d H:i:s'),
]);
