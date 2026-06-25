<?php
/**
 * CSV Preview Endpoint
 * Accepts a multipart file upload, saves it temporarily,
 * and returns headers + sample rows + auto-detected column mapping.
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

// Save to storage/imports
$storageDir = __DIR__ . '/../storage/imports';
$token      = bin2hex(random_bytes(16));
$savedPath  = $storageDir . '/tmp_' . $token . '.csv';

if (!move_uploaded_file($file['tmp_name'], $savedPath)) {
    jsonResponse(['success' => false, 'message' => 'Failed to save uploaded file.']);
}

// Parse headers + sample rows
$handle = fopen($savedPath, 'r');
if (!$handle) {
    jsonResponse(['success' => false, 'message' => 'Cannot read uploaded file.']);
}

// Strip UTF-8 BOM if present
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") rewind($handle);

$rawHeaders = fgetcsv($handle);
if (!$rawHeaders) {
    fclose($handle);
    unlink($savedPath);
    jsonResponse(['success' => false, 'message' => 'CSV has no header row.']);
}
$headers = array_map('trim', $rawHeaders);

// Read up to 10 sample rows
$sampleRows = [];
$totalRows  = 0;
while (($row = fgetcsv($handle)) !== false) {
    $totalRows++;
    if (count($sampleRows) < 10) {
        $sampleRows[] = array_map('trim', $row);
    }
}
fclose($handle);

// Auto-detect column mapping using common aliases
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
        if (in_array(strtolower($h), $alts)) {
            $autoMap[$field] = $idx;
            break;
        }
    }
}

// Count duplicates in full file (by phone)
$phoneColIdx = $autoMap['phone'] ?? null;
$duplicateCount = 0;
if ($phoneColIdx !== null) {
    $handle2 = fopen($savedPath, 'r');
    $bom2 = fread($handle2, 3);
    if ($bom2 !== "\xEF\xBB\xBF") rewind($handle2);
    fgetcsv($handle2); // skip header
    while (($row = fgetcsv($handle2)) !== false) {
        $phone = trim($row[$phoneColIdx] ?? '');
        if ($phone && Mpesa::isValidPhone($phone)) {
            $fmt = Mpesa::formatPhone($phone);
            $exists = Database::fetchOne("SELECT id FROM customers WHERE phone_formatted = ?", [$fmt]);
            if ($exists) $duplicateCount++;
        }
    }
    fclose($handle2);
}

// Store token in session so the import step can verify it
$_SESSION['import_token']     = $token;
$_SESSION['import_file']      = $savedPath;
$_SESSION['import_original_name'] = $file['name'];

jsonResponse([
    'success'        => true,
    'token'          => $token,
    'headers'        => $headers,
    'sample_rows'    => $sampleRows,
    'total_rows'     => $totalRows,
    'auto_map'       => $autoMap,
    'duplicate_count'=> $duplicateCount,
]);
