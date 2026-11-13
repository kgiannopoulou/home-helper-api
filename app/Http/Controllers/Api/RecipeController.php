<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kitchen\Recipes\Cooking;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Queries\RecipeMatchQuery;
use App\Support\HomeTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Recipes (docs/kitchen.md#recipes): what to cook with what's there, a
 * recipe, a household's own recipe, cooking one, shopping for one.
 */
class RecipeController extends Controller
{
    public function __construct(private Cooking $cooking) {}

    /** GET kitchen/recipes/suggestions?tag=&limit= */
    public function suggestions(Request $request, Household $household): JsonResponse
    {
        Gate::authorize('viewAny', [InventoryItem::class, $household]);
        $data = $request->validate(self::suggestionRules());

        return response()->json(['data' => (new RecipeMatchQuery($household, HomeTime::today(), $data['tag'] ?? null, (int) ($data['limit'] ?? 12)))->get()]);
    }

    /** GET kitchen/recipes/{recipe} */
    public function show(Household $household, string $recipe): JsonResponse
    {
        Gate::authorize('viewAny', [InventoryItem::class, $household]);

        return response()->json(['recipe' => self::recipe($this->find($household, $recipe))]);
    }

    /** POST kitchen/recipes {name, servings, minutes?, tags?, steps, ingredients: [{name, amount_text?, optional?}]} */
    public function store(Request $request, Household $household): JsonResponse
    {
        Gate::authorize('create', [InventoryItem::class, $household]);
        $d = $request->validate(self::rules());
        $recipe = $this->cooking->create($household, $request->user(), $d['name'], (int) $d['servings'], isset($d['minutes']) ? (int) $d['minutes'] : null, $d['ingredients'], $d['steps'], $d['tags'] ?? []);

        return response()->json(['recipe' => self::recipe($recipe)], 201);
    }

    /** POST kitchen/recipes/{recipe}/cook {servings?}: takes the ingredients out of the kitchen */
    public function cook(Request $request, Household $household, string $recipe): JsonResponse
    {
        Gate::authorize('create', [InventoryItem::class, $household]);
        $servings = $request->validate(['servings' => ['nullable', 'integer', 'between:1,50']])['servings'] ?? null;

        return response()->json($this->cooking->cook($household, $this->find($household, $recipe), $request->user(), $servings));
    }

    /** POST kitchen/recipes/{recipe}/shopping {servings?}: what's missing onto the list */
    public function shopping(Request $request, Household $household, string $recipe): JsonResponse
    {
        Gate::authorize('create', [InventoryItem::class, $household]);
        $servings = $request->validate(['servings' => ['nullable', 'integer', 'between:1,50']])['servings'] ?? null;

        return response()->json(['added' => $this->cooking->shopFor($household, $this->find($household, $recipe), $request->user(), $servings)]);
    }

    /** @return array<string, mixed> */
    public static function suggestionRules(): array
    {
        return [
            'tag' => ['nullable', 'string', 'max:20'],
            'limit' => ['nullable', 'integer', 'between:1,50'],
        ];
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'servings' => ['required', 'integer', 'between:1,50'],
            'minutes' => ['nullable', 'integer', 'between:1,1440'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:20'],
            'steps' => ['required', 'string', 'max:5000'],
            'ingredients' => ['required', 'array', 'min:1', 'max:40'],
            'ingredients.*.name' => ['required', 'string', 'max:60'],
            'ingredients.*.amount_text' => ['nullable', 'string', 'max:30'],
            'ingredients.*.optional' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public static function recipe(Recipe $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'servings' => $r->servings,
            'minutes' => $r->minutes,
            'tags' => $r->tags,
            'own' => $r->household_id !== null,
            'steps' => $r->steps,
            'ingredients' => $r->ingredients->map(fn (RecipeIngredient $i) => [
                'ingredient' => $i->ingredient,
                'amount' => $i->amount,
                'unit' => $i->unit,
                'label' => $i->label(),
                'optional' => $i->optional,
                'staple' => $i->staple,
            ])->all(),
        ];
    }

    private function find(Household $household, string $id): Recipe
    {
        return Recipe::query()->for($household)->with('ingredients')->findOrFail($id);
    }
}
