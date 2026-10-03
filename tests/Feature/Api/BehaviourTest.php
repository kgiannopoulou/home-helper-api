<?php

use App\Models\Chore;
use App\Models\ChoreCompletion;
use App\Models\Expense;
use App\Models\FoodEntry;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\RecurringBill;
use App\Models\Room;
use App\Models\SleepEntry;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->home = Household::factory()->create();
    $this->home->addMember($this->user);
    Sanctum::actingAs($this->user);
});

test('filters by date range and paginates', function () {
    foreach (['2026-08-31', '2026-09-01', '2026-09-15', '2026-09-30', '2026-10-01'] as $day) {
        Expense::factory()->for($this->home)->create(['date' => $day]);
    }
    $url = "/api/households/{$this->home->id}/expenses";

    $this->getJson("$url?from=2026-09-01&to=2026-09-30")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.date', '2026-09-30');

    $this->getJson("$url?per_page=2")
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonPath('links.next', url("$url?per_page=2&page=2"));

    $this->getJson("$url?from=2026-09-30&to=2026-09-01")->assertJsonValidationErrors('to');
    $this->getJson("$url?per_page=500")->assertJsonValidationErrors('per_page');
});

test('the to day is included for timestamp columns too', function () {
    $chore = Chore::factory()->for($this->home)->for(Room::factory()->for($this->home))->create();
    foreach (['2026-09-30 23:59:00', '2026-10-01 00:00:00'] as $at) {
        ChoreCompletion::factory()->for($this->home)->for($chore)->create(['done_at' => $at]);
    }

    $this->getJson("/api/households/{$this->home->id}/chore-completions?to=2026-09-30")
        ->assertJsonCount(1, 'data');
});

test('personal logs are private, even inside the household', function () {
    $partner = User::factory()->create();
    $this->home->addMember($partner);
    $mine = FoodEntry::factory()->for($this->home)->create(['user_id' => $this->user->id]);
    FoodEntry::factory()->for($this->home)->create(['user_id' => $partner->id]);

    Sanctum::actingAs($partner);
    $this->getJson("/api/households/{$this->home->id}/food-entries")->assertJsonCount(1, 'data');
    $this->getJson("/api/households/{$this->home->id}/food-entries/{$mine->id}")->assertNotFound();
    $this->deleteJson("/api/households/{$this->home->id}/food-entries/{$mine->id}")->assertNotFound();
});

test('a new personal log belongs to the caller, whatever the body says', function () {
    $partner = User::factory()->create();
    $this->home->addMember($partner);

    $this->postJson("/api/households/{$this->home->id}/weights", ['date' => '2026-09-14', 'kg' => 70.4, 'user_id' => $partner->id])
        ->assertCreated()
        ->assertJsonPath('data.user_id', $this->user->id);
});

test('cannot link to another household\'s rows', function () {
    $theirBill = RecurringBill::factory()->create();
    $theirItem = InventoryItem::factory()->create();
    $outsider = User::factory()->create();

    $this->postJson("/api/households/{$this->home->id}/expenses", [
        'date' => '2026-09-01', 'amount' => 600, 'category' => 'bills', 'recurring_bill_id' => $theirBill->id,
    ])->assertJsonValidationErrors('recurring_bill_id');

    $this->postJson("/api/households/{$this->home->id}/purchases", [
        'inventory_item_id' => $theirItem->id, 'bought_on' => '2026-09-13',
    ])->assertJsonValidationErrors('inventory_item_id');

    $room = Room::factory()->for($this->home)->create();
    $this->postJson("/api/households/{$this->home->id}/chores", [
        'room_id' => $room->id, 'name' => 'Sweep', 'every_days' => 7, 'minutes' => 10, 'assignee_id' => $outsider->id,
    ])->assertJsonValidationErrors('assignee_id');
});

test('the same name twice is a conflict, but fine once the first is deleted', function () {
    $url = "/api/households/{$this->home->id}/inventory-items";
    $milk = ['name' => 'Milk', 'category' => 'drinks', 'location' => 'fridge'];

    $id = $this->postJson($url, $milk)->assertCreated()->json('data.id');
    $this->postJson($url, $milk)->assertStatus(409);

    $this->deleteJson("$url/$id")->assertNoContent();
    $this->postJson($url, $milk)->assertCreated();
});

test('times from the phone are stored in UTC', function () {
    $this->postJson("/api/households/{$this->home->id}/food-entries", [
        'date' => '2026-09-14', 'eaten_at' => '2026-09-14T13:00:00+03:00', 'name' => 'Salad', 'meal' => 'lunch', 'kcal' => 400,
    ])->assertCreated()
        ->assertJsonPath('data.eaten_at', '2026-09-14T10:00:00+00:00');
});

test('MySQL works out the hours slept and they come back in the response', function () {
    $this->postJson("/api/households/{$this->home->id}/sleep-entries", [
        'date' => '2026-09-14', 'bed_at' => '2026-09-13T23:30:00Z', 'woke_at' => '2026-09-14T07:00:00Z', 'quality' => 4,
    ])->assertCreated()
        ->assertJsonPath('data.hours', 7.5);
});

test('PATCH checks bed and wake times together', function () {
    $entry = SleepEntry::factory()->for($this->home)->create(['user_id' => $this->user->id]);

    $this->patchJson("/api/households/{$this->home->id}/sleep-entries/{$entry->id}", ['woke_at' => '2020-01-01T07:00:00Z'])
        ->assertJsonValidationErrors('bed_at');
});

test('a real token from another household is refused', function () {
    $this->app['auth']->forgetGuards();
    $expense = Expense::factory()->for($this->home)->create();

    $stranger = User::factory()->create();
    Household::factory()->create()->addMember($stranger);
    $token = $stranger->createToken('phone')->plainTextToken;

    $this->withToken($token)->getJson("/api/households/{$this->home->id}/expenses")->assertForbidden();
    $this->withToken($token)->getJson("/api/households/{$this->home->id}/expenses/{$expense->id}")->assertForbidden();
    $this->withToken($token)->deleteJson("/api/households/{$this->home->id}/expenses/{$expense->id}")->assertForbidden();

    expect($expense->fresh())->not->toBeNull();
});
