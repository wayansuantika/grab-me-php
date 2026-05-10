<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\BookingRepository;
use App\Services\AdminReadService;
use App\Services\AdminWriteService;

class AdminController
{
    private BookingRepository $bookingRepo;
    private AdminReadService $adminReadService;
    private AdminWriteService $adminWriteService;

    public function __construct()
    {
        $this->bookingRepo = new BookingRepository();
        $this->adminReadService = new AdminReadService();
        $this->adminWriteService = new AdminWriteService();
    }

    private function ensureCsrf(): void
    {
        if (!verify_csrf()) {
            json_response(['success' => false, 'message' => 'CSRF validation failed.'], 419);
        }
    }

    private function auditContext(): array
    {
        return [
            'ip' => get_client_ip(),
            'user_agent' => (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ];
    }

    public function dashboard(): void
    {
        Auth::requireRole('admin');

        try {
            json_response(['success' => true, 'data' => $this->adminReadService->dashboard()]);
        } catch (\Throwable $e) {
            app_error('Admin dashboard error', $e);
            json_response(['success' => false, 'message' => 'Failed to load dashboard.'], 500);
        }
    }

    public function bookings(): void
    {
        Auth::requireRole('admin');

        try {
            $page = (int) ($_GET['page'] ?? 1);
            $perPage = (int) ($_GET['per_page'] ?? 50);

            $total = $this->bookingRepo->count();
            $pagination = new \App\Core\Pagination($total, $perPage, $page);
            
            $bookings = $this->bookingRepo->adminList($pagination->getLimit(), $pagination->getOffset());

            json_response([
                'success' => true,
                'data' => [
                    'bookings' => $bookings,
                    'pagination' => $pagination->getMetadata(),
                ],
            ]);
        } catch (\Throwable $e) {
            app_error('Failed to get bookings', $e);
            json_response(['success' => false, 'message' => 'Failed to retrieve bookings.'], 500);
        }
    }

    public function therapists(): void
    {
        Auth::requireRole('admin');

        try {
            json_response(['success' => true, 'data' => $this->adminReadService->therapists()]);
        } catch (\Throwable $e) {
            app_error('Failed to get therapists', $e);
            json_response(['success' => false, 'message' => 'Failed to retrieve therapists.'], 500);
        }
    }

    public function saveTherapistAreas(): void
    {
        Auth::requireRole('admin');
        $this->ensureCsrf();

        $result = $this->adminWriteService->saveTherapistAreas(request_json(), (int) Auth::id(), $this->auditContext());
        json_response(['success' => $result['success'], 'message' => $result['message']], (int) $result['status']);
    }

    public function saveTherapist(): void
    {
        Auth::requireRole('admin');
        $this->ensureCsrf();

        $result = $this->adminWriteService->saveTherapist(request_json(), (int) Auth::id(), $this->auditContext());
        json_response(['success' => $result['success'], 'message' => $result['message']], (int) $result['status']);
    }

    public function services(): void
    {
        Auth::requireRole('admin');

        json_response(['success' => true, 'data' => $this->adminReadService->services()]);
    }

    public function saveService(): void
    {
        Auth::requireRole('admin');
        $this->ensureCsrf();

        $result = $this->adminWriteService->saveService(request_json(), (int) Auth::id(), $this->auditContext());
        json_response(['success' => $result['success'], 'message' => $result['message']], (int) $result['status']);
    }

    public function areas(): void
    {
        Auth::requireRole('admin');

        json_response(['success' => true, 'data' => $this->adminReadService->areas()]);
    }

    public function saveArea(): void
    {
        Auth::requireRole('admin');
        $this->ensureCsrf();

        $result = $this->adminWriteService->saveArea(request_json(), (int) Auth::id(), $this->auditContext());
        json_response(['success' => $result['success'], 'message' => $result['message']], (int) $result['status']);
    }

    public function customers(): void
    {
        Auth::requireRole('admin');

        json_response(['success' => true, 'data' => $this->adminReadService->customers()]);
    }

    public function payments(): void
    {
        Auth::requireRole('admin');

        json_response(['success' => true, 'data' => $this->adminReadService->payments()]);
    }

    public function audits(): void
    {
        Auth::requireRole('admin');

        $page = (int) ($_GET['page'] ?? 1);
        $perPage = (int) ($_GET['per_page'] ?? 50);

        json_response(['success' => true, 'data' => $this->adminReadService->audits($page, $perPage)]);
    }

    public function confirmPayment(): void
    {
        Auth::requireRole('admin');
        $this->ensureCsrf();

        $result = $this->adminWriteService->confirmPayment(request_json(), (int) Auth::id(), $this->auditContext());
        json_response(['success' => $result['success'], 'message' => $result['message']], (int) $result['status']);
    }

    public function cancelBooking(): void
    {
        Auth::requireRole('admin');
        $this->ensureCsrf();

        $result = $this->adminWriteService->cancelBooking(request_json(), (int) Auth::id(), $this->auditContext());
        json_response(['success' => $result['success'], 'message' => $result['message']], (int) $result['status']);
    }
}
