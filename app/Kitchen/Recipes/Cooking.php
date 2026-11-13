<?php

namespace App\Kitchen\Recipes;

use App\Enums\ItemCategory;
use App\Enums\ShoppingSource;
use App\Kitchen\Expiry;
use App\Kitchen\Ingredients;
use App\Kitchen\Pantry;
use App\Kitchen\Units;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\User;
use App\Support\ItemName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What happens when a recipe is cooked or planned (docs/kitchen.md#recipes).
 *
 *   cook()     takes the ingredients out of the kitchen as `cooked`
 *              movements, scaled to the servings, the items going off
 *              soonest first. Staples are left alone, and what isn't there
 *              is reported, not invented.
 *   shopFor()  puts what's missing (or short) on the shopping list, once.
 */
class Cooking
{
    public function __construct(private Pantry $pantry) {}

    /**
     * @return array{used: list<string>, missing: list<string>}
     */
    public function cook(Household $household, Recipe $recipe, ?User $user, ?int $servings = null): array
    {
        $scale = ($servings ?? $recipe->servings) / $recipe->servings;
        $used = [];
        $missing = [];

        DB::transaction(function () use ($household, $recipe, $user, $scale, &$used, &$missing) {
            foreach ($recipe->ingredients as $ri) {
                if ($ri->staple) {
                    continue;
                }
                $items = $this->stock($household, $ri->ingredient);
                if ($items === []) {
                    if (! $ri->optional) {
                        $missing[] = $ri->ingredient;
                    }

                    continue;
                }
                $need = $ri->amount !== null ? $ri->amount * $scale : null;
                foreach ($items as $item) {
                    if ($need !== null && $need <= 0) {
                        break;
                    }
                    if ($need === null || $item->amount === null || ! Units::compatible((string) $ri->unit, (string) $item->unit)) {
                        // 4 slices against 500 g of bread: can't count it, so it's used but unchanged
                        $used[] = $item->name;

                        break;
                    }
                    $take = min($need, (float) Units::convert($item->amount, (string) $item->unit, (string) $ri->unit));
                    $this->pantry->take($item, 'cooked', $take, (string) $ri->unit, $user, $recipe->name);
                    $used[] = $item->name;
                    $need -= $take;
                }
            }
        });

        return ['used' => array_values(array_unique($used)), 'missing' => $missing];
    }

    /**
     * What a recipe needs that the kitchen lacks, onto the shopping list
     *
     * @return list<string> what was added
     */
    public function shopFor(Household $household, Recipe $recipe, ?User $user, ?int $servings = null): array
    {
        $scale = ($servings ?? $recipe->servings) / $recipe->servings;
        $listed = $household->shoppingItems()->where('checked', false)->pluck('name')
            ->mapWithKeys(fn (string $n) => [ItemName::normalize($n) => true])->all();
        $added = [];

        foreach ($recipe->ingredients as $ri) {
            if ($ri->staple || $ri->optional) {
                continue;
            }
            $have = $this->have($household, $ri);
            $need = $ri->amount !== null ? $ri->amount * $scale : null;
            if ($have !== null && ($need === null || $have >= $need)) {
                continue;
            }
            $name = ucfirst($ri->ingredient);
            if (isset($listed[ItemName::normalize($name)])) {
                continue;
            }
            $household->shoppingItems()->create([
                'added_by' => $user?->id,
                'name' => $name,
                'category' => ItemCategory::Food,
                'quantity' => $need !== null ? Units::format(max(0.0, $need - ($have ?? 0.0)), $ri->unit) : null,
                'source' => ShoppingSource::Recipe,
            ]);
            $listed[ItemName::normalize($name)] = true;
            $added[] = $name;
        }

        return $added;
    }

    /**
     * How much the kitchen has of a recipe ingredient, in its unit: null = none; INF = some, unmeasured
     */
    private function have(Household $household, RecipeIngredient $ri): ?float
    {
        $items = $this->stock($household, $ri->ingredient);
        if ($items === []) {
            return null;
        }
        $total = 0.0;
        foreach ($items as $item) {
            if ($item->amount === null || $ri->unit === null || ! Units::compatible((string) $item->unit, $ri->unit)) {
                return INF;
            }
            $total += (float) Units::convert($item->amount, (string) $item->unit, $ri->unit);
        }

        return $total;
    }

    /** @return list<InventoryItem> the household's items of this ingredient with something left, soonest to go off first */
    private function stock(Household $household, string $ingredient): array
    {
        $items = $household->inventoryItems()->where('ingredient', $ingredient)
            ->orderByRaw('expires_on IS NULL, expires_on')->get()
            ->filter(fn (InventoryItem $i) => Expiry::inStock($i))->values()->all();

        /** @var list<InventoryItem> $items */
        return $items;
    }

    /**
     * A household's own recipe from the page or the phone
     *
     * @param  list<array{name: string, amount_text?: string|null, optional?: bool}>  $ingredients
     * @param  list<string>  $tags
     */
    public function create(Household $household, User $user, string $name, int $servings, ?int $minutes, array $ingredients, string $steps, array $tags = []): Recipe
    {
        if ($ingredients === []) {
            throw ValidationException::withMessages(['ingredients' => 'A recipe needs at least one ingredient.']);
        }

        return DB::transaction(function () use ($household, $user, $name, $servings, $minutes, $ingredients, $steps, $tags) {
            $recipe = new Recipe(['name' => $name, 'servings' => $servings, 'minutes' => $minutes, 'tags' => $tags, 'steps' => $steps, 'created_by' => $user->id]);
            $recipe->household_id = $household->id;
            $recipe->save();
            $seen = [];
            foreach ($ingredients as $i) {
                $key = Ingredients::key($i['name']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $amount = Units::parse($i['amount_text'] ?? null);
                $recipe->ingredients()->create([
                    'ingredient' => $key,
                    'amount' => $amount[0] ?? null,
                    'unit' => $amount[1] ?? null,
                    'optional' => (bool) ($i['optional'] ?? false),
                    'staple' => Ingredients::isStaple($key),
                ]);
            }

            return $recipe->load('ingredients');
        });
    }
}
