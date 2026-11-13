<?php

use App\Enums\HouseholdRole;
use App\Enums\ItemCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Kitchen\Pantry;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\KitchenMovement;
use App\Models\User;
use App\Web\CurrentHousehold;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Page;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create(['name' => 'Eleni']);
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->pantry = app(Pantry::class);
});

function kitchenItem(Household $home, array $attributes = []): InventoryItem
{
    return $home->inventoryItems()->create($attributes + [
        'name' => 'Milk',
        'category' => ItemCategory::Drinks,
        'location' => StorageLocation::Fridge,
    ]);
}

describe('the item', function () {
    it('keys its ingredient from the name, in Greek too', function () {
        expect(kitchenItem($this->home, ['name' => 'ΓΙΑΟΥΡΤΙ ΣΤΡΑΓΓΙΣΤΟ'])->ingredient)->toBe('yogurt');
    });

    it('keeps an ingredient someone chose', function () {
        $item = kitchenItem($this->home, ['name' => 'Kefir', 'ingredient' => 'yogurt']);
        $item->update(['location' => StorageLocation::Pantry]);
        expect($item->fresh()->ingredient)->toBe('yogurt');
    });

    it('reads an amount from the phone\'s quantity text', function () {
        $item = kitchenItem($this->home, ['quantity' => '1,5 l']);
        expect($item)->amount->toBe(1500.0)->unit->toBe('ml')->full_amount->toBe(1500.0)->level->toBe(StockLevel::Full);
    });

    it('keeps the phone\'s own level when it sends one', function () {
        expect(kitchenItem($this->home, ['quantity' => '1 l', 'level' => StockLevel::Low])->level)->toBe(StockLevel::Low);
    });

    it('lets the level follow the amount', function (float $amount, StockLevel $level) {
        $item = kitchenItem($this->home, ['amount' => 1000, 'unit' => 'ml']);
        $item->update(['amount' => $amount]);
        expect($item->fresh()->level)->toBe($level);
    })->with([
        [800, StockLevel::Full],
        [500, StockLevel::Half],
        [200, StockLevel::Low],
        [0, StockLevel::Empty],
    ]);

    it('refuses a negative amount or an amount without a unit in the database', function () {
        $item = kitchenItem($this->home);
        expect(fn () => DB::table('inventory_items')->where('id', $item->id)->update(['amount' => -1, 'unit' => 'ml']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('inventory_items')->where('id', $item->id)->update(['amount' => 5, 'unit' => null]))->toThrow(QueryException::class);
    });
});

describe('the pantry', function () {
    it('adds and takes, one movement each, in the item\'s unit', function () {
        $item = kitchenItem($this->home);
        $this->pantry->add($item, 1, 'l', $this->owner);
        $this->pantry->take($item, 'used', 250, 'ml', $this->owner);
        // Grams for an item counted in millilitres: 1:1
        $this->pantry->take($item, 'cooked', 100, 'g', null);

        expect($item->fresh())->amount->toBe(650.0)->unit->toBe('ml')->level->toBe(StockLevel::Full)
            ->and(KitchenMovement::orderBy('occurred_at')->orderBy('id')->get(['kind', 'amount', 'unit'])->toArray())->toBe([
                ['kind' => 'bought', 'amount' => 1000.0, 'unit' => 'ml'],
                ['kind' => 'used', 'amount' => -250.0, 'unit' => 'ml'],
                ['kind' => 'cooked', 'amount' => -100.0, 'unit' => 'ml'],
            ]);
    });

    it('takes all of it without an amount, and never goes below zero', function () {
        $item = kitchenItem($this->home, ['amount' => 6, 'unit' => 'pcs', 'name' => 'Eggs']);
        $this->pantry->take($item, 'used', 10, null, $this->owner);
        expect($item->fresh()->amount)->toBe(0.0)->and(KitchenMovement::sole()->amount)->toBe(-6.0);

        $this->pantry->add($item, 4, 'pcs', null);
        $this->pantry->take($item, 'wasted', null, null, null);
        expect($item->fresh())->amount->toBe(0.0)->level->toBe(StockLevel::Empty);
    });

    it('refuses pieces for something weighed', function () {
        $item = kitchenItem($this->home, ['name' => 'Feta', 'amount' => 400, 'unit' => 'g']);
        expect(fn () => $this->pantry->take($item, 'used', 2, 'pcs', null))->toThrow(ValidationException::class, 'counted in g');
    });

    it('counts: the difference is the movement', function () {
        $item = kitchenItem($this->home, ['name' => 'Eggs', 'amount' => 6, 'unit' => 'pcs']);
        $this->pantry->set($item, 2, 'pcs', $this->owner);
        expect($item->fresh()->amount)->toBe(2.0)->and(KitchenMovement::sole())->kind->toBe('adjusted')->amount->toBe(-4.0);
    });

    it('starts an item without an amount from what comes in', function () {
        $item = kitchenItem($this->home, ['name' => 'Coffee', 'level' => StockLevel::Low]);
        $this->pantry->add($item, 500, 'g', null);
        expect($item->fresh())->amount->toBe(500.0)->unit->toBe('g')->level->toBe(StockLevel::Full);
    });
});

describe('the API', function () {
    beforeEach(fn () => Sanctum::actingAs($this->owner));

    it('returns amounts with the item, and accepts them', function () {
        $id = $this->postJson("/api/households/{$this->home->id}/inventory-items", [
            'name' => 'Feta', 'category' => 'food', 'location' => 'fridge', 'amount' => 400, 'unit' => 'g',
        ])->assertCreated()
            ->assertJsonPath('data.ingredient', 'feta')->assertJsonPath('data.amount_text', '400 g')->assertJsonPath('data.level', 'full')
            ->json('data.id');

        $this->postJson("/api/households/{$this->home->id}/inventory-items", ['name' => 'X', 'category' => 'food', 'location' => 'fridge', 'amount' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('unit');

        $this->postJson("/api/households/{$this->home->id}/inventory-items/{$id}/use", ['amount' => 150])
            ->assertOk()->assertJsonPath('item.amount_text', '250 g')->assertJsonPath('movement.label', '−150 g')->assertJsonPath('movement.by', 'Eleni');
        $this->postJson("/api/households/{$this->home->id}/inventory-items/{$id}/add", ['amount' => 0.2, 'unit' => 'kg'])
            ->assertCreated()->assertJsonPath('item.amount_text', '450 g');
        $this->getJson("/api/households/{$this->home->id}/kitchen/movements?item={$id}")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.kind', 'bought');
    });

    it('still syncs an item from a phone that knows nothing about amounts', function () {
        $this->postJson("/api/households/{$this->home->id}/inventory-items", ['name' => 'Bread', 'category' => 'food', 'location' => 'pantry', 'level' => 'half'])
            ->assertCreated()->assertJsonPath('data.amount', null)->assertJsonPath('data.level', 'half')->assertJsonPath('data.ingredient', 'bread');
    });

    it('keeps other households out', function () {
        $item = kitchenItem(Household::factory()->create());
        $this->postJson("/api/households/{$this->home->id}/inventory-items/{$item->id}/use", [])->assertNotFound();
        $this->postJson("/api/households/{$item->household_id}/inventory-items/{$item->id}/use", [])->assertForbidden();
    });
});

describe('the page', function () {
    beforeEach(fn () => $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]));

    it('shows the kitchen with amounts, without the cleaning cupboard', function () {
        $milk = kitchenItem($this->home, ['amount' => 700, 'unit' => 'ml']);
        $this->home->inventoryItems()->create(['name' => 'Dish soap', 'category' => ItemCategory::Cleaning, 'location' => StorageLocation::Cleaning]);
        $this->pantry->take($milk, 'used', 200, 'ml', $this->owner);

        $this->get('/kitchen')->assertInertia(fn (Page $p) => $p->component('kitchen')
            ->has('items', 1)->where('items.0.amount_text', '500 ml')
            ->where('movements.0.label', '−200 ml')->where('movements.0.by', 'Eleni'));
    });

    it('adds an item with an amount', function () {
        $this->post('/kitchen/items', ['name' => 'Φέτα', 'location' => 'fridge', 'amount_text' => '400 γρ'])->assertRedirect('/kitchen');
        expect(InventoryItem::sole())->ingredient->toBe('feta')->amount->toBe(400.0)->unit->toBe('g')
            ->and(KitchenMovement::sole()->kind)->toBe('bought');

        $this->post('/kitchen/items', ['name' => 'Φέτα', 'location' => 'fridge'])->assertSessionHasErrors('name');
        $this->post('/kitchen/items', ['name' => 'Rice', 'location' => 'pantry', 'amount_text' => 'some'])->assertSessionHasErrors('amount_text');
    });

    it('uses, wastes, adds and counts; a bare number is in the item\'s unit', function () {
        $feta = kitchenItem($this->home, ['name' => 'Feta', 'amount' => 400, 'unit' => 'g']);
        $this->post("/kitchen/items/{$feta->id}/use", ['kind' => 'used', 'amount_text' => '150'])->assertRedirect('/kitchen');
        $this->post("/kitchen/items/{$feta->id}/add", ['amount_text' => '0,2 kg']);
        $this->post("/kitchen/items/{$feta->id}/set", ['amount_text' => '300']);
        $this->post("/kitchen/items/{$feta->id}/use", ['kind' => 'wasted']);

        expect($feta->fresh()->amount)->toBe(0.0)
            ->and(KitchenMovement::orderBy('occurred_at')->orderBy('id')->pluck('amount')->all())->toBe([-150.0, 200.0, -150.0, -300.0]);
    });
});
