<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\BookingRepository;
use App\Core\Database;
use App\Core\Validator;
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
        $response = ['success' => $result['success'], 'message' => $result['message']];
        if (isset($result['data'])) {
            $response['data'] = $result['data'];
        }
        json_response($response, (int) $result['status']);
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

    public function syncStripePayment(): void
    {
        Auth::requireRole('admin');
        $this->ensureCsrf();

        $result = $this->adminWriteService->syncStripePayment(request_json(), (int) Auth::id(), $this->auditContext());
        json_response(['success' => $result['success'], 'message' => $result['message']], (int) $result['status']);
    }

    public function cancelBooking(): void
    {
        Auth::requireRole('admin');
        $this->ensureCsrf();

        $result = $this->adminWriteService->cancelBooking(request_json(), (int) Auth::id(), $this->auditContext());
        json_response(['success' => $result['success'], 'message' => $result['message']], (int) $result['status']);
    }

    public function uploadServiceImage(): void
    {
        Auth::requireRole('admin');
        $this->ensureCsrf();

        $serviceId = (int) ($_POST['service_id'] ?? 0);
        if ($serviceId <= 0) {
            json_response(['success' => false, 'message' => 'Invalid service ID.'], 422);
        }

        if (!isset($_FILES['image'])) {
            json_response(['success' => false, 'message' => 'No image uploaded.'], 422);
        }

        $validation = Validator::validateUpload($_FILES['image'], ['image/jpeg', 'image/png'], 5 * 1024 * 1024);
        if (!$validation['valid']) {
            json_response(['success' => false, 'message' => $validation['message']], 422);
        }

        $extension = strtolower((string) pathinfo((string) $_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            json_response(['success' => false, 'message' => 'Only JPG and PNG files are allowed.'], 422);
        }

        $uploadDir = base_path('public/uploads/services/');
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            json_response(['success' => false, 'message' => 'Upload directory could not be created.'], 500);
        }

        $safeName = 'service_' . $serviceId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $targetPath = $uploadDir . $safeName;

        if (!move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
            json_response(['success' => false, 'message' => 'Unable to save uploaded file.'], 500);
        }

        $publicUrl = '/uploads/services/' . $safeName;

        try {
            $pdo = Database::connection();
            // Remove old image file if it exists
            $old = $pdo->prepare('SELECT image_url FROM services WHERE id = :id');
            $old->execute(['id' => $serviceId]);
            $oldRow = $old->fetch();
            if ($oldRow && $oldRow['image_url']) {
                $oldPath = base_path('public' . $oldRow['image_url']);
                if (is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
            $pdo->prepare('UPDATE services SET image_url = :image_url, updated_at = :updated_at WHERE id = :id')
                ->execute(['image_url' => $publicUrl, 'updated_at' => now(), 'id' => $serviceId]);
            // Track in media_files so it appears in the File panel
            $pdo->prepare('INSERT INTO media_files (filename, original_name, mime_type, file_size, uploaded_by, created_at) VALUES (:filename, :original_name, :mime_type, :file_size, :uploaded_by, :created_at)')
                ->execute([
                    'filename'      => 'uploads/services/' . $safeName,
                    'original_name' => (string) $_FILES['image']['name'],
                    'mime_type'     => (string) $_FILES['image']['type'],
                    'file_size'     => (int) $_FILES['image']['size'],
                    'uploaded_by'   => (int) Auth::id(),
                    'created_at'    => now(),
                ]);
            // Clear admin read cache
            (new \App\Services\AdminWriteService())->clearServiceCache();
            json_response(['success' => true, 'message' => 'Image uploaded.', 'data' => ['image_url' => $publicUrl]]);
        } catch (\Throwable $e) {
            @unlink($targetPath);
            app_error('Service image upload error', $e);
            json_response(['success' => false, 'message' => 'Failed to update service image.'], 500);
        }
    }

    public function listFiles(): void
    {
        Auth::requireRole('admin');

        try {
            $pdo = Database::connection();
            $stmt = $pdo->query(
                'SELECT f.id, f.filename, f.original_name, f.mime_type, f.file_size, f.created_at,
                        u.name AS uploaded_by_name
                   FROM media_files f
                   JOIN users u ON u.id = f.uploaded_by
                  ORDER BY f.created_at DESC'
            );
            $files = $stmt->fetchAll();
            json_response(['success' => true, 'data' => ['files' => $files]]);
        } catch (\Throwable $e) {
            app_error('Failed to list files', $e);
            json_response(['success' => false, 'message' => 'Failed to retrieve files.'], 500);
        }
    }

    public function uploadFile(): void
    {
        Auth::requireRole('admin');
        $this->ensureCsrf();

        if (!isset($_FILES['file'])) {
            json_response(['success' => false, 'message' => 'No file uploaded.'], 422);
        }

        $validation = Validator::validateUpload($_FILES['file'], ['image/jpeg', 'image/png'], 5 * 1024 * 1024);
        if (!$validation['valid']) {
            json_response(['success' => false, 'message' => $validation['message']], 422);
        }

        $extension = strtolower((string) pathinfo((string) $_FILES['file']['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            json_response(['success' => false, 'message' => 'Only JPG and PNG files are allowed.'], 422);
        }

        $uploadDir = base_path('public/uploads/files/');
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            json_response(['success' => false, 'message' => 'Upload directory could not be created.'], 500);
        }

        $safeName = bin2hex(random_bytes(16)) . '.' . $extension;
        $targetPath = $uploadDir . $safeName;

        if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
            json_response(['success' => false, 'message' => 'Unable to save uploaded file.'], 500);
        }

        try {
            $pdo = Database::connection();
            $pdo->prepare(
                'INSERT INTO media_files (filename, original_name, mime_type, file_size, uploaded_by, created_at)
                 VALUES (:filename, :original_name, :mime_type, :file_size, :uploaded_by, :created_at)'
            )->execute([
                'filename'      => $safeName,
                'original_name' => basename((string) $_FILES['file']['name']),
                'mime_type'     => (string) $_FILES['file']['type'],
                'file_size'     => (int) $_FILES['file']['size'],
                'uploaded_by'   => (int) Auth::id(),
                'created_at'    => now(),
            ]);
            $fileId = (int) $pdo->lastInsertId();
            json_response([
                'success' => true,
                'message' => 'File uploaded successfully.',
                'data'    => ['id' => $fileId, 'filename' => $safeName, 'url' => '/uploads/files/' . $safeName],
            ]);
        } catch (\Throwable $e) {
            @unlink($targetPath);
            app_error('File upload DB error', $e);
            json_response(['success' => false, 'message' => 'Failed to record file upload.'], 500);
        }
    }

    public function deleteFile(int $id): void
    {
        Auth::requireRole('admin');
        $this->ensureCsrf();

        if ($id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid file ID.'], 422);
        }

        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT filename FROM media_files WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $file = $stmt->fetch();

            if (!$file) {
                json_response(['success' => false, 'message' => 'File not found.'], 404);
            }

            $rawFilename = trim((string) ($file['filename'] ?? ''));
            $relativePath = str_replace('\\', '/', $rawFilename);
            if ($relativePath === '') {
                json_response(['success' => false, 'message' => 'Invalid file path.'], 422);
            }
            if (!str_contains($relativePath, '/')) {
                $relativePath = 'uploads/files/' . ltrim($relativePath, '/');
            }
            $relativePath = ltrim($relativePath, '/');
            $publicUrl = '/' . $relativePath;

            $filePath = base_path('public/' . $relativePath);
            if (is_file($filePath)) {
                @unlink($filePath);
            }

            // Remove dangling image references so profile/service previews fall back immediately.
            $pdo->prepare('UPDATE therapists SET photo_url = NULL, updated_at = :updated_at WHERE photo_url = :url1 OR photo_url = :url2')
                ->execute([
                    'updated_at' => now(),
                    'url1' => $publicUrl,
                    'url2' => $relativePath,
                ]);
            $pdo->prepare('UPDATE services SET image_url = NULL, updated_at = :updated_at WHERE image_url = :url1 OR image_url = :url2')
                ->execute([
                    'updated_at' => now(),
                    'url1' => $publicUrl,
                    'url2' => $relativePath,
                ]);

            $pdo->prepare('DELETE FROM media_files WHERE id = :id')->execute(['id' => $id]);
            (new \App\Services\AdminWriteService())->clearServiceCache();
            json_response(['success' => true, 'message' => 'File deleted.']);
        } catch (\Throwable $e) {
            app_error('File delete error', $e);
            json_response(['success' => false, 'message' => 'Failed to delete file.'], 500);
        }
    }
}
