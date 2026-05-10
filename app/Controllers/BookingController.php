<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Validator;
use App\Services\BookingService;
use App\Services\PaymentService;

class BookingController
{
    private BookingService $bookingService;
    private PaymentService $paymentService;

    public function __construct()
    {
        $this->bookingService = new BookingService();
        $this->paymentService = new PaymentService();
    }

    public function create(): void
    {
        if (!verify_csrf()) {
            json_response(['success' => false, 'message' => 'CSRF validation failed.'], 419);
        }

        $data = request_json();

        // Validate booking input
        $errors = $this->bookingService->validateBookingCreation($data);
        if (!empty($errors)) {
            json_response(['success' => false, 'errors' => $errors], 422);
        }

        $serviceIds = array_map('intval', (array) ($data['service_ids'] ?? []));
        $addOnIds = array_map('intval', (array) ($data['addon_ids'] ?? []));
        $paymentMethod = strtolower(trim((string) ($data['payment_method'] ?? 'bank_transfer')));

        $pdo = Database::connection();

        // Fetch and validate main services
        $mainQuery = $pdo->prepare('SELECT id, price, is_addon FROM services WHERE id IN (' . implode(',', array_fill(0, count($serviceIds), '?')) . ') AND is_active = 1');
        $mainQuery->execute($serviceIds);
        $mainServices = $mainQuery->fetchAll();

        if (count($mainServices) !== count($serviceIds)) {
            json_response(['success' => false, 'message' => 'One or more selected services are invalid.'], 422);
        }

        foreach ($mainServices as $service) {
            if ((int) $service['is_addon'] === 1) {
                json_response(['success' => false, 'message' => 'Add-ons cannot be booked as standalone services.'], 422);
            }
        }

        $allItems = $mainServices;

        // Fetch and validate add-ons
        if (!empty($addOnIds)) {
            $addonQuery = $pdo->prepare('SELECT id, price, is_addon FROM services WHERE id IN (' . implode(',', array_fill(0, count($addOnIds), '?')) . ') AND is_active = 1');
            $addonQuery->execute($addOnIds);
            $addonServices = $addonQuery->fetchAll();

            if (count($addonServices) !== count($addOnIds)) {
                json_response(['success' => false, 'message' => 'One or more add-ons are invalid.'], 422);
            }

            foreach ($addonServices as $service) {
                if ((int) $service['is_addon'] !== 1) {
                    json_response(['success' => false, 'message' => 'Invalid add-on selection.'], 422);
                }
            }

            $allItems = array_merge($allItems, $addonServices);
        }

        // Calculate total
        $total = 0.0;
        foreach ($allItems as $item) {
            $total += (float) $item['price'];
        }

        // Verify therapist covers the area
        $checkTherapist = $pdo->prepare('SELECT COUNT(*) AS total FROM therapist_coverage_areas WHERE therapist_id = :therapist_id AND area_id = :area_id');
        $checkTherapist->execute([
            'therapist_id' => (int) $data['therapist_id'],
            'area_id' => (int) $data['area_id'],
        ]);

        if ((int) $checkTherapist->fetch()['total'] === 0) {
            json_response(['success' => false, 'message' => 'Selected therapist does not cover this area.'], 422);
        }

        $pdo->beginTransaction();

        try {
            $insertBooking = $pdo->prepare('
                INSERT INTO bookings (
                    booking_code, user_id, therapist_id, area_id, booking_date, booking_time,
                    customer_name, customer_phone, customer_address, notes,
                    subtotal, total_amount, payment_status, booking_status,
                    created_at, updated_at
                ) VALUES (
                    :booking_code, :user_id, :therapist_id, :area_id, :booking_date, :booking_time,
                    :customer_name, :customer_phone, :customer_address, :notes,
                    :subtotal, :total_amount, :payment_status, :booking_status,
                    :created_at, :updated_at
                )
            ');

            $bookingCode = 'BM-' . strtoupper(bin2hex(random_bytes(4)));

            $insertBooking->execute([
                'booking_code' => $bookingCode,
                'user_id' => $this->resolveBookingUserId($pdo),
                'therapist_id' => (int) $data['therapist_id'],
                'area_id' => (int) $data['area_id'],
                'booking_date' => (string) $data['booking_date'],
                'booking_time' => (string) $data['booking_time'],
                'customer_name' => (string) $data['customer_name'],
                'customer_phone' => (string) $data['customer_phone'],
                'customer_address' => (string) ($data['customer_address'] ?? ''),
                'notes' => (string) ($data['notes'] ?? ''),
                'subtotal' => $total,
                'total_amount' => $total,
                'payment_status' => 'pending',
                'booking_status' => 'pending_payment',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $bookingId = (int) $pdo->lastInsertId();

            // Insert booking items
            $insertItem = $pdo->prepare('
                INSERT INTO booking_items (booking_id, service_id, item_type, unit_price, qty, total_price, created_at)
                VALUES (:booking_id, :service_id, :item_type, :unit_price, :qty, :total_price, :created_at)
            ');

            foreach ($allItems as $item) {
                $insertItem->execute([
                    'booking_id' => $bookingId,
                    'service_id' => (int) $item['id'],
                    'item_type' => ((int) $item['is_addon'] === 1) ? 'addon' : 'service',
                    'unit_price' => (float) $item['price'],
                    'qty' => 1,
                    'total_price' => (float) $item['price'],
                    'created_at' => now(),
                ]);
            }

            // Create payment record
            $paymentResult = $this->paymentService->createPayment($bookingId, $total, $paymentMethod);
            if (!$paymentResult['success']) {
                throw new \Exception('Failed to create payment record');
            }

            $pdo->commit();

            json_response([
                'success' => true,
                'message' => 'Booking created. Continue to payment.',
                'data' => [
                    'booking_id' => $bookingId,
                    'booking_code' => $bookingCode,
                    'total_amount' => $total,
                    'payment_id' => $paymentResult['payment_id'] ?? null,
                ],
            ], 201);
        } catch (\Throwable $e) {
            $pdo->rollBack();
            app_error('Booking create error', $e);
            json_response(['success' => false, 'message' => 'Failed to create booking.'], 500);
        }
    }

    private function resolveBookingUserId(\PDO $pdo): int
    {
        $authId = Auth::id();
        if ($authId !== null) {
            return $authId;
        }

        $guestEmail = 'guest@grabmas.local';
        $guestStmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $guestStmt->execute(['email' => $guestEmail]);
        $guest = $guestStmt->fetch();
        if ($guest) {
            return (int) $guest['id'];
        }

        $insertGuest = $pdo->prepare('
            INSERT INTO users (name, email, password_hash, role, status, created_at, updated_at)
            VALUES (:name, :email, :password_hash, :role, :status, :created_at, :updated_at)
        ');
        $insertGuest->execute([
            'name' => 'Guest Customer',
            'email' => $guestEmail,
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'role' => 'customer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function myBookings(): void
    {
        Auth::requireRole(['customer', 'admin']);

        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            SELECT b.id, b.booking_code, b.booking_date, b.booking_time, b.total_amount, b.payment_status, b.booking_status, u.name AS therapist_name
            FROM bookings b
            INNER JOIN therapists t ON t.id = b.therapist_id
            INNER JOIN users u ON u.id = t.user_id
            WHERE b.user_id = :user_id
            ORDER BY b.created_at DESC
        ');
        $stmt->execute(['user_id' => Auth::id()]);

        json_response(['success' => true, 'data' => ['bookings' => $stmt->fetchAll()]]);
    }

    public function getBooking(): void
    {
        Auth::requireRole(['customer', 'admin']);

        $bookingId = (int) ($_GET['id'] ?? 0);
        if ($bookingId === 0) {
            json_response(['success' => false, 'message' => 'Booking ID required.'], 400);
        }

        try {
            $bookingRepo = new \App\Core\BookingRepository();
            $booking = $bookingRepo->findWithDetails($bookingId);

            if (!$booking) {
                json_response(['success' => false, 'message' => 'Booking not found.'], 404);
            }

            // Authorization check
            if ($booking['user_id'] !== Auth::id() && Auth::role() !== 'admin') {
                json_response(['success' => false, 'message' => 'Unauthorized.'], 403);
            }

            json_response(['success' => true, 'data' => $booking]);
        } catch (\Throwable $e) {
            app_error('Failed to get booking', $e);
            json_response(['success' => false, 'message' => 'Failed to retrieve booking.'], 500);
        }
    }

    public function cancelBooking(): void
    {
        Auth::requireRole(['customer', 'admin']);

        if (!verify_csrf()) {
            json_response(['success' => false, 'message' => 'CSRF validation failed.'], 419);
        }

        $data = request_json();
        $bookingId = (int) ($data['booking_id'] ?? 0);

        if ($bookingId === 0) {
            json_response(['success' => false, 'message' => 'Booking ID required.'], 400);
        }

        $result = $this->bookingService->cancelBooking($bookingId, Auth::id(), $data['reason'] ?? '');
        $statusCode = $result['success'] ? 200 : 400;

        json_response($result, $statusCode);
    }

    public function rescheduleBooking(): void
    {
        Auth::requireRole(['customer', 'admin']);

        if (!verify_csrf()) {
            json_response(['success' => false, 'message' => 'CSRF validation failed.'], 419);
        }

        $data = request_json();
        $bookingId = (int) ($data['booking_id'] ?? 0);

        if ($bookingId === 0) {
            json_response(['success' => false, 'message' => 'Booking ID required.'], 400);
        }

        $result = $this->bookingService->rescheduleBooking(
            $bookingId,
            Auth::id(),
            $data['new_date'] ?? '',
            $data['new_time'] ?? '',
            isset($data['new_therapist_id']) ? (int) $data['new_therapist_id'] : null
        );

        $statusCode = $result['success'] ? 200 : 400;
        json_response($result, $statusCode);
    }
}
