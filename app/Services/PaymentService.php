<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Services\StripeService;

class PaymentService
{
    private StripeService $stripeService;

    public function __construct()
    {
        $this->stripeService = new StripeService();
    }

    /**
     * Create a payment record for a booking
     */
    public function createPayment(int $bookingId, float $amount, string $method = 'bank_transfer'): array
    {
        $pdo = Database::connection();
        $provider = strtolower(trim($method));

        if (!in_array($provider, ['bank_transfer', 'stripe', 'credit_card', 'paypal'])) {
            return ['success' => false, 'message' => 'Invalid payment method.'];
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO payments (booking_id, provider, provider_payment_id, amount, currency, status, raw_payload, created_at, updated_at)
                VALUES (:booking_id, :provider, :provider_payment_id, :amount, :currency, :status, :raw_payload, :created_at, :updated_at)
            ');

            $paymentCode = 'PAY-' . strtoupper(bin2hex(random_bytes(4)));

            $stmt->execute([
                'booking_id' => $bookingId,
                'provider' => $provider,
                'provider_payment_id' => $paymentCode,
                'amount' => $amount,
                'currency' => 'idr',
                'status' => 'pending',
                'raw_payload' => json_encode(['method' => $provider, 'created_via' => 'api']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'success' => true,
                'payment_id' => $pdo->lastInsertId(),
                'message' => 'Payment record created.',
                'amount' => $amount,
                'provider' => $provider,
            ];
        } catch (\Throwable $e) {
            app_error('Failed to create payment', $e);
            return ['success' => false, 'message' => 'Failed to create payment record.'];
        }
    }

    /**
     * Process credit card payment via Stripe
     */
    public function processStripePayment(int $paymentId, string $token, string $description = ''): array
    {
        $pdo = Database::connection();

        // Get payment record
        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = :id AND status = :status');
        $stmt->execute(['id' => $paymentId, 'status' => 'pending']);
        $payment = $stmt->fetch();

        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found or already processed.'];
        }

        try {
            // Call Stripe API
            $result = $this->stripeService->charge(
                (float) $payment['amount'],
                $token,
                $description ?: "Booking Payment #{$payment['booking_id']}"
            );

            if (!$result['success']) {
                $this->updatePaymentStatus($paymentId, 'failed', $result['message']);
                return $result;
            }

            // Update payment status with Stripe transaction ID
            $this->updatePaymentStatus($paymentId, 'succeeded', $result['transaction_id']);

            // Update booking status to confirmed
            $this->confirmBooking($payment['booking_id']);

            return [
                'success' => true,
                'message' => 'Payment processed successfully.',
                'transaction_id' => $result['transaction_id'],
                'amount' => $payment['amount'],
            ];
        } catch (\Throwable $e) {
            app_error('Stripe payment failed', $e);
            $this->updatePaymentStatus($paymentId, 'failed', 'Gateway error');
            return ['success' => false, 'message' => 'Payment processing failed.'];
        }
    }

    /**
     * Process bank transfer payment (manual confirmation)
     */
    public function processBankTransfer(int $paymentId, string $referenceCode): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = :id');
        $stmt->execute(['id' => $paymentId]);
        $payment = $stmt->fetch();

        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found.'];
        }

        try {
            // Update payment with reference code and mark as pending confirmation
            $updateStmt = $pdo->prepare('
                UPDATE payments
                SET raw_payload = :payload, status = :status, updated_at = :updated_at
                WHERE id = :id
            ');

            $payload = json_decode($payment['raw_payload'] ?? '{}', true);
            $payload['bank_reference_code'] = $referenceCode;

            $updateStmt->execute([
                'payload' => json_encode($payload),
                'status' => 'pending',
                'updated_at' => now(),
                'id' => $paymentId,
            ]);

            // Send admin notification (in real implementation)
            $this->notifyAdminBankTransfer($payment['booking_id'], $referenceCode);

            return [
                'success' => true,
                'message' => 'Bank transfer details recorded. Awaiting confirmation.',
                'reference_code' => $referenceCode,
            ];
        } catch (\Throwable $e) {
            app_error('Failed to process bank transfer', $e);
            return ['success' => false, 'message' => 'Failed to record bank transfer.'];
        }
    }

    /**
     * Confirm a pending bank transfer payment (admin only)
     */
    public function confirmBankTransfer(int $paymentId, int $adminId): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT * FROM payments WHERE id = :id AND status = :status');
        $stmt->execute(['id' => $paymentId, 'status' => 'pending']);
        $payment = $stmt->fetch();

        if (!$payment) {
            return ['success' => false, 'message' => 'Payment not found or not pending confirmation.'];
        }

        try {
            $updateStmt = $pdo->prepare('
                UPDATE payments
                SET status = :status, updated_at = :updated_at
                WHERE id = :id
            ');

            $updateStmt->execute([
                'status' => 'succeeded',
                'updated_at' => now(),
                'id' => $paymentId,
            ]);

            // Confirm booking
            $this->confirmBooking($payment['booking_id']);

            return ['success' => true, 'message' => 'Payment confirmed and booking activated.'];
        } catch (\Throwable $e) {
            app_error('Failed to confirm bank transfer', $e);
            return ['success' => false, 'message' => 'Failed to confirm payment.'];
        }
    }

    /**
     * Get payment history for a booking
     */
    public function getPaymentHistory(int $bookingId): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('
            SELECT id, booking_id, amount, method, status, reference_code, transaction_id, created_at, confirmed_at
            FROM payments
            WHERE booking_id = :booking_id
            ORDER BY created_at DESC
        ');

        $stmt->execute(['booking_id' => $bookingId]);
        return $stmt->fetchAll();
    }

    /**
     * Get payment stats for admin dashboard
     */
    public function getPaymentStats(): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('
            SELECT
                (SELECT COUNT(p.id) FROM payments p) AS total_payments,
                (SELECT COUNT(DISTINCT p.booking_id) FROM payments p) AS unique_bookings,
                (
                    SELECT COALESCE(SUM(b.total_amount), 0)
                    FROM bookings b
                    WHERE b.payment_status = :paid_booking_status
                      AND b.booking_status <> :cancelled_status
                ) AS total_collected,
                (
                    SELECT COALESCE(SUM(b.total_amount), 0)
                    FROM bookings b
                    WHERE b.payment_status IN (:pending_booking_status, :processing_booking_status)
                      AND b.booking_status IN (:pending_payment_status, :confirmed_status)
                ) AS total_pending,
                (
                    SELECT COALESCE(SUM(b.total_amount), 0)
                    FROM bookings b
                    WHERE b.payment_status = :failed_booking_status
                      AND b.booking_status <> :cancelled_status_failed
                ) AS total_failed
        ');

        $stmt->execute([
            'paid_booking_status' => 'paid',
            'pending_booking_status' => 'pending',
            'processing_booking_status' => 'processing',
            'failed_booking_status' => 'failed',
            'pending_payment_status' => 'pending_payment',
            'confirmed_status' => 'confirmed',
            'cancelled_status' => 'cancelled',
            'cancelled_status_failed' => 'cancelled',
        ]);

        return $stmt->fetch() ?: [];
    }

    /**
     * Update payment status
     */
    private function updatePaymentStatus(int $paymentId, string $status, string $transactionId = ''): void
    {
        $pdo = Database::connection();

        $sql = 'UPDATE payments SET status = :status, updated_at = :updated_at';
        $params = ['status' => $status, 'updated_at' => now(), 'id' => $paymentId];

        if ($transactionId) {
            $sql .= ', provider_payment_id = :provider_payment_id';
            $params['provider_payment_id'] = $transactionId;
        }

        $sql .= ' WHERE id = :id';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Confirm booking after successful payment
     */
    private function confirmBooking(int $bookingId): void
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('
            UPDATE bookings
            SET booking_status = :status, payment_status = :payment_status, updated_at = :updated_at
            WHERE id = :id
        ');

        $stmt->execute([
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'updated_at' => now(),
            'id' => $bookingId,
        ]);
    }

    /**
     * Send admin notification for bank transfer (placeholder)
     */
    private function notifyAdminBankTransfer(int $bookingId, string $referenceCode): void
    {
        // In production, send email to admin with booking and reference code details
        // For now, this is a placeholder for the notification system
    }
}
