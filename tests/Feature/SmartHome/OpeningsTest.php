<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\SmartDevice;
use App\Models\SmartHomeConnection;
use App\Models\SmartHomeEvent;
use App\Models\User;
use App\SmartHome\Connections;
use App\SmartHome\Discovery;
use App\SmartHome\Openings;
use App\Web\CurrentHousehold;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\FakeHomeAssistant;

beforeEach(function () {
    $this->ha = FakeHomeAssistant::install();
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    [$this->connection, $secret] = app(Connections::class)->connect($this->home, $this->owner, FakeHomeAssistant::URL, FakeHomeAssistant::TOKEN);
    $this->bearer = SmartHomeConnection::webhookToken($this->connection->id, $secret);
    app(Discovery::class)->sync($this->connection);
    SmartDevice::query()->whereIn('kind', ['door', 'window', 'motion'])->update(['imported' => true]);
    $this->seq = 0;
});

/** Home Assistant pushes a state change, a moment after the last one */
function pushState(object $test, string $entityId, string $state): void
{
    $test->seq++;
    $test->withToken($test->bearer)->postJson('/api/smart-home/v1/states', [
        'entity_id' => $entityId, 'state' => $state, 'last_changed' => now()->addMilliseconds($test->seq)->toIso8601ZuluString('millisecond'),
    ])->assertAccepted();
}

function eventTypes(): array
{
    return SmartHomeEvent::query()->orderBy('occurred_at')->orderBy('id')->pluck('type')->all();
}

it('turns a door opening and closing into events, with how long it was open', function () {
    pushState($this, 'binary_sensor.front_door', 'on');
    $this->travel(4)->minutes();
    pushState($this, 'binary_sensor.front_door', 'off');

    expect(eventTypes())->toBe(['door.opened', 'door.closed']);
    $closed = SmartHomeEvent::where('type', 'door.closed')->sole();
    expect($closed->message)->toBe('Front door closed after 4 min')
        ->and($closed->data['open_seconds'])->toBeGreaterThanOrEqual(240)
        ->and($closed->household_id)->toBe($this->home->id);
});

it('knows windows and motion', function () {
    pushState($this, 'binary_sensor.bedroom_window', 'on');
    pushState($this, 'binary_sensor.hallway_motion', 'on');
    pushState($this, 'binary_sensor.hallway_motion', 'off');

    expect(eventTypes())->toBe(['window.opened', 'motion.detected']);
});

it('does not call a flat battery an opening', function () {
    pushState($this, 'binary_sensor.front_door', 'unavailable');
    pushState($this, 'binary_sensor.front_door', 'on');

    // It stopped and started answering (App\SmartHome\Alerts), but nobody opened anything
    expect(eventTypes())->toBe(['device.unavailable', 'device.back']);
});

it('ignores devices that are not imported', function () {
    SmartDevice::query()->update(['imported' => false]);
    pushState($this, 'binary_sensor.front_door', 'on');
    expect(eventTypes())->toBe([]);
});

it('warns once about a door left open', function () {
    pushState($this, 'binary_sensor.front_door', 'on');
    $openings = app(Openings::class);

    $this->travel(9)->minutes();
    expect($openings->checkLeftOpen())->toBe(0);

    $this->travel(2)->minutes();
    expect($openings->checkLeftOpen())->toBe(1)
        ->and($openings->checkLeftOpen())->toBe(0);

    $warning = SmartHomeEvent::where('type', 'door.left_open')->sole();
    expect($warning)->severity->toBe('warning')->message->toBe('Front door has been open for 11 min');

    // Closed and opened again: a new opening, a new warning later
    pushState($this, 'binary_sensor.front_door', 'off');
    $this->travel(1)->minutes();
    pushState($this, 'binary_sensor.front_door', 'on');
    $this->travel(11)->minutes();
    expect($openings->checkLeftOpen())->toBe(1);
});

it('gives windows longer than doors', function () {
    pushState($this, 'binary_sensor.bedroom_window', 'on');
    $this->travel(20)->minutes();
    $this->artisan('smart-home:check-openings')->assertSuccessful();
    expect(SmartHomeEvent::where('type', 'window.left_open')->count())->toBe(0);

    $this->travel(11)->minutes();
    $this->artisan('smart-home:check-openings')->expectsOutputToContain('1 left open')->assertSuccessful();
});

it('formats durations', function (int $seconds, string $text) {
    expect(Openings::duration($seconds))->toBe($text);
})->with([[45, '45 s'], [240, '4 min'], [3600, '1 h'], [5400, '1 h 30 min']]);

it('shows doors and windows with today\'s openings on the page', function () {
    pushState($this, 'binary_sensor.front_door', 'on');
    pushState($this, 'binary_sensor.front_door', 'off');
    pushState($this, 'binary_sensor.front_door', 'on');

    $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id])
        ->get('/smart-home')->assertInertia(fn (Page $p) => $p
        ->has('openings', 3)
        ->where('openings.0.name', 'Front door')
        ->where('openings.0.open', true)
        ->where('openings.0.openings_today', 2)
        ->where('events.0.type', 'door.opened')
        ->has('events', 3));
});
