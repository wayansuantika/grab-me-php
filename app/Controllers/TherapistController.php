<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;

class TherapistController
{
    public function list(): void
    {
        $areaId = isset($_GET['area_id']) ? (int) $_GET['area_id'] : null;
        $serviceId = isset($_GET['service_id']) ? (int) $_GET['service_id'] : null;
        $date = isset($_GET['date']) ? (string) $_GET['date'] : null;
        $time = isset($_GET['time']) ? (string) $_GET['time'] : null;

        $pdo = Database::connection();

        $sql = "
            SELECT DISTINCT t.id, t.user_id, u.name, t.bio, t.experience_years, t.rating, t.photo_url, t.is_active
            FROM therapists t
            INNER JOIN users u ON u.id = t.user_id
            LEFT JOIN therapist_coverage_areas tca ON tca.therapist_id = t.id
            LEFT JOIN therapist_services ts ON ts.therapist_id = t.id
            WHERE t.is_active = 1
              AND u.status = 'active'
                            AND (:area_id_null = 1 OR tca.area_id = :area_id)
                            AND (:service_id_null = 1 OR ts.service_id = :service_id)
        ";

        if ($date !== null && $time !== null) {
            $sql .= "
              AND (
                NOT EXISTS (SELECT 1 FROM therapist_schedules s2 WHERE s2.therapist_id = t.id)
                OR EXISTS (
                  SELECT 1
                  FROM therapist_schedules sch
                  WHERE sch.therapist_id = t.id
                    AND sch.day_of_week = WEEKDAY(:booking_date)
                    AND sch.is_available = 1
                    AND :booking_time BETWEEN sch.start_time AND sch.end_time
                )
              )
            ";
        }

        $sql .= ' ORDER BY t.rating DESC, u.name ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':area_id_null', $areaId === null ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(':area_id', $areaId, $areaId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
        $stmt->bindValue(':service_id_null', $serviceId === null ? 1 : 0, \PDO::PARAM_INT);
        $stmt->bindValue(':service_id', $serviceId, $serviceId === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);

        if ($date !== null && $time !== null) {
            $stmt->bindValue(':booking_date', $date);
            $stmt->bindValue(':booking_time', $time);
        }

        $stmt->execute();
        $therapists = $stmt->fetchAll();

        json_response(['success' => true, 'data' => ['therapists' => $therapists]]);
    }

    public function detail(int $id): void
    {
        $pdo = Database::connection();

        $therapistStmt = $pdo->prepare('
            SELECT t.id, u.name, u.email, t.bio, t.experience_years, t.specialty, t.rating, t.photo_url
            FROM therapists t
            INNER JOIN users u ON u.id = t.user_id
            WHERE t.id = :id AND t.is_active = 1
            LIMIT 1
        ');
        $therapistStmt->execute(['id' => $id]);
        $therapist = $therapistStmt->fetch();

        if (!$therapist) {
            json_response(['success' => false, 'message' => 'Therapist not found.'], 404);
        }

        $servicesStmt = $pdo->prepare('
            SELECT s.id, s.name, s.duration_minutes, s.price
            FROM therapist_services ts
            INNER JOIN services s ON s.id = ts.service_id
            WHERE ts.therapist_id = :id AND s.is_active = 1
            ORDER BY s.name ASC
        ');
        $servicesStmt->execute(['id' => $id]);

        $areasStmt = $pdo->prepare('
            SELECT ca.id, ca.name, ca.coverage_group
            FROM therapist_coverage_areas tca
            INNER JOIN coverage_areas ca ON ca.id = tca.area_id
            WHERE tca.therapist_id = :id AND ca.is_active = 1
            ORDER BY ca.name ASC
        ');
        $areasStmt->execute(['id' => $id]);

        json_response([
            'success' => true,
            'data' => [
                'therapist' => $therapist,
                'services' => $servicesStmt->fetchAll(),
                'areas' => $areasStmt->fetchAll(),
            ],
        ]);
    }
}
