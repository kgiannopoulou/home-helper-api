<?php

use App\Models\Chore;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Plant;
use App\Models\Room;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The composite foreign keys (2026_11_10_000001): a child row can't point at
 * a parent in another household, even when the validation rules are skipped.
 */

/** A raw insert, the way a bug or a manual fix would do it */
function insertRow(string $table, array $row): void
{
    DB::table($table)->insert($row + ['id' => (string) Str::ulid(), 'created_at' => now(), 'updated_at' => now()]);
}

beforeEach(function () {
    $this->mine = Household::factory()->create();
    $this->theirs = Household::factory()->create();
});

test('a purchase cannot use another household\'s item', function () {
    $item = InventoryItem::factory()->for($this->theirs)->create();

    expect(fn () => insertRow('purchases', [
        'household_id' => $this->mine->id,
        'inventory_item_id' => $item->id,
        'bought_on' => '2026-11-01',
    ]))->toThrow(QueryException::class, 'purchases_same_household_fk');
});

test('a chore cannot be in another household\'s room', function () {
    $room = Room::factory()->for($this->theirs)->create();

    expect(fn () => insertRow('chores', [
        'household_id' => $this->mine->id,
        'room_id' => $room->id,
        'name' => 'Vacuum',
        'every_days' => 7,
        'minutes' => 10,
    ]))->toThrow(QueryException::class, 'chores_same_household_fk');
});

test('a completion cannot belong to another household\'s chore', function () {
    $chore = Chore::factory()->for(Room::factory()->for($this->theirs))->create(['household_id' => $this->theirs->id]);

    expect(fn () => insertRow('chore_completions', [
        'household_id' => $this->mine->id,
        'chore_id' => $chore->id,
        'done_at' => now(),
        'minutes' => 10,
    ]))->toThrow(QueryException::class, 'chore_completions_same_household_fk');
});

test('care logs and alerts cannot point at another household\'s plant', function () {
    $plant = Plant::factory()->for($this->theirs)->create();

    expect(fn () => insertRow('plant_care_logs', [
        'household_id' => $this->mine->id,
        'plant_id' => $plant->id,
        'type' => 'watered',
        'performed_at' => now(),
    ]))->toThrow(QueryException::class, 'plant_care_logs_same_household_fk');

    expect(fn () => insertRow('plant_alerts', [
        'household_id' => $this->mine->id,
        'plant_id' => $plant->id,
        'type' => 'watering_due',
        'severity' => 'info',
        'title' => 'Water me',
        'message' => 'Water me',
        'triggered_at' => now(),
    ]))->toThrow(QueryException::class, 'plant_alerts_same_household_fk');
});

test('rows in the same household are still fine, and still cascade', function () {
    $item = InventoryItem::factory()->for($this->mine)->create();
    insertRow('purchases', ['household_id' => $this->mine->id, 'inventory_item_id' => $item->id, 'bought_on' => '2026-11-01']);

    expect(DB::table('purchases')->where('inventory_item_id', $item->id)->count())->toBe(1);

    $item->forceDelete();
    expect(DB::table('purchases')->where('inventory_item_id', $item->id)->count())->toBe(0);
});

test('the new relationships load', function () {
    $user = User::factory()->create();
    $this->mine->addMember($user);
    $room = Room::factory()->for($this->mine)->create();
    $chore = Chore::factory()->for($room)->create(['household_id' => $this->mine->id, 'assignee_id' => $user->id]);

    expect($user->memberships)->toHaveCount(1)
        ->and($user->memberships->first()->household->is($this->mine))->toBeTrue()
        ->and($this->mine->memberships->first()->user->is($user))->toBeTrue()
        ->and($user->assignedChores->first()->is($chore))->toBeTrue();
});
