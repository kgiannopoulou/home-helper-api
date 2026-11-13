<?php

use App\Enums\HouseholdRole;
use App\Models\Device;
use App\Models\Household;
use App\Models\Room;
use App\Models\SmartDevice;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEvent;
use App\Models\User;
use App\SmartHome\Connections;
use App\SmartHome\Discovery;
use App\SmartHome\HomeEvents;
use App\SmartHome\Notifications;
use App\Web\CurrentHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\FakeHomeAssistant;

beforeEach(function () {
    $this->ha = FakeHomeAssistant::install();
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 't1']]])]);
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    Device::forceCreate(['user_id' => $this->owner->id, 'expo_token' => 'ExponentPushToken[owner-phone]', 'platform' => 'android']);
    Room::factory()->for($this->home)->create(['name' => 'Bathroom']);
    [$this->connection, $secret] = app(Connections::class)->connect($this->home, $this->owner, FakeHomeAssistant::URL, FakeHomeAssistant::TOKEN);
    $this->bearer = SmartHomeConnection::webhookToken($this->connection->id, $secret);
    app(Discovery::class)->sync($this->connection);
    SmartDevice::query()->whereNot('kind', 'sensor')->update(['imported' => true]);
    $this->seq = 0;
    // Daytime at home, unless a test says otherwise
    $this->travelTo(CarbonImmutable::parse('2026-11-13 14:00', 'Europe/Athens'));
});

function pushed(object $test, string $entityId, string $state): void
{
    $test->seq++;
    $test->withToken($test->bearer)->postJson('/api/smart-home/v1/states', [
        'entity_id' => $entityId, 'state' => $state, 'last_changed' => now()->toIso8601ZuluString('millisecond'),
    ])->assertAccepted();
    $test->travel(1)->seconds();
}

/** @return list<array{title: string, body: string}> */
function phonePushes(): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_contains($pair[0]->url(), 'exp.host'))
        ->flatMap(fn ($pair) => collect($pair[0]->data())->map(fn ($m) => ['title' => $m['title'], 'body' => $m['body']]))
        ->values()->all();
}

it('pushes a door opening at night, not in the afternoon', function () {
    pushed($this, 'binary_sensor.front_door', 'on');
    pushed($this, 'binary_sensor.front_door', 'off');
    expect(phonePushes())->toBe([]);

    $this->travelTo(CarbonImmutable::parse('2026-11-14 02:30', 'Europe/Athens'));
    pushed($this, 'binary_sensor.front_door', 'on');
    expect(phonePushes())->toBe([['title' => '🚪 Home', 'body' => 'Front door opened']])
        ->and(SmartHomeEvent::where('type', 'door.opened')->latest('occurred_at')->first()->notified_at)->not->toBeNull();
});

it('sends the same thing at most every 15 minutes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 02:30', 'Europe/Athens'));
    foreach (range(1, 3) as $i) {
        pushed($this, 'binary_sensor.front_door', 'on');
        pushed($this, 'binary_sensor.front_door', 'off');
    }
    expect(phonePushes())->toHaveCount(1);

    $this->travel(16)->minutes();
    pushed($this, 'binary_sensor.front_door', 'on');
    expect(phonePushes())->toHaveCount(2);
});

it('warns when a device stops answering, and notes when it is back', function () {
    pushed($this, 'light.living_room_lamp', 'unavailable');
    pushed($this, 'light.living_room_lamp', 'off');

    expect(SmartHomeEvent::orderBy('occurred_at')->pluck('type')->all())->toBe(['device.unavailable', 'device.back'])
        ->and(phonePushes())->toBe([['title' => '🏠 Home', 'body' => 'Living room lamp stopped answering']]);
});

it('alerts once when a room crosses into very humid, and again only after it dried out', function () {
    foreach (['66', '71', '74', '72', '71'] as $v) {
        pushed($this, 'sensor.bathroom_humidity', $v);
    }
    expect(SmartHomeEvent::where('type', 'humidity.high')->count())->toBe(1)
        ->and(SmartHomeEvent::where('type', 'humidity.high')->value('message'))->toBe('Bathroom: humidity 71 %. Air it or run the dehumidifier.');

    // Wobbling just under the line doesn't re-arm it; drying out properly does
    $this->travel(20)->minutes();
    pushed($this, 'sensor.bathroom_humidity', '69');
    pushed($this, 'sensor.bathroom_humidity', '72');
    expect(SmartHomeEvent::where('type', 'humidity.high')->count())->toBe(1);

    $this->travel(2)->minutes();
    pushed($this, 'sensor.bathroom_humidity', '60');
    $this->travel(2)->minutes();
    pushed($this, 'sensor.bathroom_humidity', '73');
    expect(SmartHomeEvent::where('type', 'humidity.high')->count())->toBe(2);
});

it('alerts when a room gets cold', function () {
    pushed($this, 'sensor.bedroom_temperature', '17.4');
    expect(SmartHomeEvent::where('type', 'temperature.low')->value('message'))->toBe('Bedroom is down to 17.4 °C');
});

it('records Home Assistant going offline and coming back, and pushes the first', function () {
    $this->ha->failure = 'unreachable';
    try {
        app(Discovery::class)->sync($this->connection);
    } catch (Throwable) {
    }
    $this->ha->failure = null;
    app(Discovery::class)->sync($this->connection->fresh());

    expect(SmartHomeEvent::orderBy('occurred_at')->pluck('type')->all())->toBe(['hub.offline', 'hub.online'])
        ->and(phonePushes()[0]['body'])->toContain('not answering');
});

it('respects the owner\'s settings', function () {
    $this->connection->forceFill(['notify' => ['device.unavailable' => 'never', 'door.opened' => 'always']])->save();
    pushed($this, 'light.living_room_lamp', 'unavailable');
    pushed($this, 'binary_sensor.front_door', 'on');

    expect(phonePushes())->toBe([['title' => '🚪 Home', 'body' => 'Front door opened']]);
});

it('keeps the event when the push service is down', function () {
    Http::fake(['exp.host/*' => fn (Request $r) => throw new RuntimeException('down')]);
    $this->connection->forceFill(['notify' => ['door.opened' => 'always']])->save();

    pushed($this, 'binary_sensor.front_door', 'on');
    expect(SmartHomeEvent::where('type', 'door.opened')->sole()->notified_at)->toBeNull();
});

it('knows when it is night, across midnight', function (string $time, bool $night) {
    expect(Notifications::isNight(CarbonImmutable::parse("2026-11-13 {$time}", 'Europe/Athens')))->toBe($night);
})->with([['22:59', false], ['23:00', true], ['02:00', true], ['05:59', true], ['06:00', false], ['14:00', false]]);

it('lets the owner change the settings on the page', function () {
    $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);
    $this->patch('/smart-home/notifications', ['notify' => ['motion.detected' => 'night', 'made.up' => 'always']])->assertRedirect();

    expect($this->connection->fresh()->notify)->toMatchArray(['motion.detected' => 'night', 'door.left_open' => 'always'])
        ->not->toHaveKey('made.up');
    $this->get('/smart-home')->assertInertia(fn (Page $p) => $p->where('notifications.settings', fn ($s) => $s['motion.detected'] === 'night'));

    $this->patch('/smart-home/notifications', ['notify' => ['motion.detected' => 'loudly']])->assertSessionHasErrors('notify.motion.detected');
});

it('records events in one place for everyone who listens', function () {
    $event = app(HomeEvents::class)->record($this->home->id, null, 'approval.requested', 'warning', 'Unlock Front door lock?');
    expect($event->household_id)->toBe($this->home->id)
        ->and(phonePushes())->toBe([['title' => '🔐 Approval needed', 'body' => 'Unlock Front door lock?']]);
});
