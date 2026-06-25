<?php
/**
 * Core configuration.
 * On the server: copy config/env.example.php → config/env.php and fill in your values.
 * env.php is loaded first so it can override any default below.
 */
if (file_exists(__DIR__ . '/env.php')) {
    require_once __DIR__ . '/env.php';
}

// ── Database ─────────────────────────────────────────────────
if (!defined('DB_HOST'))    define('DB_HOST',    'localhost');
if (!defined('DB_NAME'))    define('DB_NAME',    'mpesa_bulk_stk');
if (!defined('DB_USER'))    define('DB_USER',    'root');
if (!defined('DB_PASS'))    define('DB_PASS',    '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// ── App identity ─────────────────────────────────────────────
if (!defined('APP_NAME'))    define('APP_NAME',    'BulkSTK Pro');
if (!defined('APP_VERSION')) define('APP_VERSION', '1.0.0');

// ── App URL (auto-detects HTTPS; override via APP_BASE_PATH in env.php) ──
if (!defined('APP_URL')) {
    $__scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $__host     = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $__basePath = defined('APP_BASE_PATH') ? APP_BASE_PATH : '/pushstk';
    define('APP_URL', $__scheme . '://' . $__host . rtrim($__basePath, '/'));
    unset($__scheme, $__host, $__basePath);
}

// ── Session & timezone ───────────────────────────────────────
if (!defined('SESSION_TIMEOUT')) define('SESSION_TIMEOUT', 7200);
if (!defined('TIMEZONE'))        define('TIMEZONE', 'Africa/Nairobi');
date_default_timezone_set(TIMEZONE);
