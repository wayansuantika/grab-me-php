<?php

declare(strict_types=1);

namespace App\Core;

class BookingRepository extends Repository
{
    protected string $table = 'bookings';

    /**
     * Get booking with full details (joined with related data)
     */
    public function findWithDetails(int $bookingId): ?array
    {
        $sql = "
            SELECT b.*,
                   u.name AS therapist_name, u.email AS therapist_email,
                   ca.name AS area_name,
                   (SELECT GROUP_CONCAT(CONCAT(s.name, ' x', bi.qty) ORDER BY bi.id SEPARATOR '\\n')
                    FROM booking_items bi
                    INNER JOIN services s ON s.id = bi.service_id
                    WHERE bi.booking_id = b.id) AS order_details,
                   COALESCE((SELECT p.provider FROM payments p WHERE p.booking_id = b.id LIMIT 1), 'bank_transfer') AS payment_method,
                   (SELECT p.id FROM payments p WHERE p.booking_id = b.id LIMIT 1) AS payment_id
            FROM {$this->table} b
            INNER JOIN therapists t ON t.id = b.therapist_id
            INNER JOIN users u ON u.id = t.user_id
            INNER JOIN coverage_areas ca ON ca.id = b.area_id
            WHERE b.id = :booking_id
            LIMIT 1
        ";
        return $this->queryOne($sql, ['booking_id' => $bookingId]);
    }

    /**
     * Get bookings by therapist with pagination
     */
    public function byTherapist(int $therapistId, int $limit = 50, int $offset = 0): array
    {
        $sql = "
            SELECT b.*,
                   (SELECT GROUP_CONCAT(CONCAT(s.name, ' x', bi.qty) ORDER BY bi.id SEPARATOR '\\n')
                    FROM booking_items bi
                    INNER JOIN services s ON s.id = bi.service_id
                    WHERE bi.booking_id = b.id) AS order_details
            FROM {$this->table} b
            WHERE b.therapist_id = :therapist_id
            ORDER BY b.booking_date DESC, b.booking_time DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['therapist_id' => $therapistId, 'limit' => $limit, 'offset' => $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Count bookings by therapist
     */
    public function countByTherapist(int $therapistId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) AS total FROM {$this->table} WHERE therapist_id = :therapist_id");
        $stmt->execute(['therapist_id' => $therapistId]);
        return (int) $stmt->fetch()['total'];
    }

    /**
     * Get upcoming bookings for therapist
     */
    public function upcomingForTherapist(int $therapistId, int $limit = 10): array
    {
        $sql = "
            SELECT b.id, b.booking_code, b.booking_date, b.booking_time, b.booking_status, b.customer_name, b.customer_phone
            FROM {$this->table} b
            WHERE b.therapist_id = :therapist_id
              AND b.booking_date >= CURDATE()
            ORDER BY b.booking_date ASC, b.booking_time ASC
            LIMIT :limit
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['therapist_id' => $therapistId, 'limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get bookings by customer
     */
    public function byCustomer(int $userId, int $limit = 50, int $offset = 0): array
    {
        $sql = "
            SELECT b.*,
                   (SELECT GROUP_CONCAT(CONCAT(s.name, ' x', bi.qty) ORDER BY bi.id SEPARATOR '\\n')
                    FROM booking_items bi
                    INNER JOIN services s ON s.id = bi.service_id
                    WHERE bi.booking_id = b.id) AS order_details
            FROM {$this->table} b
            WHERE b.user_id = :user_id
            ORDER BY b.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId, 'limit' => $limit, 'offset' => $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Count bookings by customer
     */
    public function countByCustomer(int $userId): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) AS total FROM {$this->table} WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetch()['total'];
    }

    /**
     * Get booking statistics
     */
    public function getStats(): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid,
                SUM(CASE WHEN booking_status = 'completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN booking_status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
                SUM(CASE WHEN booking_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                SUM(total_amount) AS total_revenue
            FROM {$this->table}
        ";
        return $this->queryOne($sql) ?: [];
    }

    /**
     * Get recent bookings for admin dashboard
     */
    public function recent(int $limit = 10): array
    {
        $sql = "
            SELECT b.id, b.booking_code, b.booking_date, b.total_amount, b.payment_status, b.booking_status, b.customer_name
            FROM {$this->table} b
            ORDER BY b.created_at DESC
            LIMIT :limit
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['limit' => $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get daily booking summary for the last N days.
     */
    public function dailySummary(int $days = 7): array
    {
        $days = max(1, $days);

        $sql = "
            SELECT
                DATE(created_at) AS summary_date,
                COUNT(*) AS bookings,
                SUM(total_amount) AS revenue,
                SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid_bookings
            FROM {$this->table}
            WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :daysMinusOne DAY)
            GROUP BY DATE(created_at)
            ORDER BY summary_date ASC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['daysMinusOne' => $days - 1]);
        $rows = $stmt->fetchAll();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(string) $row['summary_date']] = $row;
        }

        $summary = [];
        $start = new \DateTimeImmutable('-' . ($days - 1) . ' days');
        for ($offset = 0; $offset < $days; $offset++) {
            $date = $start->modify('+' . $offset . ' days');
            $key = $date->format('Y-m-d');
            $row = $indexed[$key] ?? null;

            $summary[] = [
                'date' => $key,
                'label' => $date->format('D'),
                'bookings' => (int) ($row['bookings'] ?? 0),
                'paid_bookings' => (int) ($row['paid_bookings'] ?? 0),
                'revenue' => (float) ($row['revenue'] ?? 0),
            ];
        }

        return $summary;
    }

    /**
     * Get all bookings for admin list view with detailed info
     */
    public function adminList(int $limit = 50, int $offset = 0): array
    {
        $sql = "
            SELECT
                b.id, b.booking_code, b.booking_date, b.booking_time, b.total_amount, b.payment_status, b.booking_status,
                b.customer_name, b.customer_phone, b.customer_address, b.notes,
                (SELECT GROUP_CONCAT(CONCAT(s.name, ' x', bi.qty) ORDER BY bi.id SEPARATOR '\n')
                 FROM booking_items bi
                 INNER JOIN services s ON s.id = bi.service_id
                 WHERE bi.booking_id = b.id) AS order_details,
                COALESCE((SELECT p.provider FROM payments p WHERE p.booking_id = b.id LIMIT 1), 'bank_transfer') AS payment_method,
                (SELECT p.id FROM payments p WHERE p.booking_id = b.id LIMIT 1) AS payment_id,
                u.name AS therapist_name, ca.name AS area_name
            FROM {$this->table} b
            INNER JOIN therapists t ON t.id = b.therapist_id
            INNER JOIN users u ON u.id = t.user_id
            INNER JOIN coverage_areas ca ON ca.id = b.area_id
            ORDER BY b.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['limit' => $limit, 'offset' => $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Cancel a booking
     */
    public function cancel(int $bookingId, string $cancellationReason = ''): bool
    {
        $sql = "
            UPDATE {$this->table}
            SET booking_status = 'cancelled',
                cancellation_reason = :reason,
                updated_at = :updated_at
            WHERE id = :id
              AND booking_status IN ('pending_payment', 'confirmed')
        ";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            'reason' => $cancellationReason,
            'updated_at' => now(),
            'id' => $bookingId,
        ]);
    }

    /**
     * Generate unique booking code
     */
    public function generateBookingCode(): string
    {
        $prefix = 'BK';
        $timestamp = substr(time(), -6);
        $random = str_pad(random_int(1, 999), 3, '0', STR_PAD_LEFT);
        return $prefix . $timestamp . $random;
    }
}
