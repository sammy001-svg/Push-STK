<?php
require_once __DIR__ . '/../config/mpesa.php';

/**
 * M-Pesa Daraja API – STK Push (Lipa Na M-Pesa Online)
 */
class Mpesa {

    private string $consumerKey;
    private string $consumerSecret;
    private string $shortcode;
    private string $passkey;
    private string $callbackUrl;
    private string $env;
    private string $baseUrl;

    public function __construct(array $config = []) {
        $this->consumerKey    = $config['consumer_key']    ?? $this->getSetting('mpesa_consumer_key')    ?? MPESA_CONSUMER_KEY;
        $this->consumerSecret = $config['consumer_secret'] ?? $this->getSetting('mpesa_consumer_secret') ?? MPESA_CONSUMER_SECRET;
        $this->shortcode      = $config['shortcode']       ?? $this->getSetting('mpesa_shortcode')       ?? MPESA_SHORTCODE;
        $this->passkey        = $config['passkey']         ?? $this->getSetting('mpesa_passkey')         ?? MPESA_PASSKEY;
        $this->callbackUrl    = $config['callback_url']    ?? $this->getSetting('mpesa_callback_url')    ?? MPESA_CALLBACK_URL;
        $this->env            = $config['env']             ?? $this->getSetting('mpesa_env')             ?? MPESA_ENV;
        $this->baseUrl        = $this->env === 'production' ? MPESA_PRODUCTION_URL : MPESA_SANDBOX_URL;
    }

    // -------------------------------------------------------
    // Public API
    // -------------------------------------------------------

    public function stkPush(string $phone, float $amount, string $accountRef, string $description = 'Payment'): array {
        $phone     = $this->formatPhone($phone);
        $amount    = (int) ceil($amount);
        $timestamp = date('YmdHis');
        $password  = base64_encode($this->shortcode . $this->passkey . $timestamp);
        $token     = $this->getAccessToken();

        if (!$token) {
            return ['success' => false, 'message' => 'Failed to get M-Pesa access token.'];
        }

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => $amount,
            'PartyA'            => $phone,
            'PartyB'            => $this->shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $this->callbackUrl,
            'AccountReference'  => substr($accountRef, 0, 12),
            'TransactionDesc'   => substr($description, 0, 13),
        ];

        $response = $this->curlPost(
            $this->baseUrl . '/mpesa/stkpush/v1/processrequest',
            $payload,
            $token
        );

        if (isset($response['ResponseCode']) && $response['ResponseCode'] === '0') {
            return [
                'success'              => true,
                'merchant_request_id'  => $response['MerchantRequestID'],
                'checkout_request_id'  => $response['CheckoutRequestID'],
                'response_code'        => $response['ResponseCode'],
                'response_description' => $response['ResponseDescription'],
                'customer_message'     => $response['CustomerMessage'],
            ];
        }

        $errMsg = $response['errorMessage'] ?? $response['ResponseDescription'] ?? 'STK push request failed.';
        return ['success' => false, 'message' => $errMsg, 'raw' => $response];
    }

    public function querySTKStatus(string $checkoutRequestId): array {
        $token     = $this->getAccessToken();
        $timestamp = date('YmdHis');
        $password  = base64_encode($this->shortcode . $this->passkey . $timestamp);

        if (!$token) {
            return ['success' => false, 'message' => 'Failed to get access token.'];
        }

        $payload = [
            'BusinessShortCode' => $this->shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        $response = $this->curlPost(
            $this->baseUrl . '/mpesa/stkpushquery/v1/query',
            $payload,
            $token
        );

        return $response ?? ['success' => false, 'message' => 'No response from M-Pesa'];
    }

    // -------------------------------------------------------
    // Phone number normalizer (Kenya 254XXXXXXXXX)
    // -------------------------------------------------------
    public static function formatPhone(string $phone): string {
        $phone = preg_replace('/\D/', '', $phone);

        if (strlen($phone) === 9) {
            return '254' . $phone;
        }
        if (strlen($phone) === 10 && substr($phone, 0, 1) === '0') {
            return '254' . substr($phone, 1);
        }
        if (strlen($phone) === 12 && substr($phone, 0, 3) === '254') {
            return $phone;
        }
        if (strlen($phone) === 13 && substr($phone, 0, 4) === '+254') {
            return substr($phone, 1);
        }
        return $phone;
    }

    public static function isValidPhone(string $phone): bool {
        $formatted = self::formatPhone($phone);
        return preg_match('/^2547[0-9]{8}$/', $formatted) || preg_match('/^2541[0-9]{8}$/', $formatted);
    }

    // -------------------------------------------------------
    // Token generation (cached in file for 55 min)
    // -------------------------------------------------------
    public function getAccessToken(): ?string {
        $cacheFile = sys_get_temp_dir() . '/mpesa_token_' . md5($this->consumerKey) . '.cache';

        if (file_exists($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if ($cached && isset($cached['expires_at']) && time() < $cached['expires_at']) {
                return $cached['token'];
            }
        }

        $credentials = base64_encode($this->consumerKey . ':' . $this->consumerSecret);
        $ch = curl_init($this->baseUrl . '/oauth/v1/generate?grant_type=client_credentials');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . $credentials],
            CURLOPT_SSL_VERIFYPEER => ($this->env === 'production'),
            CURLOPT_SSL_VERIFYHOST => ($this->env === 'production') ? 2 : 0,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);

        if (!$response) return null;
        $data = json_decode($response, true);
        if (!isset($data['access_token'])) return null;

        file_put_contents($cacheFile, json_encode([
            'token'      => $data['access_token'],
            'expires_at' => time() + 3200, // ~53 min
        ]));

        return $data['access_token'];
    }

    // -------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------
    private function curlPost(string $url, array $payload, string $token): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => ($this->env === 'production'),
            CURLOPT_SSL_VERIFYHOST => ($this->env === 'production') ? 2 : 0,
            CURLOPT_TIMEOUT        => 30,
        ]);
        $response = curl_exec($ch);
        $error    = curl_error($ch);

        if ($error) {
            error_log("Mpesa cURL error: {$error}");
            return null;
        }
        return json_decode($response, true);
    }

    private function getSetting(string $key): ?string {
        try {
            require_once __DIR__ . '/db.php';
            $row = Database::fetchOne(
                "SELECT setting_value FROM settings WHERE setting_key = ? LIMIT 1",
                [$key]
            );
            return $row ? $row['setting_value'] : null;
        } catch (Throwable) {
            return null;
        }
    }
}
