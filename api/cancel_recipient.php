<?php
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

$input       = json_decode(file_get_contents('php://input'), true) ?? [];
$recipientId = (int)($input['recipient_id'] ?? 0);

if (!$recipientId) {
    jsonResponse(['success' => false, 'message' => 'Recipient ID required.']);
}

$recipient = Database::fetchOne(
    "SELECT id, status, campaign_id FROM campaign_recipients WHERE id = ?",
    [$recipientId]
);

if (!$recipient) {
    jsonResponse(['success' => false, 'message' => 'Recipient not found.']);
}

// Race-condition safe: only cancels if still pending
$stmt = Database::query(
    "UPDATE campaign_recipients SET status='cancelled', completed_at=NOW() WHERE id=? AND status='pending'",
    [$recipientId]
);

if ($stmt->rowCount() === 0) {
    jsonResponse(['success' => false, 'message' => 'Recipient is no longer pending. Please refresh.']);
}

Database::query(
    "UPDATE campaigns SET
        pending_count   = GREATEST(pending_count - 1, 0),
        cancelled_count = cancelled_count + 1
     WHERE id = ?",
    [$recipient['campaign_id']]
);

logActivity(Auth::userId(), 'recipient_cancel', 'campaigns',
    "Cancelled pending recipient #{$recipientId} in campaign {$recipient['campaign_id']}");

jsonResponse(['success' => true, 'message' => 'Recipient cancelled.']);
