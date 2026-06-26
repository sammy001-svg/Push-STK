<?php
/**
 * CSV Preview Endpoint
 * Accepts a multipart file upload, saves it temporarily,
 * and returns headers + sample rows + auto-detected column mapping.
 * Duplicate check uses a single batch query instead of N per-row queries.
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/Mpesa.php';

header('Content-Type: application/json');
Auth::start();

if (!Auth::isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized.'], 401);
}

if (empty($_FILES['csv_file']['tmp_name'])) {
    jsonResponse(['success' => false, 'message' => 'No file received.']);
}

$file    = $_FILES['csv_file'];
$ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$maxSize = 5 * 1024 * 1024;

if (!in_array($ext, ['csv', 'txt'])) {
    jsonResponse(['success' => false, 'message' => 'Only CSV files are allowed.']);
}
if ($file['size'] > $maxSize) {
    jsonResponse(['success' => false, 'message' => 'File must not exceed 5MB.']);
}

$storageDir = __DIR__ . '/../storage/imports';
$token      = bin2hex(random_bytes(16));
$savedPath  = $storageDir . '/tmp_' . $token . '.csv';

if (!move_uploaded_file($file['tmp_name'], $savedPath)) {
    jsonResponse(['success' => false, 'message' => 'Failed to save uploaded file.']);
}

// ── Read headers ─────────────────────────────────────────────
$handle = fopen($savedPath, 'r');
if (!$handle) {
    unlink($savedPath);
    jsonResponse(['success' => false, 'message' => 'Cannot read uploaded file.']);
}

$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($handle);

$rawHeaders = fgetcsv($handle);
if (!$rawHeaders) {
    fclose($handle);
    unlink($savedPath);
    jsonResponse(['success' => false, 'message' => 'CSV has no header row.']);
}
$headers = array_map('trim', $rawHeaders);

// ── Auto-detect column mapping ────────────────────────────────
$aliases = [
    'name'           => ['name', 'full name', 'fullname', 'customer name', 'customer_name', 'names'],
    'phone'          => ['phone', 'phone number', 'phone_number', 'phoneno', 'mobile', 'cell', 'msisdn', 'telephone', 'tel', 'contact'],
    'email'          => ['email', 'email address', 'e-mail', 'emailaddress', 'mail'],
    'account_number' => ['account_number', 'account number', 'account', 'acc', 'acc_no', 'account no', 'loan_no', 'loanno', 'ref', 'reference'],
    'group'          => ['group', 'group_name', 'groupname', 'category', 'segment', 'tier'],
];
$autoMap = [];
foreach ($aliases as $field => $alts) {
    foreach ($headers as $idx => $h) {
        if (in_array(strtolower($h), $alts)) { $autoMap[$field] = $idx; break; }
    }
}
$phoneColIdx = $autoMap['phone'] ?? null;

// ── Single pass: collect sample rows + all formatted phones ───
$sampleRows  = [];
$allPhones   = [];  // formatted, valid phones from every row
$invalidRows = 0;   // rows whose phone is absent or malformed
$totalRows   = 0;

while (($row = fgetcsv($handle)) !== false) {
    $totalRows++;
    $trimmed = array_map('trim', $row);
    if (count($sampleRows) < 10) $sampleRows[] = $trimmed;

    if ($phoneColIdx !== null) {
        $phone = $trimmed[$phoneColIdx] ?? '';
        if ($phone && Mpesa::isValidPhone($phone)) {
            $allPhones[] = Mpesa::formatPhone($phone);
        } else {
            $invalidRows++;
        }
    }
}
fclose($handle);

// ── Batch duplicate check (one query, not N) ──────────────────
$duplicateSet   = [];
$duplicateCount = 0;
if (!empty($allPhones)) {
    $unique       = array_values(array_unique($allPhones));
    $placeholders = implode(',', array_fill(0, count($unique), '?'));
    $dbRows       = Database::fetchAll(
        "SELECT phone_formatted FROM customers WHERE phone_formatted IN ({$placeholders})",
        $unique
    );
    foreach ($dbRows as $r) $duplicateSet[$r['phone_formatted']] = true;
    foreach ($allPhones as $p) {
        if (isset($duplicateSet[$p])) $duplicateCount++;
    }
}

$validNewCount = max(0, count($allPhones) - $duplicateCount);

// ── Annotate sample rows for preview colour-coding ────────────
$annotated = [];
foreach ($sampleRows as $row) {
    $entry  = $row;
    $status = 'unknown';
    $hint   = '';

    if ($phoneColIdx !== null) {
        $phone = $row[$phoneColIdx] ?? '';
        if (!$phone || !Mpesa::isValidPhone($phone)) {
            $status = 'invalid';
            $hint   = $phone ? 'Bad phone: ' . $phone : 'Missing phone';
        } elseif (isset($duplicateSet[Mpesa::formatPhone($phone)])) {
            $status = 'duplicate';
            $hint   = 'Already in system';
        } else {
            $status = 'valid';
        }
    }
    $entry['_status'] = $status;
    $entry['_hint']   = $hint;
    $annotated[] = $entry;
}

// ── Store session ─────────────────────────────────────────────
$_SESSION['import_token']         = $token;
$_SESSION['import_file']          = $savedPath;
$_SESSION['import_original_name'] = $file['name'];

jsonResponse([
    'success'         => true,
    'token'           => $token,
    'headers'         => $headers,
    'sample_rows'     => $annotated,
    'total_rows'      => $totalRows,
    'auto_map'        => $autoMap,
    'duplicate_count' => $duplicateCount,
    'valid_new_count' => $validNewCount,
    'invalid_count'   => $invalidRows,
]);
