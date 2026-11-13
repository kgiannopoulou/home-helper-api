<?php

use App\Ai\Tools\ToolContext;
use App\Ai\Tools\ToolRegistry;
use App\Enums\HouseholdRole;
use App\Enums\ItemCategory;
use App\Enums\ShoppingSource;
use App\Enums\StorageLocation;
use App\Jobs\CheckExpiringFood;
use App\Kitchen\Recipes\Cooking;
use App\Kitchen\Recipes\RecipeLibrary;
use App\Models\Device;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\KitchenMovement;
use App\Models\Recipe;
use App\Models\User;
use App\Queries\RecipeMatchQuery;
use App\Web\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Page;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00', 'Europe/Athens'));
    $this->today = CarbonImmutable::parse('2026-11-13', 'Europe/Athens');
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->cooking = app(Cooking::class);
});

/** An item with an amount and, optionally, a use-by date */
function stock(Household $home, string $name, float $amount, string $unit, ?string $expires = null, StorageLocation $where = StorageLocation::Fridge): InventoryItem
{
    $item = $home->inventoryItems()->create(['name' => $name, 'category' => ItemCategory::Food, 'location' => $where, 'amount' => $amount, 'unit' => $unit]);
    $item->forceFill(['expires_on' => $expires])->save();

    return $item;
}

function suggest(Household $home, CarbonImmutable $today, ?string $tag = null): array
{
    return collect((new RecipeMatchQuery($home, $today, $tag, 50))->get())->keyBy('slug')->all();
}

it('loads Home Helper\'s own recipes once, with known ingredients and staples marked', function () {
    $count = count(require database_path('data/recipes.php'));
    expect(Recipe::whereNull('household_id')->count())->toBe($count);

    RecipeLibrary::sync();
    expect(Recipe::whereNull('household_id')->count())->toBe($count)
        ->and(Recipe::where('slug', 'horiatiki')->first()->ingredients->firstWhere('ingredient', 'olive oil')->staple)->toBeTrue();
});

it('suggests what can be cooked now, food going off soon first, then the quickest', function () {
    stock($this->home, 'Eggs', 6, 'pcs');
    stock($this->home, 'Tomatoes', 1000, 'g');
    stock($this->home, 'Feta', 200, 'g', '2026-11-14');
    $s = suggest($this->home, $this->today);

    expect(array_slice(array_keys($s), 0, 2))->toBe(['strapatsada', 'feta-bouyiourdi'])
        ->and($s['strapatsada'])->toMatchArray(['ready' => true, 'missing' => [], 'uses_soon' => ['feta']])
        // Needs cucumber and onion; olive oil and oregano are staples
        ->and($s['horiatiki'])->toMatchArray(['ready' => false, 'missing' => ['cucumber', 'onion'], 'required' => 4, 'have' => 2])
        // Nothing in the kitchen for a lentil soup: not suggested
        ->and($s)->not->toHaveKey('fakes');
});

it('knows when there is not enough', function () {
    stock($this->home, 'Eggs', 2, 'pcs');
    stock($this->home, 'Kefalograviera', 100, 'g')->forceFill(['ingredient' => 'cheese'])->save();

    expect(suggest($this->home, $this->today)['omelette'])->toMatchArray(['missing' => [], 'short' => 1, 'ready' => false]);
});

it('ignores what is used up, and filters by tag', function () {
    stock($this->home, 'Chicken', 0, 'g');
    stock($this->home, 'Lemons', 3, 'pcs');
    // Suggested for the lemon, but the used-up chicken counts as missing
    expect(suggest($this->home, $this->today)['kotopoulo-lemonato']['missing'])->toContain('chicken');

    stock($this->home, 'Chicken breast', 1000, 'g');
    expect(suggest($this->home, $this->today)['kotopoulo-lemonato']['missing'])->not->toContain('chicken')
        ->and(suggest($this->home, $this->today, 'vegan'))->not->toHaveKey('kotopoulo-lemonato')->toHaveKey('revithia');
});

it('cooks: takes the scaled amounts, the item going off soonest first', function () {
    $eggs = stock($this->home, 'Eggs', 6, 'pcs');
    $older = stock($this->home, 'Tomatoes (old)', 300, 'g', '2026-11-14');
    $newer = stock($this->home, 'Tomatoes', 1000, 'g', '2026-11-20');
    $feta = stock($this->home, 'Feta', 200, 'g');

    $result = $this->cooking->cook($this->home, Recipe::where('slug', 'strapatsada')->first(), $this->owner, 4);

    // Doubled: 8 eggs wanted, 6 there; 600 g tomatoes; 160 g feta
    expect($result)->toBe(['used' => ['Eggs', 'Tomatoes (old)', 'Tomatoes', 'Feta'], 'missing' => []])
        ->and($eggs->fresh()->amount)->toBe(0.0)
        ->and($older->fresh()->amount)->toBe(0.0)
        ->and($newer->fresh()->amount)->toBe(700.0)
        ->and($feta->fresh()->amount)->toBe(40.0)
        ->and(KitchenMovement::where('kind', 'cooked')->pluck('note')->unique()->all())->toBe(['Strapatsada (eggs with tomato)']);
});

it('leaves alone what it cannot measure against the recipe', function () {
    $bread = stock($this->home, 'Bread', 500, 'g', null, StorageLocation::Pantry);
    stock($this->home, 'Eggs', 6, 'pcs');
    stock($this->home, 'Milk', 1000, 'ml');

    $result = $this->cooking->cook($this->home, Recipe::where('slug', 'french-toast')->first(), $this->owner);
    expect($result['used'])->toContain('Bread')->and($bread->fresh()->amount)->toBe(500.0);
});

it('puts what is missing or short on the shopping list, once', function () {
    stock($this->home, 'Tomatoes', 100, 'g');
    $recipe = Recipe::where('slug', 'horiatiki')->first();

    expect($this->cooking->shopFor($this->home, $recipe, $this->owner))->toBe(['Tomato', 'Cucumber', 'Onion', 'Feta'])
        ->and($this->cooking->shopFor($this->home, $recipe, $this->owner))->toBe([])
        ->and($this->home->shoppingItems()->where('name', 'Tomato')->first())->quantity->toBe('300 g')->source->toBe(ShoppingSource::Recipe);
});

it('keeps a household\'s own recipe to itself', function () {
    $mine = $this->cooking->create($this->home, $this->owner, 'Γιαγιάς φασολάκια', 4, 60, [
        ['name' => 'Πράσινα φασολάκια', 'amount_text' => '1 kg'], ['name' => 'Πατάτες', 'amount_text' => '500 g'], ['name' => 'ντομάτες', 'amount_text' => '400 g'], ['name' => 'Ελαιόλαδο', 'amount_text' => '100 ml'],
    ], 'Everything in a pot, an hour on low.');
    stock($this->home, 'Potatoes', 1000, 'g', null, StorageLocation::Pantry);

    expect($mine->ingredients->pluck('ingredient')->all())->toBe(['green beans', 'potato', 'tomato', 'olive oil'])
        ->and(collect(suggest($this->home, $this->today))->pluck('name'))->toContain('Γιαγιάς φασολάκια')
        ->and(collect((new RecipeMatchQuery(Household::factory()->create(), $this->today))->get())->pluck('name'))->not->toContain('Γιαγιάς φασολάκια');
});

it('tells the assistant what to cook', function () {
    stock($this->home, 'Spinach', 500, 'g', '2026-11-14');
    stock($this->home, 'Rice', 1000, 'g', null, StorageLocation::Pantry);
    stock($this->home, 'Onions', 3, 'pcs', null, StorageLocation::Pantry);
    stock($this->home, 'Lemons', 2, 'pcs');

    $result = app(ToolRegistry::class)->find('suggest_recipes')->run(['limit' => 3], new ToolContext($this->home, $this->owner, $this->today));
    expect($result['recipes'][0])->toMatchArray(['name' => 'Spinach rice (spanakorizo)', 'can_cook_now' => true, 'uses_food_going_off_soon' => ['spinach']]);
});

it('suggests a recipe in the morning push', function () {
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 't']]])]);
    Device::forceCreate(['user_id' => $this->owner->id, 'expo_token' => 'ExponentPushToken[owner]']);
    stock($this->home, 'Eggs', 6, 'pcs', '2026-11-14');
    stock($this->home, 'Tomatoes', 500, 'g');

    (new CheckExpiringFood($this->home, '2026-11-13'))->handle();
    $body = collect(Http::recorded())->flatMap(fn ($p) => $p[0]->data())->first()['body'];
    expect($body)->toBe('Eggs (tomorrow). Try: Strapatsada (eggs with tomato).');
});

describe('the API and the page', function () {
    it('suggests, shows, cooks and shops from the phone', function () {
        Sanctum::actingAs($this->owner);
        stock($this->home, 'Eggs', 6, 'pcs');
        stock($this->home, 'Tomatoes', 1000, 'g');
        $id = Recipe::where('slug', 'strapatsada')->value('id');

        $this->getJson("/api/households/{$this->home->id}/kitchen/recipes/suggestions?limit=3")->assertOk()->assertJsonPath('data.0.slug', 'strapatsada');
        $this->getJson("/api/households/{$this->home->id}/kitchen/recipes/{$id}")->assertOk()->assertJsonPath('recipe.ingredients.0.label', '4 egg');
        $this->postJson("/api/households/{$this->home->id}/kitchen/recipes/{$id}/cook", ['servings' => 1])->assertOk()->assertJsonPath('used', ['Eggs', 'Tomatoes']);
        $this->postJson("/api/households/{$this->home->id}/kitchen/recipes/".Recipe::where('slug', 'horiatiki')->value('id').'/shopping')->assertOk()->assertJsonPath('added', ['Cucumber', 'Onion', 'Feta']);
        $this->postJson("/api/households/{$this->home->id}/kitchen/recipes", ['name' => 'X', 'servings' => 2, 'steps' => 'y', 'ingredients' => []])->assertUnprocessable();
    });

    it('shows suggestions on the Kitchen page and adds a recipe there', function () {
        stock($this->home, 'Eggs', 6, 'pcs');
        $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);

        $this->get('/kitchen')->assertInertia(fn (Page $p) => $p->has('recipes')->where('recipes.0.missing', fn ($m) => count($m) <= 1));
        $this->post('/kitchen/recipes', ['name' => 'Boiled eggs', 'servings' => 1, 'minutes' => 10, 'steps' => 'Boil 8 minutes.', 'ingredients' => [['name' => 'eggs', 'amount_text' => '2']]])->assertRedirect('/kitchen');
        $this->get('/kitchen')->assertInertia(fn (Page $p) => $p->where('recipes.0.name', 'Boiled eggs')->where('recipes.0.own', true));
    });
});
