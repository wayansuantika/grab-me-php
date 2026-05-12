<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\BookingRepository;
use App\Core\Database;
use App\Core\FileCache;
use App\Core\TherapistRepository;
use App\Core\UserRepository;

class AdminReadService
{
    private const CACHE_PREFIX = 'admin.read.';
    private const DASHBOARD_TTL = 60;
    private const LIST_TTL = 45;

    private FileCache $cache;
    private UserRepository $userRepo;
    private TherapistRepository $therapistRepo;
    private BookingRepository $bookingRepo;
    private PaymentService $paymentService;

    public function __construct()
    {
        $this->cache = new FileCache();
        $this->userRepo = new UserRepository();
        $this->therapistRepo = new TherapistRepository();
        $this->bookingRepo = new BookingRepository();
        $this->paymentService = new PaymentService();
    }

    public function dashboard(): array
    {
        return $this->cache->remember(self::CACHE_PREFIX . 'dashboard.v1', self::DASHBOARD_TTL, function (): array {
            $customerCount = $this->userRepo->countByRole('customer');
            $pdo = Database::connection();
            $verifiedCustomerCount = (int) $pdo->query("SELECT COUNT(DISTINCT b.user_id) FROM bookings b INNER JOIN users u ON u.id = b.user_id WHERE u.role = 'customer'")->fetchColumn();
            $therapistStats = $this->therapistRepo->getStats();
            $bookingStats = $this->bookingRepo->getStats();
            $paymentStats = $this->paymentService->getPaymentStats();

            $metrics = [
                'operations' => [
                    'customers_total' => $verifiedCustomerCount,
                    'customers_registered_total' => $customerCount,
                    'customers_with_bookings_total' => $verifiedCustomerCount,
                    'therapists_total' => (int) ($therapistStats['total_therapists'] ?? 0),
                    'therapists_active' => (int) ($therapistStats['active_therapists'] ?? 0),
                    'bookings_total' => (int) ($bookingStats['total'] ?? 0),
                    'bookings_confirmed' => (int) ($bookingStats['confirmed'] ?? 0),
                    'bookings_completed' => (int) ($bookingStats['completed'] ?? 0),
                    'bookings_cancelled' => (int) ($bookingStats['cancelled'] ?? 0),
                ],
                'finance' => [
                    'payments_total' => (int) ($paymentStats['total_payments'] ?? 0),
                    'payments_collected_amount' => (float) ($paymentStats['total_collected'] ?? 0),
                    'payments_pending_amount' => (float) ($paymentStats['total_pending'] ?? 0),
                    'payments_failed_amount' => (float) ($paymentStats['total_failed'] ?? 0),
                ],
            ];

            return [
                'metrics' => $metrics,
                'stats' => [
                    'customers' => $metrics['operations']['customers_total'],
                    'therapists' => $metrics['operations']['therapists_total'],
                    'bookings' => $metrics['operations']['bookings_total'],
                    'payments_paid' => $metrics['finance']['payments_collected_amount'],
                    'payments_pending' => $metrics['finance']['payments_pending_amount'],
                    'total_revenue' => $metrics['finance']['payments_collected_amount'],
                ],
                'recent_bookings' => $this->bookingRepo->recent(10),
                'booking_summary' => $this->bookingRepo->dailySummary(7),
            ];
        });
    }

    public function therapists(): array
    {
        return $this->cache->remember(self::CACHE_PREFIX . 'therapists.v1', self::LIST_TTL, fn(): array => [
            'therapists' => $this->therapistRepo->allWithDetails(),
        ]);
    }

    public function services(): array
    {
        return $this->cache->remember(self::CACHE_PREFIX . 'services.v1', self::LIST_TTL, function (): array {
            $pdo = Database::connection();

            return [
                'categories' => $pdo->query('SELECT id, name FROM service_categories WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll(),
                'services' => $pdo->query('SELECT s.id, s.name, s.category_id, c.name AS category_name, s.description, s.image_url, s.duration_minutes, s.price, s.is_addon, s.sort_order, s.is_active FROM services s INNER JOIN service_categories c ON c.id = s.category_id ORDER BY s.id DESC')->fetchAll(),
            ];
        });
    }

    public function areas(): array
    {
        return $this->cache->remember(self::CACHE_PREFIX . 'areas.v1', self::LIST_TTL, function (): array {
            $pdo = Database::connection();

            return [
                'areas' => $pdo->query('SELECT id, name, coverage_group, is_active FROM coverage_areas ORDER BY id DESC')->fetchAll(),
            ];
        });
    }

    public function customers(): array
    {
        return $this->cache->remember(self::CACHE_PREFIX . 'customers.v1', self::LIST_TTL, function (): array {
            $pdo = Database::connection();

            return [
                'customers' => $pdo->query("SELECT id, name, email, status, created_at FROM users WHERE role = 'customer' ORDER BY id DESC LIMIT 200")->fetchAll(),
            ];
        });
    }

    public function payments(): array
    {
        return $this->cache->remember(self::CACHE_PREFIX . 'payments.v1', self::LIST_TTL, function (): array {
            $pdo = Database::connection();

            return [
                'payments' => $pdo->query('
                    SELECT p.id, p.booking_id, b.booking_code, b.customer_name, b.customer_phone, b.booking_status,
                           p.provider, p.provider_payment_id, p.amount, p.currency, p.status, p.created_at
                    FROM payments p
                    INNER JOIN bookings b ON b.id = p.booking_id
                    ORDER BY p.id DESC
                    LIMIT 200
                ')->fetchAll(),
            ];
        });
    }

    public function audits(int $page = 1, int $perPage = 50): array
    {
        $safePage = max(1, $page);
        $safePerPage = min(100, max(10, $perPage));
        $offset = ($safePage - 1) * $safePerPage;

        return $this->cache->remember(self::CACHE_PREFIX . "audits.p{$safePage}.pp{$safePerPage}.v2", self::LIST_TTL, function () use ($safePerPage, $offset, $safePage): array {
            $pdo = Database::connection();

            $count = 0;
            try {
                $countStmt = $pdo->query('SELECT COUNT(*) AS total FROM admin_logs');
                $count = (int) (($countStmt->fetch()['total'] ?? 0));
            } catch (\Throwable) {
                // Old deployments may not have admin_logs yet.
                return [
                    'logs' => [],
                    'pagination' => [
                        'page' => $safePage,
                        'per_page' => $safePerPage,
                        'total' => 0,
                        'total_pages' => 0,
                    ],
                ];
            }

            $stmt = $pdo->prepare('
                SELECT l.id, l.admin_id, l.action, l.target_id, l.target_type, l.details, l.created_at,
                       u.name AS admin_name, u.email AS admin_email,
                       b.booking_code AS target_booking_code
                FROM admin_logs l
                LEFT JOIN users u ON u.id = l.admin_id
                LEFT JOIN bookings b ON b.id = l.target_id AND l.target_type = "booking"
                ORDER BY l.id DESC
                LIMIT :limit OFFSET :offset
            ');
            $stmt->bindValue(':limit', $safePerPage, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();

            $logs = array_map(static function (array $row): array {
                $detailsRaw = (string) ($row['details'] ?? '');
                $decoded = json_decode($detailsRaw, true);

                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'admin_id' => (int) ($row['admin_id'] ?? 0),
                    'admin_name' => (string) ($row['admin_name'] ?? ''),
                    'admin_email' => (string) ($row['admin_email'] ?? ''),
                    'action' => (string) ($row['action'] ?? ''),
                    'target_id' => (int) ($row['target_id'] ?? 0),
                    'target_type' => (string) ($row['target_type'] ?? ''),
                    'target_booking_code' => (string) ($row['target_booking_code'] ?? ''),
                    'details' => is_array($decoded) ? $decoded : ['raw' => $detailsRaw],
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }, $stmt->fetchAll());

            return [
                'logs' => $logs,
                'pagination' => [
                    'page' => $safePage,
                    'per_page' => $safePerPage,
                    'total' => $count,
                    'total_pages' => (int) ceil($count / $safePerPage),
                ],
            ];
        });
    }

    public function clearCachedReads(): void
    {
        $this->cache->forgetByPrefix(self::CACHE_PREFIX);
    }
}