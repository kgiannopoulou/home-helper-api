<?php

namespace App\Ai\Tools\Shopping;

use App\Ai\Tools\Tool;
use App\Ai\Tools\ToolContext;
use App\Kitchen\Planning\PlanShoppingList;
use App\Models\MealPlanEntry;

/**
 * "What's for dinner?" (docs/kitchen.md#meal-plan): the planned meals for
 * the next days and what they still need. Read-only; planning is done on
 * the Meals page.
 */
class GetMealPlan extends Tool
{
    public function __construct(private PlanShoppingList $list) {}

    public function name(): string
    {
        return 'get_meal_plan';
    }

    public function label(): string
    {
        return 'Checked the meal plan';
    }

    public function description(): string
    {
        return 'The meal plan: breakfasts, lunches and dinners planned for today and the next days (default 7), whether each is cooked, and the ingredients the planned meals still need beyond what is in the kitchen. Use it for "what\'s for dinner?", "what are we eating this week?", "what do we need for the week?".';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 14, 'description' => 'Default 7, starting today'],
            ],
            'additionalProperties' => false,
        ];
    }

    public function rules(ToolContext $context): array
    {
        return ['days' => ['sometimes', 'integer', 'between:1,14']];
    }

    public function run(array $input, ToolContext $context): array
    {
        $from = $context->today;
        $to = $from->addDays(((int) ($input['days'] ?? 7)) - 1);
        $meals = $context->household->mealPlanEntries()->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with('recipe')->orderBy('date')->get()
            ->sortBy(fn (MealPlanEntry $e) => $e->date->toDateString().array_search($e->meal, MealPlanEntry::MEALS, true))->values();

        return [
            'meals' => $meals->map(fn (MealPlanEntry $e) => [
                'date' => $e->date->toDateString(),
                'meal' => $e->meal,
                'what' => $e->label(),
                'for' => $e->servings,
                'cooked' => $e->cooked_at !== null,
            ])->all(),
            'still_needed' => array_map(fn (string $k, array $n) => ['ingredient' => $k, 'for' => $n['for']], array_keys($n = $this->list->needed($context->household, $from, $to)), $n),
            'note' => $meals->isEmpty() ? 'Nothing planned. The Meals page can plan the week.' : null,
        ];
    }
}
