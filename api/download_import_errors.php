<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::start();
Auth::requireLogin();

$token     = preg_replace('/[^a-f0-9]/', '', $_GET['token'] ?? '');
$sessToken = $_SESSION['error_download_token'] ?? '';
$filePath  = $_SESSION['error_download_file']  ?? '';

if (!$token || !hash_equals($sessToken, $token) || !$filePath || !file_exists($filePath)) {
    http_response_code(404);
    die('File not found or session expired. Re-import to regenerate the error list.');
}

$filename = 'import_errors_' . date('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');

echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel opens correctly
readfile($filePath);

@unlink($filePath);
unset($_SESSION['error_download_token'], $_SESSION['error_download_file']);
exit;
