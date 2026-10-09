<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use PDO;

class PlatePlanService
{
    protected PDO $db;

    public function __construct(?PDO $db = null)
    {
        if ($db !== null) {
            $this->db = $db;
        } else {
            require_once dirname(__DIR__) . '/db.php';
            $this->db = get_db();
        }
    }

    /**
     * Get all meals with ingredients and counts.
     */
    public function getMeals(): array
    {
        $stmt = $this->db->query(
            'SELECT m.id, m.name, COUNT(i.id) AS ingredient_count
               FROM meals m
          LEFT JOIN ingredients i ON i.meal_id = m.id
           GROUP BY m.id
           ORDER BY m.name COLLATE NOCASE'
        );
        $meals = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $iStmt = $this->db->prepare(
            'SELECT name, quantity FROM ingredients WHERE meal_id = :mid ORDER BY name'
        );
        foreach ($meals as &$meal) {
            $iStmt->execute([':mid' => $meal['id']]);
            $meal['ingredients'] = $iStmt->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($meal);

        return $meals;
    }

    /**
     * Get distinct list of ingredient names for autocomplete.
     */
    public function getAllIngredients(): array
    {
        $stmt = $this->db->query(
            'SELECT DISTINCT name FROM ingredients ORDER BY name COLLATE NOCASE'
        );
        return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'name');
    }

    /**
     * Save/Create a meal with ingredients.
     *
     * @param array<array{name: string, quantity: string}> $ingredients
     */
    public function saveMeal(string $name, array $ingredients = []): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('Meal name is required.');
        }

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('INSERT INTO meals (name) VALUES (:name)');
            $stmt->execute([':name' => $name]);
            $mealId = (int) $this->db->lastInsertId();

            if (!empty($ingredients)) {
                $ingStmt = $this->db->prepare(
                    'INSERT INTO ingredients (meal_id, name, quantity) VALUES (:mid, :name, :qty)'
                );
                foreach ($ingredients as $ing) {
                    $ingName = trim($ing['name'] ?? '');
                    $ingQty  = trim($ing['quantity'] ?? '');
                    if ($ingName === '') {
                        continue;
                    }
                    $ingStmt->execute([
                        ':mid'  => $mealId,
                        ':name' => $ingName,
                        ':qty'  => $ingQty,
                    ]);
                }
            }

            $this->db->commit();
            return $mealId;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a meal (cascades to ingredients & weekly plan).
     */
    public function deleteMeal(int $mealId): bool
    {
        if ($mealId <= 0) {
            throw new InvalidArgumentException('Invalid meal ID.');
        }

        $stmt = $this->db->prepare('DELETE FROM meals WHERE id = :id');
        return $stmt->execute([':id' => $mealId]);
    }

    /**
     * Get weekly schedule.
     */
    public function getWeeklyPlan(): array
    {
        $stmt = $this->db->query(
            'SELECT wp.id, wp.day_of_week, wp.meal_id, wp.meal_type, m.name AS meal_name
               FROM weekly_plan wp
               JOIN meals m ON m.id = wp.meal_id
              ORDER BY wp.day_of_week,
                       CASE wp.meal_type
                         WHEN "breakfast" THEN 1
                         WHEN "lunch"     THEN 2
                         WHEN "dinner"    THEN 3
                         WHEN "snack"     THEN 4
                         ELSE 5
                       END,
                       wp.id'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Add a meal to the weekly plan.
     */
    public function addToPlan(int $dayOfWeek, int $mealId, string $mealType): int
    {
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            throw new InvalidArgumentException('Invalid day_of_week (must be 1–7).');
        }
        if ($mealId <= 0) {
            throw new InvalidArgumentException('Invalid meal_id.');
        }

        $validTypes = ['breakfast', 'lunch', 'dinner', 'snack'];
        $mealType = strtolower(trim($mealType));
        if (!in_array($mealType, $validTypes, true)) {
            throw new InvalidArgumentException('Invalid meal_type. Allowed: ' . implode(', ', $validTypes));
        }

        $stmt = $this->db->prepare(
            'INSERT INTO weekly_plan (day_of_week, meal_id, meal_type) VALUES (:day, :mid, :type)'
        );
        $stmt->execute([
            ':day'  => $dayOfWeek,
            ':mid'  => $mealId,
            ':type' => $mealType,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Change or replace a meal on a specific day and meal type slot.
     */
    public function changeMealOnDay(int $dayOfWeek, string $mealType, int $newMealId): int
    {
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            throw new InvalidArgumentException('Invalid day_of_week (must be 1–7).');
        }
        $validTypes = ['breakfast', 'lunch', 'dinner', 'snack'];
        $mealType = strtolower(trim($mealType));
        if (!in_array($mealType, $validTypes, true)) {
            throw new InvalidArgumentException('Invalid meal_type.');
        }

        // Delete existing scheduled meal in this slot
        $del = $this->db->prepare('DELETE FROM weekly_plan WHERE day_of_week = :day AND meal_type = :type');
        $del->execute([':day' => $dayOfWeek, ':type' => $mealType]);

        // Insert new meal
        return $this->addToPlan($dayOfWeek, $newMealId, $mealType);
    }

    /**
     * Update an existing plan entry with a new meal ID.
     */
    public function updatePlanMeal(int $planId, int $newMealId, ?string $newMealType = null): bool
    {
        if ($planId <= 0 || $newMealId <= 0) {
            throw new InvalidArgumentException('Invalid plan_id or meal_id.');
        }

        if ($newMealType !== null) {
            $stmt = $this->db->prepare('UPDATE weekly_plan SET meal_id = :mid, meal_type = :type WHERE id = :id');
            return $stmt->execute([
                ':mid'  => $newMealId,
                ':type' => strtolower(trim($newMealType)),
                ':id'   => $planId,
            ]);
        }

        $stmt = $this->db->prepare('UPDATE weekly_plan SET meal_id = :mid WHERE id = :id');
        return $stmt->execute([
            ':mid' => $newMealId,
            ':id'  => $planId,
        ]);
    }

    /**
     * Remove a meal from the weekly plan by schedule row ID.
     */
    public function removeFromPlan(int $planId): bool
    {
        if ($planId <= 0) {
            throw new InvalidArgumentException('Invalid plan_id.');
        }

        $stmt = $this->db->prepare('DELETE FROM weekly_plan WHERE id = :id');
        return $stmt->execute([':id' => $planId]);
    }

    /**
     * Clear all meals for a given day (1–7).
     */
    public function clearDay(int $dayOfWeek): bool
    {
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            throw new InvalidArgumentException('Invalid day_of_week.');
        }

        $stmt = $this->db->prepare('DELETE FROM weekly_plan WHERE day_of_week = :day');
        return $stmt->execute([':day' => $dayOfWeek]);
    }

    /**
     * Get aggregated grocery list.
     */
    public function getGroceryList(): array
    {
        $rawStmt = $this->db->query(
            'SELECT wp.day_of_week, wp.meal_type, m.name AS meal_name,
                    i.name AS ingredient_name, i.quantity
               FROM weekly_plan wp
               JOIN meals m       ON m.id = wp.meal_id
          LEFT JOIN ingredients i ON i.meal_id = wp.meal_id
              ORDER BY wp.day_of_week, wp.meal_type, m.name, i.name'
        );
        $rawRows = $rawStmt->fetchAll(PDO::FETCH_ASSOC);

        $totals = [];
        foreach ($rawRows as $row) {
            $ing = $row['ingredient_name'];
            if (!$ing) {
                continue;
            }
            $qty = trim((string)($row['quantity'] ?? ''));

            if (preg_match('/^(\d+(?:\.\d+)?)\s*(.*)$/', $qty, $m)) {
                $num  = (float) $m[1];
                $unit = trim($m[2]);
            } else {
                $num  = null;
                $unit = $qty;
            }

            if (!isset($totals[$ing])) {
                $totals[$ing] = ['numeric' => 0, 'unit' => $unit, 'has_numeric' => false, 'raw_list' => []];
            }
            if ($num !== null) {
                if (!$totals[$ing]['has_numeric'] || $totals[$ing]['unit'] === $unit) {
                    $totals[$ing]['numeric']   += $num;
                    $totals[$ing]['unit']        = $unit;
                    $totals[$ing]['has_numeric'] = true;
                } else {
                    $totals[$ing]['raw_list'][] = $qty;
                }
            } else {
                $totals[$ing]['raw_list'][] = $qty ?: '1';
            }
        }

        $totalsList = [];
        foreach ($totals as $name => $t) {
            if ($t['has_numeric']) {
                $display = $t['numeric'] . ($t['unit'] ? ' ' . $t['unit'] : '');
                if (!empty($t['raw_list'])) {
                    $display .= ' + ' . implode(', ', array_unique($t['raw_list']));
                }
            } else {
                $display = implode(', ', array_unique($t['raw_list'])) ?: '—';
            }
            $totalsList[] = ['name' => $name, 'total' => $display];
        }
        usort($totalsList, fn($a, $b) => strcasecmp($a['name'], $b['name']));

        return ['grocery' => $rawRows, 'totals' => $totalsList];
    }

    /**
     * Load fixture meals from src/fixture.php.
     */
    public function loadFixtures(): array
    {
        require_once dirname(__DIR__) . '/fixture.php';
        // $meals defined in fixture.php

        $this->db->beginTransaction();
        try {
            $findMeal = $this->db->prepare(
                'SELECT id FROM meals WHERE name = :name COLLATE NOCASE LIMIT 1'
            );
            $mInsert  = $this->db->prepare('INSERT INTO meals (name) VALUES (:name)');
            $delIngs  = $this->db->prepare('DELETE FROM ingredients WHERE meal_id = :mid');
            $iInsert  = $this->db->prepare(
                'INSERT INTO ingredients (meal_id, name, quantity) VALUES (:mid, :name, :qty)'
            );

            $inserted = 0;
            $updated  = 0;

            foreach ($meals as $meal) {
                $findMeal->execute([':name' => $meal['name']]);
                $existing = $findMeal->fetch(PDO::FETCH_ASSOC);

                if ($existing) {
                    $mid = (int) $existing['id'];
                    $delIngs->execute([':mid' => $mid]);
                    $updated++;
                } else {
                    $mInsert->execute([':name' => $meal['name']]);
                    $mid = (int) $this->db->lastInsertId();
                    $inserted++;
                }

                foreach ($meal['ingredients'] as $ingName => $qty) {
                    $readable = ucwords(str_replace('_', ' ', (string)$ingName));
                    $iInsert->execute([
                        ':mid'  => $mid,
                        ':name' => $readable,
                        ':qty'  => (string)$qty,
                    ]);
                }
            }

            $this->db->commit();
            return ['success' => true, 'inserted' => $inserted, 'updated' => $updated];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
