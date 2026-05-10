<?php

declare(strict_types=1);

namespace App\Core;

class TherapistRepository extends Repository
{
    protected string $table = 'therapists';

    /**
     * Find therapist by user ID
     */
    public function findByUserId(int $userId): ?array
    {
        return $this->findBy('user_id', $userId);
    }

    /**
     * Get all therapists with user details
     */
    public function allWithDetails(int $limit = 50, int $offset = 0): array
    {
        $sql = "
            SELECT
                t.id,
                u.name,
                u.email,
                u.phone,
                t.specialty,
                t.experience_years,
                t.rating,
                t.is_active,
                GROUP_CONCAT(DISTINCT ca.coverage_group ORDER BY ca.coverage_group SEPARATOR ',') AS coverage_groups_csv
            FROM {$this->table} t
            INNER JOIN users u ON u.id = t.user_id
            LEFT JOIN therapist_coverage_areas tca ON tca.therapist_id = t.id
            LEFT JOIN coverage_areas ca ON ca.id = tca.area_id
            GROUP BY t.id, u.name, u.email, u.phone, t.specialty, t.experience_years, t.rating, t.is_active
            ORDER BY t.rating DESC, t.id DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['limit' => $limit, 'offset' => $offset]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['coverage_groups'] = empty($row['coverage_groups_csv'])
                ? []
                : explode(',', (string) $row['coverage_groups_csv']);
            unset($row['coverage_groups_csv']);
        }

        return $rows;
    }

    /**
     * Count active therapists
     */
    public function countActive(): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) AS total FROM {$this->table} WHERE is_active = 1");
        $stmt->execute();
        return (int) $stmt->fetch()['total'];
    }

    /**
     * Get therapist with coverage areas
     */
    public function findWithCoverage(int $therapistId): ?array
    {
        $therapist = $this->find($therapistId);
        if (!$therapist) {
            return null;
        }

        $sql = "
            SELECT DISTINCT ca.coverage_group
            FROM therapist_coverage_areas tca
            INNER JOIN coverage_areas ca ON ca.id = tca.area_id
            WHERE tca.therapist_id = :therapist_id
            ORDER BY ca.coverage_group
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['therapist_id' => $therapistId]);
        $groups = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $therapist['coverage_groups'] = array_values(array_unique($groups));

        return $therapist;
    }

    /**
     * Get therapists by area
     */
    public function byArea(int $areaId, int $limit = 50, int $offset = 0): array
    {
        $sql = "
            SELECT DISTINCT t.id, u.name, u.email, u.phone, t.specialty, t.experience_years, t.rating
            FROM {$this->table} t
            INNER JOIN users u ON u.id = t.user_id
            INNER JOIN therapist_coverage_areas tca ON tca.therapist_id = t.id
            WHERE tca.area_id = :area_id AND t.is_active = 1
            ORDER BY t.rating DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['area_id' => $areaId, 'limit' => $limit, 'offset' => $offset]);
        return $stmt->fetchAll();
    }

    /**
     * Get therapist statistics
     */
    public function getStats(): array
    {
        $sql = "
            SELECT
                COUNT(DISTINCT id) AS total_therapists,
                SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_therapists,
                AVG(rating) AS average_rating,
                SUM(CASE WHEN experience_years > 0 THEN experience_years ELSE 0 END) AS total_experience_years
            FROM {$this->table}
        ";
        return $this->queryOne($sql) ?: [];
    }
}
