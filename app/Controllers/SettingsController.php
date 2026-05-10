<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;

class SettingsController
{
    public function get(): void
    {
        $pdo = Database::connection();
        $rows = $pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll();
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        json_response(['success' => true, 'data' => ['settings' => $settings]]);
    }

    public function save(): void
    {
        Auth::requireRole('admin');

        if (!verify_csrf()) {
            json_response(['success' => false, 'message' => 'CSRF validation failed.'], 419);
        }

        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        if (!is_array($payload)) {
            json_response(['success' => false, 'message' => 'Invalid payload.'], 400);
        }

        $pdo = Database::connection();
        $stmt = $pdo->prepare('
            INSERT INTO settings (setting_key, setting_value, updated_at)
            VALUES (:k, :v, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ');

        foreach ($payload as $key => $value) {
            if (!is_string($key) || strlen($key) > 120) continue;
            $stmt->execute([':k' => $key, ':v' => (string) $value]);
        }

        json_response(['success' => true, 'message' => 'Settings saved successfully.']);
    }
}
