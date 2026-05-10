<?php

declare(strict_types=1);

namespace App\Core;

class ServiceRepository extends Repository
{
    protected string $table = 'services';

    /**
     * Get all active services
     */
    public function active(): array
    {
        return $this->queryMany('SELECT * FROM ' . $this->table . ' WHERE is_active = 1 ORDER BY name ASC');
    }

    /**
     * Get main services (not add-ons)
     */
    public function mainServices(): array
    {
        return $this->queryMany('
            SELECT * FROM ' . $this->table . '
            WHERE is_active = 1 AND is_addon = 0
            ORDER BY name ASC
        ');
    }

    /**
     * Get add-on services
     */
    public function addOns(): array
    {
        return $this->queryMany('
            SELECT * FROM ' . $this->table . '
            WHERE is_active = 1 AND is_addon = 1
            ORDER BY name ASC
        ');
    }

    /**
     * Get services by category
     */
    public function byCategory(int $categoryId): array
    {
        return $this->queryMany('
            SELECT * FROM ' . $this->table . '
            WHERE category_id = :category_id AND is_active = 1
            ORDER BY name ASC
        ', ['category_id' => $categoryId]);
    }

    /**
     * Get services with pricing details
     */
    public function withPricing(): array
    {
        return $this->queryMany('
            SELECT
                id, name, description, price, is_addon, is_active,
                CONCAT(\'$\', FORMAT(price, 2)) AS formatted_price,
                created_at, updated_at
            FROM ' . $this->table . '
            WHERE is_active = 1
            ORDER BY name ASC
        ');
    }

    /**
     * Search services by name
     */
    public function search(string $query): array
    {
        $searchTerm = '%' . $query . '%';
        return $this->queryMany('
            SELECT * FROM ' . $this->table . '
            WHERE is_active = 1 AND (name LIKE :query OR description LIKE :query)
            ORDER BY name ASC
            LIMIT 20
        ', ['query' => $searchTerm]);
    }

    /**
     * Get services for a therapist's specializations
     */
    public function byTherapist(int $therapistId): array
    {
        return $this->queryMany('
            SELECT DISTINCT s.*
            FROM ' . $this->table . ' s
            INNER JOIN therapist_specializations ts ON ts.service_id = s.id
            WHERE ts.therapist_id = :therapist_id AND s.is_active = 1
            ORDER BY s.name ASC
        ', ['therapist_id' => $therapistId]);
    }

    /**
     * Get popular services (most booked)
     */
    public function popular(int $limit = 10): array
    {
        return $this->queryMany('
            SELECT s.*, COUNT(bi.id) AS booking_count
            FROM ' . $this->table . ' s
            LEFT JOIN booking_items bi ON bi.service_id = s.id
            WHERE s.is_active = 1 AND s.is_addon = 0
            GROUP BY s.id
            ORDER BY booking_count DESC
            LIMIT :limit
        ', ['limit' => $limit]);
    }
}
