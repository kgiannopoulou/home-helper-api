<?php

namespace App\Kitchen\Planning;

use App\Enums\ItemCategory;
use App\Enums\ShoppingSource;
use App\Kitchen\Ingredients;
use App\Kitchen\Units;
use App\Models\Household;
use App\Models\MealPlanEntry;
use App\Models\ShoppingItem;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The shopping list the meal plan keeps (docs/kitchen.md#meal-plan):
 * everything the planned, not yet cooked meals need, minus what the kitchen
 * has, in the amounts still missing.
 *
 * Its own lines (source meal_plan) follow the plan: added, their amounts
 * updated, removed when the meal goes or the food is bought. Anything a
 * person put on the list is left alone and counts as already listed, and so
 * does a line someone ticked off. Staples and optional ingredients never go
 * on it. Run it again and nothing changes.
 */
class PlanShoppingList
{
    public function __construct(private MealPlanner $planner) {}

    /**
     * @return array{needed: list<array{ingredient: string, amount: float|null, unit: string|null, for: list<string>}>, added: list<string>, updated: list<string>, removed: list<string>}
     */
    public function sync(Household $household, ?User $user, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $needed = $this->needed($household, $from, $to);

        return DB::transaction(function () use ($household, $user, $needed) {
            $list = $household->shoppingItems()->where('checked', false)->get();
            $ours = $list->filter(fn (ShoppingItem $s) => $s->source === ShoppingSource::MealPlan)->keyBy(fn (ShoppingItem $s) => Ingredients::key($s->name));
            $theirs = $list->reject(fn (ShoppingItem $s) => $s->source === ShoppingSource::MealPlan)->map(fn (ShoppingItem $s) => Ingredients::key($s->name))->all();
            $added = $updated = $removed = [];

            foreach ($needed as $key => $n) {
                if (in_array($key, $theirs, true)) {
                    continue;
                }
                $quantity = $n['amount'] !== null ? Units::format($n['amount'], $n['unit']) : null;
                $line = $ours->pull($key);
                if ($line) {
                    if ($line->quantity !== $quantity) {
                        $line->forceFill(['quantity' => $quantity])->save();
                        $updated[] = $line->name;
                    }

                    continue;
                }
                $household->shoppingItems()->create([
                    'added_by' => $user?->id,
                    'name' => ucfirst($key),
                    'category' => ItemCategory::Food,
                    'quantity' => $quantity,
                    'source' => ShoppingSource::MealPlan,
                ]);
                $added[] = ucfirst($key);
            }
            // What the plan no longer needs
            foreach ($ours as $line) {
                $line->delete();
                $removed[] = $line->name;
            }

            return ['needed' => self::rows($needed), 'added' => $added, 'updated' => $updated, 'removed' => $removed];
        });
    }

    /**
     * needed() as a list, the ingredient in each row
     *
     * @param  array<string, array{amount: float|null, unit: string|null, for: list<string>}>  $needed
     * @return list<array{ingredient: string, amount: float|null, unit: string|null, for: list<string>}>
     */
    public static function rows(array $needed): array
    {
        $rows = [];
        foreach ($needed as $key => $n) {
            $rows[] = ['ingredient' => $key] + $n;
        }

        return $rows;
    }

    /**
     * What the planned meals need beyond the kitchen, per ingredient
     *
     * @return array<string, array{amount: float|null, unit: string|null, for: list<string>}>
     */
    public function needed(Household $household, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $entries = $household->mealPlanEntries()->whereNull('cooked_at')->whereNotNull('recipe_id')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->with('recipe.ingredients')->orderBy('date')->get();

        $want = [];
        foreach ($entries as $e) {
            /** @var MealPlanEntry $e */
            if (! $e->recipe) {
                continue;
            }
            $scale = $e->servings / $e->recipe->servings;
            foreach ($e->recipe->ingredients as $i) {
                if ($i->staple || $i->optional) {
                    continue;
                }
                $w = $want[$i->ingredient] ?? ['amount' => 0.0, 'unit' => $i->unit, 'for' => []];
                if ($i->amount === null || $w['amount'] === null || ($w['unit'] !== null && ! Units::compatible((string) $i->unit, (string) $w['unit']))) {
                    $w['amount'] = null;
                } else {
                    $w['amount'] += $i->amount * $scale;
                    $w['unit'] ??= $i->unit;
                }
                $w['for'][] = $e->recipe->name;
                $want[$i->ingredient] = $w;
            }
        }

        $stock = $this->planner->stock($household);
        $short = [];
        foreach ($want as $key => $w) {
            $s = $stock[$key] ?? null;
            $w['for'] = array_values(array_unique($w['for']));
            if ($s === null) {
                $short[$key] = $w;

                continue;
            }
            // Some there, but how much can't be compared: assume it's enough
            if ($w['amount'] === null || $s['amount'] === null || ! Units::compatible((string) $w['unit'], (string) $s['unit'])) {
                continue;
            }
            $missing = $w['amount'] - (float) Units::convert($s['amount'], (string) $s['unit'], (string) $w['unit']);
            if ($missing > 0.0001) {
                $short[$key] = ['amount' => round($missing, 3)] + $w;
            }
        }

        return $short;
    }
}
