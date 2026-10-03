<?php

use App\Models\AdminItem;
use App\Models\Budget;
use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Event;
use App\Models\Expense;
use App\Models\FoodEntry;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\ItemPrice;
use App\Models\Purchase;
use App\Models\RecurringBill;
use App\Models\Room;
use App\Models\ShoppingItem;
use App\Models\ShoppingTrip;
use App\Models\SleepEntry;
use App\Models\Supply;
use App\Models\Todo;
use App\Models\User;
use App\Models\WaterEntry;
use App\Models\Weight;
use App\Models\Workout;
use Laravel\Sanctum\Sanctum;

/*
 * Every module goes through the same checks: list, create, validation, show,
 * update, delete, and that another household's data is out of reach.
 *
 * Each case: [url slug, make a row for (household, user), a valid new row,
 *             an invalid change => the field that should fail, a PATCH body]
 */
dataset('modules', [
    'expenses' => ['expenses',
        fn (Household $h, User $u) => Expense::factory()->for($h)->create(['user_id' => $u->id]),
        fn (Household $h) => ['date' => '2026-09-14', 'amount' => 12.5, 'category' => 'groceries', 'note' => 'Lidl'],
        [['amount' => -3], 'amount'], ['amount' => 20]],
    'recurring bills' => ['recurring-bills',
        fn (Household $h) => RecurringBill::factory()->for($h)->create(),
        fn (Household $h) => ['name' => 'Window cleaner', 'amount' => 30, 'category' => 'household', 'day_of_month' => 12],
        [['day_of_month' => 31], 'day_of_month'], ['amount' => 35]],
    'budgets' => ['budgets',
        fn (Household $h) => Budget::factory()->for($h)->create(),
        fn (Household $h) => ['period' => 'week', 'category' => 'groceries', 'amount' => 90],
        [['period' => 'year'], 'period'], ['amount' => 100]],
    'inventory items' => ['inventory-items',
        fn (Household $h) => InventoryItem::factory()->for($h)->create(),
        fn (Household $h) => ['name' => 'Basil', 'category' => 'food', 'location' => 'fridge', 'expires_on' => '2026-10-08'],
        [['location' => 'garage'], 'location'], ['level' => 'low']],
    'purchases' => ['purchases',
        fn (Household $h) => Purchase::factory()->for($h)->for(InventoryItem::factory()->for($h))->create(),
        fn (Household $h) => ['inventory_item_id' => InventoryItem::factory()->for($h)->create()->id, 'bought_on' => '2026-09-13', 'price' => 1.49],
        [['bought_on' => 'last Saturday'], 'bought_on'], ['price' => 1.59]],
    'shopping items' => ['shopping-items',
        fn (Household $h) => ShoppingItem::factory()->for($h)->create(),
        fn (Household $h) => ['name' => 'Bananas', 'category' => 'food', 'quantity' => '1 kg'],
        [['category' => 'fruit'], 'category'], ['checked' => true]],
    'shopping trips' => ['shopping-trips',
        fn (Household $h) => ShoppingTrip::factory()->for($h)->create(),
        fn (Household $h) => ['date' => '2026-09-13', 'store' => 'Lidl', 'total' => 54.3, 'item_count' => 17],
        [['total' => -1], 'total'], ['total' => 60]],
    'item prices' => ['item-prices',
        fn (Household $h) => ItemPrice::factory()->for($h)->create(),
        fn (Household $h) => ['name' => 'milk', 'price' => 1.49, 'seen_on' => '2026-09-13'],
        [['price' => 'cheap'], 'price'], ['price' => 1.55]],
    'rooms' => ['rooms',
        fn (Household $h) => Room::factory()->for($h)->create(),
        fn (Household $h) => ['name' => 'Garage', 'emoji' => '🚗'],
        [['name' => ''], 'name'], ['personal' => true]],
    'chores' => ['chores',
        fn (Household $h) => Chore::factory()->for($h)->for(Room::factory()->for($h))->create(),
        fn (Household $h) => ['room_id' => Room::factory()->for($h)->create()->id, 'name' => 'Sweep', 'every_days' => 7, 'minutes' => 10],
        [['every_days' => 0], 'every_days'], ['minutes' => 12]],
    'chore completions' => ['chore-completions',
        fn (Household $h) => ChoreCompletion::factory()->for($h)->for(Chore::factory()->for($h)->for(Room::factory()->for($h)))->create(),
        fn (Household $h) => ['chore_id' => Chore::factory()->for($h)->for(Room::factory()->for($h))->create()->id, 'done_at' => '2026-09-14T10:00:00Z', 'minutes' => 15],
        [['minutes' => 0], 'minutes'], ['minutes' => 20]],
    'supplies' => ['supplies',
        fn (Household $h) => Supply::factory()->for($h)->create(),
        fn (Household $h) => ['name' => 'Descaler', 'level' => 'full'],
        [['level' => 'gone'], 'level'], ['level' => 'low']],
    'food entries' => ['food-entries',
        fn (Household $h, User $u) => FoodEntry::factory()->for($h)->create(['user_id' => $u->id]),
        fn (Household $h) => ['date' => '2026-09-14', 'eaten_at' => '2026-09-14T13:00:00+03:00', 'name' => 'Chicken salad', 'meal' => 'lunch', 'kcal' => 430, 'protein' => 38],
        [['meal' => 'brunch'], 'meal'], ['kcal' => 450]],
    'water entries' => ['water-entries',
        fn (Household $h, User $u) => WaterEntry::factory()->for($h)->create(['user_id' => $u->id]),
        fn (Household $h) => ['date' => '2026-09-14', 'drunk_at' => '2026-09-14T10:00:00Z', 'ml' => 250],
        [['ml' => 0], 'ml'], ['ml' => 330]],
    'sleep entries' => ['sleep-entries',
        fn (Household $h, User $u) => SleepEntry::factory()->for($h)->create(['user_id' => $u->id]),
        fn (Household $h) => ['date' => '2025-01-15', 'bed_at' => '2025-01-14T23:30:00Z', 'woke_at' => '2025-01-15T07:00:00Z', 'quality' => 4],
        [['woke_at' => '2025-01-14T22:00:00Z'], 'woke_at'], ['quality' => 5]],
    'workouts' => ['workouts',
        fn (Household $h, User $u) => Workout::factory()->for($h)->create(['user_id' => $u->id]),
        fn (Household $h) => ['date' => '2026-09-14', 'type' => 'run', 'minutes' => 30, 'kcal' => 270],
        [['type' => 'dance'], 'type'], ['minutes' => 35]],
    'weights' => ['weights',
        fn (Household $h, User $u) => Weight::factory()->for($h)->create(['user_id' => $u->id]),
        fn (Household $h) => ['date' => '2025-01-15', 'kg' => 70.2],
        [['kg' => 5], 'kg'], ['kg' => 70]],
    'events' => ['events',
        fn (Household $h) => Event::factory()->for($h)->create(),
        fn (Household $h) => ['title' => 'Dentist', 'starts_at' => '2026-10-10T10:00:00Z', 'ends_at' => '2026-10-10T11:00:00Z'],
        [['ends_at' => '2026-10-10T09:00:00Z'], 'ends_at'], ['title' => 'Dentist (moved)']],
    'todos' => ['todos',
        fn (Household $h) => Todo::factory()->for($h)->create(),
        fn (Household $h) => ['title' => 'Call the plumber', 'due_on' => '2026-10-05', 'minutes' => 10],
        [['minutes' => 0], 'minutes'], ['done_at' => '2026-10-04T18:00:00Z']],
    'admin items' => ['admin-items',
        fn (Household $h) => AdminItem::factory()->for($h)->create(),
        fn (Household $h) => ['title' => 'Car insurance', 'kind' => 'renewal', 'due_on' => '2026-11-01', 'repeat_months' => 12],
        [['kind' => 'tax'], 'kind'], ['amount' => 310]],
]);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->home = Household::factory()->create();
    $this->home->addMember($this->user);

    $this->stranger = User::factory()->create();
    $this->away = Household::factory()->create();
    $this->away->addMember($this->stranger);

    Sanctum::actingAs($this->user);
});

// $valid is unused here, but Pest fails to bind this dataset to a closure with only two parameters
test('lists only this household\'s rows', function (string $slug, Closure $make, Closure $valid) {
    $make($this->home, $this->user);
    $make($this->home, $this->user);
    $make($this->away, $this->stranger);

    $this->getJson("/api/households/{$this->home->id}/$slug")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);
})->with('modules');

test('creates a row', function (string $slug, Closure $make, Closure $valid) {
    $body = $valid($this->home);

    $response = $this->postJson("/api/households/{$this->home->id}/$slug", $body)
        ->assertCreated()
        ->assertJsonStructure(['data' => ['id', 'updated_at']]);

    $id = $response->json('data.id');
    $this->getJson("/api/households/{$this->home->id}/$slug/$id")->assertOk()->assertJsonPath('data.id', $id);
})->with('modules');

test('rejects invalid input', function (string $slug, Closure $make, Closure $valid, array $invalid) {
    [$change, $field] = $invalid;

    $this->postJson("/api/households/{$this->home->id}/$slug", [...$valid($this->home), ...$change])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    $this->postJson("/api/households/{$this->home->id}/$slug", [])->assertUnprocessable();
})->with('modules');

test('updates and deletes a row', function (string $slug, Closure $make, Closure $valid, array $invalid, array $patch) {
    $row = $make($this->home, $this->user);
    $url = "/api/households/{$this->home->id}/$slug/{$row->id}";

    $response = $this->patchJson($url, $patch)->assertOk();
    $field = array_key_first($patch);
    expect($response->json("data.$field"))->not->toBeNull();

    $this->deleteJson($url)->assertNoContent();
    $this->getJson($url)->assertNotFound();
    expect($row::withTrashed()->find($row->id)->trashed())->toBeTrue();
})->with('modules');

test('another household\'s data is refused', function (string $slug, Closure $make, Closure $valid) {
    $theirs = $make($this->away, $this->stranger);

    // Their household's URL: not a member
    $this->getJson("/api/households/{$this->away->id}/$slug")->assertForbidden();
    $this->postJson("/api/households/{$this->away->id}/$slug", $valid($this->away))->assertForbidden();
    $this->getJson("/api/households/{$this->away->id}/$slug/{$theirs->id}")->assertForbidden();

    // Their row under our household's URL: it isn't there
    $this->getJson("/api/households/{$this->home->id}/$slug/{$theirs->id}")->assertNotFound();
    $this->patchJson("/api/households/{$this->home->id}/$slug/{$theirs->id}", [])->assertNotFound();
    $this->deleteJson("/api/households/{$this->home->id}/$slug/{$theirs->id}")->assertNotFound();

    expect($theirs->fresh()->trashed())->toBeFalse();
})->with('modules');
