<?php
/**
 * api.php — Plate Plan REST & JSON API Endpoints
 *
 * Dedicated API handler supporting both JSON and POST form payloads for:
 * - Meals management
 * - Weekly schedule planning
 * - Grocery lists
 * - AI Agent chat & Weekly nutrition evaluation
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/db.php';
require_once __DIR__ . '/../src/request_guard.php';

use App\Services\PlatePlanService;
use App\Services\AiAgentService;

plate_plan_guard_request();

header('Content-Type: application/json; charset=UTF-8');

$rawInput = file_get_contents('php://input');
$jsonInput = json_decode($rawInput, true);

$params = array_merge(
    $_GET,
    $_POST,
    is_array($jsonInput) ? $jsonInput : []
);

$action = $params['action'] ?? '';

$platePlanService = new PlatePlanService();
$aiService        = new AiAgentService($platePlanService);

try {
    switch ($action) {
        case 'get_meals':
        case 'meals':
            echo json_encode(['meals' => $platePlanService->getMeals()]);
            break;

        case 'get_all_ingredients':
            echo json_encode(['ingredients' => $platePlanService->getAllIngredients()]);
            break;

        case 'get_weekly_plan':
        case 'weekly_plan':
            echo json_encode(['plan' => $platePlanService->getWeeklyPlan()]);
            break;

        case 'add_to_plan':
            $day  = (int)($params['day_of_week'] ?? 0);
            $mid  = (int)($params['meal_id'] ?? 0);
            $type = (string)($params['meal_type'] ?? 'lunch');
            $pid  = $platePlanService->addToPlan($day, $mid, $type);
            echo json_encode(['success' => true, 'plan_id' => $pid]);
            break;

        case 'remove_from_plan':
            $pid = (int)($params['plan_id'] ?? 0);
            $platePlanService->removeFromPlan($pid);
            echo json_encode(['success' => true]);
            break;

        case 'clear_day':
            $day = (int)($params['day_of_week'] ?? 0);
            $platePlanService->clearDay($day);
            echo json_encode(['success' => true]);
            break;

        case 'save_meal':
        case 'create_meal':
            $name = trim((string)($params['name'] ?? ''));
            $ingredients = [];

            // Handle both structured ingredients array and form fields
            if (isset($params['ingredients']) && is_array($params['ingredients'])) {
                $ingredients = $params['ingredients'];
            } else {
                $count = (int)($params['ing_count'] ?? 0);
                for ($i = 0; $i < $count; $i++) {
                    $ingName = trim((string)($params["ing_name_{$i}"] ?? ''));
                    $ingQty  = trim((string)($params["ing_quantity_{$i}"] ?? ''));
                    if ($ingName !== '') {
                        $ingredients[] = ['name' => $ingName, 'quantity' => $ingQty];
                    }
                }
            }

            $mealId = $platePlanService->saveMeal($name, $ingredients);
            echo json_encode(['success' => true, 'meal_id' => $mealId]);
            break;

        case 'delete_meal':
            $mid = (int)($params['meal_id'] ?? 0);
            $platePlanService->deleteMeal($mid);
            echo json_encode(['success' => true]);
            break;

        case 'get_grocery_list':
        case 'grocery_list':
            $list = $platePlanService->getGroceryList();
            echo json_encode($list);
            break;

        case 'load_fixtures':
            $res = $platePlanService->loadFixtures();
            echo json_encode($res);
            break;

        case 'evaluate_weekly_plan':
        case 'evaluate_week':
            $evaluation = $aiService->evaluateWeekPlan();
            echo json_encode($evaluation);
            break;

        case 'chat':
            $message = (string)($params['message'] ?? '');
            $history = (array)($params['history'] ?? []);
            $response = $aiService->chat($message, $history);
            echo json_encode($response);
            break;

        default:
            http_response_code(400);
            echo json_encode([
                'error'   => 'Unknown action: ' . htmlspecialchars($action),
                'actions' => [
                    'meals', 'weekly_plan', 'add_to_plan', 'remove_from_plan',
                    'clear_day', 'create_meal', 'delete_meal', 'grocery_list',
                    'load_fixtures', 'evaluate_weekly_plan', 'chat'
                ],
            ]);
            break;
    }
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
