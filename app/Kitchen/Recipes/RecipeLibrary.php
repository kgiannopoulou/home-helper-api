<?php

namespace App\Kitchen\Recipes;

use App\Kitchen\Ingredients;
use App\Kitchen\Units;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Home Helper's own recipes (database/data/recipes.php), loaded by slug:
 * running it again updates them and adds new ones, never twice. Households'
 * own recipes are untouched.
 */
final class RecipeLibrary
{
    /** @return int how many recipes the library has */
    public static function sync(): int
    {
        /** @var array<string, array{0: string, 1: int, 2: int, 3: list<string>, 4: list<array{0: string, 1: float|int, 2: string, 3?: bool}>, 5: string}> $library */
        $library = require database_path('data/recipes.php');
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s.v');

        DB::transaction(function () use ($library, $now) {
            foreach ($library as $slug => [$name, $servings, $minutes, $tags, $ingredients, $steps]) {
                $id = DB::table('recipes')->where('slug', $slug)->value('id') ?? strtolower((string) Str::ulid());
                DB::table('recipes')->upsert([[
                    'id' => $id, 'household_id' => null, 'slug' => $slug, 'name' => $name, 'servings' => $servings,
                    'minutes' => $minutes, 'tags' => json_encode($tags), 'steps' => $steps, 'created_at' => $now, 'updated_at' => $now,
                ]], ['id'], ['name', 'servings', 'minutes', 'tags', 'steps', 'updated_at']);

                DB::table('recipe_ingredients')->where('recipe_id', $id)->delete();
                DB::table('recipe_ingredients')->insert(array_map(fn (array $i) => self::row($id, $i), $ingredients));
            }
        });

        return count($library);
    }

    /**
     * @param  array{0: string, 1: float|int, 2: string, 3?: bool}  $i
     * @return array<string, mixed>
     */
    private static function row(string $recipeId, array $i): array
    {
        [$key, $amount, $unit] = $i;
        if (! array_key_exists($key, Ingredients::ALIASES)) {
            throw new RuntimeException("Unknown ingredient in database/data/recipes.php: {$key}");
        }
        $base = Units::toBase((float) $amount, $unit) ?? throw new RuntimeException("Unknown unit {$unit} for {$key}");

        return [
            'recipe_id' => $recipeId,
            'ingredient' => $key,
            'amount' => $base[0],
            'unit' => $base[1],
            'optional' => (bool) ($i[3] ?? false),
            'staple' => Ingredients::isStaple($key),
        ];
    }
}
