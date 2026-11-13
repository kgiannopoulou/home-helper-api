<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\SmartHomeConnection;
use App\Models\User;
use App\SmartHome\Connections;
use App\Web\CurrentHousehold;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\FakeHomeAssistant;

beforeEach(function () {
    $this->ha = FakeHomeAssistant::install();
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->home->addMember($this->member);
    $this->connections = app(Connections::class);
});

function connectHome(object $test): array
{
    return $test->connections->connect($test->home, $test->owner, FakeHomeAssistant::URL, FakeHomeAssistant::TOKEN);
}

it('connects after checking the token with Home Assistant', function () {
    [$connection, $secret] = connectHome($this);

    expect($connection->status)->toBe('connected')
        ->and($connection->location_name)->toBe('Home Helper test home')
        ->and($connection->ha_version)->toBe('2026.11.1')
        ->and($connection->household_id)->toBe($this->home->id)
        ->and(strlen($secret))->toBe(48);

    // The token is encrypted at rest and never serialised
    $raw = DB::table('smart_home_connections')->value('token');
    expect($raw)->not->toContain(FakeHomeAssistant::TOKEN)
        ->and($connection->fresh()->token)->toBe(FakeHomeAssistant::TOKEN)
        ->and($connection->toArray())->not->toHaveKeys(['token', 'webhook_secret_hash']);
});

it('refuses a wrong token or an address that is not Home Assistant, and saves nothing', function () {
    expect(fn () => $this->connections->connect($this->home, $this->owner, FakeHomeAssistant::URL, 'wrong-token-wrong-token-wrong'))
        ->toThrow(ValidationException::class, 'refused the token');

    $this->ha->failure = 'unreachable';
    expect(fn () => connectHome($this))->toThrow(ValidationException::class, 'not answering');

    $this->ha->failure = 'not_json';
    expect(fn () => connectHome($this))->toThrow(ValidationException::class);

    expect(SmartHomeConnection::count())->toBe(0);
});

it('only lets the owner connect', function () {
    expect(fn () => $this->connections->connect($this->home, $this->member, FakeHomeAssistant::URL, FakeHomeAssistant::TOKEN))
        ->toThrow(ValidationException::class, 'owner');
});

it('normalizes the address and refuses paths, credentials and other schemes', function (string $in, ?string $out) {
    if ($out === null) {
        expect(fn () => Connections::normalizeUrl($in))->toThrow(ValidationException::class);
    } else {
        expect(Connections::normalizeUrl($in))->toBe($out);
    }
})->with([
    ['192.168.1.20:8123', 'http://192.168.1.20:8123'],
    ['http://homeassistant.local:8123/', 'http://homeassistant.local:8123'],
    ['HTTPS://Home.Example.com', 'https://home.example.com'],
    ['http://ha:8123/api/other', null],
    ['http://user:pass@ha:8123', null],
    ['file:///etc/passwd', null],
    ['ftp://ha', null],
    ['http://ha:8123/?next=x', null],
]);

it('keeps one connection per household: connecting again replaces it and its secret', function () {
    [$first, $oldSecret] = connectHome($this);
    [$second, $newSecret] = connectHome($this);

    expect($second->id)->toBe($first->id)
        ->and(SmartHomeConnection::count())->toBe(1)
        ->and($this->connections->forWebhook(SmartHomeConnection::webhookToken($first->id, $oldSecret)))->toBeNull()
        ->and($this->connections->forWebhook(SmartHomeConnection::webhookToken($first->id, $newSecret))?->id)->toBe($first->id);
});

it('tracks the status from the last real call', function () {
    [$connection] = connectHome($this);

    $this->ha->failure = 'unreachable';
    $this->connections->check($connection);
    expect($connection->fresh())->status->toBe('unreachable')->status_message->toContain('not answering')->last_error_at->not->toBeNull();

    $this->ha->failure = 'unauthorized';
    expect($this->connections->check($connection)->status)->toBe('unauthorized');

    $this->ha->failure = 'server_error';
    expect($this->connections->check($connection)->status)->toBe('error');

    $this->ha->failure = null;
    expect($this->connections->check($connection))->status->toBe('connected')->status_message->toBeNull();
});

describe('the webhook', function () {
    beforeEach(function () {
        [$this->connection, $secret] = connectHome($this);
        $this->bearer = SmartHomeConnection::webhookToken($this->connection->id, $secret);
    });

    it('accepts a state change with the connection secret', function () {
        $this->withToken($this->bearer)
            ->postJson('/api/smart-home/v1/states', ['entity_id' => 'binary_sensor.front_door', 'state' => 'on', 'attributes' => ['device_class' => 'door'], 'last_changed' => now()->toIso8601String(), 'old_state' => 'off'])
            ->assertAccepted();

        expect($this->connection->fresh()->last_event_at)->not->toBeNull();
    });

    it('refuses a missing, wrong or rotated secret, and a user token', function () {
        $this->postJson('/api/smart-home/v1/states', ['entity_id' => 'light.x'])->assertUnauthorized()->assertJsonPath('error', 'invalid_webhook_token');
        $this->withToken($this->connection->id.'.'.str_repeat('a', 48))->postJson('/api/smart-home/v1/states', ['entity_id' => 'light.x'])->assertUnauthorized();
        $this->withToken($this->owner->createToken('phone')->plainTextToken)->postJson('/api/smart-home/v1/states', ['entity_id' => 'light.x'])->assertUnauthorized();

        $this->connections->rotateSecret($this->connection);
        $this->withToken($this->bearer)->postJson('/api/smart-home/v1/states', ['entity_id' => 'light.x'])->assertUnauthorized();
    });

    it('validates what Home Assistant sends', function () {
        $this->withToken($this->bearer)->postJson('/api/smart-home/v1/states', ['entity_id' => 'not an entity'])->assertUnprocessable();
    });
});

describe('the page', function () {
    it('shows the connect form to the owner, and the webhook secret once', function () {
        $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id])
            ->get('/smart-home')->assertInertia(fn (Page $p) => $p->component('smart-home')->where('connection', null)->where('can.manage', true));

        $this->post('/smart-home/connection', ['url' => FakeHomeAssistant::URL, 'token' => FakeHomeAssistant::TOKEN])->assertRedirect('/smart-home');
        $this->followingRedirects()->get('/smart-home')->assertOk();

        $connection = SmartHomeConnection::sole();
        $this->withSession(['smart_home_webhook' => ['url' => 'x', 'token' => 'y']])->get('/smart-home')
            ->assertInertia(fn (Page $p) => $p->where('connection.status', 'connected')->where('connection.base_url', FakeHomeAssistant::URL)->where('webhook.token', 'y')->missing('connection.token'));
        expect($connection->connected_by)->toBe($this->owner->id);
    });

    it('lets members look and check, but not connect or disconnect', function () {
        connectHome($this);
        $this->actingAs($this->member)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);

        $this->get('/smart-home')->assertInertia(fn (Page $p) => $p->where('can.manage', false)->where('connection.status', 'connected'));
        $this->post('/smart-home/connection/check')->assertRedirect();
        $this->post('/smart-home/connection', ['url' => FakeHomeAssistant::URL, 'token' => FakeHomeAssistant::TOKEN])->assertForbidden();
        $this->post('/smart-home/connection/secret')->assertForbidden();
        $this->delete('/smart-home/connection')->assertForbidden();
        expect(SmartHomeConnection::count())->toBe(1);
    });

    it('shows Home Assistant errors on the form field', function () {
        $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id])
            ->post('/smart-home/connection', ['url' => FakeHomeAssistant::URL, 'token' => 'wrong-token-wrong-token-wrong'])
            ->assertSessionHasErrors('token');
    });

    it('disconnects', function () {
        connectHome($this);
        $this->actingAs($this->owner)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id])
            ->delete('/smart-home/connection')->assertRedirect('/smart-home');
        expect(SmartHomeConnection::count())->toBe(0);
    });
});
