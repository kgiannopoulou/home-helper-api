<?php

namespace App\Kitchen\Planning;

use App\Kitchen\Expiry;
use App\Kitchen\Units;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * "Plan the week" (docs/kitchen.md#meal-plan): fills the empty meals, one
 * day at a time, against a copy of the kitchen that each chosen meal uses up,
 * so Tuesday doesn't plan on the eggs Monday already ate.
 *
 * For each empty meal, every recipe that fits it (breakfasts for breakfast,
 * no desserts for dinner, the tag if one was asked for, not already this
 * week) is scored:
 *
 *   +12 for each ingredient that goes off that day, +8 the day after (use it first)
 *    −4 for each ingredient the kitchen won't have (it'll go on the list)
 *    −3 when its main ingredient was yesterday's too
 *    −2 for over 45 minutes on a weekday
 *
 * Meals someone planned stay; their ingredients come out of the copy first.
 */
class MealPlanner
{
    /**
     * @param  list<string>  $meals
     * @return list<MealPlanEntry> the meals it added
     */
    public function plan(Household $household, ?User $user, CarbonImmutable $from, int $days = 7, array $meals = ['dinner'], ?string $tag = null, ?int $servings = null): array
    {
        $servings ??= max(1, $household->users()->count());
        $to = $from->addDays($days - 1);
        $existing = $household->mealPlanEntries()->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with('recipe.ingredients')->get();
        $recipes = Recipe::query()->for($household)->with('ingredients')->orderBy('name')->get()
            ->filter(fn (Recipe $r) => $tag === null || in_array($tag, $r->tags, true));

        $stock = $this->stock($household);
        /** @var list<string> $used */
        $used = $existing->pluck('recipe_id')->filter()->values()->all();
        foreach ($existing->sortBy('date') as $e) {
            if ($e->recipe && ! $e->cooked_at) {
                $this->consume($stock, $e->recipe, $e->servings);
            }
        }

        $added = [];
        DB::transaction(function () use ($household, $user, $from, $days, $meals, $servings, $existing, $recipes, &$stock, &$used, &$added) {
            $yesterday = null;
            for ($d = 0; $d < $days; $d++) {
                $date = $from->addDays($d);
                foreach (MealPlanEntry::MEALS as $meal) {
                    if (! in_array($meal, $meals, true)) {
                        continue;
                    }
                    $taken = $existing->first(fn (MealPlanEntry $e) => $e->date->toDateString() === $date->toDateString() && $e->meal === $meal);
                    if ($taken) {
                        $yesterday = $taken->recipe ? $this->main($taken->recipe) : $yesterday;

                        continue;
                    }
                    $best = $this->best($recipes, $meal, $date, $stock, $used, $yesterday);
                    if (! $best) {
                        continue;
                    }
                    $entry = new MealPlanEntry(['date' => $date, 'meal' => $meal, 'recipe_id' => $best->id, 'servings' => $servings, 'auto' => true, 'created_by' => $user?->id]);
                    $entry->household_id = $household->id;
                    $entry->save();
                    $added[] = $entry->setRelation('recipe', $best);

                    $this->consume($stock, $best, $servings);
                    $used[] = $best->id;
                    $yesterday = $this->main($best);
                }
            }
        });

        return $added;
    }

    /**
     * @param  Collection<int, Recipe>  $recipes
     * @param  array<string, array{amount: float|null, unit: string|null, expires: string|null}>  $stock
     * @param  list<string>  $used
     */
    private function best(Collection $recipes, string $meal, CarbonImmutable $date, array $stock, array $used, ?string $yesterday): ?Recipe
    {
        $best = null;
        $bestScore = null;
        foreach ($recipes as $r) {
            if (in_array($r->id, $used, true) || ! $this->fits($r, $meal)) {
                continue;
            }
            $score = 0;
            foreach ($r->ingredients as $i) {
                $s = $stock[$i->ingredient] ?? null;
                $there = $s !== null && ($s['amount'] === null || $s['amount'] > 0);
                if ($there && $s['expires'] === $date->toDateString()) {
                    $score += 12;
                } elseif ($there && $s['expires'] === $date->addDay()->toDateString()) {
                    $score += 8;
                }
                if (! $there && ! $i->optional && ! $i->staple) {
                    $score -= 4;
                }
            }
            if ($yesterday !== null && $this->main($r) === $yesterday) {
                $score -= 3;
            }
            if ($date->isWeekday() && ($r->minutes ?? 0) > 45) {
                $score -= 2;
            }
            if ($bestScore === null || $score > $bestScore) {
                [$best, $bestScore] = [$r, $score];
            }
        }

        return $best;
    }

    private function fits(Recipe $r, string $meal): bool
    {
        $breakfast = in_array('breakfast', $r->tags, true);

        return match ($meal) {
            'breakfast' => $breakfast,
            default => ! $breakfast && ! in_array('dessert', $r->tags, true),
        };
    }

    /** The ingredient a recipe is mostly about: its biggest non-staple by amount */
    private function main(Recipe $r): ?string
    {
        return $r->ingredients->reject(fn (RecipeIngredient $i) => $i->staple || $i->optional)
            ->sortByDesc(fn (RecipeIngredient $i) => $i->unit === 'pcs' ? ($i->amount ?? 0) * 100 : ($i->amount ?? 0))
            ->first()?->ingredient;
    }

    /**
     * The kitchen per ingredient: how much (null = there, unmeasured), the soonest date
     *
     * @return array<string, array{amount: float|null, unit: string|null, expires: string|null}>
     */
    public function stock(Household $household): array
    {
        $stock = [];
        foreach ($household->inventoryItems()->whereNotNull('ingredient')->get() as $item) {
            /** @var InventoryItem $item */
            if (! Expiry::inStock($item)) {
                continue;
            }
            $key = (string) $item->ingredient;
            $s = $stock[$key] ?? ['amount' => 0.0, 'unit' => $item->unit, 'expires' => null];
            if ($item->amount === null || $s['amount'] === null || ($s['unit'] !== null && ! Units::compatible((string) $item->unit, (string) $s['unit']))) {
                $s['amount'] = null;
            } else {
                $s['amount'] += $item->amount;
                $s['unit'] ??= $item->unit;
            }
            $date = $item->expires_on?->toDateString();
            if ($date !== null && ($s['expires'] === null || $date < $s['expires'])) {
                $s['expires'] = $date;
            }
            $stock[$key] = $s;
        }

        return $stock;
    }

    /** @param array<string, array{amount: float|null, unit: string|null, expires: string|null}> $stock */
    private function consume(array &$stock, Recipe $recipe, int $servings): void
    {
        $scale = $servings / $recipe->servings;
        foreach ($recipe->ingredients as $i) {
            $s = $stock[$i->ingredient] ?? null;
            if ($s === null || $s['amount'] === null || $i->amount === null || ! Units::compatible((string) $i->unit, (string) $s['unit'])) {
                continue;
            }
            $stock[$i->ingredient]['amount'] = max(0.0, $s['amount'] - $i->amount * $scale);
        }
    }
}
