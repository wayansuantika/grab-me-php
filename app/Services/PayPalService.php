<?php

declare(strict_types=1);

namespace App\Services;

class PayPalService
{
    private string $clientId;
    private string $clientSecret;
    private string $mode;
    private string $baseUrl;

    public function __construct()
    {
        $this->clientId = (string) config('paypal.client_id', '');
        $this->clientSecret = (string) config('paypal.client_secret', '');
        $this->mode = (string) config('paypal.mode', 'sandbox');
        $this->baseUrl = $this->mode === 'production'
            ? 'https://api.paypal.com'
            : 'https://api.sandbox.paypal.com';
    }

    /**
     * Get access token from PayPal API
     */
    private function getAccessToken(): ?string
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            return null;
        }

        $ch = curl_init($this->baseUrl . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->clientId . ':' . $this->clientSecret,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return null;
        }

        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }

    /**
     * Create a PayPal order
     */
    public function createOrder(int $amountCents, string $currency = 'IDR', array $metadata = []): array
    {
        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            return ['success' => false, 'message' => 'PayPal credentials not configured.'];
        }

        // Convert cents to decimal amount (e.g., 100000 cents = 1000.00)
        $amountDecimal = number_format($amountCents / 100, 2, '.', '');

        $orderData = [
            'intent' => 'CAPTURE',
            'purchase_units' => [
                [
                    'amount' => [
                        'currency_code' => strtoupper($currency),
                        'value' => $amountDecimal,
                    ],
                ],
            ],
            'payer' => [
                'email_address' => $metadata['customer_email'] ?? 'customer@example.com',
            ],
        ];

        // Add return URLs (in production, these should be configurable)
        $returnUrl = config('app.url', 'http://localhost/grabmas');
        $orderData['application_context'] = [
            'return_url' => $returnUrl . '/#/booking?status=success',
            'cancel_url' => $returnUrl . '/#/booking?status=cancelled',
            'brand_name' => 'GrabMas Spa',
            'locale' => 'en-US',
            'user_action' => 'PAY_NOW',
        ];

        if (isset($metadata['booking_id'])) {
            $orderData['purchase_units'][0]['custom_id'] = (string) $metadata['booking_id'];
        }
        if (isset($metadata['booking_code'])) {
            $orderData['purchase_units'][0]['reference_id'] = (string) $metadata['booking_code'];
        }

        $ch = curl_init($this->baseUrl . '/v2/checkout/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($orderData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            return ['success' => false, 'message' => 'PayPal request error: ' . $error];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || $statusCode >= 400) {
            $errorMsg = $decoded['message'] ?? $decoded['details'][0]['issue'] ?? 'PayPal API error';
            return ['success' => false, 'message' => 'PayPal error: ' . $errorMsg];
        }

        return ['success' => true, 'data' => $decoded];
    }

    /**
     * Capture a PayPal order (after user approves)
     */
    public function captureOrder(string $orderId): array
    {
        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            return ['success' => false, 'message' => 'PayPal credentials not configured.'];
        }

        $ch = curl_init($this->baseUrl . '/v2/checkout/orders/' . urlencode($orderId) . '/capture');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([]),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            return ['success' => false, 'message' => 'PayPal request error: ' . $error];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || $statusCode >= 400) {
            $errorMsg = $decoded['message'] ?? $decoded['details'][0]['issue'] ?? 'PayPal API error';
            return ['success' => false, 'message' => 'PayPal error: ' . $errorMsg];
        }

        return ['success' => true, 'data' => $decoded];
    }

    /**
     * Verify webhook signature (IPN style)
     */
    public function verifyWebhookSignature(array $postData, string $transmissionId, string $transmissionTime, string $certUrl, string $actualSignature): bool
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            return false;
        }

        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            return false;
        }

        $verificationData = [
            'transmission_id' => $transmissionId,
            'transmission_time' => $transmissionTime,
            'cert_url' => $certUrl,
            'auth_algo' => 'SHA-256',
            'transmission_sig' => $actualSignature,
            'webhook_id' => config('paypal.webhook_id', ''),
            'webhook_event' => $postData,
        ];

        $ch = curl_init($this->baseUrl . '/v1/notifications/verify-webhook-signature');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($verificationData),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return false;
        }

        $data = json_decode($response, true);
        return ($data['verification_status'] ?? '') === 'SUCCESS';
    }
}
