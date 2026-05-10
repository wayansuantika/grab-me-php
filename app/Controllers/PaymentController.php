<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Services\StripeService;

class PaymentController
{
    public function createIntent(): void
    {
        if (!verify_csrf()) {
            json_response(['success' => false, 'message' => 'CSRF validation failed.'], 419);
        }

        $data = request_json();
        $bookingId = (int) ($data['booking_id'] ?? 0);
        $customerPhone = trim((string) ($data['customer_phone'] ?? ''));
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
}
