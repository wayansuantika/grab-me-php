<?php

declare(strict_types=1);

namespace App\Services;

class StripeService
{
    private string $secretKey;
    private string $webhookSecret;

    public function __construct()
    {
        $this->secretKey = (string) config('stripe.secret_key', '');
        $this->webhookSecret = (string) config('stripe.webhook_secret', '');
    }

    public function createPaymentIntent(int $amountCents, string $currency = 'idr', array $metadata = []): array
    {
        if ($this->secretKey === '') {
            return ['success' => false, 'message' => 'Stripe key not configured.'];
        }

        $fields = [
            'amount' => $amountCents,
            'currency' => strtolower($currency),
            'automatic_payment_methods[enabled]' => 'true',
        ];

        foreach ($metadata as $key => $value) {
            $fields['metadata[' . $key . ']'] = (string) $value;
        }

        $ch = curl_init('https://api.stripe.com/v1/payment_intents');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->secretKey,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $error !== '') {
            return ['success' => false, 'message' => 'Stripe request error: ' . $error];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || $statusCode >= 400) {
            return ['success' => false, 'message' => $decoded['error']['message'] ?? 'Stripe API error.'];
        }

        return ['success' => true, 'data' => $decoded];
    }

    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        if ($this->webhookSecret === '' || $signatureHeader === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signatureHeader) as $entry) {
            [$k, $v] = array_pad(explode('=', trim($entry), 2), 2, '');
            if ($k !== '' && $v !== '') {
                $parts[$k] = $v;
            }
        }

        if (!isset($parts['t'], $parts['v1'])) {
            return false;
        }

        $signedPayload = $parts['t'] . '.' . $payload;
        $computed = hash_hmac('sha256', $signedPayload, $this->webhookSecret);

        return hash_equals($computed, $parts['v1']);
    }
}
