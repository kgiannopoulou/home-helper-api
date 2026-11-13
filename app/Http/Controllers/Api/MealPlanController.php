<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kitchen\Planning\MealPlan;
use App\Kitchen\Planning\PlanShoppingList;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\MealPlanEntry;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The meal plan from the phone (docs/kitchen.md#meal-plan).
 */
class MealPlanController extends Controller
{
    public function __construct(private MealPlan $plan) {}

    /** GET meal-plan?from=YYYY-MM-DD: a week of meals and what they still need */
    public function index(Request $request, Household $household, PlanShoppingList $list): JsonResponse
    {
        Gate::authorize('viewAny', [InventoryItem::class, $household]);
        $from = self::from($request);
        $to = $from->addDays(6);

        return response()->json([
            'from' => $from->toDateString(),
            'entries' => $household->mealPlanEntries()->whereBetween('date', [$from->toDateString(), $to->toDateString()])
                ->with('recipe')->orderBy('date')->get()->map(fn (MealPlanEntry $e) => MealPlan::entry($e))->all(),
            'shopping' => PlanShoppingList::rows($list->needed($household, $from, $to)),
        ]);
    }

    /** PUT meal-plan/{date}/{meal} {recipe_id | title, servings} */
    public function set(Request $request, Household $household, string $date, string $meal): JsonResponse
    {
        Gate::authorize('create', [InventoryItem::class, $household]);
        $data = $request->validate(self::rules());
        $entry = $this->plan->set($household, $request->user(), self::date($date), $meal, $data);

        return response()->json(['entry' => MealPlan::entry($entry)]);
    }

    public function clear(Request $request, Household $household, string $date, string $meal): JsonResponse
    {
        Gate::authorize('create', [InventoryItem::class, $household]);
        $this->plan->clear($household, $request->user(), self::date($date), $meal);

        return response()->json(null, 204);
    }

    /** POST meal-plan/plan {from?, days?, meals?, tag?, servings?}: fill the empty meals */
    public function auto(Request $request, Household $household): JsonResponse
    {
        Gate::authorize('create', [InventoryItem::class, $household]);
        $d = $request->validate(self::planRules());
        $added = $this->plan->plan($household, $request->user(), isset($d['from']) ? self::date($d['from']) : HomeTime::today(), (int) ($d['days'] ?? 7), $d['meals'] ?? ['dinner'], $d['tag'] ?? null, isset($d['servings']) ? (int) $d['servings'] : null);

        return response()->json(['added' => array_map(fn (MealPlanEntry $e) => MealPlan::entry($e), $added)]);
    }

    /** POST meal-plan/{entry}/cooked: takes the ingredients out of the kitchen */
    public function cooked(Request $request, Household $household, string $entry): JsonResponse
    {
        Gate::authorize('create', [InventoryItem::class, $household]);
        $row = $household->mealPlanEntries()->with('recipe')->findOrFail($entry);

        return response()->json($this->plan->cooked($household, $request->user(), $row));
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'recipe_id' => ['nullable', 'string', 'size:26'],
            'title' => ['nullable', 'string', 'max:120'],
            'servings' => ['required', 'integer', 'between:1,50'],
        ];
    }

    /** @return array<string, mixed> */
    public static function planRules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'days' => ['nullable', 'integer', 'between:1,14'],
            'meals' => ['nullable', 'array'],
            'meals.*' => ['in:breakfast,lunch,dinner'],
            'tag' => ['nullable', 'string', 'max:20'],
            'servings' => ['nullable', 'integer', 'between:1,50'],
        ];
    }

    public static function from(Request $request): CarbonImmutable
    {
        $from = $request->validate(['from' => ['nullable', 'date_format:Y-m-d']])['from'] ?? null;

        return $from ? self::date($from) : HomeTime::today()->startOfWeek();
    }

    public static function date(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, HomeTime::zone())->startOfDay();
    }
}
