<?php

declare(strict_types=1);

namespace App\Services;

use App\AI\CallbackTool;
use App\AI\ProviderFactory;
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\AssistantMessage;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;
use Throwable;

/**
 * Plate Plan AI assistant, built on Neuron AI so any supported provider
 * (Gemini, OpenAI, Anthropic, Ollama, ...) can be selected in .env.local.
 * See App\AI\ProviderFactory for the configuration variables.
 */
class AiAgentService
{
    protected PlatePlanService $platePlanService;

    public function __construct(?PlatePlanService $service = null)
    {
        $this->platePlanService = $service ?? new PlatePlanService();
    }

    /**
     * Whether an AI provider is configured; otherwise the app uses its offline heuristics.
     */
    public function hasApiKey(): bool
    {
        return ProviderFactory::isConfigured();
    }

    /**
     * Chat with the Plate Plan assistant.
     *
     * @param array<array{role: string, content: string}> $history
     */
    public function chat(string $userMessage, array $history = []): array
    {
        $userMessage = trim($userMessage);
        if ($userMessage === '') {
            return [
                'reply'   => 'Please provide a message or instruction.',
                'actions' => [],
            ];
        }

        if (!$this->hasApiKey()) {
            return $this->handleFallbackChat($userMessage);
        }

        try {
            return $this->runAgent($userMessage, $history);
        } catch (Throwable $e) {
            return [
                'reply'   => '⚠️ AI Error (' . $this->providerLabel() . '): ' . $e->getMessage() . (preg_match('/\b(401|403)\b|api.?key|unauthori[sz]ed/i', $e->getMessage())
                    ? "\n\nPlease check the API key for your AI provider in `.env.local`."
                    : "\n\nPlease try again in a moment."),
                'actions' => [],
            ];
        }
    }

    /**
     * Evaluate the weekly meal plan.
     */
    public function evaluateWeekPlan(): array
    {
        $weeklyPlan = $this->platePlanService->getWeeklyPlan();
        $allMeals   = $this->platePlanService->getMeals();

        $promptText   = PromptService::formatEvaluationPrompt($weeklyPlan, $allMeals);
        $systemPrompt = PromptService::getEvaluationSystemPrompt();
        $disclaimer   = PromptService::getNoticeDisclaimer();

        if (!$this->hasApiKey()) {
            return [
                'evaluation' => $this->generateLocalEvaluation($weeklyPlan, $allMeals),
                'disclaimer' => $disclaimer,
                'source'     => 'local_heuristic',
                'notice'     => 'Note: Running in offline/demo mode. Configure an AI provider in .env.local (see README) to enable full AI evaluations.',
            ];
        }

        try {
            set_time_limit(300);
            $state = Agent::make()
                ->setAiProvider(ProviderFactory::make())
                ->setInstructions($systemPrompt)
                ->setThreadId(bin2hex(random_bytes(8)))
                ->chat(new UserMessage($promptText));

            $text = trim((string)$state->getMessage()?->getContent());

            return [
                'evaluation' => $text !== '' ? $text : 'Unable to generate evaluation at this time.',
                'disclaimer' => $disclaimer,
                'source'     => ProviderFactory::providerName(),
                'model'      => ProviderFactory::model(),
            ];
        } catch (Throwable $e) {
            return [
                'evaluation' => $this->generateLocalEvaluation($weeklyPlan, $allMeals) . "\n\n*(AI provider encountered an error: " . $e->getMessage() . ")*",
                'disclaimer' => $disclaimer,
                'source'     => 'local_fallback',
                'error'      => $e->getMessage(),
            ];
        }
    }

    /**
     * Run the tool-calling agent for one user message.
     */
    protected function runAgent(string $userMessage, array $history): array
    {
        // Tool loops with several provider round-trips can outlast PHP's default limit
        set_time_limit(300);

        $actionsPerformed = [];
        $agent = Agent::make()
            ->setAiProvider(ProviderFactory::make())
            ->setInstructions(PromptService::getAssistantSystemPrompt())
            ->setTools($this->buildTools($actionsPerformed))
            // History comes from the browser on every request, so each request is its own thread
            ->setThreadId(bin2hex(random_bytes(8)));

        $messages = [];
        foreach ($history as $h) {
            $content = (string)($h['content'] ?? '');
            if ($content === '') {
                continue;
            }
            $messages[] = in_array($h['role'] ?? '', ['assistant', 'model'], true)
                ? new AssistantMessage($content)
                : new UserMessage($content);
        }
        $messages[] = new UserMessage($userMessage);

        $reply = (string)$agent->chat($messages)->getMessage()?->getContent();

        // A planning request must save meals. Some models answer with a written plan
        // instead, so insist once before giving up.
        if (self::isPlanningRequest($userMessage) && !self::hasWrittenToPlan($actionsPerformed)) {
            $reply = (string)$agent->chat(new UserMessage(
                'You have not saved anything yet. Do not describe the plan: call get_meals if needed, '
                . 'then save the meals with a single plan_meals call, and only then summarize briefly.'
            ))->getMessage()?->getContent();
        }

        return [
            'reply'   => trim($reply) !== '' ? $reply : 'Actions completed successfully.',
            'actions' => $actionsPerformed,
        ];
    }

    /**
     * The tools the agent can call. Each one runs executeTool() and records itself in $actions.
     *
     * @return CallbackTool[]
     */
    protected function buildTools(array &$actions): array
    {
        $day      = fn (bool $required = true) => new ToolProperty('day_of_week', PropertyType::INTEGER, '1=Monday, 2=Tuesday, 3=Wednesday, 4=Thursday, 5=Friday, 6=Saturday, 7=Sunday', $required);
        $mealType = fn () => new ToolProperty('meal_type', PropertyType::STRING, 'Meal slot', true, ['breakfast', 'lunch', 'dinner', 'snack']);

        $definitions = [
            ['get_meals', 'Retrieve all available meals with their IDs and ingredients.', []],
            ['get_weekly_plan', 'Get the current weekly meal planner schedule, including plan_id of each entry.', []],
            ['add_to_plan', 'Add one meal to a specific day and slot.', [
                $day(),
                new ToolProperty('meal_id', PropertyType::INTEGER, 'ID of the meal', true),
                $mealType(),
            ]],
            ['plan_meals', 'Save many meals to the weekly plan in ONE call. Use this to plan a whole week or several days instead of calling add_to_plan repeatedly.', [
                new ArrayProperty('entries', 'Meals to save', true, new ObjectProperty('entry', 'One planned meal', true, null, [
                    $day(),
                    $mealType(),
                    new ToolProperty('meal_id', PropertyType::INTEGER, 'ID of an existing meal', true),
                ])),
                new ToolProperty('replace_existing', PropertyType::BOOLEAN, 'true = replace whatever is already in each slot; false (default) = add alongside it'),
            ]],
            ['change_meal_on_day', 'Change or replace the meal scheduled for a specific day and slot with a new meal.', [
                $day(),
                $mealType(),
                new ToolProperty('new_meal_id', PropertyType::INTEGER, 'ID of the new meal to set', true),
            ]],
            ['remove_from_plan', 'Remove a planned meal by its plan_id (from get_weekly_plan).', [
                new ToolProperty('plan_id', PropertyType::INTEGER, 'The plan entry ID to delete', true),
            ]],
            ['clear_day', 'Clear all scheduled meals for a given day.', [$day()]],
            ['clear_week', 'Clear all scheduled meals for the whole week (Monday to Sunday).', []],
            ['create_meal', 'Create a new meal with ingredients with explicit units (e.g. 200g, 1 pc, 15 ml). Returns the new meal_id.', [
                new ToolProperty('name', PropertyType::STRING, 'Name of the meal', true),
                new ArrayProperty('ingredients', 'Ingredients of the meal', false, new ObjectProperty('ingredient', 'One ingredient', true, null, [
                    new ToolProperty('name', PropertyType::STRING, 'Ingredient name', true),
                    new ToolProperty('quantity', PropertyType::STRING, 'Quantity with unit (e.g. 200g, 1 pc)'),
                ])),
            ]],
            ['delete_meal', 'Delete a meal from the library by ID.', [
                new ToolProperty('meal_id', PropertyType::INTEGER, 'Meal ID to delete', true),
            ]],
            ['get_grocery_list', 'Fetch the consolidated weekly grocery list.', []],
        ];

        $tools = [];
        foreach ($definitions as [$name, $description, $properties]) {
            $tools[] = new CallbackTool($name, $description, $properties, function (array $args) use ($name, &$actions) {
                $args = json_decode((string)json_encode($args), true) ?: [];
                try {
                    $result = $this->executeTool($name, $args);
                } catch (Throwable $e) {
                    // Report the failure back so the model can correct itself (e.g. a wrong meal_id)
                    $result = ['success' => false, 'error' => $e->getMessage()];
                }
                $actions[] = ['tool' => $name, 'arguments' => $args, 'result' => $result];
                return $result;
            });
        }
        return $tools;
    }

    protected function providerLabel(): string
    {
        try {
            return ProviderFactory::providerName() . ' · ' . ProviderFactory::model();
        } catch (Throwable) {
            return 'not configured';
        }
    }

    /**
     * Whether the user is asking to fill the plan (e.g. "Plan meals for this week", "plan Monday").
     */
    protected static function isPlanningRequest(string $message): bool
    {
        $lower = strtolower($message);
        return (bool)preg_match('/\b(plan|fill|schedule|populate)\b/', $lower)
            && (bool)preg_match('/\b(week|days?|monday|tuesday|wednesday|thursday|friday|saturday|sunday|weekend|meals?)\b/', $lower)
            && !preg_match('/\b(evaluate|review|grocery|shopping)\b/', $lower);
    }

    /**
     * Whether any tool call so far has added meals to the plan.
     */
    protected static function hasWrittenToPlan(array $actions): bool
    {
        foreach ($actions as $action) {
            if (in_array($action['tool'], ['plan_meals', 'add_to_plan', 'change_meal_on_day', 'update_meal_on_day'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Execute tool against PlatePlanService.
     */
    protected function executeTool(string $name, array $args): mixed
    {
        switch ($name) {
            case 'get_meals':
                return $this->platePlanService->getMeals();

            case 'get_weekly_plan':
                return $this->platePlanService->getWeeklyPlan();

            case 'add_to_plan':
                $day  = (int)($args['day_of_week'] ?? 1);
                $mid  = (int)($args['meal_id'] ?? 0);
                $type = (string)($args['meal_type'] ?? 'lunch');
                $pid  = $this->platePlanService->addToPlan($day, $mid, $type);
                return ['success' => true, 'plan_id' => $pid, 'message' => "Meal added to day {$day} as {$type}"];

            case 'plan_meals':
                $replace = (bool)($args['replace_existing'] ?? false);
                $added = [];
                $failed = [];
                foreach ((array)($args['entries'] ?? []) as $entry) {
                    $day  = (int)($entry['day_of_week'] ?? 0);
                    $mid  = (int)($entry['meal_id'] ?? 0);
                    $type = (string)($entry['meal_type'] ?? '');
                    try {
                        $pid = $replace
                            ? $this->platePlanService->changeMealOnDay($day, $type, $mid)
                            : $this->platePlanService->addToPlan($day, $mid, $type);
                        $added[] = ['plan_id' => $pid, 'day_of_week' => $day, 'meal_type' => $type, 'meal_id' => $mid];
                    } catch (Throwable $e) {
                        $failed[] = ['day_of_week' => $day, 'meal_type' => $type, 'meal_id' => $mid, 'error' => $e->getMessage()];
                    }
                }
                return ['success' => empty($failed), 'added' => $added, 'failed' => $failed];

            case 'change_meal_on_day':
            case 'update_meal_on_day':
                $day  = (int)($args['day_of_week'] ?? 1);
                $mid  = (int)($args['new_meal_id'] ?? ($args['meal_id'] ?? 0));
                $type = (string)($args['meal_type'] ?? 'lunch');
                $pid  = $this->platePlanService->changeMealOnDay($day, $type, $mid);
                return ['success' => true, 'plan_id' => $pid, 'message' => "Changed day {$day} {$type} to meal {$mid}"];

            case 'remove_from_plan':
                $pid = (int)($args['plan_id'] ?? 0);
                $this->platePlanService->removeFromPlan($pid);
                return ['success' => true, 'message' => "Removed plan item {$pid}"];

            case 'clear_day':
                $day = (int)($args['day_of_week'] ?? 1);
                $this->platePlanService->clearDay($day);
                return ['success' => true, 'message' => "Cleared all meals for day {$day}"];

            case 'clear_week':
                for ($day = 1; $day <= 7; $day++) {
                    $this->platePlanService->clearDay($day);
                }
                return ['success' => true, 'message' => 'Cleared all meals for the whole week'];

            case 'create_meal':
                $name = (string)($args['name'] ?? '');
                $ings = (array)($args['ingredients'] ?? []);
                $mid  = $this->platePlanService->saveMeal($name, $ings);
                return ['success' => true, 'meal_id' => $mid, 'message' => "Created meal '{$name}' with ID {$mid}"];

            case 'delete_meal':
                $mid = (int)($args['meal_id'] ?? 0);
                $this->platePlanService->deleteMeal($mid);
                return ['success' => true, 'message' => "Deleted meal {$mid}"];

            case 'get_grocery_list':
                return $this->platePlanService->getGroceryList();

            case 'evaluate_weekly_plan':
                return $this->evaluateWeekPlan();

            default:
                return ['error' => "Unknown tool: {$name}"];
        }
    }

    /**
     * Fallback chat when no AI provider is configured.
     * Provides smart keyword parsing so users can test immediately!
     */
    protected function handleFallbackChat(string $message): array
    {
        $lower = strtolower($message);
        $actions = [];
        $meals = $this->platePlanService->getMeals();

        $dayMap = [
            'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
            'friday' => 5, 'saturday' => 6, 'sunday' => 7,
            'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7,
        ];

        // 1. Evaluate weekly plan
        if (str_contains($lower, 'evaluate') || str_contains($lower, 'review') || str_contains($lower, 'health') || str_contains($lower, 'analysis')) {
            $eval = $this->evaluateWeekPlan();
            return [
                'reply'   => $eval['evaluation'] . "\n\n💡 *Tip: Configure an AI provider in `.env.local` (see README) for advanced generative nutritional analysis.*",
                'actions' => [],
            ];
        }

        // 2a. Clear the whole week
        if (str_contains($lower, 'clear') && str_contains($lower, 'week')) {
            for ($day = 1; $day <= 7; $day++) {
                $this->platePlanService->clearDay($day);
            }
            return [
                'reply'   => "Cleared all meals for the **whole week**.",
                'actions' => [['tool' => 'clear_week']],
            ];
        }

        // 2. Clear entire day
        if (str_contains($lower, 'clear') && (str_contains($lower, 'day') || str_contains($lower, 'monday') || str_contains($lower, 'tuesday') || str_contains($lower, 'wednesday') || str_contains($lower, 'thursday') || str_contains($lower, 'friday') || str_contains($lower, 'saturday') || str_contains($lower, 'sunday'))) {
            foreach ($dayMap as $name => $num) {
                if (str_contains($lower, $name)) {
                    $this->platePlanService->clearDay($num);
                    return [
                        'reply'   => "Cleared all meals scheduled for **" . ucfirst($name) . "**.",
                        'actions' => [['tool' => 'clear_day', 'day_of_week' => $num]],
                    ];
                }
            }
        }

        // 3. Plan the week
        if (str_contains($lower, 'plan') && (str_contains($lower, 'week') || str_contains($lower, 'meals'))) {
            $actions = [];
            $mealPool = $meals;
            shuffle($mealPool);
            for ($d = 1; $d <= 7; $d++) {
                foreach (['breakfast', 'lunch', 'dinner'] as $slot) {
                    if (empty($mealPool)) $mealPool = $meals;
                    $selected = array_shift($mealPool);
                    if ($selected) {
                        $pid = $this->platePlanService->changeMealOnDay($d, $slot, (int)$selected['id']);
                        $actions[] = ['tool' => 'change_meal_on_day', 'day_of_week' => $d, 'meal_type' => $slot, 'meal_id' => $selected['id']];
                    }
                }
            }
            return [
                'reply'   => "✨ Successfully planned meals for your entire week (Monday to Sunday) across breakfast, lunch, and dinner! Check out your updated planner.",
                'actions' => $actions,
            ];
        }

        // 4. Change / Update / Replace meal on a day
        if (str_contains($lower, 'change') || str_contains($lower, 'update') || str_contains($lower, 'replace') || str_contains($lower, 'switch') || str_contains($lower, 'swap')) {
            $foundDay = 1;
            foreach ($dayMap as $name => $num) {
                if (str_contains($lower, $name)) {
                    $foundDay = $num;
                    break;
                }
            }

            $foundType = 'lunch';
            if (str_contains($lower, 'breakfast')) $foundType = 'breakfast';
            elseif (str_contains($lower, 'dinner')) $foundType = 'dinner';
            elseif (str_contains($lower, 'snack')) $foundType = 'snack';

            foreach ($meals as $m) {
                if (str_contains($lower, strtolower($m['name']))) {
                    $pid = $this->platePlanService->changeMealOnDay($foundDay, $foundType, (int)$m['id']);
                    $dayName = array_search($foundDay, $dayMap) ?: 'Day ' . $foundDay;
                    return [
                        'reply'   => "Updated **" . ucfirst((string)$dayName) . "** **{$foundType}** to **{$m['name']}**! 🔄",
                        'actions' => [['tool' => 'change_meal_on_day', 'plan_id' => $pid, 'day_of_week' => $foundDay, 'meal_type' => $foundType, 'meal_name' => $m['name']]],
                    ];
                }
            }
        }

        // 5. Remove specific meal from day
        if (str_contains($lower, 'remove') || str_contains($lower, 'delete') && !str_contains($lower, 'create')) {
            $foundDay = 1;
            foreach ($dayMap as $name => $num) {
                if (str_contains($lower, $name)) {
                    $foundDay = $num;
                    break;
                }
            }

            $foundType = null;
            if (str_contains($lower, 'breakfast')) $foundType = 'breakfast';
            elseif (str_contains($lower, 'dinner')) $foundType = 'dinner';
            elseif (str_contains($lower, 'lunch')) $foundType = 'lunch';
            elseif (str_contains($lower, 'snack')) $foundType = 'snack';

            $plan = $this->platePlanService->getWeeklyPlan();
            foreach ($plan as $p) {
                if ((int)$p['day_of_week'] === $foundDay && ($foundType === null || $p['meal_type'] === $foundType)) {
                    $this->platePlanService->removeFromPlan((int)$p['id']);
                    $dayName = array_search($foundDay, $dayMap) ?: 'Day ' . $foundDay;
                    return [
                        'reply'   => "Removed **{$p['meal_name']}** from **" . ucfirst((string)$dayName) . "** ({$p['meal_type']}). 🗑️",
                        'actions' => [['tool' => 'remove_from_plan', 'plan_id' => $p['id']]],
                    ];
                }
            }
        }

        // 6. Create meal
        if (str_contains($lower, 'create') || str_contains($lower, 'new meal')) {
            if (preg_match('/create (?:new )?meal\s+([^,]+)/i', $message, $m)) {
                $mName = trim($m[1]);
                $mid = $this->platePlanService->saveMeal($mName, [
                    ['name' => 'Main Ingredient', 'quantity' => '200g'],
                ]);
                return [
                    'reply'   => "Created new meal **{$mName}** (ID: {$mid})! You can now drag it into any day slot or ask me to schedule it.",
                    'actions' => [['tool' => 'create_meal', 'meal_id' => $mid, 'meal_name' => $mName]],
                ];
            }
        }

        // 7. Add meal to day
        if (str_contains($lower, 'add')) {
            $foundDay = 1;
            foreach ($dayMap as $name => $num) {
                if (str_contains($lower, $name)) {
                    $foundDay = $num;
                    break;
                }
            }

            $foundType = 'lunch';
            if (str_contains($lower, 'breakfast')) $foundType = 'breakfast';
            elseif (str_contains($lower, 'dinner')) $foundType = 'dinner';
            elseif (str_contains($lower, 'snack')) $foundType = 'snack';

            foreach ($meals as $m) {
                if (str_contains($lower, strtolower($m['name']))) {
                    $pid = $this->platePlanService->addToPlan($foundDay, (int)$m['id'], $foundType);
                    $dayName = array_search($foundDay, $dayMap) ?: 'Day ' . $foundDay;
                    return [
                        'reply'   => "Added **{$m['name']}** to **" . ucfirst((string)$dayName) . "** for **{$foundType}**! 🎉",
                        'actions' => [['tool' => 'add_to_plan', 'plan_id' => $pid, 'day_of_week' => $foundDay, 'meal_type' => $foundType, 'meal_name' => $m['name']]],
                    ];
                }
            }
        }

        return [
            'reply' => "👋 Hi! I'm your **Plate Plan AI Assistant**.\n\n" .
                       "You can ask me to:\n" .
                       "• *\"Add Chicken Salad to Monday lunch\"*\n" .
                       "• *\"Change Wednesday dinner to Salmon with Rice\"*\n" .
                       "• *\"Plan meals for this week\"*\n" .
                       "• *\"Remove meal from Tuesday lunch\"*\n" .
                       "• *\"Create a new meal Avocado Toast\"*\n" .
                       "• *\"Clear Thursday\"*\n" .
                       "• *\"Evaluate my weekly meal plan\"*\n\n" .
                       "🔑 **To unlock full conversational AI:**\n" .
                       "Set `AI_PROVIDER` (gemini, openai, anthropic, ollama, ...) and its API key in `.env.local`.",
            'actions' => [],
        ];
    }

    /**
     * Local heuristic evaluation for instant feedback even without an API key.
     */
    protected function generateLocalEvaluation(array $weeklyPlan, array $allMeals): string
    {
        $plannedCount = count($weeklyPlan);
        $daysCovered  = count(array_unique(array_column($weeklyPlan, 'day_of_week')));

        $score = min(100, max(20, (int)($plannedCount * 5.5 + $daysCovered * 5)));

        $out = "### 🏆 Overall Plan Score: {$score}/100\n\n";
        $out .= "**Weekly Summary:** You currently have **{$plannedCount} meals scheduled** across **{$daysCovered} of 7 days**.\n\n";

        $out .= "#### 🥗 Nutritional Strengths\n";
        if ($plannedCount >= 10) {
            $out .= "• **High Planning Consistency**: Planning {$plannedCount} meals significantly reduces impulsive eating and improves grocery efficiency.\n";
        } else {
            $out .= "• **Good Start**: Foundational meal choices scheduled with fresh proteins and greens.\n";
        }
        $out .= "• **Whole Food Emphasis**: Meals like salads, chicken, and grains provide balanced micronutrients.\n\n";

        $out .= "#### ⚠️ Areas for Improvement & Gaps\n";
        if ($daysCovered < 7) {
            $missing = 7 - $daysCovered;
            $out .= "• **Unscheduled Days**: {$missing} day(s) have no meals planned. Consider batch cooking or scheduling quick dinners.\n";
        }
        $out .= "• **Hydration & Fiber**: Ensure at least 25–30g of dietary fiber daily by incorporating chia seeds, oats, or beans.\n\n";

        $out .= "#### 💡 Actionable Tips for This Week\n";
        $out .= "1. Pre-chop vegetables like bell peppers and zucchini for quick mid-week cooking.\n";
        $out .= "2. Keep high-protein snacks handy (e.g. Greek yogurt, nuts, boiled eggs).\n";
        $out .= "3. Check the Grocery List modal to bundle all bulk shopping.\n";

        return $out;
    }
}
