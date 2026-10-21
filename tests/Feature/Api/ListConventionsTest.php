<?php

use App\Models\Expense;
use App\Models\Household;
use App\Models\Room;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/*
 * The list parameters every module shares (docs/api.md#lists):
 * page, per_page, from/to (dated modules), updated_since and sort.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->home = Household::factory()->create();
    $this->home->addMember($this->user);
    Sanctum::actingAs($this->user);
    $this->url = "/api/households/{$this->home->id}/expenses";
});

test('sorts by an allowed column, either way', function () {
    foreach ([30, 5, 12] as $amount) {
        Expense::factory()->for($this->home)->create(['amount' => $amount]);
    }

    $amounts = fn (string $sort) => collect($this->getJson("{$this->url}?sort=$sort")->assertOk()->json('data'))
        ->pluck('amount')->map(fn ($a) => (float) $a)->all();

    expect($amounts('amount'))->toBe([5.0, 12.0, 30.0])
        ->and($amounts('-amount'))->toBe([30.0, 12.0, 5.0]);
});

test('refuses a column that is not sortable', function () {
    $this->getJson("{$this->url}?sort=note")->assertJsonValidationErrors('sort');
    $this->getJson("{$this->url}?sort=amount;drop table users")->assertJsonValidationErrors('sort');
});

test('undated modules sort by their own column by default', function () {
    foreach (['Kitchen', 'Bathroom', 'Living room'] as $name) {
        Room::factory()->for($this->home)->create(['name' => $name]);
    }

    $this->getJson("/api/households/{$this->home->id}/rooms")
        ->assertJsonPath('data.0.name', 'Bathroom')
        ->assertJsonPath('data.2.name', 'Living room');

    $this->getJson("/api/households/{$this->home->id}/rooms?sort=-name")
        ->assertJsonPath('data.0.name', 'Living room');
});

test('updated_since returns only rows changed since then', function () {
    Carbon::setTestNow('2026-10-01 10:00:00');
    Expense::factory()->for($this->home)->create(['note' => 'old']);
    Carbon::setTestNow('2026-10-02 10:00:00');
    Expense::factory()->for($this->home)->create(['note' => 'new']);
    Carbon::setTestNow();

    $this->getJson("{$this->url}?updated_since=2026-10-02T00:00:00%2B00:00")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.note', 'new');

    $this->getJson("{$this->url}?updated_since=yesterday-ish")->assertJsonValidationErrors('updated_since');
});

test('pages stay stable when many rows share a sort value', function () {
    Expense::factory()->for($this->home)->count(7)->create(['date' => '2026-09-01']);

    $ids = collect(range(1, 4))
        ->flatMap(fn ($page) => $this->getJson("{$this->url}?per_page=2&page=$page")->json('data.*.id'))
        ->all();

    expect($ids)->toHaveCount(7)->and(array_unique($ids))->toHaveCount(7);
});

test('the envelope has data, links and meta', function () {
    Expense::factory()->for($this->home)->count(3)->create();

    $this->getJson("{$this->url}?per_page=2")
        ->assertJsonStructure([
            'data',
            'links' => ['first', 'last', 'prev', 'next'],
            'meta' => ['current_page', 'last_page', 'per_page', 'total', 'from', 'to'],
        ]);
});
