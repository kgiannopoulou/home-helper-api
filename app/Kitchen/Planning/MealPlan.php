<?php

namespace App\Kitchen\Planning;

use App\Kitchen\Recipes\Cooking;
use App\Models\Household;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\User;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * The meal plan's actions, shared by the Meals page and the API
 * (docs/kitchen.md#meal-plan). Every change brings the plan's shopping
 * list up to date for the next week.
 */
class MealPlan
{
    public function __construct(private MealPlanner $planner, private PlanShoppingList $list, private Cooking $cooking) {}

    /**
     * @param  array{recipe_id?: string|null, title?: string|null, servings: int}  $data
     */
    public function set(Household $household, User $user, CarbonImmutable $date, string $meal, array $data): MealPlanEntry
    {
        $recipe = ! empty($data['recipe_id']) ? Recipe::query()->for($household)->findOrFail($data['recipe_id']) : null;
        $title = trim((string) ($data['title'] ?? '')) ?: null;
        if (! $recipe && ! $title) {
            throw ValidationException::withMessages(['recipe_id' => 'Choose a recipe or write what it is.']);
        }
        $entry = $household->mealPlanEntries()->where('date', $date->toDateString())->where('meal', $meal)->first() ?? new MealPlanEntry(['date' => $date, 'meal' => $meal]);
        $entry->household_id = $household->id;
        $entry->forceFill([
            'recipe_id' => $recipe?->id,
            'title' => $recipe ? null : mb_substr((string) $title, 0, 120),
            'servings' => $data['servings'],
            'auto' => false,
            'cooked_at' => null,
            'created_by' => $user->id,
        ])->save();
        $this->refreshList($household, $user);

        return $entry->load('recipe');
    }

    public function clear(Household $household, User $user, CarbonImmutable $date, string $meal): void
    {
        $household->mealPlanEntries()->where('date', $date->toDateString())->where('meal', $meal)->delete();
        $this->refreshList($household, $user);
    }

    /**
     * @param  list<string>  $meals
     * @return list<MealPlanEntry>
     */
    public function plan(Household $household, User $user, CarbonImmutable $from, int $days, array $meals, ?string $tag, ?int $servings): array
    {
        $added = $this->planner->plan($household, $user, $from, $days, $meals, $tag, $servings);
        $this->refreshList($household, $user);

        return $added;
    }

    /**
     * @return array{used: list<string>, missing: list<string>}
     */
    public function cooked(Household $household, User $user, MealPlanEntry $entry): array
    {
        if ($entry->cooked_at) {
            throw ValidationException::withMessages(['entry' => 'Already cooked.']);
        }
        $result = $entry->recipe ? $this->cooking->cook($household, $entry->recipe->load('ingredients'), $user, $entry->servings) : ['used' => [], 'missing' => []];
        $entry->forceFill(['cooked_at' => CarbonImmutable::now()])->save();
        $this->refreshList($household, $user);

        return $result;
    }

    /**
     * The plan's list for today and the six days after
     *
     * @return array{needed: list<array{ingredient: string, amount: float|null, unit: string|null, for: list<string>}>, added: list<string>, updated: list<string>, removed: list<string>}
     */
    public function refreshList(Household $household, ?User $user): array
    {
        $today = HomeTime::today();

        return $this->list->sync($household, $user, $today, $today->addDays(6));
    }

    /** @return array<string, mixed> */
    public static function entry(MealPlanEntry $e): array
    {
        return [
            'id' => $e->id,
            'date' => $e->date->toDateString(),
            'meal' => $e->meal,
            'recipe_id' => $e->recipe_id,
            'label' => $e->label(),
            'servings' => $e->servings,
            'auto' => $e->auto,
            'cooked' => $e->cooked_at !== null,
            'minutes' => $e->recipe?->minutes,
        ];
    }
}
