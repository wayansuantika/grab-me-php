<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\BookingRepository;
use App\Core\UserRepository;

class BookingService
{
    private BookingRepository $bookingRepo;
    private UserRepository $userRepo;

    public function __construct()
    {
        $this->bookingRepo = new BookingRepository();
        $this->userRepo = new UserRepository();
    }

    /**
     * Cancel a booking and handle refunds
     */
    public function cancelBooking(int $bookingId, int $userId, string $reason = ''): array
    {
        $pdo = Database::connection();
        $booking = $this->bookingRepo->findWithDetails($bookingId);

        if (!$booking) {
            return ['success' => false, 'message' => 'Booking not found.'];
        }

        // Check authorization - customer can only cancel their own bookings
        if ($booking['user_id'] !== $userId) {
            return ['success' => false, 'message' => 'Unauthorized.'];
        }

        // Check booking status - can't cancel if already completed or cancelled
        if (!in_array($booking['booking_status'], ['pending_payment', 'confirmed'])) {
            return ['success' => false, 'message' => "Cannot cancel booking with status: {$booking['booking_status']}"];
        }

        try {
            $pdo->beginTransaction();

            // Update booking status
            $this->bookingRepo->cancel($bookingId, $reason);

            // Handle refund if payment was made
            if ($booking['payment_status'] === 'paid' && $booking['payment_id']) {
                $this->processRefund($booking['payment_id']);
            }

            $pdo->commit();

            return [
                'success' => true,
                'message' => 'Booking cancelled successfully.',
                'booking_code' => $booking['booking_code'],
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            app_error('Failed to cancel booking', $e);
            return ['success' => false, 'message' => 'Failed to cancel booking.'];
        }
    }

    /**
     * Reschedule a booking
     */
    public function rescheduleBooking(
        int $bookingId,
        int $userId,
        string $newDate,
        string $newTime,
        ?int $newTherapistId = null
    ): array {
        $booking = $this->bookingRepo->findWithDetails($bookingId);

        if (!$booking) {
            return ['success' => false, 'message' => 'Booking not found.'];
        }

        if ($booking['user_id'] !== $userId) {
            return ['success' => false, 'message' => 'Unauthorized.'];
        }

        if (!in_array($booking['booking_status'], ['confirmed', 'pending_payment'])) {
            return ['success' => false, 'message' => 'Cannot reschedule this booking.'];
        }

        // Validate new date/time
        $error = null;
        if (!\App\Core\Validator::validateDate($newDate) || !\App\Core\Validator::validateTime($newTime)) {
            return ['success' => false, 'message' => 'Invalid date or time format.'];
        }

        if (!\App\Core\Validator::validateFutureDate($newDate, $error)) {
            return ['success' => false, 'message' => $error];
        }

        try {
            $updateData = [
                'booking_date' => $newDate,
                'booking_time' => $newTime,
            ];

            if ($newTherapistId) {
                // Verify therapist covers the area
                $pdo = Database::connection();
                $check = $pdo->prepare('
                    SELECT COUNT(*) AS total FROM therapist_coverage_areas
                    WHERE therapist_id = :therapist_id AND area_id = :area_id
                ');
                $check->execute(['therapist_id' => $newTherapistId, 'area_id' => $booking['area_id']]);

                if ((int) $check->fetch()['total'] === 0) {
                    return ['success' => false, 'message' => 'Selected therapist does not cover this area.'];
                }

                $updateData['therapist_id'] = $newTherapistId;
            }

            $this->bookingRepo->update($bookingId, $updateData);

            return [
                'success' => true,
                'message' => 'Booking rescheduled successfully.',
                'booking_code' => $booking['booking_code'],
                'new_date' => $newDate,
                'new_time' => $newTime,
            ];
        } catch (\Throwable $e) {
            app_error('Failed to reschedule booking', $e);
            return ['success' => false, 'message' => 'Failed to reschedule booking.'];
        }
    }

    /**
     * Get booking summary for customer
     */
    public function getCustomerBookingSummary(int $userId): array
    {
        $bookings = $this->bookingRepo->byCustomer($userId, 200, 0);
        $stats = [
            'total' => 0,
            'confirmed' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'total_spent' => 0,
        ];

        foreach ($bookings as $booking) {
            $stats['total']++;
            $stats['total_spent'] += (float) $booking['total_amount'];

            switch ($booking['booking_status']) {
                case 'confirmed':
                    $stats['confirmed']++;
                    break;
                case 'completed':
                    $stats['completed']++;
                    break;
                case 'cancelled':
                    $stats['cancelled']++;
                    break;
            }
        }

        return $stats;
    }

    /**
     * Process a refund for a payment
     */
    private function processRefund(int $paymentId): void
    {
        $pdo = Database::connection();

        // For now, just mark as refunded. In production, integrate with actual payment gateway
        $stmt = $pdo->prepare('
            UPDATE payments
            SET status = :status, refunded_at = :refunded_at
            WHERE id = :id AND status = :current_status
        ');

        $stmt->execute([
            'status' => 'refunded',
            'refunded_at' => now(),
            'id' => $paymentId,
            'current_status' => 'succeeded',
        ]);
    }

    /**
     * Get therapist's revenue summary
     */
    public function getTherapistRevenue(int $therapistId): array
    {
        $pdo = Database::connection();

        $sql = "
            SELECT
                COUNT(b.id) AS total_bookings,
                SUM(CASE WHEN b.payment_status = 'paid' THEN b.total_amount ELSE 0 END) AS paid_revenue,
                SUM(CASE WHEN b.booking_status = 'completed' THEN b.total_amount ELSE 0 END) AS completed_revenue,
                SUM(b.total_amount) AS total_revenue,
                AVG(CASE WHEN b.payment_status = 'paid' THEN b.total_amount END) AS avg_payment
            FROM bookings b
            WHERE b.therapist_id = :therapist_id
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['therapist_id' => $therapistId]);
        return $stmt->fetch() ?: [];
    }

    /**
     * Validate booking can be created
     */
    public function validateBookingCreation(array $data): array
    {
        $errors = [];
        $validator = new \App\Core\Validator();

        // Validate required fields
        $required = ['area_id', 'therapist_id', 'booking_date', 'booking_time', 'customer_name', 'customer_phone', 'service_ids'];
        $fieldErrors = $validator::required($data, $required);
        if (!empty($fieldErrors)) {
            return $fieldErrors;
        }

        // Validate date/time
        if (!\App\Core\Validator::validateDate($data['booking_date'])) {
            $errors['booking_date'] = 'Invalid date format.';
        } elseif (!\App\Core\Validator::validateFutureDate($data['booking_date'], $error)) {
            $errors['booking_date'] = $error;
        }

        if (!\App\Core\Validator::validateTime($data['booking_time'])) {
            $errors['booking_time'] = 'Invalid time format.';
        }

        // Validate phone
        if (!\App\Core\Validator::validatePhone($data['customer_phone'], $error)) {
            $errors['customer_phone'] = $error;
        }

        // Validate payment method
        $paymentMethod = strtolower(trim((string) ($data['payment_method'] ?? 'bank_transfer')));
        if (!in_array($paymentMethod, ['bank_transfer', 'credit_card'])) {
            $errors['payment_method'] = 'Invalid payment method.';
        }

        // Validate services selected
        $serviceIds = array_map('intval', (array) ($data['service_ids'] ?? []));
        if (count($serviceIds) === 0) {
            $errors['service_ids'] = 'At least one service is required.';
        }

        return $errors;
    }
}
