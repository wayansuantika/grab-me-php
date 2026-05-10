<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Validator;

class TherapistPanelController
{
    private function therapistIdByUser(int $userId): ?int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM therapists WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ? (int) $row['id'] : null;
    }

    public function dashboard(): void
    {
        Auth::requireRole('therapist');

        $therapistId = $this->therapistIdByUser((int) Auth::id());
        if ($therapistId === null) {
            json_response(['success' => false, 'message' => 'Therapist profile not found.'], 404);
        }

        $pdo = Database::connection();
        $statsStmt = $pdo->prepare('
            SELECT
                COUNT(*) AS total_bookings,
                SUM(CASE WHEN booking_status = "confirmed" THEN 1 ELSE 0 END) AS confirmed_bookings,
                SUM(CASE WHEN booking_status = "completed" THEN 1 ELSE 0 END) AS completed_bookings
            FROM bookings
            WHERE therapist_id = :therapist_id
        ');
        $statsStmt->execute(['therapist_id' => $therapistId]);

        $upcomingStmt = $pdo->prepare('
                        SELECT b.id, b.booking_code, b.booking_date, b.booking_time, b.booking_status, b.customer_name
            FROM bookings b
            WHERE b.therapist_id = :therapist_id
              AND b.booking_date >= CURDATE()
            ORDER BY b.booking_date ASC, b.booking_time ASC
            LIMIT 10
        ');
        $upcomingStmt->execute(['therapist_id' => $therapistId]);

        json_response([
            'success' => true,
            'data' => [
                'stats' => $statsStmt->fetch(),
                'upcoming' => $upcomingStmt->fetchAll(),
            ],
        ]);
    }

    public function bookings(): void
    {
        Auth::requireRole('therapist');

        $therapistId = $this->therapistIdByUser((int) Auth::id());
        if ($therapistId === null) {
            json_response(['success' => false, 'message' => 'Therapist profile not found.'], 404);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            SELECT b.id, b.booking_code, b.booking_date, b.booking_time, b.customer_name, b.customer_phone,
                   b.customer_address, b.total_amount, b.payment_status, b.booking_status, b.notes,
                   (
                       SELECT GROUP_CONCAT(CONCAT(s.name, " x", bi.qty) ORDER BY bi.id SEPARATOR "\n")
                       FROM booking_items bi
                       INNER JOIN services s ON s.id = bi.service_id
                       WHERE bi.booking_id = b.id
                   ) AS order_details
            FROM bookings b
            WHERE b.therapist_id = :therapist_id
            ORDER BY b.booking_date DESC, b.booking_time DESC
            LIMIT 200
        ');
        $stmt->execute(['therapist_id' => $therapistId]);

        json_response(['success' => true, 'data' => ['bookings' => $stmt->fetchAll()]]);
    }

    public function profile(): void
    {
        Auth::requireRole('therapist');

        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            SELECT t.id, t.user_id, t.bio, t.specialty, t.experience_years, t.rating, t.photo_url, t.is_active,
                   u.name, u.email, u.phone
            FROM therapists t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.user_id = :user_id
            LIMIT 1
        ');
        $stmt->execute(['user_id' => (int) Auth::id()]);
        $profile = $stmt->fetch();

        if (!$profile) {
            json_response(['success' => false, 'message' => 'Therapist profile not found.'], 404);
        }

        json_response(['success' => true, 'data' => ['profile' => $profile]]);
    }

    public function updateProfile(): void
    {
        Auth::requireRole('therapist');

        if (!verify_csrf()) {
            json_response(['success' => false, 'message' => 'CSRF validation failed.'], 419);
        }

        $data = request_json();
        $name = trim((string) ($data['name'] ?? ''));
        $phone = trim((string) ($data['phone'] ?? ''));
        $bio = trim((string) ($data['bio'] ?? ''));
        $specialty = trim((string) ($data['specialty'] ?? ''));
        $experienceYears = (int) ($data['experience_years'] ?? 0);

        if ($name === '') {
            json_response(['success' => false, 'message' => 'Name is required.'], 422);
        }

        $therapistId = $this->therapistIdByUser((int) Auth::id());
        if ($therapistId === null) {
            json_response(['success' => false, 'message' => 'Therapist profile not found.'], 404);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('UPDATE users SET name = :name, phone = :phone, updated_at = :updated_at WHERE id = :id')->execute([
                'name' => $name,
                'phone' => $phone,
                'updated_at' => now(),
                'id' => (int) Auth::id(),
            ]);

            $pdo->prepare('UPDATE therapists SET bio = :bio, specialty = :specialty, experience_years = :experience_years, updated_at = :updated_at WHERE id = :id')->execute([
                'bio' => $bio,
                'specialty' => $specialty,
                'experience_years' => $experienceYears,
                'updated_at' => now(),
                'id' => $therapistId,
            ]);

            $pdo->commit();
            json_response(['success' => true, 'message' => 'Profile updated successfully.']);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            app_log('Therapist update profile error: ' . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to update profile.'], 500);
        }
    }

    public function setAvailability(): void
    {
        Auth::requireRole('therapist');

        if (!verify_csrf()) {
            json_response(['success' => false, 'message' => 'CSRF validation failed.'], 419);
        }

        $data = request_json();
        $isActive = isset($data['is_active']) ? (int) (bool) $data['is_active'] : null;
        if ($isActive === null) {
            json_response(['success' => false, 'message' => 'Availability value is required.'], 422);
        }

        $therapistId = $this->therapistIdByUser((int) Auth::id());
        if ($therapistId === null) {
            json_response(['success' => false, 'message' => 'Therapist profile not found.'], 404);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('UPDATE therapists SET is_active = :is_active, updated_at = :updated_at WHERE id = :id')->execute([
                'is_active' => $isActive,
                'updated_at' => now(),
                'id' => $therapistId,
            ]);

            $pdo->prepare('UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :id')->execute([
                'status' => $isActive ? 'active' : 'inactive',
                'updated_at' => now(),
                'id' => (int) Auth::id(),
            ]);

            $pdo->commit();
            json_response(['success' => true, 'message' => $isActive ? 'Availability turned on.' : 'Availability turned off.']);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            app_log('Therapist availability toggle error: ' . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to update availability.'], 500);
        }
    }

    public function updateSchedule(): void
    {
        Auth::requireRole('therapist');

        if (!verify_csrf()) {
            json_response(['success' => false, 'message' => 'CSRF validation failed.'], 419);
        }

        $data = request_json();
        $schedules = (array) ($data['schedules'] ?? []);

        $therapistId = $this->therapistIdByUser((int) Auth::id());
        if ($therapistId === null) {
            json_response(['success' => false, 'message' => 'Therapist profile not found.'], 404);
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('DELETE FROM therapist_schedules WHERE therapist_id = :therapist_id')->execute(['therapist_id' => $therapistId]);

            $insert = $pdo->prepare('
                INSERT INTO therapist_schedules (therapist_id, day_of_week, start_time, end_time, is_available, created_at, updated_at)
                VALUES (:therapist_id, :day_of_week, :start_time, :end_time, :is_available, :created_at, :updated_at)
            ');

            foreach ($schedules as $schedule) {
                $insert->execute([
                    'therapist_id' => $therapistId,
                    'day_of_week' => (int) ($schedule['day_of_week'] ?? 0),
                    'start_time' => (string) ($schedule['start_time'] ?? '09:00:00'),
                    'end_time' => (string) ($schedule['end_time'] ?? '18:00:00'),
                    'is_available' => isset($schedule['is_available']) ? (int) (bool) $schedule['is_available'] : 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $pdo->commit();
            json_response(['success' => true, 'message' => 'Schedule updated.']);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            app_log('Update therapist schedule error: ' . $e->getMessage());
            json_response(['success' => false, 'message' => 'Failed to update schedule.'], 500);
        }
    }

    public function updateProfilePhoto(): void
    {
        Auth::requireRole('therapist');

        if (!verify_csrf()) {
            json_response(['success' => false, 'message' => 'CSRF validation failed.'], 419);
        }

        if (!isset($_FILES['photo'])) {
            json_response(['success' => false, 'message' => 'Photo is required.'], 422);
        }

        $validation = Validator::validateUpload($_FILES['photo'], ['image/jpeg', 'image/png', 'image/webp'], 2 * 1024 * 1024);
        if (!$validation['valid']) {
            json_response(['success' => false, 'message' => $validation['message']], 422);
        }

        $therapistId = $this->therapistIdByUser((int) Auth::id());
        if ($therapistId === null) {
            json_response(['success' => false, 'message' => 'Therapist profile not found.'], 404);
        }

        $extension = pathinfo((string) $_FILES['photo']['name'], PATHINFO_EXTENSION);
        $safeName = 'therapist_' . $therapistId . '_' . time() . '.' . strtolower($extension ?: 'jpg');
        $targetPath = base_path('public/uploads/' . $safeName);

        if (!move_uploaded_file($_FILES['photo']['tmp_name'], $targetPath)) {
            json_response(['success' => false, 'message' => 'Unable to save uploaded file.'], 500);
        }

        $publicUrl = '/uploads/' . $safeName;
        $pdo = Database::connection();
        $pdo->prepare('UPDATE therapists SET photo_url = :photo_url, updated_at = :updated_at WHERE id = :id')->execute([
            'photo_url' => $publicUrl,
            'updated_at' => now(),
            'id' => $therapistId,
        ]);

        json_response(['success' => true, 'message' => 'Profile photo updated.', 'data' => ['photo_url' => $publicUrl]]);
    }
}
