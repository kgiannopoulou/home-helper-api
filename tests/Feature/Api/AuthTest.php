<?php

use App\Models\Household;
use App\Models\User;

test('logs in with email and password and gets a token', function () {
    $user = User::factory()->create(['email' => 'kon@example.com']);
    Household::factory()->create()->addMember($user);

    $token = $this->postJson('/api/login', [
        'email' => 'kon@example.com',
        'password' => 'password',
        'device_name' => 'Pixel 8',
    ])->assertOk()
        ->assertJsonPath('user.email', 'kon@example.com')
        ->json('token');

    expect($user->tokens()->sole()->name)->toBe('Pixel 8');

    $this->withToken($token)->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonCount(1, 'data.households');
});

test('a wrong password is a validation error', function () {
    User::factory()->create(['email' => 'kon@example.com']);

    $this->postJson('/api/login', ['email' => 'kon@example.com', 'password' => 'nope'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
});

test('login is rate limited', function () {
    User::factory()->create(['email' => 'kon@example.com']);

    foreach (range(1, 6) as $i) {
        $this->postJson('/api/login', ['email' => 'kon@example.com', 'password' => 'nope'])->assertUnprocessable();
    }
    $this->postJson('/api/login', ['email' => 'kon@example.com', 'password' => 'nope'])->assertTooManyRequests();
});

test('logout revokes only the token in use', function () {
    $user = User::factory()->create();
    $phone = $user->createToken('phone')->plainTextToken;
    $tablet = $user->createToken('tablet')->plainTextToken;

    $this->withToken($phone)->postJson('/api/logout')->assertNoContent();
    $this->app['auth']->forgetGuards();

    $this->withToken($phone)->getJson('/api/me')->assertUnauthorized();
    $this->app['auth']->forgetGuards();
    $this->withToken($tablet)->getJson('/api/me')->assertOk();
});

test('the API needs a token', function () {
    $home = Household::factory()->create();

    $this->getJson('/api/me')->assertUnauthorized();
    $this->getJson("/api/households/{$home->id}/expenses")->assertUnauthorized();
});
