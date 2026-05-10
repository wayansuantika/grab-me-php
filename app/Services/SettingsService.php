<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

class SettingsService
{
    private static array $cache = [];

    /**
     * Get a setting value
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // Check cache first
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = :key');
        $stmt->execute(['key' => $key]);
        $result = $stmt->fetch();

        if ($result) {
            $value = $result['value'];
            // Attempt to parse JSON values
            if (is_json($value)) {
                $value = json_decode($value, true);
            }
            self::$cache[$key] = $value;
            return $value;
        }

        return $default;
    }

    /**
     * Get all settings
     */
    public static function all(): array
    {
        $pdo = Database::connection();

        $stmt = $pdo->prepare('SELECT key, value FROM settings ORDER BY key ASC');
        $stmt->execute();

        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $value = $row['value'];
            if (is_json($value)) {
                $value = json_decode($value, true);
            }
            $settings[$row['key']] = $value;
        }

        self::$cache = $settings;
        return $settings;
    }

    /**
     * Set a setting value
     */
    public static function set(string $key, mixed $value, int $adminId): array
    {
        $pdo = Database::connection();

        // Convert arrays to JSON
        $storageValue = is_array($value) ? json_encode($value) : (string) $value;

        try {
            // Check if key exists
            $checkStmt = $pdo->prepare('SELECT id FROM settings WHERE key = :key');
            $checkStmt->execute(['key' => $key]);

            if ($checkStmt->fetch()) {
                // Update existing
                $stmt = $pdo->prepare('
                    UPDATE settings
                    SET value = :value, updated_at = :updated_at
                    WHERE key = :key
                ');
            } else {
                // Insert new
                $stmt = $pdo->prepare('
                    INSERT INTO settings (key, value, created_at, updated_at)
                    VALUES (:key, :value, :created_at, :updated_at)
                ');
                $stmt->bindValue(':created_at', now());
            }

            $stmt->execute([
                'key' => $key,
                'value' => $storageValue,
                'updated_at' => now(),
            ]);

            // Clear cache
            unset(self::$cache[$key]);

            // Log admin action
            self::logSettingChange($key, $adminId, $storageValue);

            return ['success' => true, 'message' => "Setting '$key' updated successfully."];
        } catch (\Throwable $e) {
            app_error('Failed to update setting', $e);
            return ['success' => false, 'message' => "Failed to update setting '$key'."];
        }
    }

    /**
     * Get site configuration
     */
    public static function getSiteConfig(): array
    {
        return [
            'site_name' => self::get('site_name', 'GrabMas'),
            'site_email' => self::get('site_email', 'info@grabmas.com'),
            'currency' => self::get('currency', 'IDR'),
            'timezone' => self::get('timezone', 'Asia/Jakarta'),
            'booking_cancellation_hours' => (int) self::get('booking_cancellation_hours', 24),
            'booking_reschedule_hours' => (int) self::get('booking_reschedule_hours', 24),
            'min_booking_advance_hours' => (int) self::get('min_booking_advance_hours', 2),
            'max_booking_advance_days' => (int) self::get('max_booking_advance_days', 90),
        ];
    }

    /**
     * Get payment settings
     */
    public static function getPaymentSettings(): array
    {
        return [
            'stripe_public_key' => self::get('stripe_public_key', ''),
            'stripe_secret_key' => self::get('stripe_secret_key', ''),
            'bank_account_name' => self::get('bank_account_name', ''),
            'bank_account_number' => self::get('bank_account_number', ''),
            'bank_name' => self::get('bank_name', ''),
            'payment_methods' => self::get('payment_methods', ['bank_transfer', 'credit_card']),
            'automatic_payment_confirmation' => parse_bool(self::get('automatic_payment_confirmation', false)),
        ];
    }

    /**
     * Get email settings
     */
    public static function getEmailSettings(): array
    {
        return [
            'smtp_host' => self::get('smtp_host', ''),
            'smtp_port' => (int) self::get('smtp_port', 587),
            'smtp_username' => self::get('smtp_username', ''),
            'smtp_password' => self::get('smtp_password', ''),
            'smtp_encryption' => self::get('smtp_encryption', 'tls'),
            'from_email' => self::get('from_email', self::get('site_email', 'noreply@grabmas.com')),
            'from_name' => self::get('from_name', self::get('site_name', 'GrabMas')),
        ];
    }

    /**
     * Get notification settings
     */
    public static function getNotificationSettings(): array
    {
        return [
            'send_booking_confirmation' => parse_bool(self::get('send_booking_confirmation', true)),
            'send_booking_reminder' => parse_bool(self::get('send_booking_reminder', true)),
            'send_therapist_notification' => parse_bool(self::get('send_therapist_notification', true)),
            'reminder_hours_before' => (int) self::get('reminder_hours_before', 24),
        ];
    }

    /**
     * Update site configuration
     */
    public static function updateSiteConfig(array $config, int $adminId): array
    {
        $allowed = ['site_name', 'site_email', 'currency', 'timezone', 'booking_cancellation_hours', 'booking_reschedule_hours', 'min_booking_advance_hours', 'max_booking_advance_days'];
        $errors = [];

        foreach ($config as $key => $value) {
            if (!in_array($key, $allowed)) {
                continue;
            }

            $result = self::set($key, $value, $adminId);
            if (!$result['success']) {
                $errors[] = $result['message'];
            }
        }

        if (!empty($errors)) {
            return ['success' => false, 'message' => 'Some settings failed to update.', 'errors' => $errors];
        }

        return ['success' => true, 'message' => 'Site configuration updated successfully.'];
    }

    /**
     * Update payment settings
     */
    public static function updatePaymentSettings(array $settings, int $adminId): array
    {
        $allowed = ['stripe_public_key', 'stripe_secret_key', 'bank_account_name', 'bank_account_number', 'bank_name', 'payment_methods', 'automatic_payment_confirmation'];
        $errors = [];

        foreach ($settings as $key => $value) {
            if (!in_array($key, $allowed)) {
                continue;
            }

            $result = self::set($key, $value, $adminId);
            if (!$result['success']) {
                $errors[] = $result['message'];
            }
        }

        if (!empty($errors)) {
            return ['success' => false, 'message' => 'Some settings failed to update.', 'errors' => $errors];
        }

        return ['success' => true, 'message' => 'Payment settings updated successfully.'];
    }

    /**
     * Log setting change
     */
    private static function logSettingChange(string $key, int $adminId, string $value): void
    {
        try {
            $pdo = Database::connection();

            // Don't log sensitive values
            $logValue = str_contains($key, 'password') || str_contains($key, 'secret') || str_contains($key, 'key')
                ? '***REDACTED***'
                : substr($value, 0, 255);

            $stmt = $pdo->prepare('
                INSERT INTO admin_logs (admin_id, action, details, created_at)
                VALUES (:admin_id, :action, :details, :created_at)
            ');

            $stmt->execute([
                'admin_id' => $adminId,
                'action' => 'setting_changed',
                'details' => json_encode(['key' => $key, 'value' => $logValue]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Silently fail logging to prevent blocking the operation
            app_error('Failed to log setting change', $e);
        }
    }

    /**
     * Clear all cache
     */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Delete a setting
     */
    public static function delete(string $key, int $adminId): array
    {
        $pdo = Database::connection();

        try {
            $stmt = $pdo->prepare('DELETE FROM settings WHERE key = :key');
            $stmt->execute(['key' => $key]);

            unset(self::$cache[$key]);

            self::logSettingChange($key, $adminId, 'DELETED');

            return ['success' => true, 'message' => "Setting '$key' deleted successfully."];
        } catch (\Throwable $e) {
            app_error('Failed to delete setting', $e);
            return ['success' => false, 'message' => "Failed to delete setting '$key'."];
        }
    }
}
