<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

abstract class Repository
{
    protected PDO $pdo;
    protected string $table;

    public function __construct()
    {
        $this->pdo = Database::connection();
    }

    /**
     * Fetch all records with optional pagination and filtering
     */
    public function all(array $filters = [], array $sort = [], int $limit = 50, int $offset = 0): array
    {
        $query = "SELECT * FROM {$this->table}";
        $params = [];
        $where = $this->buildWhere($filters, $params);

        if (!empty($where)) {
            $query .= " WHERE " . $where;
        }

        if (!empty($sort)) {
            $orderBy = [];
            foreach ($sort as $field => $direction) {
                $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
                $orderBy[] = "{$field} {$direction}";
            }
            $query .= " ORDER BY " . implode(', ', $orderBy);
        }

        $query .= " LIMIT :limit OFFSET :offset";
        $params['limit'] = $limit;
        $params['offset'] = $offset;

        $stmt = $this->pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT === gettype($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Count total records with optional filters
     */
    public function count(array $filters = []): int
    {
        $query = "SELECT COUNT(*) AS total FROM {$this->table}";
        $params = [];
        $where = $this->buildWhere($filters, $params);

        if (!empty($where)) {
            $query .= " WHERE " . $where;
        }

        $stmt = $this->pdo->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT === gettype($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->execute();
        return (int) $stmt->fetch()['total'];
    }

    /**
     * Find a record by ID
     */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Find by custom field
     */
    public function findBy(string $field, mixed $value): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->table} WHERE {$field} = :value LIMIT 1");
        $stmt->execute(['value' => $value]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Create a new record
     */
    public function create(array $data): int
    {
        $data['created_at'] = $data['created_at'] ?? now();
        $data['updated_at'] = $data['updated_at'] ?? now();

        $fields = array_keys($data);
        $placeholders = array_map(fn($f) => ":{$f}", $fields);

        $query = "INSERT INTO {$this->table} (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($data);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Update a record
     */
    public function update(int $id, array $data): bool
    {
        $data['updated_at'] = now();
        unset($data['id'], $data['created_at']);

        $sets = array_map(fn($f) => "{$f} = :{$f}", array_keys($data));
        $query = "UPDATE {$this->table} SET " . implode(', ', $sets) . " WHERE id = :id";

        $data['id'] = $id;
        $stmt = $this->pdo->prepare($query);
        return $stmt->execute($data);
    }

    /**
     * Delete a record
     */
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = :id LIMIT 1");
        return $stmt->execute(['id' => $id]);
    }

    /**
     * Execute a custom query
     */
    protected function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Execute a custom query expecting single result
     */
    protected function queryOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    /**
     * Build WHERE clause from filters
     */
    protected function buildWhere(array $filters, array &$params): string
    {
        $conditions = [];
        foreach ($filters as $field => $value) {
            $key = str_replace('.', '_', $field);
            $conditions[] = "{$field} = :{$key}";
            $params[$key] = $value;
        }
        return implode(' AND ', $conditions);
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): void
    {
        $this->pdo->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): void
    {
        $this->pdo->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }
}
