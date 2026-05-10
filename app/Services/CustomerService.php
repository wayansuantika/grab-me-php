<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\BookingRepository;

class CustomerService
{
    private BookingRepository $bookingRepo;

    public function __construct()
    {
        $this->bookingRepo = new BookingRepository();
    }

    /**
     * Get complete customer dashboard data
     */
    public function getDashboardData(int $userId): array
    {
        $pdo = Database::connection();

        // Get customer info
        $userStmt = $pdo->prepare('SELECT id, name, email, phone, created_at FROM users WHERE id = :id');
        $userStmt->execute(['id' => $userId]);
        $customer = $userStmt->fetch();

        if (!$customer) {
            return ['success' => false, 'message' => 'Customer not found.'];
        }

        // Get booking statistics
        $bookings = $this->bookingRepo->byCustomer($userId, 500, 0);

        $stats = [
            'total_bookings' => 0,
            'upcoming_bookings' => 0,
            'completed_bookings' => 0,
            'cancelled_bookings' => 0,
            'total_spent' => 0,
            'average_booking_value' => 0,
        ];

        $now = date('Y-m-d H:i:s');

        foreach ($bookings as $booking) {
            $stats['total_bookings']++;
            $stats['total_spent'] += (float) $booking['total_amount'];

            $bookingDateTime = $booking['booking_date'] . ' ' . $booking['booking_time'];

            switch ($booking['booking_status']) {
                case 'confirmed':
                    if ($bookingDateTime > $now) {
                        $stats['upcoming_bookings']++;
                    }
                    break;
                case 'completed':
                    $stats['completed_bookings']++;
                    break;
                case 'cancelled':
                    $stats['cancelled_bookings']++;
                    break;
            }
        }

        if ($stats['total_bookings'] > 0) {
            $stats['average_booking_value'] = round($stats['total_spent'] / $stats['total_bookings'], 2);
        }

        // Get recent bookings (last 5)
        $recentBookings = array_slice($this->bookingRepo->recent($userId, 5), 0, 5);

        // Get favorite therapists
        $favoriteStmt = $pdo->prepare('
            SELECT therapist_id, COUNT(id) AS booking_count
            FROM bookings
            WHERE user_id = :user_id AND therapist_id IS NOT NULL
            GROUP BY therapist_id
            ORDER BY booking_count DESC
            LIMIT 3
        ');

        $favoriteStmt->execute(['user_id' => $userId]);
        $favoriteTherapists = $favoriteStmt->fetchAll();

        return [
            'success' => true,
            'customer' => $customer,
            'stats' => $stats,
            'recent_bookings' => $recentBookings,
            'favorite_therapists' => $favoriteTherapists,
        ];
    }

    /**
     * Get customer booking history with pagination
     */
    public function getBookingHistory(int $userId, int $page = 1, int $perPage = 10): array
    {
        try {
            $bookings = $this->bookingRepo->byCustomer($userId, 10000, 0);
            $total = count($bookings);

            $pagination = new \App\Core\Pagination($total, $perPage, $page);
            $offset = $pagination->getOffset();
            $limit = $pagination->getLimit();

            $paginatedBookings = array_slice($bookings, $offset, $limit);

            return [
                'success' => true,
                'bookings' => $paginatedBookings,
                'pagination' => $pagination->getMetadata(),
            ];
        } catch (\Throwable $e) {
            app_error('Failed to get booking history', $e);
            return ['success' => false, 'message' => 'Failed to retrieve booking history.'];
        }
    }

    /**
     * Get customer reviews written
     */
    public function getReviews(int $userId): array
    {
        $pdo = Database::connection();

        try {
            $stmt = $pdo->prepare('
                SELECT
                    r.id,
                    r.booking_id,
                    r.therapist_id,
                    r.rating,
                    r.comment,
                    r.created_at,
                    t.name AS therapist_name
                FROM reviews r
                LEFT JOIN users t ON r.therapist_id = t.id
                WHERE r.user_id = :user_id
                ORDER BY r.created_at DESC
            ');

            $stmt->execute(['user_id' => $userId]);
            return ['success' => true, 'reviews' => $stmt->fetchAll()];
        } catch (\Throwable $e) {
            app_error('Failed to get reviews', $e);
            return ['success' => false, 'message' => 'Failed to retrieve reviews.'];
        }
    }

    /**
     * Add a review for a completed booking
     */
    public function addReview(int $userId, int $bookingId, int $therapistId, int $rating, string $comment): array
    {
        $pdo = Database::connection();

        // Validate rating
        if ($rating < 1 || $rating > 5) {
            return ['success' => false, 'message' => 'Rating must be between 1 and 5.'];
        }

        // Verify booking belongs to customer and is completed
        $stmt = $pdo->prepare('
            SELECT id FROM bookings
            WHERE id = :id AND user_id = :user_id AND booking_status = :status
        ');

        $stmt->execute([
            'id' => $bookingId,
            'user_id' => $userId,
            'status' => 'completed',
        ]);

        if (!$stmt->fetch()) {
            return ['success' => false, 'message' => 'You can only review completed bookings.'];
        }

        // Check if review already exists
        $checkStmt = $pdo->prepare('
            SELECT id FROM reviews
            WHERE booking_id = :booking_id AND user_id = :user_id
        ');

        $checkStmt->execute(['booking_id' => $bookingId, 'user_id' => $userId]);

        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'You have already reviewed this booking.'];
        }

        try {
            $insStmt = $pdo->prepare('
                INSERT INTO reviews (user_id, booking_id, therapist_id, rating, comment, created_at)
                VALUES (:user_id, :booking_id, :therapist_id, :rating, :comment, :created_at)
            ');

            $insStmt->execute([
                'user_id' => $userId,
                'booking_id' => $bookingId,
                'therapist_id' => $therapistId,
                'rating' => $rating,
                'comment' => sanitize_string($comment),
                'created_at' => now(),
            ]);

            return [
                'success' => true,
                'message' => 'Thank you for your review!',
                'review_id' => $pdo->lastInsertId(),
            ];
        } catch (\Throwable $e) {
            app_error('Failed to add review', $e);
            return ['success' => false, 'message' => 'Failed to save review.'];
        }
    }

    /**
     * Get saved/favorite therapists
     */
    public function getFavorites(int $userId): array
    {
        $pdo = Database::connection();

        try {
            $stmt = $pdo->prepare('
                SELECT
                    t.id,
                    u.name,
                    u.email,
                    t.specialization,
                    t.bio,
                    t.phone,
                    (SELECT AVG(rating) FROM reviews WHERE therapist_id = t.id) AS avg_rating,
                    (SELECT COUNT(id) FROM bookings WHERE user_id = :user_id AND therapist_id = t.id) AS booking_count
                FROM user_favorites f
                JOIN therapists t ON f.therapist_id = t.id
                JOIN users u ON t.user_id = u.id
                WHERE f.user_id = :user_id
                ORDER BY f.created_at DESC
            ');

            $stmt->execute(['user_id' => $userId]);

            return ['success' => true, 'favorites' => $stmt->fetchAll()];
        } catch (\Throwable $e) {
            app_error('Failed to get favorites', $e);
            return ['success' => false, 'message' => 'Failed to retrieve favorites.'];
        }
    }

    /**
     * Add therapist to favorites
     */
    public function addFavorite(int $userId, int $therapistId): array
    {
        $pdo = Database::connection();

        // Check if already favorited
        $checkStmt = $pdo->prepare('
            SELECT id FROM user_favorites
            WHERE user_id = :user_id AND therapist_id = :therapist_id
        ');

        $checkStmt->execute(['user_id' => $userId, 'therapist_id' => $therapistId]);

        if ($checkStmt->fetch()) {
            return ['success' => false, 'message' => 'Already in favorites.'];
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO user_favorites (user_id, therapist_id, created_at)
                VALUES (:user_id, :therapist_id, :created_at)
            ');

            $stmt->execute([
                'user_id' => $userId,
                'therapist_id' => $therapistId,
                'created_at' => now(),
            ]);

            return ['success' => true, 'message' => 'Added to favorites.'];
        } catch (\Throwable $e) {
            app_error('Failed to add favorite', $e);
            return ['success' => false, 'message' => 'Failed to add favorite.'];
        }
    }

    /**
     * Remove therapist from favorites
     */
    public function removeFavorite(int $userId, int $therapistId): array
    {
        $pdo = Database::connection();

        try {
            $stmt = $pdo->prepare('
                DELETE FROM user_favorites
                WHERE user_id = :user_id AND therapist_id = :therapist_id
            ');

            $stmt->execute(['user_id' => $userId, 'therapist_id' => $therapistId]);

            return ['success' => true, 'message' => 'Removed from favorites.'];
        } catch (\Throwable $e) {
            app_error('Failed to remove favorite', $e);
            return ['success' => false, 'message' => 'Failed to remove favorite.'];
        }
    }

    /**
     * Update customer profile
     */
    public function updateProfile(int $userId, array $data): array
    {
        $allowed = ['name', 'phone', 'address'];
        $updates = [];
        $params = ['id' => $userId];

        foreach ($allowed as $field) {
            if (isset($data[$field]) && !empty($data[$field])) {
                $updates[$field] = $data[$field];
                $params[$field] = $data[$field];
            }
        }

        if (empty($updates)) {
            return ['success' => false, 'message' => 'No valid fields to update.'];
        }

        try {
            $setClause = implode(', ', array_map(fn($k) => "$k = :$k", array_keys($updates)));
            $sql = "UPDATE users SET $setClause, updated_at = :updated_at WHERE id = :id";
            $params['updated_at'] = now();

            $stmt = Database::connection()->prepare($sql);
            $stmt->execute($params);

            return ['success' => true, 'message' => 'Profile updated successfully.'];
        } catch (\Throwable $e) {
            app_error('Failed to update customer profile', $e);
            return ['success' => false, 'message' => 'Failed to update profile.'];
        }
    }
}
