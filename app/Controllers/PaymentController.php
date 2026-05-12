<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Services\StripeService;
use App\Services\PayPalService;

class PaymentController
{
    public function createIntent(): void
    {
        if (!verify_csrf()) {
            json_response(['success' => false, 'message' => 'CSRF validation failed.'], 419);
        }

        try {
            $data = request_json();
            $bookingId = (int) ($data['booking_id'] ?? 0);
            $customerPhone = trim((string) ($data['customer_phone'] ?? ''));
            $paymentMethod = strtolower(trim((string) ($data['payment_method'] ?? 'stripe')));

            if ($bookingId <= 0) {
                json_response(['success' => false, 'message' => 'Invalid booking.'], 422);
            }

            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT id, booking_code, user_id, customer_phone, total_amount, payment_status FROM bookings WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $bookingId]);
            $booking = $stmt->fetch();

            if (!$booking) {
                json_response(['success' => false, 'message' => 'Booking not found.'], 404);
            }

            $authId = Auth::id();
            if ($authId !== null) {
                if ((int) $booking['user_id'] !== $authId) {
                    json_response(['success' => false, 'message' => 'Booking not found.'], 404);
                }
            } else {
                if ($customerPhone === '' || $customerPhone !== (string) $booking['customer_phone']) {
                    json_response(['success' => false, 'message' => 'Booking verification failed.'], 403);
                }
            }

            if ($booking['payment_status'] === 'paid') {
                json_response(['success' => false, 'message' => 'Booking already paid.'], 422);
            }

            $amountCents = (int) round(((float) $booking['total_amount']) * 100);

            // Route to appropriate payment gateway
            if ($paymentMethod === 'paypal') {
                $this->createPayPalOrder($pdo, $booking, $amountCents);
            } else {
                $this->createStripeIntent($pdo, $booking, $amountCents);
            }
        } catch (\Throwable $e) {
            app_error('Payment createIntent error', $e);
            json_response(['success' => false, 'message' => 'Failed to create payment intent.'], 500);
        }
    }

    private function createStripeIntent($pdo, $booking, int $amountCents): void
    {
        $stripe = new StripeService();
        $result = $stripe->createPaymentIntent($amountCents, 'idr', [
            'booking_id' => (string) $booking['id'],
            'booking_code' => (string) $booking['booking_code'],
            'user_id' => (string) ((int) $booking['user_id']),
        ]);

        if (!$result['success']) {
            json_response(['success' => false, 'message' => $result['message']], 500);
        }

        $intent = $result['data'];

        $insertPayment = $pdo->prepare('
            INSERT INTO payments (booking_id, provider, provider_payment_id, amount, currency, status, raw_payload, created_at, updated_at)
            VALUES (:booking_id, :provider, :provider_payment_id, :amount, :currency, :status, :raw_payload, :created_at, :updated_at)
        ');
        $insertPayment->execute([
            'booking_id' => (int) $booking['id'],
            'provider' => 'stripe',
            'provider_payment_id' => (string) ($intent['id'] ?? ''),
            'amount' => (float) $booking['total_amount'],
            'currency' => 'idr',
            'status' => 'pending',
            'raw_payload' => json_encode($intent),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        json_response([
            'success' => true,
            'data' => [
                'client_secret' => $intent['client_secret'] ?? null,
                'publishable_key' => config('stripe.publishable_key', ''),
            ],
        ]);
    }

    private function createPayPalOrder($pdo, $booking, int $amountCents): void
    {
        $paypal = new PayPalService();
        $result = $paypal->createOrder($amountCents, 'IDR', [
            'booking_id' => (string) $booking['id'],
            'booking_code' => (string) $booking['booking_code'],
            'customer_email' => (string) ($booking['customer_email'] ?? 'customer@example.com'),
        ]);

        if (!$result['success']) {
            json_response(['success' => false, 'message' => $result['message']], 500);
        }

        $order = $result['data'];
        $orderId = $order['id'] ?? '';

        if ($orderId === '') {
            json_response(['success' => false, 'message' => 'Failed to create PayPal order.'], 500);
        }

        $insertPayment = $pdo->prepare('
            INSERT INTO payments (booking_id, provider, provider_payment_id, amount, currency, status, raw_payload, created_at, updated_at)
            VALUES (:booking_id, :provider, :provider_payment_id, :amount, :currency, :status, :raw_payload, :created_at, :updated_at)
        ');
        $insertPayment->execute([
            'booking_id' => (int) $booking['id'],
            'provider' => 'paypal',
            'provider_payment_id' => $orderId,
            'amount' => (float) $booking['total_amount'],
            'currency' => 'idr',
            'status' => 'pending',
            'raw_payload' => json_encode($order),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Return approval link for user to redirect to PayPal
        $approvalLink = '';
        foreach ($order['links'] ?? [] as $link) {
            if ($link['rel'] === 'approve') {
                $approvalLink = $link['href'] ?? '';
                break;
            }
        }

        json_response([
            'success' => true,
            'data' => [
                'order_id' => $orderId,
                'approval_url' => $approvalLink,
            ],
        ]);
    }

    public function webhook(): void
    {
        $payload = file_get_contents('php://input') ?: '';
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        $stripe = new StripeService();
        if (!$stripe->verifyWebhookSignature($payload, (string) $signature)) {
            json_response(['success' => false, 'message' => 'Invalid signature.'], 400);
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            json_response(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        $type = $event['type'] ?? '';
        $intent = $event['data']['object'] ?? [];
        $providerId = (string) ($intent['id'] ?? '');
        $bookingIdFromMetadata = (int) ($intent['metadata']['booking_id'] ?? 0);

        if ($providerId === '') {
            json_response(['success' => false, 'message' => 'Invalid intent.'], 400);
        }

        $pdo = Database::connection();

        if ($type === 'payment_intent.succeeded') {
            $pdo->beginTransaction();
            try {
                $paymentStmt = $pdo->prepare('SELECT id, booking_id FROM payments WHERE provider = :provider AND provider_payment_id = :provider_payment_id ORDER BY id DESC LIMIT 1 FOR UPDATE');
                $paymentStmt->execute(['provider' => 'stripe', 'provider_payment_id' => $providerId]);
                $payment = $paymentStmt->fetch();

                // Fallback path: reconcile by booking_id from Stripe metadata when intent id is not found.
                if (!$payment && $bookingIdFromMetadata > 0) {
                    $fallbackStmt = $pdo->prepare('SELECT id, booking_id FROM payments WHERE provider = :provider AND booking_id = :booking_id ORDER BY id DESC LIMIT 1 FOR UPDATE');
                    $fallbackStmt->execute([
                        'provider' => 'stripe',
                        'booking_id' => $bookingIdFromMetadata,
                    ]);
                    $payment = $fallbackStmt->fetch();
                }

                $targetBookingId = (int) ($payment['booking_id'] ?? $bookingIdFromMetadata);

                if ($payment) {
                    $pdo->prepare('UPDATE payments SET status = :status, raw_payload = :raw_payload, updated_at = :updated_at WHERE id = :id')->execute([
                        'status' => 'succeeded',
                        'raw_payload' => $payload,
                        'updated_at' => now(),
                        'id' => (int) $payment['id'],
                    ]);

                    // Keep the newest provider payment id in sync if it changed.
                    if ($providerId !== '') {
                        $pdo->prepare('UPDATE payments SET provider_payment_id = :provider_payment_id, updated_at = :updated_at WHERE id = :id')->execute([
                            'provider_payment_id' => $providerId,
                            'updated_at' => now(),
                            'id' => (int) $payment['id'],
                        ]);
                    }
                } elseif ($targetBookingId > 0) {
                    $amount = ((float) ($intent['amount_received'] ?? $intent['amount'] ?? 0)) / 100;
                    $currency = strtolower((string) ($intent['currency'] ?? 'idr'));

                    $pdo->prepare('INSERT INTO payments (booking_id, provider, provider_payment_id, amount, currency, status, raw_payload, created_at, updated_at) VALUES (:booking_id, :provider, :provider_payment_id, :amount, :currency, :status, :raw_payload, :created_at, :updated_at)')->execute([
                        'booking_id' => $targetBookingId,
                        'provider' => 'stripe',
                        'provider_payment_id' => $providerId,
                        'amount' => $amount,
                        'currency' => $currency,
                        'status' => 'succeeded',
                        'raw_payload' => $payload,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if ($targetBookingId > 0) {
                    $pdo->prepare('UPDATE bookings SET payment_status = :payment_status, booking_status = :booking_status, updated_at = :updated_at WHERE id = :id')->execute([
                        'payment_status' => 'paid',
                        'booking_status' => 'confirmed',
                        'updated_at' => now(),
                        'id' => $targetBookingId,
                    ]);
                }

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                app_log('Stripe webhook transaction error: ' . $e->getMessage());
                json_response(['success' => false, 'message' => 'Webhook processing failed.'], 500);
            }
        }

        if ($type === 'payment_intent.payment_failed') {
            $pdo->prepare('UPDATE payments SET status = :status, raw_payload = :raw_payload, updated_at = :updated_at WHERE provider = :provider AND provider_payment_id = :provider_payment_id')->execute([
                'status' => 'failed',
                'raw_payload' => $payload,
                'updated_at' => now(),
                'provider' => 'stripe',
                'provider_payment_id' => $providerId,
            ]);
        }

        json_response(['success' => true, 'message' => 'Webhook processed.']);
    }

    public function capturePayPalOrder(): void
    {
        if (!verify_csrf()) {
            json_response(['success' => false, 'message' => 'CSRF validation failed.'], 419);
        }

        $data = request_json();
        $orderId = trim((string) ($data['order_id'] ?? ''));

        if ($orderId === '') {
            json_response(['success' => false, 'message' => 'Invalid PayPal order ID.'], 422);
        }

        $pdo = Database::connection();

        // Find the payment record
        $paymentStmt = $pdo->prepare('SELECT id, booking_id, amount FROM payments WHERE provider = :provider AND provider_payment_id = :provider_payment_id LIMIT 1');
        $paymentStmt->execute(['provider' => 'paypal', 'provider_payment_id' => $orderId]);
        $payment = $paymentStmt->fetch();

        if (!$payment) {
            json_response(['success' => false, 'message' => 'Payment record not found.'], 404);
        }

        // Capture the order on PayPal
        $paypal = new PayPalService();
        $result = $paypal->captureOrder($orderId);

        if (!$result['success']) {
            json_response(['success' => false, 'message' => $result['message']], 400);
        }

        $captureData = $result['data'];

        // Check if order was successfully captured
        $status = $captureData['status'] ?? '';
        if ($status !== 'COMPLETED') {
            json_response(['success' => false, 'message' => 'PayPal order capture failed: ' . $status], 400);
        }

        // Update payment and booking status
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE payments SET status = :status, raw_payload = :raw_payload, updated_at = :updated_at WHERE id = :id')->execute([
                'status' => 'succeeded',
                'raw_payload' => json_encode($captureData),
                'updated_at' => now(),
                'id' => (int) $payment['id'],
            ]);

            $pdo->prepare('UPDATE bookings SET payment_status = :payment_status, booking_status = :booking_status, updated_at = :updated_at WHERE id = :id')->execute([
                'payment_status' => 'paid',
                'booking_status' => 'confirmed',
                'updated_at' => now(),
                'id' => (int) $payment['booking_id'],
            ]);

            $pdo->commit();

            json_response([
                'success' => true,
                'message' => 'Payment captured successfully',
                'data' => [
                    'order_id' => $orderId,
                    'status' => $status,
                ],
            ]);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            app_error('Failed to capture PayPal order', $e);
            json_response(['success' => false, 'message' => 'Payment capture failed.'], 500);
        }
    }

    public function paypalWebhook(): void
    {
        $payload = file_get_contents('php://input') ?: '';

        if ($payload === '') {
            json_response(['success' => false, 'message' => 'Empty payload.'], 400);
        }

        $event = json_decode($payload, true);
        if (!is_array($event)) {
            json_response(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        $eventType = $event['event_type'] ?? '';
        $resource = $event['resource'] ?? [];

        // Handle CHECKOUT.ORDER.COMPLETED (order was approved and captured by PayPal)
        if ($eventType === 'CHECKOUT.ORDER.COMPLETED') {
            $orderId = $resource['id'] ?? '';

            if ($orderId === '') {
                json_response(['success' => false, 'message' => 'Invalid order.'], 400);
            }

            $pdo = Database::connection();
            $pdo->beginTransaction();

            try {
                $paymentStmt = $pdo->prepare('SELECT id, booking_id FROM payments WHERE provider = :provider AND provider_payment_id = :provider_payment_id FOR UPDATE');
                $paymentStmt->execute(['provider' => 'paypal', 'provider_payment_id' => $orderId]);
                $payment = $paymentStmt->fetch();

                if ($payment) {
                    $pdo->prepare('UPDATE payments SET status = :status, raw_payload = :raw_payload, updated_at = :updated_at WHERE id = :id')->execute([
                        'status' => 'succeeded',
                        'raw_payload' => $payload,
                        'updated_at' => now(),
                        'id' => (int) $payment['id'],
                    ]);

                    $pdo->prepare('UPDATE bookings SET payment_status = :payment_status, booking_status = :booking_status, updated_at = :updated_at WHERE id = :id')->execute([
                        'payment_status' => 'paid',
                        'booking_status' => 'confirmed',
                        'updated_at' => now(),
                        'id' => (int) $payment['booking_id'],
                    ]);
                }

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                app_log('PayPal webhook transaction error: ' . $e->getMessage());
            }
        }

        json_response(['success' => true, 'message' => 'Webhook processed.']);
    }
}
