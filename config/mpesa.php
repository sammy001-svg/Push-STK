<?php
/**
 * M-Pesa Daraja API Configuration
 * Update these values with your Safaricom Daraja credentials.
 * Get credentials at: https://developer.safaricom.co.ke
 */

// Environment: 'sandbox' or 'production'
define('MPESA_ENV', 'production');

// Daraja API Credentials
define('MPESA_CONSUMER_KEY',    '9lyGcbSASaXP7i056BMsQyba79NKne99GJ8MkULG29Gjpl3L');
define('MPESA_CONSUMER_SECRET', 'GTzfjwZeTk3CP63AXcAsfUJx0uBtPYjTn4gE3YxVf6e9K9CvX6c7izGCEgL6I6H8');

// Business Short Code (Paybill or Till Number)
define('MPESA_SHORTCODE', '4321679');

// Lipa Na M-Pesa Online Passkey
define('MPESA_PASSKEY', '1d477a8bb88ef74f2d5af2238819e64426e290520bb8101484383aee856b5919');

// Callback URLs - must be publicly accessible HTTPS endpoints
define('MPESA_CALLBACK_URL', APP_URL . '/api/callback.php');
define('MPESA_TIMEOUT_URL',  APP_URL . '/api/timeout.php');

// Bulk processing settings
define('BATCH_SIZE',   5);    // STK pushes per batch
define('BATCH_DELAY',  1200); // ms between batches (client-side polling)
define('MAX_RETRIES',  2);    // Retry failed pushes
define('STK_TIMEOUT',  55);   // Seconds to wait for callback before marking as timeout

// Daraja Base URLs
define('MPESA_SANDBOX_URL',    'https://sandbox.safaricom.co.ke');
define('MPESA_PRODUCTION_URL', 'https://api.safaricom.co.ke');
