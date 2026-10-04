<?php

use App\Models\Device;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->token = $this->user->createToken('phone')->plainTextToken;
});

test('a phone registers its push token once', function () {
    $body = ['token' => 'ExponentPushToken[abc123]', 'platform' => 'android', 'name' => 'Pixel 9'];

    $this->withToken($this->token)->postJson('/api/devices', $body)->assertCreated()->assertJsonPath('data.name', 'Pixel 9');
    $this->withToken($this->token)->postJson('/api/devices', $body)->assertOk();

    expect(Device::sole()->only('user_id', 'expo_token', 'platform'))
        ->toBe(['user_id' => $this->user->id, 'expo_token' => 'ExponentPushToken[abc123]', 'platform' => 'android']);
});

test('only Expo push tokens are accepted', function (array $body) {
    $this->withToken($this->token)->postJson('/api/devices', $body)->assertUnprocessable();
})->with([
    'missing' => [[]],
    'not expo' => [['token' => 'fcm:abc']],
    'bad platform' => [['token' => 'ExpoPushToken[abc]', 'platform' => 'windows']],
]);

test('a phone that logs in as someone else moves to them', function () {
    $this->withToken($this->token)->postJson('/api/devices', ['token' => 'ExponentPushToken[shared]'])->assertCreated();

    $other = User::factory()->create();
    $this->app['auth']->forgetGuards();
    $this->withToken($other->createToken('phone')->plainTextToken)->postJson('/api/devices', ['token' => 'ExponentPushToken[shared]'])->assertOk();

    expect(Device::sole()->user_id)->toBe($other->id);
});

test('logging out stops the pushes to that phone', function () {
    $this->withToken($this->token)->postJson('/api/devices', ['token' => 'ExponentPushToken[abc]'])->assertCreated();

    $this->withToken($this->token)->postJson('/api/logout')->assertNoContent();

    expect(Device::count())->toBe(0);
});

test('pushes can be turned off', function () {
    $this->withToken($this->token)->postJson('/api/devices', ['token' => 'ExponentPushToken[abc]'])->assertCreated();

    $this->withToken($this->token)->deleteJson('/api/devices', ['token' => 'ExponentPushToken[abc]'])->assertNoContent();

    expect(Device::count())->toBe(0);
});

test('needs a login', function () {
    $this->postJson('/api/devices', ['token' => 'ExponentPushToken[abc]'])->assertUnauthorized();
});
