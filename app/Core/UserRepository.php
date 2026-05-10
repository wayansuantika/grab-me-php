<?php

declare(strict_types=1);

namespace App\Core;

class UserRepository extends Repository
{
    protected string $table = 'users';

    /**
     * Find user by email
     */
    public function findByEmail(string $email): ?array
    {
        return $this->findBy('email', $email);
    }

    /**
     * Check if email exists
     */
    public function emailExists(string $email, ?int $exceptId = null): bool
    {
        $query = "SELECT COUNT(*) AS total FROM {$this->table} WHERE email = :email";
        $params = ['email' => $email];

        if ($exceptId) {
            $query .= " AND id != :id";
            $params['id'] = $exceptId;
        }

        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return (int) $stmt->fetch()['total'] > 0;
    }

    /**
     * Get users by role
     */
    public function byRole(string $role, int $limit = 50, int $offset = 0): array
    {
        $query = "SELECT * FROM {$this->table} WHERE role = :role ORDER BY id DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute(['role' => $role, 'limit' => $limit, 'offset' => $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Count users by role
     */
    public function countByRole(string $role): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) AS total FROM {$this->table} WHERE role = :role");
        $stmt->execute(['role' => $role]);
        return (int) $stmt->fetch()['total'];
    }
}
