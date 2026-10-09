<?php

declare(strict_types=1);

namespace App\MCP;

use App\Services\PlatePlanService;
use App\Services\PromptService;
use App\Services\AiAgentService;
use Throwable;

class McpServer
{
    protected PlatePlanService $platePlanService;

    public function __construct(?PlatePlanService $service = null)
    {
        $this->platePlanService = $service ?? new PlatePlanService();
    }

    /**
     * Handle incoming JSON-RPC 2.0 string or array.
     */
    public function handle(array|string $rawInput): array
    {
        $data = is_array($rawInput) ? $rawInput : json_decode($rawInput, true);

        if (!is_array($data)) {
            return $this->rpcError(null, -32700, 'Parse error: Invalid JSON');
        }

        $id     = $data['id'] ?? null;
        $method = $data['method'] ?? '';
        $params = $data['params'] ?? [];

        try {
            switch ($method) {
                case 'initialize':
                    return [
                        'jsonrpc' => '2.0',
                        'id'      => $id,
                        'result'  => [
                            'protocolVersion' => '2024-11-05',
                            'capabilities'    => [
                                'tools' => [
                                    'listChanged' => false,
                                ],
                            ],
                            'serverInfo' => [
                                'name'    => 'plate-plan-mcp',
                                'version' => '1.0.0',
                            ],
                        ],
                    ];

                case 'notifications/initialized':
                case 'initialized':
                    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => ['status' => 'ok']];

                case 'ping':
                    return ['jsonrpc' => '2.0', 'id' => $id, 'result' => new \stdClass()];

                case 'tools/list':
                    return [
                        'jsonrpc' => '2.0',
                        'id'      => $id,
                        'result'  => [
                            'tools' => $this->getToolsDefinitions(),
                        ],
                    ];

                case 'tools/call':
                    $toolName  = $params['name'] ?? '';
                    $arguments = $params['arguments'] ?? [];
                    $result    = $this->callTool($toolName, $arguments);

                    return [
                        'jsonrpc' => '2.0',
                        'id'      => $id,
                        'result'  => [
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                                ],
                            ],
                        ],
                    ];

                default:
                    return $this->rpcError($id, -32601, "Method '{$method}' not found");
            }
        } catch (Throwable $e) {
            return $this->rpcError($id, -32603, 'Internal server error: ' . $e->getMessage());
        }
    }

    /**
     * Tool definitions compliant with MCP specification.
     */
    public function getToolsDefinitions(): array
    {
        return [
            [
                'name'        => 'get_meals',
                'description' => 'Retrieve all meals stored in the Plate Plan library with their ingredients and quantities.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
            [
                'name'        => 'create_meal',
                'description' => 'Create a new meal with a list of ingredients and specified measurement units (e.g., "200g", "1 pc", "50ml").',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['name'],
                    'properties' => [
                        'name' => [
                            'type'        => 'string',
                            'description' => 'Name of the meal (e.g. "Mediterranean Salmon Bowl")',
                        ],
                        'ingredients' => [
                            'type'        => 'array',
                            'description' => 'List of ingredients with name and quantity with units',
                            'items'       => [
                                'type'       => 'object',
                                'required'   => ['name'],
                                'properties' => [
                                    'name'     => ['type' => 'string', 'description' => 'Ingredient name (e.g., "Salmon filet")'],
                                    'quantity' => ['type' => 'string', 'description' => 'Quantity with unit (e.g., "200g", "1 pc", "2 tbsp")'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'delete_meal',
                'description' => 'Delete an existing meal from the library by its ID.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['meal_id'],
                    'properties' => [
                        'meal_id' => [
                            'type'        => 'integer',
                            'description' => 'The ID of the meal to delete',
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'get_weekly_plan',
                'description' => 'Get the current weekly meal schedule (Monday to Sunday) across breakfast, lunch, dinner, and snack slots.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
            [
                'name'        => 'add_to_plan',
                'description' => 'Add a meal to a specific day of the week and meal slot.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['day_of_week', 'meal_id', 'meal_type'],
                    'properties' => [
                        'day_of_week' => [
                            'type'        => 'integer',
                            'description' => 'Day of the week: 1 (Mon), 2 (Tue), 3 (Wed), 4 (Thu), 5 (Fri), 6 (Sat), 7 (Sun)',
                            'minimum'     => 1,
                            'maximum'     => 7,
                        ],
                        'meal_id' => [
                            'type'        => 'integer',
                            'description' => 'ID of the meal to add',
                        ],
                        'meal_type' => [
                            'type'        => 'string',
                            'description' => 'Slot type: "breakfast", "lunch", "dinner", or "snack"',
                            'enum'        => ['breakfast', 'lunch', 'dinner', 'snack'],
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'change_meal_on_day',
                'description' => 'Change or replace the meal scheduled for a specific day and slot with a new meal.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['day_of_week', 'meal_type', 'new_meal_id'],
                    'properties' => [
                        'day_of_week' => [
                            'type'        => 'integer',
                            'description' => 'Day of the week: 1 (Mon) to 7 (Sun)',
                            'minimum'     => 1,
                            'maximum'     => 7,
                        ],
                        'meal_type' => [
                            'type'        => 'string',
                            'description' => 'Slot type: "breakfast", "lunch", "dinner", or "snack"',
                            'enum'        => ['breakfast', 'lunch', 'dinner', 'snack'],
                        ],
                        'new_meal_id' => [
                            'type'        => 'integer',
                            'description' => 'ID of the new meal to set in this slot',
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'remove_from_plan',
                'description' => 'Remove a scheduled meal entry from the weekly planner.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['plan_id'],
                    'properties' => [
                        'plan_id' => [
                            'type'        => 'integer',
                            'description' => 'The ID of the weekly_plan schedule row',
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'clear_day',
                'description' => 'Clear all scheduled meals for an entire day of the week.',
                'inputSchema' => [
                    'type'       => 'object',
                    'required'   => ['day_of_week'],
                    'properties' => [
                        'day_of_week' => [
                            'type'        => 'integer',
                            'description' => 'Day number 1–7 to clear completely',
                            'minimum'     => 1,
                            'maximum'     => 7,
                        ],
                    ],
                ],
            ],
            [
                'name'        => 'get_grocery_list',
                'description' => 'Get the consolidated weekly grocery shopping list with aggregated ingredient amounts.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
            [
                'name'        => 'evaluate_weekly_plan',
                'description' => 'Perform an AI nutritional and diversity review of the weekly meal plan.',
                'inputSchema' => [
                    'type'       => 'object',
                    'properties' => new \stdClass(),
                ],
            ],
        ];
    }

    /**
     * Execute tool implementation.
     */
    public function callTool(string $name, array $args): mixed
    {
        switch ($name) {
            case 'get_meals':
                return $this->platePlanService->getMeals();

            case 'create_meal':
                $mealName = (string)($args['name'] ?? '');
                $ings     = (array)($args['ingredients'] ?? []);
                $id       = $this->platePlanService->saveMeal($mealName, $ings);
                return [
                    'success' => true,
                    'meal_id' => $id,
                    'message' => "Meal '{$mealName}' created successfully.",
                ];

            case 'delete_meal':
                $mid = (int)($args['meal_id'] ?? 0);
                $this->platePlanService->deleteMeal($mid);
                return ['success' => true, 'message' => "Meal {$mid} deleted."];

            case 'get_weekly_plan':
                return $this->platePlanService->getWeeklyPlan();

            case 'add_to_plan':
                $day  = (int)($args['day_of_week'] ?? 1);
                $mid  = (int)($args['meal_id'] ?? 0);
                $type = (string)($args['meal_type'] ?? 'lunch');
                $pid  = $this->platePlanService->addToPlan($day, $mid, $type);
                return [
                    'success' => true,
                    'plan_id' => $pid,
                    'message' => "Added meal {$mid} to day {$day} as {$type}.",
                ];

            case 'change_meal_on_day':
            case 'update_meal_on_day':
                $day  = (int)($args['day_of_week'] ?? 1);
                $mid  = (int)($args['new_meal_id'] ?? ($args['meal_id'] ?? 0));
                $type = (string)($args['meal_type'] ?? 'lunch');
                $pid  = $this->platePlanService->changeMealOnDay($day, $type, $mid);
                return [
                    'success' => true,
                    'plan_id' => $pid,
                    'message' => "Changed meal on day {$day} ({$type}) to meal {$mid}.",
                ];

            case 'remove_from_plan':
                $pid = (int)($args['plan_id'] ?? 0);
                $this->platePlanService->removeFromPlan($pid);
                return ['success' => true, 'message' => "Removed plan entry {$pid}."];

            case 'clear_day':
                $day = (int)($args['day_of_week'] ?? 1);
                $this->platePlanService->clearDay($day);
                return ['success' => true, 'message' => "Cleared day {$day}."];

            case 'get_grocery_list':
                return $this->platePlanService->getGroceryList();

            case 'evaluate_weekly_plan':
                $ai = new AiAgentService($this->platePlanService);
                return $ai->evaluateWeekPlan();

            default:
                throw new \InvalidArgumentException("Unknown tool: {$name}");
        }
    }

    protected function rpcError(?int $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => [
                'code'    => $code,
                'message' => $message,
            ],
        ];
    }
}
