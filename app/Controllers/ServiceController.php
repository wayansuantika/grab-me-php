<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;

class ServiceController
{
    public function categoriesWithServices(): void
    {
        $pdo = Database::connection();

        $categories = $pdo->query('SELECT id, name, description FROM service_categories WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
        $services = $pdo->query('SELECT id, category_id, name, description, image_url, duration_minutes, price, is_addon, is_active FROM services WHERE is_active = 1 ORDER BY is_addon ASC, sort_order ASC, id ASC')->fetchAll();

        $grouped = [];
        foreach ($categories as $category) {
            $grouped[(int) $category['id']] = [
                'id' => (int) $category['id'],
                'name' => $category['name'],
                'description' => $category['description'],
                'services' => [],
            ];
        }

        foreach ($services as $service) {
            $categoryId = (int) $service['category_id'];
            if (!isset($grouped[$categoryId])) {
                continue;
            }

            $grouped[$categoryId]['services'][] = [
                'id' => (int) $service['id'],
                'name' => $service['name'],
                'description' => $service['description'],
                'image_url' => $service['image_url'] ?? null,
                'duration_minutes' => (int) $service['duration_minutes'],
                'price' => (float) $service['price'],
                'is_addon' => (bool) $service['is_addon'],
            ];
        }

        $visibleCategories = array_values(array_filter($grouped, static function (array $category): bool {
            return !empty($category['services']);
        }));

        json_response(['success' => true, 'data' => ['categories' => $visibleCategories]]);
    }

    public function areas(): void
    {
        $pdo = Database::connection();
        $areas = $pdo->query('SELECT id, name, coverage_group, is_active FROM coverage_areas WHERE is_active = 1 ORDER BY name ASC')->fetchAll();

        json_response(['success' => true, 'data' => ['areas' => $areas]]);
    }
}
