<?php
/**
 * Auto-detect the running ngrok tunnel URL
 * by querying the local ngrok agent API at http://127.0.0.1:4040
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

// Hit the ngrok local API
$ctx = stream_context_create(['http' => [
    'timeout'         => 3,
    'ignore_errors'   => true,
]]);

$raw = @file_get_contents('http://127.0.0.1:4040/api/tunnels', false, $ctx);

if (!$raw) {
    jsonResponse(['success' => false, 'message' => 'ngrok is not running. Start it first: C:\\ngrok\\ngrok.exe http 80']);
}

$data    = json_decode($raw, true);
$tunnels = $data['tunnels'] ?? [];

if (empty($tunnels)) {
    jsonResponse(['success' => false, 'message' => 'No active tunnels found. Start ngrok with: C:\\ngrok\\ngrok.exe http 80']);
}

// Prefer HTTPS tunnel
$url = null;
foreach ($tunnels as $t) {
    if (strpos($t['public_url'] ?? '', 'https://') === 0) {
        $url = rtrim($t['public_url'], '/');
        break;
    }
}
if (!$url) {
    $url = rtrim($tunnels[0]['public_url'] ?? '', '/');
}

if (!$url) {
    jsonResponse(['success' => false, 'message' => 'Could not find a public URL in ngrok tunnels.']);
}

// Save to settings
$callbackUrl = $url . '/pushstk/api/callback.php';
saveSetting('ngrok_url',          $url,         Auth::userId());
saveSetting('mpesa_callback_url', $callbackUrl, Auth::userId());

logActivity(Auth::userId(), 'ngrok_detect', 'test', "Auto-detected ngrok URL: {$url}");

jsonResponse([
    'success'      => true,
    'ngrok_url'    => $url,
    'callback_url' => $callbackUrl,
]);
