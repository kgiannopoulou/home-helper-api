<?php

use App\Models\Expense;
use App\Models\Household;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/*
 * Every API error has the same body:
 *   {"message", "code", "errors"? (validation only), "request_id"}
 * and the same id in the X-Request-Id header.
 */

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->home = Household::factory()->create();
    $this->home->addMember($this->user);
});

test('401 without a token', function () {
    $this->getJson('/api/me')
        ->assertUnauthorized()
        ->assertJsonStructure(['message', 'code', 'request_id'])
        ->assertJsonPath('code', 'unauthenticated');
});

test('403 for someone else\'s household', function () {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson("/api/households/{$this->home->id}/expenses")
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});

test('404 does not name models or ids', function () {
    Sanctum::actingAs($this->user);

    $response = $this->getJson("/api/households/{$this->home->id}/expenses/01JAAAAAAAAAAAAAAAAAAAAAAA")
        ->assertNotFound()
        ->assertJson(['message' => 'Not found.', 'code' => 'not_found']);

    expect($response->getContent())->not->toContain('App\\\\Models');
});

test('422 lists the fields, the Laravel way, plus a code', function () {
    Sanctum::actingAs($this->user);

    $this->postJson("/api/households/{$this->home->id}/expenses", ['amount' => -1])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors(['amount', 'date', 'category'])
        ->assertJsonStructure(['message', 'code', 'errors', 'request_id']);
});

test('409 for a duplicate keeps its own code', function () {
    Sanctum::actingAs($this->user);
    $body = ['name' => 'Basil', 'category' => 'food', 'location' => 'fridge'];

    $this->postJson("/api/households/{$this->home->id}/inventory-items", $body)->assertCreated();
    $this->postJson("/api/households/{$this->home->id}/inventory-items", $body)
        ->assertConflict()
        ->assertJson(['message' => 'That already exists.', 'code' => 'conflict']);
});

test('405 for a method a route does not have', function () {
    Sanctum::actingAs($this->user);

    $this->putJson('/api/me')->assertStatus(405)->assertJsonPath('code', 'method_not_allowed');
});

test('the request id is echoed in the header and the body', function () {
    $response = $this->getJson('/api/me', ['X-Request-Id' => 'phone-abc-123'])->assertUnauthorized();

    expect($response->headers->get('X-Request-Id'))->toBe('phone-abc-123')
        ->and($response->json('request_id'))->toBe('phone-abc-123');
});

test('a malformed request id is replaced', function () {
    $response = $this->getJson('/api/me', ['X-Request-Id' => str_repeat('x', 200)]);

    expect($response->json('request_id'))->toMatch('/^[0-9a-f-]{36}$/');
});

test('successful responses carry a request id too', function () {
    Sanctum::actingAs($this->user);
    Expense::factory()->for($this->home)->create();

    $response = $this->getJson("/api/households/{$this->home->id}/expenses")->assertOk();

    expect($response->headers->get('X-Request-Id'))->not->toBeEmpty()
        ->and($response->json())->not->toHaveKey('code');
});
