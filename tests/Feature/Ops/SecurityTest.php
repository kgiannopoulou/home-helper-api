<?php

use App\Models\User;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

/*
 * Security headers, CORS and token expiry (docs/security.md).
 */

test('API responses forbid scripts, framing and caching', function () {
    $this->getJson('/api/me')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'")
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cache-Control', 'no-store, private');
});

test('web pages can only be framed by the site itself, and get no API CSP', function () {
    $response = $this->get('/');

    $response->assertHeader('X-Frame-Options', 'SAMEORIGIN')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->has('Content-Security-Policy'))->toBeFalse();
});

test('HSTS only over HTTPS in production', function () {
    expect($this->get('https://localhost/')->headers->has('Strict-Transport-Security'))->toBeFalse();

    app()->detectEnvironment(fn () => 'production');
    $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    expect($this->get('http://localhost/')->headers->has('Strict-Transport-Security'))->toBeFalse();
});

test('CORS: a listed origin is allowed and sees X-Request-Id, an unlisted one is never echoed back', function () {
    config(['cors.allowed_origins' => ['https://home.example.com']]);

    $this->withHeaders(['Origin' => 'https://home.example.com'])->getJson('/api/health')
        ->assertHeader('Access-Control-Allow-Origin', 'https://home.example.com')
        ->assertHeader('Access-Control-Expose-Headers', 'X-Request-Id, Retry-After');

    $response = $this->withHeaders(['Origin' => 'https://evil.example'])->getJson('/api/health');
    // (with one origin listed, the header always names it, so the browser blocks the other site)
    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example')->not->toBe('*');
});

test('tokens expire after SANCTUM_TOKEN_EXPIRATION minutes', function () {
    $user = User::factory()->create(['password' => Hash::make('pw-123456')]);
    $token = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'pw-123456'])->json('token');

    $this->withToken($token)->getJson('/api/me')->assertOk();

    $this->travel(config('sanctum.expiration') + 1)->minutes();
    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
});

test('the default token lifetime is 90 days', function () {
    expect(config('sanctum.expiration'))->toBe(60 * 24 * 90);
});

test('login gives the same answer for a wrong password and an unknown email', function () {
    $user = User::factory()->create(['password' => Hash::make('pw-123456')]);

    $wrong = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'nope'])->assertUnprocessable()->json();
    $unknown = $this->postJson('/api/login', ['email' => 'nobody@example.com', 'password' => 'nope'])->assertUnprocessable()->json();

    expect($unknown['errors'])->toBe($wrong['errors']);
});

test('a token\'s user id lands in the log context', function () {
    Sanctum::actingAs($user = User::factory()->create());
    $this->getJson('/api/me')->assertOk();

    // Sanctum::actingAs skips the guard, so check the real token path too
    $token = $user->createToken('phone')->plainTextToken;
    app('auth')->forgetGuards();
    Context::flush();
    $this->withToken($token)->getJson('/api/me')->assertOk();

    expect(Context::get('user_id'))->toBe($user->id);
});
