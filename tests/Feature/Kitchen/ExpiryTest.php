<?php

use App\Enums\HouseholdRole;
use App\Enums\ItemCategory;
use App\Enums\StockLevel;
use App\Enums\StorageLocation;
use App\Jobs\CheckExpiringFood;
use App\Kitchen\Expiry;
use App\Kitchen\Pantry;
use App\Models\Device;
use App\Models\Household;
use App\Models\InventoryItem;
use App\Models\User;
use App\Queries\KitchenWasteQuery;
use App\Web\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Page;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00', 'Europe/Athens'));
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 't']]])]);
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    Device::forceCreate(['user_id' => $this->owner->id, 'expo_token' => 'ExponentPushToken[owner]']);
    $this->pantry = app(Pantry::class);
    $this->today = CarbonImmutable::parse('2026-11-13', 'Europe/Athens');
});

function food(Household $home, string $name, StorageLocation $where = StorageLocation::Fridge, array $more = []): InventoryItem
{
    return $home->inventoryItems()->create($more + ['name' => $name, 'category' => ItemCategory::Food, 'location' => $where]);
}

function expiryPushes(): array
{
    return collect(Http::recorded())->filter(fn ($p) => str_contains($p[0]->url(), 'exp.host'))
        ->flatMap(fn ($p) => $p[0]->data())->map(fn ($m) => $m['title'].' | '.$m['body'])->values()->all();
}

describe('dates', function () {
    it('estimates a date when food comes in, and says it is an estimate', function () {
        $milk = food($this->home, 'Milk');
        $this->pantry->add($milk, 1, 'l', null);
        $rice = food($this->home, 'Rice', StorageLocation::Pantry);
        $this->pantry->add($rice, 1, 'kg', null);

        expect($milk->fresh())->expires_on->toDateString()->toBe('2026-11-20')->expiry_estimated->toBeTrue()
            ->and($rice->fresh()->expires_on->toDateString())->toBe('2028-11-12');
    });

    it('keeps a typed date for the older pack, and starts again once it was empty', function () {
        $feta = food($this->home, 'Feta', more: ['amount' => 200, 'unit' => 'g']);
        app(Expiry::class)->set($feta, $this->today->addDays(5));
        $this->pantry->add($feta, 400, 'g', null);
        expect($feta->fresh())->expires_on->toDateString()->toBe('2026-11-18')->expiry_estimated->toBeFalse();

        $this->pantry->take($feta, 'used', null, null, null);
        $this->pantry->add($feta, 400, 'g', null);
        expect($feta->fresh())->expires_on->toDateString()->toBe('2027-01-12')->expiry_estimated->toBeTrue();
    });

    it('brings the date closer when opened, never further', function () {
        $yogurt = food($this->home, 'Greek yoghurt');
        $this->pantry->add($yogurt, 1, 'kg', null);
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/households/{$this->home->id}/inventory-items/{$yogurt->id}/opened")->assertOk()
            ->assertJsonPath('item.expires_on', '2026-11-18')->assertJsonPath('item.opened_on', '2026-11-13');

        $eggs = food($this->home, 'Eggs', more: ['amount' => 6, 'unit' => 'pcs']);
        app(Expiry::class)->set($eggs, $this->today->addDays(3));
        $this->postJson("/api/households/{$this->home->id}/inventory-items/{$eggs->id}/opened")->assertOk()->assertJsonPath('item.expires_on', '2026-11-16');
    });

    it('keeps months in the freezer and two days once thawed', function () {
        $chicken = food($this->home, 'Chicken breast');
        $this->pantry->add($chicken, 500, 'g', null);
        expect($chicken->fresh()->expires_on->toDateString())->toBe('2026-11-15');

        $chicken->update(['location' => StorageLocation::Freezer]);
        expect($chicken->fresh()->expires_on->toDateString())->toBe('2027-08-10');

        $this->travel(30)->days();
        $chicken->update(['location' => StorageLocation::Fridge]);
        expect($chicken->fresh()->expires_on->toDateString())->toBe('2026-12-15');
    });

    it('tells expired from today, soon and later, and ignores what is used up', function () {
        $item = food($this->home, 'Milk', more: ['amount' => 500, 'unit' => 'ml']);
        $state = fn (int $days) => Expiry::status($item->forceFill(['expires_on' => $this->today->addDays($days)]), $this->today)['state'];

        expect([$state(-1), $state(0), $state(2), $state(5), $state(20)])->toBe(['expired', 'today', 'soon', 'week', 'ok'])
            ->and(Expiry::status($item->forceFill(['amount' => 0, 'level' => StockLevel::Empty]), $this->today)['state'])->toBe('none');
    });
});

describe('the morning push', function () {
    it('names what goes off today or tomorrow, once per date', function () {
        food($this->home, 'Milk', more: ['amount' => 500, 'unit' => 'ml', 'expires_on' => '2026-11-14']);
        food($this->home, 'Greek yoghurt', more: ['amount' => 200, 'unit' => 'g', 'expires_on' => '2026-11-13']);
        food($this->home, 'Feta', more: ['amount' => 400, 'unit' => 'g', 'expires_on' => '2026-11-20']);
        food($this->home, 'Spinach', more: ['amount' => 0, 'unit' => 'g', 'expires_on' => '2026-11-13']);

        (new CheckExpiringFood($this->home, '2026-11-13'))->handle();
        expect(expiryPushes())->toBe(['🥛 Use soon | Greek yoghurt (today) and Milk (tomorrow).']);

        // The next morning: the yoghurt is a day past, the milk is due today, both already mentioned
        (new CheckExpiringFood($this->home, '2026-11-14'))->handle();
        expect(expiryPushes())->toHaveCount(1);
    });

    it('asks again about a new date', function () {
        $milk = food($this->home, 'Milk', more: ['amount' => 500, 'unit' => 'ml', 'expires_on' => '2026-11-13']);
        (new CheckExpiringFood($this->home, '2026-11-13'))->handle();
        app(Expiry::class)->stillGood($milk->fresh(), $this->today->subDay());
        (new CheckExpiringFood($this->home, '2026-11-13'))->handle();

        expect(expiryPushes())->toHaveCount(2)->and(expiryPushes()[1])->toContain('Milk (tomorrow)');
    });

    it('stays quiet when nothing is due, and says gone off when all of it is', function () {
        (new CheckExpiringFood($this->home, '2026-11-13'))->handle();
        expect(expiryPushes())->toBe([]);

        food($this->home, 'Cream', more: ['amount' => 200, 'unit' => 'ml', 'expires_on' => '2026-11-11']);
        (new CheckExpiringFood($this->home, '2026-11-13'))->handle();
        expect(expiryPushes())->toBe(['🗑️ Gone off in the kitchen | Cream (2 days ago).']);
    });
});

describe('waste', function () {
    it('adds up what was thrown away, priced from the last pack', function () {
        $feta = food($this->home, 'Feta');
        $trip = $this->home->shoppingTrips()->create(['date' => '2026-11-01', 'total' => 6, 'source' => 'manual']);
        $this->home->purchases()->create(['inventory_item_id' => $feta->id, 'shopping_trip_id' => $trip->id, 'bought_on' => '2026-10-01', 'price' => 5.00]);
        $this->home->purchases()->create(['inventory_item_id' => $feta->id, 'shopping_trip_id' => $trip->id, 'bought_on' => '2026-11-01', 'price' => 6.00]);
        $this->pantry->add($feta, 400, 'g', null);
        $this->pantry->take($feta, 'wasted', 100, 'g', null);
        $this->pantry->take($feta, 'expired', 100, 'g', null);
        $this->pantry->take($feta, 'used', 100, 'g', null);

        $lettuce = food($this->home, 'Lettuce', more: ['amount' => 1, 'unit' => 'pcs']);
        $this->pantry->take($lettuce, 'expired', null, null, null);

        $w = (new KitchenWasteQuery($this->home, $this->today, 30))->get();
        expect($w['items'])->toHaveCount(2)
            ->and($w['items'][0])->toMatchArray(['name' => 'Feta', 'amount' => 200.0, 'unit' => 'g', 'times' => 2, 'value' => 3.0])
            ->and($w['items'][1])->toMatchArray(['name' => 'Lettuce', 'value' => null])
            ->and($w['value'])->toBe(3.0)->and($w['priced'])->toBe(1);
    });

    it('counts only the period asked for', function () {
        $milk = food($this->home, 'Milk', more: ['amount' => 1000, 'unit' => 'ml']);
        $this->pantry->take($milk, 'wasted', 200, 'ml', null, at: $this->today->subDays(40));
        expect((new KitchenWasteQuery($this->home, $this->today, 30))->get()['items'])->toBe([]);
    });
});

describe('the API and the page', function () {
    it('lists what is expiring, takes printed dates and "still good", and reports waste', function () {
        Sanctum::actingAs($this->owner);
        $milk = food($this->home, 'Milk', more: ['amount' => 500, 'unit' => 'ml', 'expires_on' => '2026-11-14']);

        $this->getJson("/api/households/{$this->home->id}/kitchen/expiring?days=3")->assertOk()
            ->assertJsonPath('data.0.name', 'Milk')->assertJsonPath('data.0.expiry.state', 'soon')->assertJsonPath('data.0.expiry.days', 1);
        $this->postJson("/api/households/{$this->home->id}/inventory-items/{$milk->id}/expiry", ['expires_on' => '2026-11-19'])
            ->assertOk()->assertJsonPath('item.expires_on', '2026-11-19')->assertJsonPath('item.expiry_estimated', false);
        $this->postJson("/api/households/{$this->home->id}/inventory-items/{$milk->id}/still-good")
            ->assertOk()->assertJsonPath('item.expires_on', '2026-11-15');
        $this->getJson("/api/households/{$this->home->id}/kitchen/waste?days=30")->assertOk()->assertJsonPath('value', 0);
    });

    it('shows use-soon and waste on the Kitchen page', function () {
        food($this->home, 'Milk', more: ['amount' => 500, 'unit' => 'ml', 'expires_on' => '2026-11-12']);
        $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);

        $this->get('/kitchen')->assertInertia(fn (Page $p) => $p->where('expiring.0.name', 'Milk')->where('expiring.0.expiry.state', 'expired')->has('waste.items'));
        $id = InventoryItem::sole()->id;
        $this->post("/kitchen/items/{$id}/still-good")->assertRedirect('/kitchen');
        $this->post("/kitchen/items/{$id}/opened")->assertRedirect('/kitchen');
        $this->post("/kitchen/items/{$id}/expiry", ['expires_on' => '2026-11-30'])->assertRedirect('/kitchen');
        expect(InventoryItem::sole())->expires_on->toDateString()->toBe('2026-11-30')->opened_on->toDateString()->toBe('2026-11-13');
    });
});
