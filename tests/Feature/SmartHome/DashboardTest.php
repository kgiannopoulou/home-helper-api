<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Room;
use App\Models\SmartDevice;
use App\Models\User;
use App\SmartHome\Connections;
use App\SmartHome\Discovery;
use App\SmartHome\HomeSummary;
use App\Web\CurrentHousehold;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\FakeHomeAssistant;

beforeEach(function () {
    $this->ha = FakeHomeAssistant::install();
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    Room::factory()->for($this->home)->create(['name' => 'Bathroom']);
    [$this->connection] = app(Connections::class)->connect($this->home, $this->owner, FakeHomeAssistant::URL, FakeHomeAssistant::TOKEN);
    $this->ha->set('light.living_room_lamp', 'on', ['brightness' => 200]);
    $this->ha->set('binary_sensor.front_door', 'on');
    $this->ha->set('sensor.bathroom_humidity', '71');
    $this->ha->set('light.bedroom_lamp', 'unavailable');
    app(Discovery::class)->sync($this->connection);
    SmartDevice::query()->whereNot('kind', 'sensor')->update(['imported' => true]);
});

it('sums up the house from the stored states', function () {
    $summary = HomeSummary::for($this->home);

    expect($summary)->toMatchArray([
        'connected' => true,
        'lights_on' => 1,
        // three lights and the hallway light switch
        'lights' => 4,
        'open' => ['Front door'],
        'unlocked' => [],
        'temperature' => ['min' => 19.5, 'max' => 23.0],
        'humidity' => ['max' => 71.0, 'room' => 'Bathroom'],
        'unavailable' => ['Bedroom lamp'],
    ]);
});

it('only counts imported devices, and is null without Home Assistant', function () {
    SmartDevice::query()->where('kind', 'humidity')->update(['imported' => false]);
    expect(HomeSummary::for($this->home)['humidity'])->toBeNull()
        ->and(HomeSummary::for(Household::factory()->create()))->toBeNull();
});

it('puts the summary on the Smart home page and the main dashboard', function () {
    $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);

    $this->get('/smart-home')->assertInertia(fn (Page $p) => $p
        ->where('summary.lights_on', 1)
        ->where('summary.open', ['Front door'])
        ->has('devices', 17));
    $this->get('/dashboard')->assertInertia(fn (Page $p) => $p
        ->where('smartHome.humidity.room', 'Bathroom'));
});

it('does not call Home Assistant to show the page', function () {
    $this->ha->failure = 'unreachable';
    $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id])
        ->get('/smart-home')->assertOk()->assertInertia(fn (Page $p) => $p->has('devices', 17));
});
