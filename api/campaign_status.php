<?php
/**
 * Campaign Status API — start, pause, get status
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

$input      = json_decode(file_get_contents('php://input'), true) ?? [];
$campaignId = (int)($input['campaign_id'] ?? $_GET['campaign_id'] ?? 0);
$action     = $input['action'] ?? $_GET['action'] ?? 'status';

if (!$campaignId) {
    jsonResponse(['success' => false, 'message' => 'Campaign ID is required.']);
}

$campaign = Database::fetchOne("SELECT * FROM campaigns WHERE id = ?", [$campaignId]);
if (!$campaign) {
    jsonResponse(['success' => false, 'message' => 'Campaign not found.']);
}

switch ($action) {

    case 'cancel_schedule':
        if ($campaign['status'] !== 'scheduled') {
            jsonResponse(['success' => false, 'message' => 'Campaign is not scheduled.']);
        }
        Database::update('campaigns', ['status' => 'draft', 'scheduled_at' => null], 'id = ?', [$campaignId]);
        logActivity(Auth::userId(), 'campaign_unschedule', 'campaigns', "Cancelled schedule for campaign ID {$campaignId}");
        // For form POST redirect (non-AJAX call)
        if (!empty($_POST)) {
            flash('success', 'Scheduled launch cancelled. Campaign reverted to draft.');
            header('Location: ' . APP_URL . '/campaigns/view.php?id=' . $campaignId);
            exit;
        }
        jsonResponse(['success' => true, 'message' => 'Schedule cancelled.', 'status' => 'draft']);
        break;

    case 'start':
        if (!in_array($campaign['status'], ['draft', 'paused', 'queued', 'scheduled'])) {
            jsonResponse(['success' => false, 'message' => 'Campaign cannot be started from status: ' . $campaign['status']]);
        }
        Database::update('campaigns', [
            'status'     => 'running',
            'started_at' => $campaign['started_at'] ?? date('Y-m-d H:i:s'),
        ], 'id = ?', [$campaignId]);
        logActivity(Auth::userId(), 'campaign_start', 'campaigns', "Started campaign ID {$campaignId}");
        if (!empty($_POST)) {
            flash('success', 'Campaign launched!');
            header('Location: ' . APP_URL . '/campaigns/view.php?id=' . $campaignId);
            exit;
        }
        jsonResponse(['success' => true, 'message' => 'Campaign started.', 'status' => 'running']);
        break;

    case 'retry':
        // Re-queue failed / timeout / cancelled recipients for another send attempt
        $retryStatuses = $input['statuses'] ?? ['failed', 'timeout', 'cancelled'];
        $retryStatuses = array_values(array_intersect($retryStatuses, ['failed', 'timeout', 'cancelled']));
        if (empty($retryStatuses)) {
            jsonResponse(['success' => false, 'message' => 'No valid statuses to retry.']);
        }

        $ph     = implode(',', array_fill(0, count($retryStatuses), '?'));
        $qArgs  = array_merge([$campaignId], $retryStatuses);
        $count  = Database::count(
            "SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status IN ({$ph})",
            $qArgs
        );
        if ($count === 0) {
            jsonResponse(['success' => false, 'message' => 'No recipients to retry.']);
        }

        Database::query("
            UPDATE campaign_recipients
               SET status              = 'pending',
                   checkout_request_id = NULL,
                   merchant_request_id = NULL,
                   result_code         = NULL,
                   result_desc         = NULL,
                   mpesa_receipt       = NULL,
                   error_message       = NULL,
                   sent_at             = NULL,
                   completed_at        = NULL,
                   retry_count         = retry_count + 1
             WHERE campaign_id = ? AND status IN ({$ph})
        ", $qArgs);

        $stats = Database::fetchOne("
            SELECT
                SUM(status IN ('sent','success','failed','timeout','cancelled')) AS sent_count,
                SUM(status = 'success')                                          AS success_count,
                SUM(status IN ('failed','timeout','cancelled'))                  AS failed_count,
                SUM(status IN ('pending','processing','sent'))                   AS pending_count
            FROM campaign_recipients WHERE campaign_id = ?
        ", [$campaignId]);

        Database::update('campaigns', [
            'status'        => 'draft',
            'sent_count'    => (int)$stats['sent_count'],
            'success_count' => (int)$stats['success_count'],
            'failed_count'  => (int)$stats['failed_count'],
            'pending_count' => (int)$stats['pending_count'],
            'completed_at'  => null,
        ], 'id = ?', [$campaignId]);

        logActivity(Auth::userId(), 'campaign_retry', 'campaigns',
            "Retried {$count} recipients (statuses: " . implode(',', $retryStatuses) . ") in campaign ID {$campaignId}");

        jsonResponse([
            'success'  => true,
            'message'  => "{$count} recipient(s) queued for retry. Click Launch to resend.",
            'retried'  => $count,
            'redirect' => APP_URL . '/campaigns/view.php?id=' . $campaignId,
        ]);
        break;

    case 'pause':
        if ($campaign['status'] !== 'running') {
            jsonResponse(['success' => false, 'message' => 'Campaign is not running.']);
        }
        Database::update('campaigns', ['status' => 'paused'], 'id = ?', [$campaignId]);
        // Mark any 'processing' recipients back to 'pending' (in case they weren't sent yet)
        Database::query(
            "UPDATE campaign_recipients SET status='pending' WHERE campaign_id=? AND status='processing'",
            [$campaignId]
        );
        logActivity(Auth::userId(), 'campaign_pause', 'campaigns', "Paused campaign ID {$campaignId}");
        jsonResponse(['success' => true, 'message' => 'Campaign paused.', 'status' => 'paused']);
        break;

    case 'set_status':
        $newStatus = $input['status'] ?? '';
        $allowed   = ['paused', 'running', 'completed'];
        if (!in_array($newStatus, $allowed)) {
            jsonResponse(['success' => false, 'message' => 'Invalid status.']);
        }
        Database::update('campaigns', ['status' => $newStatus], 'id = ?', [$campaignId]);
        jsonResponse(['success' => true, 'status' => $newStatus]);
        break;

    case 'status':
    default:
        $stats = Database::fetchOne("
            SELECT
                SUM(status IN ('sent','success','failed','timeout','cancelled')) AS sent_count,
                SUM(status = 'success')                                          AS success_count,
                SUM(status IN ('failed','timeout','cancelled'))                  AS failed_count,
                SUM(status = 'pending')                                          AS pending_count,
                SUM(status = 'sent')                                             AS awaiting_callback
            FROM campaign_recipients WHERE campaign_id = ?
        ", [$campaignId]);

        $pct = $campaign['total_recipients'] > 0
            ? round(($campaign['sent_count'] / $campaign['total_recipients']) * 100, 1)
            : 0;

        jsonResponse([
            'success'           => true,
            'status'            => $campaign['status'],
            'total'             => (int)$campaign['total_recipients'],
            'sent_count'        => (int)$campaign['sent_count'],
            'success_count'     => (int)$stats['success_count'],
            'failed_count'      => (int)$stats['failed_count'],
            'pending_count'     => (int)$campaign['pending_count'],
            'awaiting_callback' => (int)$stats['awaiting_callback'],
            'progress_pct'      => $pct,
            'done'              => $campaign['status'] === 'completed',
        ]);
        break;
}
