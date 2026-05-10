<?php

declare(strict_types=1);

namespace App\Core;

class Validator
{
    public static function required(array $data, array $fields): array
    {
        $errors = [];
        foreach ($fields as $field) {
            $value = $data[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $errors[$field] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }
        return $errors;
    }

    public static function email(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate email format with error message
     */
    public static function validateEmail(string $email, ?string &$error = null): bool
    {
        if (empty(trim($email))) {
            $error = 'Email is required.';
            return false;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format.';
            return false;
        }
        return true;
    }

    /**
     * Validate password strength
     */
    public static function validatePassword(string $password, ?string &$error = null): bool
    {
        if (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
            return false;
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $error = 'Password must contain at least one uppercase letter.';
            return false;
        }
        if (!preg_match('/[a-z]/', $password)) {
            $error = 'Password must contain at least one lowercase letter.';
            return false;
        }
        if (!preg_match('/[0-9]/', $password)) {
            $error = 'Password must contain at least one number.';
            return false;
        }
        return true;
    }

    /**
     * Validate phone number
     */
    public static function validatePhone(string $phone, ?string &$error = null): bool
    {
        $cleaned = preg_replace('/[^0-9+\-\s]/', '', $phone);
        if (strlen($cleaned) < 10) {
            $error = 'Invalid phone number.';
            return false;
        }
        return true;
    }

    /**
     * Validate date format
     */
    public static function validateDate(string $date, string $format = 'Y-m-d', ?string &$error = null): bool
    {
        $d = \DateTime::createFromFormat($format, $date);
        if (!$d || $d->format($format) !== $date) {
            $error = "Invalid date format. Expected {$format}.";
            return false;
        }
        return true;
    }

    /**
     * Validate future date
     */
    public static function validateFutureDate(string $date, ?string &$error = null): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$d) {
            $error = 'Invalid date format.';
            return false;
        }
        if ($d <= new \DateTime('today')) {
            $error = 'Date must be in the future.';
            return false;
        }
        return true;
    }

    /**
     * Validate time format HH:MM
     */
    public static function validateTime(string $time, ?string &$error = null): bool
    {
        if (!preg_match('/^([0-1][0-9]|2[0-3]):[0-5][0-9]$/', $time)) {
            $error = 'Invalid time format. Expected HH:MM.';
            return false;
        }
        return true;
    }

    /**
     * Validate value is in allowed list
     */
    public static function validateIn(mixed $value, array $allowed, ?string &$error = null): bool
    {
        if (!in_array($value, $allowed, true)) {
            $error = 'Invalid selection.';
            return false;
        }
        return true;
    }

    public static function validateUpload(array $file, array $allowedMimeTypes, int $maxSizeBytes = 2097152): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return ['valid' => false, 'message' => 'Upload failed.'];
        }

        if (($file['size'] ?? 0) > $maxSizeBytes) {
            return ['valid' => false, 'message' => 'File size exceeds limit.'];
        }

        $tmpName = $file['tmp_name'] ?? '';
        $mime = is_string($tmpName) ? mime_content_type($tmpName) : false;
        if ($mime === false || !in_array($mime, $allowedMimeTypes, true)) {
            return ['valid' => false, 'message' => 'Invalid file type.'];
        }

        return ['valid' => true, 'message' => 'OK'];
    }
}
