<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

Auth::start();
Auth::logout();

header('Location: ' . APP_URL . '/index.php');
exit;
