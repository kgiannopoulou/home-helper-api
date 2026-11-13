<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\RecipeController as Api;
use App\Kitchen\Recipes\Cooking;
use App\Models\InventoryItem;
use App\Models\Recipe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** The Kitchen page's recipes card: a recipe's details, cooking it, shopping for it, adding one */
class RecipeController extends WebController
{
    public function __construct(private Cooking $cooking) {}

    /** JSON for the card's "show recipe" */
    public function show(Request $request, string $recipe): JsonResponse
    {
        Gate::authorize('viewAny', [InventoryItem::class, $this->household($request)]);

        return response()->json(['recipe' => Api::recipe($this->find($request, $recipe))]);
    }

    public function store(Request $request): RedirectResponse
    {
        $household = $this->household($request);
        Gate::authorize('create', [InventoryItem::class, $household]);
        $d = $request->validate(Api::rules());
        $this->cooking->create($household, $request->user(), $d['name'], (int) $d['servings'], isset($d['minutes']) ? (int) $d['minutes'] : null, $d['ingredients'], $d['steps'], $d['tags'] ?? []);

        return to_route('kitchen');
    }

    public function cook(Request $request, string $recipe): RedirectResponse
    {
        $household = $this->household($request);
        Gate::authorize('create', [InventoryItem::class, $household]);
        $servings = $request->validate(['servings' => ['nullable', 'integer', 'between:1,50']])['servings'] ?? null;
        $this->cooking->cook($household, $this->find($request, $recipe), $request->user(), $servings);

        return to_route('kitchen');
    }

    public function shopping(Request $request, string $recipe): RedirectResponse
    {
        $household = $this->household($request);
        Gate::authorize('create', [InventoryItem::class, $household]);
        $this->cooking->shopFor($household, $this->find($request, $recipe), $request->user());

        return to_route('kitchen');
    }

    private function find(Request $request, string $id): Recipe
    {
        return Recipe::query()->for($this->household($request))->with('ingredients')->findOrFail($id);
    }
}
