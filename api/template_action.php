<?php
/**
 * Campaign Template CRUD
 * POST: { action: 'save'|'update'|'delete', ...fields }
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

// ── Shared field sanitiser ────────────────────────────────────
function extractFields(array $input): array|false {
    $name  = trim($input['name'] ?? '');
    $desc  = trim($input['description'] ?? '');
    $amt   = trim($input['amount'] ?? '');
    $ref   = trim($input['account_ref'] ?? '');
    $tdesc = trim($input['transaction_desc'] ?? 'Payment');

    if (!$name)  return false;
    if (!is_numeric($amt) || (float)$amt < 1) return false;
    if (!$ref || strlen($ref) > 12) return false;
    if (strlen($tdesc) > 13) $tdesc = substr($tdesc, 0, 13);

    return [
        'name'             => mb_substr($name, 0, 200),
        'description'      => $desc ?: null,
        'amount'           => (float)$amt,
        'account_ref'      => $ref,
        'transaction_desc' => $tdesc,
    ];
}

// ── Save new template ─────────────────────────────────────────
if ($action === 'save') {
    $fields = extractFields($input);
    if (!$fields) {
        jsonResponse(['success' => false, 'message' => 'Invalid template data.']);
    }
    $fields['created_by'] = Auth::userId();
    $id = Database::insert('campaign_templates', $fields);
    logActivity(Auth::userId(), 'template_save', 'campaigns',
        "Saved campaign template '{$fields['name']}'");
    jsonResponse(['success' => true, 'id' => $id, 'message' => "Template '{$fields['name']}' saved."]);
}

// ── Update existing template ──────────────────────────────────
if ($action === 'update') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonResponse(['success' => false, 'message' => 'Template ID required.']);

    $tpl = Database::fetchOne("SELECT id FROM campaign_templates WHERE id = ?", [$id]);
    if (!$tpl) jsonResponse(['success' => false, 'message' => 'Template not found.']);

    $fields = extractFields($input);
    if (!$fields) jsonResponse(['success' => false, 'message' => 'Invalid template data.']);

    Database::update('campaign_templates', $fields, 'id = ?', [$id]);
    jsonResponse(['success' => true, 'message' => "Template updated."]);
}

// ── Delete template ───────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    if (!$id) jsonResponse(['success' => false, 'message' => 'Template ID required.']);

    $tpl = Database::fetchOne("SELECT name FROM campaign_templates WHERE id = ?", [$id]);
    if (!$tpl) jsonResponse(['success' => false, 'message' => 'Template not found.']);

    Database::query("DELETE FROM campaign_templates WHERE id = ?", [$id]);
    logActivity(Auth::userId(), 'template_delete', 'campaigns',
        "Deleted campaign template '{$tpl['name']}'");
    jsonResponse(['success' => true, 'message' => "Template deleted."]);
}

jsonResponse(['success' => false, 'message' => 'Unknown action.']);
