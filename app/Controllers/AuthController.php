<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Validator;

class AuthController
{
    public function register(): void
    {
        $data = request_json();
        $errors = Validator::required($data, ['name', 'email', 'password']);

        if (!empty($errors)) {
            json_response(['success' => false, 'errors' => $errors], 422);
        }

        if (!Validator::email((string) $data['email'])) {
            json_response(['success' => false, 'message' => 'Invalid email format.'], 422);
        }

        if (strlen((string) $data['password']) < 8) {
            json_response(['success' => false, 'message' => 'Password must be at least 8 characters.'], 422);
        }

        $pdo = Database::connection();

        $check = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $check->execute(['email' => $data['email']]);
        if ($check->fetch()) {
            json_response(['success' => false, 'message' => 'Email already registered.'], 409);
        }

        $insert = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, created_at, updated_at) VALUES (:name, :email, :password_hash, :role, :created_at, :updated_at)');
        $insert->execute([
            'name' => trim((string) $data['name']),
            'email' => strtolower(trim((string) $data['email'])),
            'password_hash' => password_hash((string) $data['password'], PASSWORD_DEFAULT),
            'role' => 'customer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = (int) $pdo->lastInsertId();

        $sessionUser = [
            'id' => $id,
            'name' => trim((string) $data['name']),
            'email' => strtolower(trim((string) $data['email'])),
            'role' => 'customer',
        ];

        Auth::attempt($sessionUser);

        // Fallback in case session wasn't persisted
        if (!Auth::user()) {
            $sessionUser = $sessionUser; // Use prepared data
        } else {
            $sessionUser = Auth::user();
        }

        json_response([
            'success' => true,
            'message' => 'Registration successful.',
            'data' => ['user' => $sessionUser, 'csrf_token' => csrf_token()],
        ], 201);
    }

    public function login(): void
    {
        try {
            $data = request_json();
            $errors = Validator::required($data, ['email', 'password']);

            if (!empty($errors)) {
                json_response(['success' => false, 'errors' => $errors], 422);
            }

            $pdo = Database::connection();
            $stmt = $pdo->prepare('SELECT id, name, email, password_hash, role, status FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => strtolower(trim((string) $data['email']))]);

            $user = $stmt->fetch();
            if (!$user || !password_verify((string) $data['password'], (string) $user['password_hash'])) {
                json_response(['success' => false, 'message' => 'Invalid login credentials.'], 401);
            }

            if (($user['status'] ?? 'active') !== 'active') {
                json_response(['success' => false, 'message' => 'Account is inactive.'], 403);
            }

            Auth::attempt($user);

            $sessionUser = Auth::user();
            if (!$sessionUser) {
                // Fallback: return the user data directly if session wasn't persisted
                $sessionUser = [
                    'id' => (int) $user['id'],
                    'name' => (string) $user['name'],
                    'email' => (string) $user['email'],
                    'role' => (string) $user['role'],
                ];
            }

            json_response([
                'success' => true,
                'message' => 'Login successful.',
                'data' => ['user' => $sessionUser, 'csrf_token' => csrf_token()],
            ]);
        } catch (\Throwable $e) {
            app_error('Login error', $e);
            json_response(['success' => false, 'message' => 'Login failed: ' . $e->getMessage()], 500);
        }
    }

    public function logout(): void
    {
        Auth::logout();
        json_response(['success' => true, 'message' => 'Logged out successfully.']);
    }

    public function me(): void
    {
        if (!Auth::check()) {
            json_response([
                'success' => true,
                'data' => [
                    'user' => null,
                    'csrf_token' => csrf_token(),
                ],
            ]);
        }

        json_response([
            'success' => true,
            'data' => [
                'user' => Auth::user(),
                'csrf_token' => csrf_token(),
            ],
        ]);
    }
}
