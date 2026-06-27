<?php
/**
 * Bulk Recipient Action
 * POST: { action: 'cancel'|'resend', ids: [1,2,3,...] }
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');
Auth::start();
if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 401);
}
verifyCsrf();

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';
$raw    = array_filter(array_map('intval', (array)($input['ids'] ?? [])));
$ids    = array_values(array_unique($raw));

if (!$ids || !in_array($action, ['cancel', 'resend'], true)) {
    jsonResponse(['success' => false, 'message' => 'Invalid request.']);
}
if (count($ids) > 200) {
    jsonResponse(['success' => false, 'message' => 'Select at most 200 recipients at once.']);
}

$ph = implode(',', array_fill(0, count($ids), '?'));

// ── Bulk Cancel ───────────────────────────────────────────────
if ($action === 'cancel') {
    // Fetch only the pending ones so we know their campaign_ids
    $rows = Database::fetchAll(
        "SELECT id, campaign_id FROM campaign_recipients WHERE id IN ({$ph}) AND status='pending'",
        $ids
    );

    if (empty($rows)) {
        jsonResponse(['success' => false, 'message' => 'None of the selected recipients are pending.']);
    }

    $pendingIds = array_column($rows, 'id');
    $ph2 = implode(',', array_fill(0, count($pendingIds), '?'));

    Database::query(
        "UPDATE campaign_recipients SET status='cancelled', completed_at=NOW() WHERE id IN ({$ph2})",
        $pendingIds
    );

    // Atomic incremental counter update per campaign
    $perCampaign = [];
    foreach ($rows as $r) {
        $perCampaign[$r['campaign_id']] = ($perCampaign[$r['campaign_id']] ?? 0) + 1;
    }
    foreach ($perCampaign as $cid => $count) {
        Database::query(
            "UPDATE campaigns SET
                pending_count   = GREATEST(pending_count - ?, 0),
                cancelled_count = cancelled_count + ?
             WHERE id = ?",
            [$count, $count, $cid]
        );
    }

    $n = count($pendingIds);
    logActivity(Auth::userId(), 'bulk_recipient_cancel', 'campaigns',
        "Bulk cancelled {$n} recipients");

    jsonResponse(['success' => true, 'cancelled' => $n,
        'message' => "{$n} recipient(s) cancelled."]);
}

// ── Bulk Resend (re-queue to pending) ─────────────────────────
if ($action === 'resend') {
    $rows = Database::fetchAll(
        "SELECT id, campaign_id FROM campaign_recipients
          WHERE id IN ({$ph}) AND status IN ('failed','timeout','cancelled')",
        $ids
    );

    if (empty($rows)) {
        jsonResponse(['success' => false, 'message' => 'None of the selected recipients can be re-queued.']);
    }

    $resendIds = array_column($rows, 'id');
    $ph2 = implode(',', array_fill(0, count($resendIds), '?'));

    Database::query(
        "UPDATE campaign_recipients
            SET status='pending', retry_count = retry_count + 1,
                error_message = NULL, result_desc = NULL, completed_at = NULL
          WHERE id IN ({$ph2})",
        $resendIds
    );

    // Atomic counter update per campaign + re-open completed campaigns
    $perCampaign = [];
    foreach ($rows as $r) {
        $perCampaign[$r['campaign_id']] = ($perCampaign[$r['campaign_id']] ?? 0) + 1;
    }
    foreach ($perCampaign as $cid => $count) {
        Database::query(
            "UPDATE campaigns SET
                pending_count = pending_count + ?,
                failed_count  = GREATEST(failed_count - ?, 0)
             WHERE id = ?",
            [$count, $count, $cid]
        );
        // Re-open completed campaigns so the user can launch them again
        Database::query(
            "UPDATE campaigns SET status='paused' WHERE id = ? AND status = 'completed'",
            [$cid]
        );
    }

    $n = count($resendIds);
    logActivity(Auth::userId(), 'bulk_recipient_resend', 'campaigns',
        "Bulk re-queued {$n} recipients for resend");

    jsonResponse([
        'success' => true,
        'queued'  => $n,
        'message' => "{$n} recipient(s) re-queued. Launch the campaign to send them.",
    ]);
}
