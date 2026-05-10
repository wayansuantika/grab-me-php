<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\TherapistRepository;
use App\Core\Validator;

class TherapistService
{
    private TherapistRepository $therapistRepo;

    public function __construct()
    {
        $this->therapistRepo = new TherapistRepository();
    }

    /**
     * Register a new therapist profile
     */
    public function registerTherapist(int $userId, array $data): array
    {
        $errors = [];

        // Validate required fields
        $required = ['specialization', 'experience_years', 'bio', 'phone', 'coverage_areas'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }

        // Validate experience years
        $expYears = (int) ($data['experience_years'] ?? 0);
        if ($expYears < 0 || $expYears > 70) {
            $errors['experience_years'] = 'Experience years must be between 0 and 70.';
        }

        // Validate phone
        if (!Validator::validatePhone($data['phone'] ?? '', $error)) {
            $errors['phone'] = $error;
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        try {
            $pdo = Database::connection();
            $pdo->beginTransaction();

            // Insert therapist profile
            $stmt = $pdo->prepare('
                INSERT INTO therapists (user_id, specialization, experience_years, bio, phone, is_active, created_at)
                VALUES (:user_id, :specialization, :experience_years, :bio, :phone, :is_active, :created_at)
            ');

            $stmt->execute([
                'user_id' => $userId,
                'specialization' => trim($data['specialization']),
                'experience_years' => $expYears,
                'bio' => trim($data['bio']),
                'phone' => format_phone($data['phone']),
                'is_active' => 1,
                'created_at' => now(),
            ]);

            $therapistId = $pdo->lastInsertId();

            // Add coverage areas
            $this->setCoverageAreas($therapistId, (array) ($data['coverage_areas'] ?? []));

            $pdo->commit();

            return [
                'success' => true,
                'message' => 'Therapist profile created successfully.',
                'therapist_id' => $therapistId,
            ];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            app_error('Failed to register therapist', $e);
            return ['success' => false, 'message' => 'Failed to register therapist profile.'];
        }
    }

    /**
     * Update therapist profile
     */
    public function updateProfile(int $therapistId, int $userId, array $data): array
    {
        $therapist = $this->therapistRepo->findByUserId($userId);

        if (!$therapist || (int) $therapist['id'] !== $therapistId) {
            return ['success' => false, 'message' => 'Unauthorized.'];
        }

        $updates = [];
        $params = ['id' => $therapistId];

        // Only allow certain fields to be updated
        $allowedFields = ['specialization', 'experience_years', 'bio', 'phone'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updates[$field] = $data[$field];
                $params[$field] = $data[$field];
            }
        }

        if (empty($updates)) {
            return ['success' => false, 'message' => 'No valid fields to update.'];
        }

        try {
            $setClause = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($updates)));
            $sql = "UPDATE therapists SET $setClause, updated_at = :updated_at WHERE id = :id";
            $params['updated_at'] = now();

            $stmt = Database::connection()->prepare($sql);
            $stmt->execute($params);

            return ['success' => true, 'message' => 'Profile updated successfully.'];
        } catch (\Throwable $e) {
            app_error('Failed to update therapist profile', $e);
            return ['success' => false, 'message' => 'Failed to update profile.'];
        }
    }

    /**
     * Set therapy specializations/areas for a therapist
     */
    public function setSpecializations(int $therapistId, array $serviceIds): array
    {
        $pdo = Database::connection();

        if (empty($serviceIds)) {
            return ['success' => false, 'message' => 'At least one specialization is required.'];
        }

        try {
            $pdo->beginTransaction();

            // Remove existing specializations
            $delStmt = $pdo->prepare('DELETE FROM therapist_specializations WHERE therapist_id = :therapist_id');
            $delStmt->execute(['therapist_id' => $therapistId]);

            // Add new specializations
            $insStmt = $pdo->prepare('
                INSERT INTO therapist_specializations (therapist_id, service_id, created_at)
                VALUES (:therapist_id, :service_id, :created_at)
            ');

            foreach ($serviceIds as $serviceId) {
                $insStmt->execute([
                    'therapist_id' => $therapistId,
                    'service_id' => (int) $serviceId,
                    'created_at' => now(),
                ]);
            }

            $pdo->commit();

            return ['success' => true, 'message' => 'Specializations updated.'];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            app_error('Failed to update specializations', $e);
            return ['success' => false, 'message' => 'Failed to update specializations.'];
        }
    }

    /**
     * Set coverage areas for a therapist
     */
    public function setCoverageAreas(int $therapistId, array $areaIds): array
    {
        $pdo = Database::connection();

        if (empty($areaIds)) {
            return ['success' => false, 'message' => 'At least one coverage area is required.'];
        }

        try {
            $pdo->beginTransaction();

            // Remove existing coverage
            $delStmt = $pdo->prepare('DELETE FROM therapist_coverage_areas WHERE therapist_id = :therapist_id');
            $delStmt->execute(['therapist_id' => $therapistId]);

            // Add new coverage areas
            $insStmt = $pdo->prepare('
                INSERT INTO therapist_coverage_areas (therapist_id, area_id, created_at)
                VALUES (:therapist_id, :area_id, :created_at)
            ');

            foreach ($areaIds as $areaId) {
                $insStmt->execute([
                    'therapist_id' => $therapistId,
                    'area_id' => (int) $areaId,
                    'created_at' => now(),
                ]);
            }

            $pdo->commit();

            return ['success' => true, 'message' => 'Coverage areas updated.'];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            app_error('Failed to update coverage areas', $e);
            return ['success' => false, 'message' => 'Failed to update coverage areas.'];
        }
    }

    /**
     * Get therapist availability for a date range
     */
    public function getAvailability(int $therapistId, string $date): array
    {
        $pdo = Database::connection();

        // Get therapist's booked times for the day
        $stmt = $pdo->prepare('
            SELECT booking_time
            FROM bookings
            WHERE therapist_id = :therapist_id
            AND booking_date = :booking_date
            AND booking_status IN (:confirmed, :pending)
            ORDER BY booking_time ASC
        ');

        $stmt->execute([
            'therapist_id' => $therapistId,
            'booking_date' => $date,
            'confirmed' => 'confirmed',
            'pending' => 'pending_payment',
        ]);

        $bookedTimes = array_column($stmt->fetchAll(), 'booking_time');

        // Generate available time slots (9 AM to 6 PM, 1-hour slots)
        $available = [];
        for ($hour = 9; $hour < 18; $hour++) {
            $timeSlot = str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00';
            if (!in_array($timeSlot, $bookedTimes)) {
                $available[] = $timeSlot;
            }
        }

        return [
            'therapist_id' => $therapistId,
            'date' => $date,
            'available_slots' => $available,
            'booked_slots' => $bookedTimes,
        ];
    }

    /**
     * Get therapist statistics
     */
    public function getStats(int $therapistId): array
    {
        $pdo = Database::connection();

        $stats = [
            'total_bookings' => 0,
            'completed_bookings' => 0,
            'cancelled_bookings' => 0,
            'total_revenue' => 0,
            'avg_rating' => 0,
            'total_reviews' => 0,
        ];

        // Get booking stats
        $stmt = $pdo->prepare('
            SELECT
                COUNT(id) AS total,
                SUM(CASE WHEN booking_status = :completed THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN booking_status = :cancelled THEN 1 ELSE 0 END) AS cancelled,
                SUM(total_amount) AS revenue
            FROM bookings
            WHERE therapist_id = :therapist_id
        ');

        $stmt->execute([
            'therapist_id' => $therapistId,
            'completed' => 'completed',
            'cancelled' => 'cancelled',
        ]);

        $bookingData = $stmt->fetch();
        if ($bookingData) {
            $stats['total_bookings'] = (int) $bookingData['total'];
            $stats['completed_bookings'] = (int) $bookingData['completed'];
            $stats['cancelled_bookings'] = (int) $bookingData['cancelled'];
            $stats['total_revenue'] = (float) ($bookingData['revenue'] ?? 0);
        }

        // Get review stats
        $reviewStmt = $pdo->prepare('
            SELECT
                AVG(rating) AS avg_rating,
                COUNT(id) AS total_reviews
            FROM reviews
            WHERE therapist_id = :therapist_id
        ');

        $reviewStmt->execute(['therapist_id' => $therapistId]);
        $reviewData = $reviewStmt->fetch();

        if ($reviewData && $reviewData['total_reviews']) {
            $stats['avg_rating'] = round((float) $reviewData['avg_rating'], 2);
            $stats['total_reviews'] = (int) $reviewData['total_reviews'];
        }

        return $stats;
    }

    /**
     * Activate or deactivate therapist account
     */
    public function toggleActive(int $therapistId, bool $isActive, int $adminId): array
    {
        $pdo = Database::connection();

        try {
            $stmt = $pdo->prepare('
                UPDATE therapists
                SET is_active = :is_active, updated_at = :updated_at
                WHERE id = :id
            ');

            $stmt->execute([
                'is_active' => $isActive ? 1 : 0,
                'updated_at' => now(),
                'id' => $therapistId,
            ]);

            // Log admin action
            $logStmt = $pdo->prepare('
                INSERT INTO admin_logs (admin_id, action, target_id, target_type, created_at)
                VALUES (:admin_id, :action, :target_id, :target_type, :created_at)
            ');

            $logStmt->execute([
                'admin_id' => $adminId,
                'action' => $isActive ? 'therapist_activated' : 'therapist_deactivated',
                'target_id' => $therapistId,
                'target_type' => 'therapist',
                'created_at' => now(),
            ]);

            return [
                'success' => true,
                'message' => 'Therapist status updated.',
                'is_active' => $isActive,
            ];
        } catch (\Throwable $e) {
            app_error('Failed to toggle therapist status', $e);
            return ['success' => false, 'message' => 'Failed to update therapist status.'];
        }
    }
}
