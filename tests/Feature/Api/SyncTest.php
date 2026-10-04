<?php

use App\Models\Chore;
use App\Models\Expense;
use App\Models\FoodEntry;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\Room;
use App\Models\ShoppingItem;
use App\Models\SleepEntry;
use App\Models\StepCount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Carbon::setTestNow('2026-10-03 12:00:00');
    $this->me = User::factory()->create();
    $this->partner = User::factory()->create();
    $this->home = Household::factory()->create();
    $this->home->addMember($this->me);
    $this->home->addMember($this->partner);
    Sanctum::actingAs($this->me);
});

function sync(?string $since, array $changes): TestResponse
{
    return test()->postJson('/api/households/'.test()->home->id.'/sync', ['since' => $since, 'changes' => $changes]);
}

function milk(array $extra = []): array
{
    return ['id' => 'milk0000000000000000000001', 'name' => 'Milk', 'category' => 'drinks', 'location' => 'fridge',
        'level' => 'full', 'purchases' => ['2026-09-26', '2026-10-01'], 'updated_at' => '2026-10-03T09:00:00.000Z', ...$extra];
}

test('a new row is stored with the phone\'s id and time', function () {
    sync(null, ['inventory_items' => [milk()]])
        ->assertOk()
        ->assertJsonPath('rejected', [])
        ->assertJsonMissingPath('changes.inventory_items'); // the phone already has it

    $item = InventoryItem::findOrFail('milk0000000000000000000001');
    expect($item->household_id)->toBe($this->home->id)
        ->and($item->updated_at->format('Y-m-d H:i:s.v'))->toBe('2026-10-03 09:00:00.000')
        ->and($item->purchases()->pluck('bought_on')->map->toDateString()->sort()->values()->all())->toBe(['2026-09-26', '2026-10-01']);
});

test('an older change never overwrites a newer one', function () {
    sync(null, ['inventory_items' => [milk(['level' => 'low', 'updated_at' => '2026-10-03T10:00:00.500Z'])]]);

    // The other phone was offline and changed it earlier
    $reply = sync(null, ['inventory_items' => [milk(['level' => 'empty', 'updated_at' => '2026-10-03T10:00:00.499Z'])]])
        ->assertOk()
        ->assertJsonPath('rejected', []);

    expect(InventoryItem::sole()->level->value)->toBe('low');
    // …and it gets the newer row back to replace its own
    expect($reply->json('changes.inventory_items.0'))->toMatchArray(['level' => 'low', 'updated_at' => '2026-10-03T10:00:00.500+00:00']);
});

test('a tie keeps the stored row', function () {
    sync(null, ['inventory_items' => [milk(['level' => 'low'])]]);
    sync(null, ['inventory_items' => [milk(['level' => 'empty'])]]);

    expect(InventoryItem::sole()->level->value)->toBe('low');
});

test('a newer change wins, from any time zone', function () {
    sync(null, ['inventory_items' => [milk(['level' => 'low', 'updated_at' => '2026-10-03T10:00:00Z'])]]);
    sync(null, ['inventory_items' => [milk(['level' => 'empty', 'updated_at' => '2026-10-03T13:30:00+03:00'])]]);

    expect(InventoryItem::sole()->level->value)->toBe('empty');
});

test('a deletion arrives as a tombstone and goes out to the other phone', function () {
    sync(null, ['inventory_items' => [milk()]]);
    $since = sync(null, [])->json('since');

    Carbon::setTestNow('2026-10-03 12:05:00');
    sync($since, ['inventory_items' => [['id' => 'milk0000000000000000000001', 'updated_at' => '2026-10-03T12:04:00Z', 'deleted_at' => '2026-10-03T12:04:00Z']]])
        ->assertOk();
    expect(InventoryItem::withTrashed()->sole()->trashed())->toBeTrue();

    // The partner's phone asks what changed since its last sync
    Sanctum::actingAs($this->partner);
    sync($since, [])
        ->assertJsonPath('changes.inventory_items.0.id', 'milk0000000000000000000001')
        ->assertJsonPath('changes.inventory_items.0.deleted_at', '2026-10-03T12:04:00.000+00:00');
});

test('an older deletion does not remove a newer edit', function () {
    sync(null, ['inventory_items' => [milk(['updated_at' => '2026-10-03T11:00:00Z'])]]);
    sync(null, ['inventory_items' => [['id' => 'milk0000000000000000000001', 'updated_at' => '2026-10-03T10:00:00Z', 'deleted_at' => '2026-10-03T10:00:00Z']]]);

    expect(InventoryItem::sole()->trashed())->toBeFalse();
});

test('milk added on one phone shows on the other after its next sync', function () {
    Sanctum::actingAs($this->partner);
    $partnerSince = sync(null, [])->json('since');

    Carbon::setTestNow('2026-10-03 12:10:00');
    Sanctum::actingAs($this->me);
    sync($partnerSince, ['shopping_items' => [[
        'id' => '01k6mlkmilkmilkmilkmilk001', 'name' => 'Milk', 'category' => 'drinks', 'quantity' => '2',
        'checked' => false, 'source' => 'manual', 'updated_at' => '2026-10-03T12:09:30Z',
    ]]]);

    Carbon::setTestNow('2026-10-03 12:11:00');
    Sanctum::actingAs($this->partner);
    $reply = sync($partnerSince, [])->assertOk();

    expect($reply->json('changes.shopping_items'))->toHaveCount(1)
        ->and($reply->json('changes.shopping_items.0'))->toMatchArray(['name' => 'Milk', 'quantity' => '2', 'added_by' => $this->me->id, 'deleted_at' => null]);

    // Inside the few seconds of overlap the same row can come again; merging it twice changes nothing
    expect(sync($reply->json('since'), [])->json('changes.shopping_items.*.id'))->toBe(['01k6mlkmilkmilkmilkmilk001']);
});

test('since only returns rows stored after it, starting a few seconds early', function () {
    $item = ShoppingItem::factory()->for($this->home)->create();
    $since = sync(null, [])->json('since');
    $dbNow = CarbonImmutable::parse(DB::selectOne('SELECT NOW(3) AS now')->now, 'UTC');

    // From MySQL's clock (the one that sets synced_at), 5 seconds early
    expect(CarbonImmutable::parse($since)->diffInMilliseconds($dbNow))->toBeGreaterThanOrEqual(5000)->toBeLessThan(6000);
    // So the item stored a moment ago comes again…
    expect(sync($since, [])->json('changes.shopping_items.0.id'))->toBe($item->id);
    // …but nothing is newer than now
    expect(sync($dbNow->addSecond()->format('Y-m-d\TH:i:s.vP'), [])->json('changes'))->toBe([]);
});

test('the same room made on two phones becomes one, and its chores follow', function () {
    $kitchen = Room::factory()->for($this->home)->create(['name' => 'Kitchen']);

    $reply = sync(null, [
        'rooms' => [['id' => 'phoneroom1', 'name' => 'kitchen', 'emoji' => '🍳', 'updated_at' => '2026-10-03T08:00:00Z']],
        'chores' => [['id' => 'phonechore1', 'room_id' => 'phoneroom1', 'name' => 'Mop floor', 'every_days' => 7, 'minutes' => 15, 'updated_at' => '2026-10-03T08:00:00Z']],
    ])->assertOk()->assertJsonPath('rejected', []);

    expect($reply->json('remapped'))->toBe(['rooms' => ['phoneroom1' => $kitchen->id]])
        ->and(Room::count())->toBe(1)
        ->and(Chore::sole()->room_id)->toBe($kitchen->id);
});

test('personal logs are kept per person', function () {
    FoodEntry::factory()->for($this->home)->create(['user_id' => $this->partner->id]);

    sync(null, ['food_entries' => [[
        'id' => 'myfood1', 'date' => '2026-10-03', 'eaten_at' => '2026-10-03T13:00:00+03:00', 'name' => 'Salad',
        'meal' => 'lunch', 'kcal' => 400, 'updated_at' => '2026-10-03T10:01:00Z',
    ]]])->assertJsonMissingPath('changes.food_entries');

    expect(FoodEntry::find('myfood1')->user_id)->toBe($this->me->id)
        ->and(FoodEntry::find('myfood1')->eaten_at->toIso8601String())->toBe('2026-10-03T10:00:00+00:00');
});

test('a new night of sleep replaces the old row for that date', function () {
    Carbon::setTestNow('2026-10-03 07:00:00');
    $old = SleepEntry::factory()->for($this->home)->create(['user_id' => $this->me->id, 'date' => '2026-10-03']);
    Carbon::setTestNow('2026-10-03 12:00:00');

    sync(null, ['sleep_entries' => [
        ['id' => 'newnight', 'date' => '2026-10-03', 'bed_at' => '2026-10-02T23:00:00Z', 'woke_at' => '2026-10-03T07:00:00Z', 'quality' => 4, 'updated_at' => '2026-10-03T08:00:00Z'],
        ['id' => $old->id, 'updated_at' => '2026-10-03T08:00:00Z', 'deleted_at' => '2026-10-03T08:00:00Z'],
    ]])->assertJsonPath('rejected', []);

    expect(SleepEntry::sole()->id)->toBe('newnight')
        ->and((float) SleepEntry::sole()->hours)->toBe(8.0);
});

test('another household\'s row is never touched, and a bad row does not stop the rest', function () {
    $theirs = Expense::factory()->create(['amount' => 10]);

    $reply = sync(null, ['expenses' => [
        ['id' => $theirs->id, 'date' => '2026-10-01', 'amount' => 999, 'category' => 'fun', 'updated_at' => '2026-10-03T11:00:00Z'],
        ['id' => 'bad1', 'date' => 'yesterday', 'amount' => -5, 'category' => 'fun', 'updated_at' => '2026-10-03T11:00:00Z'],
        ['id' => 'good1', 'date' => '2026-10-02', 'amount' => 12.5, 'category' => 'fun', 'updated_at' => '2026-10-03T11:00:00Z'],
    ]])->assertOk();

    expect(collect($reply->json('rejected'))->map(fn ($r) => [$r['id'], $r['reason']])->all())
        ->toBe([[$theirs->id, 'forbidden'], ['bad1', 'invalid']])
        ->and($reply->json('rejected.1.errors'))->toHaveKeys(['date', 'amount'])
        ->and((float) $theirs->fresh()->amount)->toBe(10.0)
        ->and(Expense::find('good1')->user_id)->toBe($this->me->id);
});

test('a clock in the future cannot win forever', function () {
    sync(null, ['inventory_items' => [milk(['level' => 'low', 'updated_at' => '2030-01-01T00:00:00Z'])]]);

    expect(InventoryItem::sole()->updated_at->toDateTimeString())->toBe('2026-10-03 12:00:00');

    Carbon::setTestNow('2026-10-03 12:30:00');
    sync(null, ['inventory_items' => [milk(['level' => 'empty', 'updated_at' => '2026-10-03T12:29:00Z'])]]);
    expect(InventoryItem::sole()->level->value)->toBe('empty');
});

test('purchase days follow the phone\'s list', function () {
    sync(null, ['inventory_items' => [milk(['purchases' => ['2026-09-20', '2026-09-26', '2026-09-26']])]]);
    sync(null, ['inventory_items' => [milk(['purchases' => ['2026-09-26', '2026-10-02'], 'updated_at' => '2026-10-03T11:00:00Z'])]]);

    Sanctum::actingAs($this->partner);
    expect(sync(null, [])->json('changes.inventory_items.0.purchases'))->toBe(['2026-09-26', '2026-10-02']);
});

test('rejects unknown collections and needs membership', function () {
    sync(null, ['pets' => []])->assertUnprocessable();

    Sanctum::actingAs(User::factory()->create());
    sync(null, [])->assertForbidden();
});

test('ids are checked', function () {
    sync(null, ['rooms' => [['id' => Str::repeat('x', 40), 'name' => 'Hall', 'updated_at' => '2026-10-03T08:00:00Z']]])
        ->assertJsonPath('rejected.0.reason', 'invalid');
});

test('this month\'s rent added on both phones is one expense', function () {
    $bill = ['id' => 'billA', 'name' => 'Rent', 'amount' => 620, 'category' => 'bills', 'day_of_month' => 1, 'updated_at' => '2026-10-01T08:00:00Z'];
    $rent = fn (string $id, string $billId) => ['id' => $id, 'date' => '2026-10-01', 'amount' => 620, 'category' => 'bills', 'source' => 'recurring', 'recurring_bill_id' => $billId, 'updated_at' => '2026-10-01T08:00:00Z'];
    sync(null, ['recurring_bills' => [$bill], 'expenses' => [$rent('rentA', 'billA')]]);

    // The partner's phone had its own Rent bill and added its own October rent
    Sanctum::actingAs($this->partner);
    $reply = sync(null, [
        'recurring_bills' => [[...$bill, 'id' => 'billB']],
        'expenses' => [$rent('rentB', 'billB'), ['id' => 'lunch', 'date' => '2026-10-01', 'amount' => 9, 'category' => 'eating_out', 'updated_at' => '2026-10-01T13:00:00Z']],
    ])->assertJsonPath('rejected', []);

    expect($reply->json('remapped'))->toBe(['recurring_bills' => ['billB' => 'billA'], 'expenses' => ['rentB' => 'rentA']])
        ->and(Expense::where('source', 'recurring')->count())->toBe(1)
        ->and(Expense::count())->toBe(2);
});

test('steps sync per day with the id every phone of that person makes', function () {
    $id = StepCount::idFor($this->me->id, '2026-10-02');
    $day = ['id' => $id, 'date' => '2026-10-02', 'steps' => 8123, 'updated_at' => '2026-10-03T06:00:00Z'];

    sync(null, ['step_counts' => [$day]])->assertJsonPath('rejected', []);
    // My second phone counted more that day
    sync(null, ['step_counts' => [[...$day, 'steps' => 9010, 'updated_at' => '2026-10-03T07:00:00Z']]])->assertJsonPath('rejected', []);

    expect(StepCount::sole()->only('id', 'user_id', 'steps'))->toBe(['id' => $id, 'user_id' => $this->me->id, 'steps' => 9010]);

    // Made on the server (seeder, API): the same id the phone would make
    $partners = StepCount::factory()->for($this->home)->for($this->partner)->create(['date' => '2026-10-02']);
    expect($partners->id)->toBe(StepCount::idFor($this->partner->id, '2026-10-02'))
        ->and($partners->id)->not->toBe($id);
});
