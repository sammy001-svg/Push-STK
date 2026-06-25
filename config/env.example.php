<?php
/**
 * Environment Configuration Template
 * ────────────────────────────────────
 * 1. Copy this file to  config/env.php
 * 2. Fill in the values below for your server
 * 3. NEVER commit config/env.php  — add it to .gitignore
 *
 * On cPanel:
 *   - Create a MySQL database and user via cPanel → MySQL Databases
 *   - DB_NAME and DB_USER are usually prefixed with your cPanel username
 *     e.g.  sammy_pushstk  /  sammy_dbuser
 */

// ── Database ─────────────────────────────────────────────────
define('DB_HOST', 'localhost');
define('DB_NAME', 'cpanel_username_pushstk');   // change to your cPanel DB name
define('DB_USER', 'cpanel_username_dbuser');    // change to your cPanel DB user
define('DB_PASS', 'YourStrongPassword');

// ── Install path ─────────────────────────────────────────────
// Installed at domain root  →  define('APP_BASE_PATH', '');
// Installed in subdirectory →  define('APP_BASE_PATH', '/pushstk');
define('APP_BASE_PATH', '');

// ── App name (optional) ──────────────────────────────────────
define('APP_NAME', 'BulkSTK Pro');
