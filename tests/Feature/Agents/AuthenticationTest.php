<?php

use App\Agents\RequestSignature;
use App\Models\Household;
use Tests\Support\AgentClient;

beforeEach(function () {
    $this->home = Household::factory()->create(['name' => 'Home']);
    $this->client = AgentClient::enrolled($this, $this->home);
});

it('lets a correctly signed agent in and signs the answer', function () {
    $this->client->get('me')
        ->assertOk()
        ->assertJsonPath('agent_id', $this->client->agent->id)
        ->assertJsonPath('household.name', 'Home');
});

it('refuses a request without the signature headers', function () {
    $this->getJson('/api/agent/v1/me')->assertUnauthorized()->assertJsonPath('error', 'missing_signature');
});

it('refuses an unknown agent', function () {
    $this->client->send('GET', 'me', override: [RequestSignature::HEADER_AGENT => '01JAAAAAAAAAAAAAAAAAAAAAAA'])
        ->assertUnauthorized()->assertJsonPath('error', 'unknown_agent');
});

it('refuses a signature made with another secret', function () {
    $this->client->send('GET', 'me', override: [RequestSignature::HEADER_SIGNATURE => hash_hmac('sha512', 'x', 'y')])
        ->assertUnauthorized()->assertJsonPath('error', 'bad_signature');
});

it('refuses a body changed after signing', function () {
    $nonce = bin2hex(random_bytes(16));
    $ts = (string) time();
    $signed = RequestSignature::signRequest($this->client->agent->secretBytes(), 'GET', '/api/agent/v1/me', $ts, $nonce, $this->client->agent->id, '');
    $this->client->send('GET', 'me', '{"extra":1}', [RequestSignature::HEADER_SIGNATURE => $signed], (int) $ts, $nonce)
        ->assertUnauthorized()->assertJsonPath('error', 'bad_signature');
});

it('refuses a replayed request', function () {
    $nonce = bin2hex(random_bytes(16));
    $this->client->send('GET', 'me', nonce: $nonce)->assertOk();
    $this->client->assertSigned($this->client->send('GET', 'me', nonce: $nonce))
        ->assertUnauthorized()->assertJsonPath('error', 'replayed');
});

it('tells an agent with a wrong clock the server time, signed', function () {
    $response = $this->client->assertSigned($this->client->send('GET', 'me', timestamp: time() - 3600))
        ->assertUnauthorized()
        ->assertJsonPath('error', 'clock_skew');

    expect($response->json('server_time'))->toBeGreaterThanOrEqual(time() - 1);
});

it('a forged request does not spend the nonce', function () {
    $nonce = bin2hex(random_bytes(16));
    $this->client->send('GET', 'me', override: [RequestSignature::HEADER_SIGNATURE => str_repeat('a', 128)], nonce: $nonce)
        ->assertUnauthorized();
    $this->client->send('GET', 'me', nonce: $nonce)->assertOk();
});

it('tells a revoked agent, signed, so it can stop', function () {
    $this->client->agent->update(['revoked_at' => now()]);

    $this->client->assertSigned($this->client->send('GET', 'me'))
        ->assertUnauthorized()
        ->assertJsonPath('error', 'agent_revoked');
});
