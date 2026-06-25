<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

Auth::start();
Auth::requireLogin();
verifyCsrf();

$ngrokUrl = rtrim(trim($_POST['ngrok_url'] ?? ''), '/');

if ($ngrokUrl) {
    $callbackUrl = $ngrokUrl . '/pushstk/api/callback.php';
    saveSetting('ngrok_url',          $ngrokUrl,    Auth::userId());
    saveSetting('mpesa_callback_url', $callbackUrl, Auth::userId());
    flash('success', "Callback URL updated to: {$callbackUrl}");
} else {
    flash('error', 'Please enter a valid ngrok URL.');
}

redirect(APP_URL . '/test_push.php');
