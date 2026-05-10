<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\FileCache;

class AdminWriteService
{
    private const ADMIN_READ_CACHE_PREFIX = 'admin.read.';

    public function saveTherapistAreas(array $data, int $adminId, array $context = []): array
    {
        $therapistId = (int) ($data['therapist_id'] ?? 0);
        $rawGroups = (array) ($data['groups'] ?? []);
        $groups = array_values(array_unique(array_filter($rawGroups, fn($g) => in_array($g, ['A', 'B'], true))));

        if ($therapistId <= 0) {
            return $this->result(false, 'Invalid therapist.', 422);
        }

        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM therapists WHERE id = :id LIMIT 1');
        $check->execute(['id' => $therapistId]);
        if (!$check->fetch()) {
            return $this->result(false, 'Therapist not found.', 404);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM therapist_coverage_areas WHERE therapist_id = :tid')->execute(['tid' => $therapistId]);

            if (!empty($groups)) {
                $inList = implode(',', array_fill(0, count($groups), '?'));
                $areaStmt = $pdo->prepare("SELECT id FROM coverage_areas WHERE coverage_group IN ($inList) AND is_active = 1");
                $areaStmt->execute($groups);
                $areaIds = $areaStmt->fetchAll(\PDO::FETCH_COLUMN);

                $insert = $pdo->prepare('INSERT INTO therapist_coverage_areas (therapist_id, area_id) VALUES (:tid, :aid)');
                foreach ($areaIds as $areaId) {
                    $insert->execute(['tid' => $therapistId, 'aid' => (int) $areaId]);
                }
            }

            $pdo->commit();
            $this->clearAdminReadCache();

            $label = match (implode('', $groups)) {
                'A' => 'Group A',
                'B' => 'Group B',
                'AB' => 'Both Groups (A + B)',
                default => 'None',
            };

            $this->auditLog($adminId, 'therapist_coverage_updated', [
                'therapist_id' => $therapistId,
                'groups' => $groups,
                'label' => $label,
            ], $therapistId, 'therapist', $context);

            return $this->result(true, "Coverage set to: $label.");
        } catch (\Throwable $e) {
            $pdo->rollBack();
            app_log('Admin save therapist areas error: ' . $e->getMessage());
            return $this->result(false, 'Failed to update coverage areas.', 500);
        }
    }

    public function saveTherapist(array $data, int $adminId, array $context = []): array
    {
        $id = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $phone = trim((string) ($data['phone'] ?? ''));
        $specialty = trim((string) ($data['specialty'] ?? ''));
        $experience = (int) ($data['experience_years'] ?? 0);
        $rating = (float) ($data['rating'] ?? 5);
        $isActive = isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1;
        $password = (string) ($data['password'] ?? '');

        if ($name === '' || $email === '') {
            return $this->result(false, 'Name and email are required.', 422);
        }

        $pdo = Database::connection();

        if ($id > 0) {
            $therapist = $pdo->prepare('SELECT id, user_id FROM therapists WHERE id = :id LIMIT 1');
            $therapist->execute(['id' => $id]);
            $current = $therapist->fetch();

            if (!$current) {
                return $this->result(false, 'Therapist not found.', 404);
            }

            $duplicate = $pdo->prepare('SELECT id FROM users WHERE email = :email AND id != :user_id LIMIT 1');
            $duplicate->execute(['email' => $email, 'user_id' => (int) $current['user_id']]);
            if ($duplicate->fetch()) {
                return $this->result(false, 'Email already used by another account.', 409);
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare('UPDATE users SET name = :name, email = :email, phone = :phone, role = :role, status = :status, updated_at = :updated_at WHERE id = :id')->execute([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'role' => 'therapist',
                    'status' => $isActive ? 'active' : 'inactive',
                    'updated_at' => now(),
                    'id' => (int) $current['user_id'],
                ]);

                if ($password !== '') {
                    $pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id')->execute([
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'updated_at' => now(),
                        'id' => (int) $current['user_id'],
                    ]);
                }

                $pdo->prepare('UPDATE therapists SET specialty = :specialty, experience_years = :experience_years, rating = :rating, is_active = :is_active, updated_at = :updated_at WHERE id = :id')->execute([
                    'specialty' => $specialty,
                    'experience_years' => $experience,
                    'rating' => $rating,
                    'is_active' => $isActive,
                    'updated_at' => now(),
                    'id' => $id,
                ]);

                $pdo->commit();
                $this->clearAdminReadCache();

                $this->auditLog($adminId, 'therapist_updated', [
                    'therapist_id' => $id,
                    'user_id' => (int) $current['user_id'],
                    'email' => $email,
                    'is_active' => $isActive,
                ], $id, 'therapist', $context);

                return $this->result(true, 'Therapist updated successfully.');
            } catch (\Throwable $e) {
                $pdo->rollBack();
                app_log('Admin update therapist error: ' . $e->getMessage());
                return $this->result(false, 'Failed to update therapist.', 500);
            }
        }

        if ($password === '') {
            return $this->result(false, 'Password is required for new therapist.', 422);
        }

        $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $check->execute(['email' => $email]);
        if ($check->fetch()) {
            return $this->result(false, 'Email already exists.', 409);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO users (name, email, password_hash, role, phone, status, created_at, updated_at) VALUES (:name, :email, :password_hash, :role, :phone, :status, :created_at, :updated_at)')->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'role' => 'therapist',
                'phone' => $phone,
                'status' => $isActive ? 'active' : 'inactive',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $userId = (int) $pdo->lastInsertId();

            $pdo->prepare('INSERT INTO therapists (user_id, specialty, experience_years, rating, is_active, created_at, updated_at) VALUES (:user_id, :specialty, :experience_years, :rating, :is_active, :created_at, :updated_at)')->execute([
                'user_id' => $userId,
                'specialty' => $specialty,
                'experience_years' => $experience,
                'rating' => $rating,
                'is_active' => $isActive,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $pdo->commit();
            $this->clearAdminReadCache();

            $newTherapistId = (int) $pdo->lastInsertId();
            $this->auditLog($adminId, 'therapist_created', [
                'therapist_id' => $newTherapistId,
                'user_id' => $userId,
                'email' => $email,
                'is_active' => $isActive,
            ], $newTherapistId, 'therapist', $context);

            return $this->result(true, 'Therapist created successfully.', 201);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            app_log('Admin create therapist error: ' . $e->getMessage());
            return $this->result(false, 'Failed to create therapist.', 500);
        }
    }

    public function saveService(array $data, int $adminId, array $context = []): array
    {
        $id = (int) ($data['id'] ?? 0);

        $payload = [
            'category_id' => (int) ($data['category_id'] ?? 0),
            'name' => trim((string) ($data['name'] ?? '')),
            'description' => trim((string) ($data['description'] ?? '')),
            'duration_minutes' => (int) ($data['duration_minutes'] ?? 60),
            'price' => (float) ($data['price'] ?? 0),
            'is_addon' => isset($data['is_addon']) ? (int) (bool) $data['is_addon'] : 0,
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];

        if ($payload['category_id'] <= 0 || $payload['name'] === '' || $payload['price'] <= 0) {
            return $this->result(false, 'Category, name, and price are required.', 422);
        }

        $pdo = Database::connection();
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE services SET category_id = :category_id, name = :name, description = :description, duration_minutes = :duration_minutes, price = :price, is_addon = :is_addon, is_active = :is_active, sort_order = :sort_order, updated_at = :updated_at WHERE id = :id');
            $stmt->execute(array_merge($payload, ['updated_at' => now(), 'id' => $id]));
            $this->clearAdminReadCache();

            $this->auditLog($adminId, 'service_updated', [
                'service_id' => $id,
                'category_id' => $payload['category_id'],
                'price' => $payload['price'],
                'is_active' => $payload['is_active'],
            ], $id, 'service', $context);

            return $this->result(true, 'Service updated successfully.');
        }

        $stmt = $pdo->prepare('INSERT INTO services (category_id, name, description, duration_minutes, price, is_addon, sort_order, is_active, created_at, updated_at) VALUES (:category_id, :name, :description, :duration_minutes, :price, :is_addon, :sort_order, :is_active, :created_at, :updated_at)');
        $stmt->execute(array_merge($payload, ['created_at' => now(), 'updated_at' => now()]));
        $this->clearAdminReadCache();

        $newServiceId = (int) $pdo->lastInsertId();
        $this->auditLog($adminId, 'service_created', [
            'service_id' => $newServiceId,
            'category_id' => $payload['category_id'],
            'price' => $payload['price'],
            'is_active' => $payload['is_active'],
        ], $newServiceId, 'service', $context);

        return $this->result(true, 'Service created successfully.', 201);
    }

    public function saveArea(array $data, int $adminId, array $context = []): array
    {
        $id = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        $coverageGroup = strtoupper(trim((string) ($data['coverage_group'] ?? 'A')));
        $isActive = isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1;

        if ($name === '' || !in_array($coverageGroup, ['A', 'B'], true)) {
            return $this->result(false, 'Valid area name and coverage group are required.', 422);
        }

        $pdo = Database::connection();
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE coverage_areas SET name = :name, coverage_group = :coverage_group, is_active = :is_active, updated_at = :updated_at WHERE id = :id');
            $stmt->execute([
                'name' => $name,
                'coverage_group' => $coverageGroup,
                'is_active' => $isActive,
                'updated_at' => now(),
                'id' => $id,
            ]);
            $this->clearAdminReadCache();
            $this->auditLog($adminId, 'area_updated', [
                'area_id' => $id,
                'coverage_group' => $coverageGroup,
                'is_active' => $isActive,
            ], $id, 'coverage_area', $context);
            return $this->result(true, 'Coverage area updated successfully.');
        }

        $stmt = $pdo->prepare('INSERT INTO coverage_areas (name, coverage_group, is_active, created_at, updated_at) VALUES (:name, :coverage_group, :is_active, :created_at, :updated_at)');
        $stmt->execute([
            'name' => $name,
            'coverage_group' => $coverageGroup,
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->clearAdminReadCache();

        $newAreaId = (int) $pdo->lastInsertId();
        $this->auditLog($adminId, 'area_created', [
            'area_id' => $newAreaId,
            'coverage_group' => $coverageGroup,
            'is_active' => $isActive,
        ], $newAreaId, 'coverage_area', $context);

        return $this->result(true, 'Coverage area created successfully.', 201);
    }

    public function confirmPayment(array $data, int $adminId, array $context = []): array
    {
        $paymentId = (int) ($data['payment_id'] ?? 0);
        $target = strtolower(trim((string) ($data['target_status'] ?? '')));

        if ($paymentId <= 0 || !in_array($target, ['paid', 'pending'], true)) {
            return $this->result(false, 'Invalid payment update request.', 422);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, booking_id, provider FROM payments WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $paymentId]);
        $payment = $stmt->fetch();

        if (!$payment) {
            return $this->result(false, 'Payment not found.', 404);
        }

        if ((string) $payment['provider'] !== 'bank_transfer') {
            return $this->result(false, 'Only bank transfer can be manually confirmed.', 422);
        }

        $paymentStatus = $target === 'paid' ? 'succeeded' : 'pending';
        $bookingPaymentStatus = $target === 'paid' ? 'paid' : 'pending';
        $bookingStatus = $target === 'paid' ? 'confirmed' : 'pending_payment';

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE payments SET status = :status, updated_at = :updated_at WHERE id = :id')->execute([
                'status' => $paymentStatus,
                'updated_at' => now(),
                'id' => (int) $payment['id'],
            ]);

            $pdo->prepare('UPDATE bookings SET payment_status = :payment_status, booking_status = :booking_status, updated_at = :updated_at WHERE id = :id')->execute([
                'payment_status' => $bookingPaymentStatus,
                'booking_status' => $bookingStatus,
                'updated_at' => now(),
                'id' => (int) $payment['booking_id'],
            ]);

            $pdo->commit();
            $this->clearAdminReadCache();

            $this->auditLog($adminId, 'payment_status_updated', [
                'payment_id' => (int) $payment['id'],
                'booking_id' => (int) $payment['booking_id'],
                'target_status' => $target,
            ], (int) $payment['id'], 'payment', $context);

            return $this->result(true, $target === 'paid' ? 'Bank transfer marked as paid.' : 'Bank transfer marked as pending.');
        } catch (\Throwable $e) {
            $pdo->rollBack();
            app_log('Admin confirm payment error: ' . $e->getMessage());
            return $this->result(false, 'Failed to update payment status.', 500);
        }
    }

    public function cancelBooking(array $data, int $adminId, array $context = []): array
    {
        $bookingId = (int) ($data['booking_id'] ?? 0);

        if ($bookingId <= 0) {
            return $this->result(false, 'Invalid booking ID.', 422);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, booking_status FROM bookings WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $bookingId]);
        $booking = $stmt->fetch();

        if (!$booking) {
            return $this->result(false, 'Booking not found.', 404);
        }

        if ((string) $booking['booking_status'] === 'cancelled') {
            return $this->result(false, 'Booking is already cancelled.', 422);
        }

        $pdo->prepare('UPDATE bookings SET booking_status = :status, updated_at = :updated_at WHERE id = :id')
            ->execute(['status' => 'cancelled', 'updated_at' => now(), 'id' => $bookingId]);

        $this->clearAdminReadCache();

        $this->auditLog($adminId, 'booking_cancelled', [
            'booking_id' => $bookingId,
            'previous_status' => (string) $booking['booking_status'],
        ], $bookingId, 'booking', $context);

        return $this->result(true, 'Booking cancelled. If paid via bank transfer, please process a manual refund.');
    }

    private function auditLog(int $adminId, string $action, array $details = [], int $targetId = 0, string $targetType = '', array $context = []): void
    {
        try {
            $pdo = Database::connection();

            $payload = array_merge($details, [
                'source_ip' => (string) ($context['ip'] ?? ''),
                'user_agent' => (string) ($context['user_agent'] ?? ''),
            ]);

            try {
                $stmt = $pdo->prepare('INSERT INTO admin_logs (admin_id, action, target_id, target_type, details, created_at) VALUES (:admin_id, :action, :target_id, :target_type, :details, :created_at)');
                $stmt->execute([
                    'admin_id' => $adminId,
                    'action' => $action,
                    'target_id' => $targetId,
                    'target_type' => $targetType,
                    'details' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                ]);
            } catch (\Throwable) {
                // Fallback for deployments with older admin_logs schema.
                $stmt = $pdo->prepare('INSERT INTO admin_logs (admin_id, action, details, created_at) VALUES (:admin_id, :action, :details, :created_at)');
                $stmt->execute([
                    'admin_id' => $adminId,
                    'action' => $action,
                    'details' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            app_error('Failed to write admin audit log', $e);
        }
    }

    private function clearAdminReadCache(): void
    {
        (new FileCache())->forgetByPrefix(self::ADMIN_READ_CACHE_PREFIX);
    }

    private function result(bool $success, string $message, int $status = 200): array
    {
        return [
            'success' => $success,
            'message' => $message,
            'status' => $status,
        ];
    }
}