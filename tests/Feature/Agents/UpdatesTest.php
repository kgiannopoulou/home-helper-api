<?php

use App\Agents\Releases;
use App\Enums\HouseholdRole;
use App\Models\AgentEvent;
use App\Models\AgentRelease;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\AgentClient;

beforeEach(function () {
    Storage::fake(Releases::DISK);
    $pair = Releases::generateKeyPair();
    config(['homehelper.agents.release_secret_key' => $pair['secret'], 'homehelper.agents.release_public_key' => $pair['public']]);
    $this->publicKey = $pair['public'];

    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create();
    $this->member = User::factory()->create();
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->home->addMember($this->member);
    $this->client = AgentClient::enrolled($this, $this->home, ['version' => '0.5.0', 'platform' => 'windows-x64']);

    $this->binary = tempnam(sys_get_temp_dir(), 'hha');
    file_put_contents($this->binary, 'MZ new agent build '.str_repeat('x', 1000));
});

afterEach(fn () => @unlink($this->binary));

function beatVersion(AgentClient $client, string $version = '0.5.0'): array
{
    return $client->post('heartbeat', ['version' => $version, 'platform' => 'windows-x64'])->assertOk()->json();
}

it('signs a release the agent can verify with the public key alone', function () {
    $release = app(Releases::class)->publish('0.6.0', 'windows-x64', $this->binary, notes: 'Faster heartbeats');

    $manifest = Releases::manifest('0.6.0', 'windows-x64', hash_file('sha512', $this->binary), filesize($this->binary));
    expect(sodium_crypto_sign_verify_detached(hex2bin($release->signature), $manifest, hex2bin($this->publicKey)))->toBeTrue()
        ->and($release->size)->toBe(filesize($this->binary))
        ->and(Storage::disk(Releases::DISK)->exists($release->path))->toBeTrue();
});

it('offers a newer release for the platform, without installing it', function () {
    app(Releases::class)->publish('0.6.0', 'windows-x64', $this->binary);
    app(Releases::class)->publish('0.7.0', 'linux-arm64', $this->binary);

    $offer = beatVersion($this->client)['update'];
    expect($offer)->toMatchArray(['version' => '0.6.0', 'platform' => 'windows-x64', 'install' => false])
        ->and($offer['url'])->toStartWith('releases/');

    expect(beatVersion($this->client, '0.6.0')['update'])->toBeNull();  // up to date
});

it('installs only when the owner asks', function () {
    app(Releases::class)->publish('0.6.0', 'windows-x64', $this->binary);

    expect(fn () => app(Releases::class)->requestInstall($this->client->agent, $this->member))->toThrow(ValidationException::class);
    app(Releases::class)->requestInstall($this->client->agent->fresh(), $this->owner);

    expect(beatVersion($this->client)['update']['install'])->toBeTrue();
});

it('serves the file to agents of that platform, signed like any response', function () {
    $release = app(Releases::class)->publish('0.6.0', 'windows-x64', $this->binary);

    $response = $this->client->get("releases/{$release->id}/download")->assertOk();
    expect($response->getContent())->toBe(file_get_contents($this->binary));

    $linux = AgentClient::enrolled($this, $this->home, ['name' => 'Pi', 'platform' => 'linux-arm64']);
    $linux->send('GET', "releases/{$release->id}/download")->assertNotFound();
});

it('records every version change, and an install request is done when the version arrives', function () {
    app(Releases::class)->publish('0.6.0', 'windows-x64', $this->binary);
    app(Releases::class)->requestInstall($this->client->agent->fresh(), $this->owner);

    beatVersion($this->client, '0.6.0');
    $event = AgentEvent::where('type', 'agent.updated')->sole();
    expect($event->data)->toMatchArray(['from' => '0.5.0', 'to' => '0.6.0'])
        ->and($event->severity)->toBe('info')
        ->and($this->client->agent->fresh()->update_requested_version)->toBeNull();

    beatVersion($this->client, '0.5.0');  // rolled back
    expect(AgentEvent::where('type', 'agent.updated')->latest('occurred_at')->first()->severity)->toBe('warning');
});

it('refuses to sign without a key, or with a key that is not the published one', function () {
    config(['homehelper.agents.release_secret_key' => null]);
    expect(fn () => app(Releases::class)->publish('0.6.0', 'windows-x64', $this->binary))->toThrow(RuntimeException::class);

    config(['homehelper.agents.release_secret_key' => Releases::generateKeyPair()['secret']]);
    expect(fn () => app(Releases::class)->publish('0.6.0', 'windows-x64', $this->binary))->toThrow(RuntimeException::class, 'not a pair');
    expect(AgentRelease::count())->toBe(0);
});

it('has command-line tools for the key and the release', function () {
    $this->artisan('agent:release-key')->expectsOutputToContain('AGENT_RELEASE_PUBLIC_KEY=')->assertSuccessful();
    $this->artisan('agent:release', ['release' => '0.6.0', 'platform' => 'windows-x64', 'file' => $this->binary])->assertSuccessful();
    $this->artisan('agent:release', ['release' => 'latest', 'platform' => 'windows-x64', 'file' => $this->binary])->assertFailed();
});
