<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->home = Household::factory()->create();
    $this->home->addMember($this->user, HouseholdRole::Owner);
});

test('signed-in requests are limited per user, with Retry-After', function () {
    Sanctum::actingAs($this->user);

    foreach (range(1, 120) as $i) {
        $this->getJson('/api/me')->assertOk();
    }
    $this->getJson('/api/me')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'too_many_requests');

    // Someone else on the same address has their own budget
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/me')->assertOk();
});

test('sync is limited to 60 a minute', function () {
    Sanctum::actingAs($this->user);
    $url = "/api/households/{$this->home->id}/sync";

    foreach (range(1, 60) as $i) {
        $this->postJson($url, ['since' => null, 'changes' => []])->assertOk();
    }
    $this->postJson($url, ['since' => null, 'changes' => []])->assertTooManyRequests();
});

test('invites are limited, since each one sends an email', function () {
    Notification::fake();
    Sanctum::actingAs($this->user);
    $url = "/api/households/{$this->home->id}/invites";

    foreach (range(1, 20) as $i) {
        $this->postJson($url, ['email' => "friend$i@example.com"])->assertCreated();
    }
    $this->postJson($url, ['email' => 'one-more@example.com'])->assertTooManyRequests();
});

test('login is limited per account, and per address across accounts', function () {
    // Per account: covered by AuthTest ("login is rate limited"). Per address:
    foreach (range(1, 30) as $i) {
        $this->postJson('/api/login', ['email' => "guess$i@example.com", 'password' => 'nope'])->assertUnprocessable();
    }
    $this->postJson('/api/login', ['email' => 'guess31@example.com', 'password' => 'nope'])->assertTooManyRequests();
});
