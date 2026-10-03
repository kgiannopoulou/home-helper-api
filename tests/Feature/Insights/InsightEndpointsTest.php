<?php

use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

test('every insight endpoint answers for the demo household', function () {
    Carbon::setTestNow('2026-10-03 12:00');
    $this->seed();
    $home = Household::sole();
    Sanctum::actingAs($home->users()->firstOrFail());
    $url = "/api/households/{$home->id}/insights";

    $this->getJson("$url/weekly-spending?weeks=4")->assertOk()->assertJsonPath('data.0.week_start', '2026-09-07');

    // All 12 staples have been bought often enough to predict
    $this->getJson("$url/run-out")->assertOk()->assertJsonCount(12, 'data');

    // The oven is set to every 2 weeks and done about monthly
    $this->getJson("$url/slipping-chores")
        ->assertOk()
        ->assertJsonFragment(['name' => 'Clean the oven', 'trend' => 'late', 'suggested_every_days' => 30]);

    $this->getJson("$url/shopping-day")
        ->assertOk()
        ->assertJsonPath('data.usual.name', 'Saturday')
        ->assertJsonPath('data.usual.next_on', '2026-10-03');

    $this->getJson("$url/budget-forecast")
        ->assertOk()
        ->assertJsonPath('data.budget', 1650)
        ->assertJsonPath('data.day', 3);
});

test('validates the number of weeks', function () {
    $user = User::factory()->create();
    $home = Household::factory()->create();
    $home->addMember($user);
    Sanctum::actingAs($user);

    $this->getJson("/api/households/{$home->id}/insights/weekly-spending?weeks=60")->assertJsonValidationErrors('weeks');
});

test('another household\'s insights are refused', function (string $insight) {
    Sanctum::actingAs(User::factory()->create());
    $home = Household::factory()->create();

    $this->getJson("/api/households/{$home->id}/insights/$insight")->assertForbidden();
})->with(['weekly-spending', 'run-out', 'slipping-chores', 'shopping-day', 'budget-forecast']);
