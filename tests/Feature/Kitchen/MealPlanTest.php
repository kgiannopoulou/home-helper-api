<?php

use App\Enums\HouseholdRole;
use App\Enums\ItemCategory;
use App\Enums\ShoppingSource;
use App\Enums\StorageLocation;
use App\Jobs\PrepareShoppingList;
use App\Kitchen\Planning\MealPlanner;
use App\Kitchen\Planning\PlanShoppingList;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\MealPlanEntry;
use App\Models\Recipe;
use App\Models\ShoppingItem;
use App\Models\User;
use App\Web\CurrentHousehold;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Page;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    // Friday 13 November 2026
    $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00', 'Europe/Athens'));
    $this->monday = CarbonImmutable::parse('2026-11-16', 'Europe/Athens');
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->partner = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->home->addMember($this->partner);
    $this->planner = app(MealPlanner::class);
    $this->list = app(PlanShoppingList::class);
});

function have(Household $home, string $name, float $amount, string $unit, ?string $expires = null): InventoryItem
{
    $item = $home->inventoryItems()->create(['name' => $name, 'category' => ItemCategory::Food, 'location' => StorageLocation::Fridge, 'amount' => $amount, 'unit' => $unit]);
    $item->forceFill(['expires_on' => $expires])->save();

    return $item;
}

function planned(Household $home, string $date, string $slug, int $servings = 2, string $meal = 'dinner'): MealPlanEntry
{
    $e = new MealPlanEntry(['date' => $date, 'meal' => $meal, 'recipe_id' => Recipe::where('slug', $slug)->value('id'), 'servings' => $servings]);
    $e->household_id = $home->id;
    $e->save();

    return $e;
}

describe('planning the week', function () {
    it('fills every dinner, uses up what goes off first, never the same recipe twice', function () {
        have($this->home, 'Spinach', 500, 'g', '2026-11-18');
        have($this->home, 'Minced meat', 500, 'g', '2026-11-16');
        have($this->home, 'Rice', 1000, 'g');

        $added = $this->planner->plan($this->home, $this->owner, $this->monday);
        $byDay = collect($added)->mapWithKeys(fn ($e) => [$e->date->format('D') => $e->recipe->slug]);

        expect($added)->toHaveCount(7)
            ->and($byDay['Mon'])->toBe('makaronia-kima')       // the mince goes off on Monday
            ->and($byDay['Tue'])->toBe('spanakorizo')          // the spinach on Wednesday: Tuesday is its last good evening
            ->and($byDay->unique())->toHaveCount(7)
            ->and(collect($added)->every(fn ($e) => $e->servings === 2 && $e->auto && $e->meal === 'dinner'))->toBeTrue();
    });

    it('keeps the meals someone planned, and plans around their ingredients', function () {
        have($this->home, 'Eggs', 4, 'pcs', '2026-11-17');
        planned($this->home, '2026-11-16', 'strapatsada');

        $added = $this->planner->plan($this->home, $this->owner, $this->monday, 2);
        expect($added)->toHaveCount(1)
            ->and(MealPlanEntry::where('date', '2026-11-16')->sole()->recipe->slug)->toBe('strapatsada')
            // Strapatsada ate the four eggs: Tuesday doesn't plan an omelette on them
            ->and($added[0]->recipe->slug)->not->toBe('omelette');
    });

    it('plans breakfasts from breakfast recipes, and follows a tag', function () {
        $added = $this->planner->plan($this->home, $this->owner, $this->monday, 2, ['breakfast', 'dinner'], 'vegetarian');

        $breakfasts = collect($added)->where('meal', 'breakfast');
        expect($breakfasts)->toHaveCount(2)
            ->and($breakfasts->every(fn ($e) => in_array('breakfast', $e->recipe->tags, true)))->toBeTrue()
            ->and(collect($added)->every(fn ($e) => in_array('vegetarian', $e->recipe->tags, true)))->toBeTrue();
    });
});

describe('the shopping list', function () {
    it('lists what the planned meals need beyond the kitchen, in the amounts missing', function () {
        have($this->home, 'Tomatoes', 300, 'g');
        planned($this->home, '2026-11-16', 'horiatiki', 2);     // 400 g tomato, 1 cucumber, ½ onion, 150 g feta
        planned($this->home, '2026-11-17', 'strapatsada', 4);   // 8 eggs, 600 g tomato, (feta optional)

        $r = $this->list->sync($this->home, $this->owner, $this->monday, $this->monday->addDays(6));
        $list = ShoppingItem::where('source', ShoppingSource::MealPlan)->pluck('quantity', 'name')->all();

        expect($list)->toBe(['Tomato' => '700 g', 'Cucumber' => '1', 'Onion' => '0.5', 'Feta' => '150 g', 'Egg' => '8'])
            ->and(collect($r['needed'])->firstWhere('ingredient', 'tomato')['for'])->toBe(['Greek salad (horiatiki)', 'Strapatsada (eggs with tomato)']);

        // Again: nothing changes
        expect($this->list->sync($this->home, $this->owner, $this->monday, $this->monday->addDays(6)))->toMatchArray(['added' => [], 'updated' => [], 'removed' => []]);
    });

    it('follows the plan: amounts change, lines go, what a person listed is left alone', function () {
        $this->home->shoppingItems()->create(['name' => 'Eggs', 'category' => ItemCategory::Food, 'quantity' => '12']);
        $salad = planned($this->home, '2026-11-16', 'horiatiki', 2);
        planned($this->home, '2026-11-17', 'strapatsada', 2);
        $this->list->sync($this->home, $this->owner, $this->monday, $this->monday->addDays(6));
        expect(ShoppingItem::where('name', 'Egg')->exists())->toBeFalse(); // "Eggs" was already on the list

        $salad->delete();
        have($this->home, 'Tomatoes', 1000, 'g');
        $r = $this->list->sync($this->home, $this->owner, $this->monday, $this->monday->addDays(6));

        expect($r['removed'])->toEqualCanonicalizing(['Tomato', 'Cucumber', 'Onion', 'Feta'])
            ->and(ShoppingItem::pluck('name')->all())->toBe(['Eggs']);
    });

    it('leaves out cooked meals and staples', function () {
        $e = planned($this->home, '2026-11-16', 'fakes', 4);
        $e->forceFill(['cooked_at' => now()])->save();
        planned($this->home, '2026-11-17', 'tzatziki', 4);

        $this->list->sync($this->home, null, $this->monday, $this->monday->addDays(6));
        expect(ShoppingItem::pluck('name')->sort()->values()->all())->toBe(['Cucumber', 'Garlic', 'Yogurt']);
    });

    it('is part of the evening-before-shopping list', function () {
        // Saturday is the usual shopping day: three Saturday trips
        foreach (['2026-10-24', '2026-10-31', '2026-11-07'] as $d) {
            $this->home->shoppingTrips()->create(['date' => $d, 'total' => 50, 'source' => 'manual']);
        }
        planned($this->home, '2026-11-15', 'tzatziki', 4);
        (new PrepareShoppingList($this->home, '2026-11-13'))->handle();

        expect(ShoppingItem::where('source', ShoppingSource::MealPlan)->pluck('name')->sort()->values()->all())->toBe(['Cucumber', 'Garlic', 'Yogurt']);
    });
});

describe('cooking from the plan', function () {
    it('cooks a planned meal: the kitchen and the list follow', function () {
        $eggs = have($this->home, 'Eggs', 6, 'pcs');
        have($this->home, 'Tomatoes', 1000, 'g');
        $e = planned($this->home, '2026-11-13', 'strapatsada', 2);
        Sanctum::actingAs($this->owner);

        $this->postJson("/api/households/{$this->home->id}/meal-plan/{$e->id}/cooked")->assertOk()->assertJsonPath('used', ['Eggs', 'Tomatoes']);
        expect($e->fresh()->cooked_at)->not->toBeNull()->and($eggs->fresh()->amount)->toBe(2.0);
        $this->postJson("/api/households/{$this->home->id}/meal-plan/{$e->id}/cooked")->assertUnprocessable();
    });
});

describe('the API and the page', function () {
    it('plans, sets, clears and shops from the phone', function () {
        Sanctum::actingAs($this->owner);
        $base = "/api/households/{$this->home->id}/meal-plan";

        $this->postJson("{$base}/plan", ['from' => '2026-11-16', 'days' => 3])->assertOk()->assertJsonCount(3, 'added');
        $this->putJson("{$base}/2026-11-16/lunch", ['title' => 'Eating out', 'servings' => 2])->assertOk()->assertJsonPath('entry.label', 'Eating out');
        $this->putJson("{$base}/2026-11-16/dinner", ['recipe_id' => Recipe::where('slug', 'fakes')->value('id'), 'servings' => 4])
            ->assertOk()->assertJsonPath('entry.label', 'Lentil soup (fakes)')->assertJsonPath('entry.auto', false);
        $this->getJson("{$base}?from=2026-11-16")->assertOk()->assertJsonCount(4, 'entries')->assertJsonPath('shopping.0.ingredient', fn ($v) => is_string($v));
        $this->deleteJson("{$base}/2026-11-16/lunch")->assertNoContent();
        $this->putJson("{$base}/2026-11-16/snack", ['title' => 'x', 'servings' => 1])->assertNotFound();
        $this->putJson("{$base}/2026-11-16/dinner", ['servings' => 2])->assertUnprocessable();

        // The list followed every change
        expect(ShoppingItem::where('source', ShoppingSource::MealPlan)->pluck('name'))->toContain('Lentils');
    });

    it('shows the week on the Meals page', function () {
        planned($this->home, '2026-11-16', 'fakes', 2);
        $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);

        $this->get('/meals?from=2026-11-16')->assertInertia(fn (Page $p) => $p->component('meals')
            ->where('from', '2026-11-16')->has('days', 7)
            ->where('days.0.meals.dinner.label', 'Lentil soup (fakes)')
            ->has('recipes')->has('shopping'));
        $this->post('/meals/plan', ['from' => '2026-11-16'])->assertRedirect();
        expect(MealPlanEntry::count())->toBe(7);
    });
});
