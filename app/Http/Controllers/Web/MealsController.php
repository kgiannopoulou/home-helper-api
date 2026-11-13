<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Api\MealPlanController as Api;
use App\Kitchen\Planning\MealPlan;
use App\Kitchen\Planning\PlanShoppingList;
use App\Models\InventoryItem;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Queries\RecipeMatchQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Meals page (resources/js/pages/meals.tsx, docs/kitchen.md#meal-plan):
 * a week of breakfasts, lunches and dinners, "plan the week", and what the
 * plan still needs, which is kept on the shopping list.
 */
class MealsController extends WebController
{
    public function __construct(private MealPlan $plan) {}

    public function show(Request $request, PlanShoppingList $list): Response
    {
        $household = $this->household($request);
        $from = Api::from($request);
        $to = $from->addDays(6);
        $entries = $household->mealPlanEntries()->whereBetween('date', [$from->toDateString(), $to->toDateString()])->with('recipe')->get();

        // Recipes for the pickers: what suits the kitchen first, then the rest by name
        $suggested = collect((new RecipeMatchQuery($household, $this->today(), null, 50))->get())->pluck('id')->all();
        $recipes = Recipe::query()->for($household)->orderBy('name')->get(['id', 'name', 'minutes', 'tags', 'household_id'])
            ->sortBy(fn (Recipe $r) => [($i = array_search($r->id, $suggested, true)) === false ? 999 : $i, $r->name])->values()
            ->map(fn (Recipe $r) => ['id' => $r->id, 'name' => $r->name, 'minutes' => $r->minutes, 'tags' => $r->tags, 'suggested' => in_array($r->id, $suggested, true)])->all();

        $needed = $list->needed($household, $from, $to);

        return Inertia::render('meals', [
            'from' => $from->toDateString(),
            'today' => $this->today()->toDateString(),
            'days' => collect(range(0, 6))->map(function (int $d) use ($from, $entries) {
                $date = $from->addDays($d)->toDateString();

                return [
                    'date' => $date,
                    'meals' => collect(MealPlanEntry::MEALS)->mapWithKeys(fn (string $m) => [$m => ($e = $entries->first(fn (MealPlanEntry $e) => $e->date->toDateString() === $date && $e->meal === $m)) ? MealPlan::entry($e) : null])->all(),
                ];
            })->all(),
            'recipes' => $recipes,
            'shopping' => PlanShoppingList::rows($needed),
            'servings' => max(1, $household->users()->count()),
        ]);
    }

    public function set(Request $request, string $date, string $meal): RedirectResponse
    {
        $household = $this->household($request);
        Gate::authorize('create', [InventoryItem::class, $household]);
        $this->plan->set($household, $request->user(), Api::date($date), $meal, $request->validate(Api::rules()));

        return back();
    }

    public function clear(Request $request, string $date, string $meal): RedirectResponse
    {
        $household = $this->household($request);
        Gate::authorize('create', [InventoryItem::class, $household]);
        $this->plan->clear($household, $request->user(), Api::date($date), $meal);

        return back();
    }

    public function auto(Request $request): RedirectResponse
    {
        $household = $this->household($request);
        Gate::authorize('create', [InventoryItem::class, $household]);
        $d = $request->validate(Api::planRules());
        $from = isset($d['from']) ? Api::date($d['from']) : $this->today();
        $this->plan->plan($household, $request->user(), $from, (int) ($d['days'] ?? 7), $d['meals'] ?? ['dinner'], $d['tag'] ?? null, isset($d['servings']) ? (int) $d['servings'] : null);

        return back();
    }

    public function cooked(Request $request, string $entry): RedirectResponse
    {
        $household = $this->household($request);
        Gate::authorize('create', [InventoryItem::class, $household]);
        $this->plan->cooked($household, $request->user(), $household->mealPlanEntries()->with('recipe')->findOrFail($entry));

        return back();
    }
}
