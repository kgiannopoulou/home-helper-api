<?php

use App\Agents\Releases;
use App\Enums\HouseholdRole;
use App\Models\AgentCommand;
use App\Models\AgentEnrollmentCode;
use App\Models\AgentEvent;
use App\Models\Household;
use App\Models\User;
use App\Web\CurrentHousehold;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Page;
use Tests\Support\AgentClient;

beforeEach(function () {
    $this->home = Household::factory()->create();
    $this->owner = User::factory()->create(['name' => 'Eleni']);
    $this->member = User::factory()->create(['name' => 'Nikos']);
    $this->home->addMember($this->owner, HouseholdRole::Owner);
    $this->home->addMember($this->member);

    $this->client = AgentClient::enrolled($this, $this->home, [
        'metrics' => [
            'cpu_percent' => 12.5,
            'memory' => ['total_bytes' => 16_000_000_000, 'used_bytes' => 4_000_000_000],
            'disks' => [['mount' => 'C:\\', 'total_bytes' => 500, 'free_bytes' => 100]],
            'temperatures' => [['label' => 'CPU', 'celsius' => 55]],
        ],
        'inventory' => [
            'hostname' => 'MINIPC', 'os' => ['name' => 'Windows', 'version' => '10.0.26300'], 'cpu' => ['model' => 'N100', 'cores' => 4],
            'disks' => [], 'audio' => [['id' => 'm', 'name' => 'Microphone (USB)', 'direction' => 'input', 'default' => true, 'state' => 'active']],
            'commands' => [
                ['key' => 'ping_router', 'description' => 'Ping', 'sensitive' => false, 'params' => [['name' => 'host', 'description' => null, 'pattern' => '^192\\.168\\.[0-9]{1,3}\\.[0-9]{1,3}$']]],
                ['key' => 'restart_computer', 'description' => 'Restart', 'sensitive' => true, 'params' => []],
            ],
        ],
    ]);
    $this->agent = $this->client->agent;
});

function asMember(User $user, Household $home): void
{
    test()->actingAs($user)->withSession([CurrentHousehold::SESSION_KEY => $home->id]);
}

it('shows each computer with its readings, devices and commands', function () {
    $this->client->post('heartbeat', ['version' => '0.5.0', 'platform' => 'windows-x64', 'metrics' => $this->agent->metrics])->assertOk();
    asMember($this->member, $this->home);

    $this->get('/computers')->assertOk()->assertInertia(fn (Page $page) => $page
        ->component('computers')
        ->has('computers', 1)
        ->where('computers.0.name', 'Mini PC')
        ->where('computers.0.online', true)
        ->where('computers.0.memory.percent', 25)
        ->where('computers.0.disks.0.used_percent', 80)
        ->where('computers.0.microphones.0.name', 'Microphone (USB)')
        ->has('computers.0.commands', 2)
        ->has('computers.0.history', 1)
        ->where('computers.0.can', ['run' => true, 'manage' => false])
        ->where('can.pair', false));
});

it('lets the owner create a pairing code, shown once', function () {
    asMember($this->owner, $this->home);
    $this->followingRedirects()->post('/computers/pair', ['name' => 'Laptop'])
        ->assertInertia(fn (Page $page) => $page->where('pairing.code', fn ($code) => (bool) preg_match('/^[A-Z0-9]{4}-[A-Z0-9]{4}$/', $code)));
    $this->get('/computers')->assertInertia(fn (Page $page) => $page->where('pairing', null));
    expect(AgentEnrollmentCode::sole()->name)->toBe('Laptop');

    asMember($this->member, $this->home);
    $this->post('/computers/pair')->assertForbidden();
});

it('runs a command for a member, and holds a sensitive one for the owner', function () {
    asMember($this->member, $this->home);
    $this->post("/computers/{$this->agent->id}/commands", ['command' => 'ping_router', 'params' => ['host' => '192.168.1.1']])->assertRedirect();
    $this->post("/computers/{$this->agent->id}/commands", ['command' => 'restart_computer'])->assertRedirect();
    $this->post("/computers/{$this->agent->id}/commands", ['command' => 'ping_router', 'params' => ['host' => '8.8.8.8; calc']])->assertSessionHasErrors('params.host');

    $restart = AgentCommand::where('command_key', 'restart_computer')->sole();
    expect(AgentCommand::where('command_key', 'ping_router')->sole()->status)->toBe('queued')
        ->and($restart->status)->toBe('awaiting_confirmation');

    $this->post("/computers/commands/{$restart->id}/confirm")->assertSessionHasErrors('command');
    asMember($this->owner, $this->home);
    $this->post("/computers/commands/{$restart->id}/confirm")->assertRedirect();
    expect($restart->fresh()->status)->toBe('queued')->and($restart->fresh()->confirmed_by)->toBe($this->owner->id);
});

it('lets the owner install an update', function () {
    Storage::fake(Releases::DISK);
    $pair = Releases::generateKeyPair();
    config(['homehelper.agents.release_secret_key' => $pair['secret'], 'homehelper.agents.release_public_key' => $pair['public']]);
    $file = tempnam(sys_get_temp_dir(), 'hha');
    file_put_contents($file, 'MZ build');
    app(Releases::class)->publish('0.6.0', 'windows-x64', $file);
    @unlink($file);

    asMember($this->owner, $this->home);
    $this->get('/computers')->assertInertia(fn (Page $page) => $page->where('computers.0.update.version', '0.6.0')->where('computers.0.update.requested', false));
    $this->post("/computers/{$this->agent->id}/update")->assertRedirect();
    expect($this->agent->fresh()->update_requested_version)->toBe('0.6.0');
});

it('removes a computer: its secret stops working at once', function () {
    asMember($this->member, $this->home);
    $this->delete("/computers/{$this->agent->id}")->assertForbidden();

    asMember($this->owner, $this->home);
    $this->delete("/computers/{$this->agent->id}")->assertRedirect();
    expect(AgentEvent::where('type', 'agent.revoked')->count())->toBe(1);

    $this->client->assertSigned($this->client->send('GET', 'me'))->assertJsonPath('error', 'agent_revoked');
    $this->get('/computers')->assertInertia(fn (Page $page) => $page->has('computers', 0));
});

it('hides other households\' computers', function () {
    $stranger = User::factory()->create();
    $other = Household::factory()->create();
    $other->addMember($stranger, HouseholdRole::Owner);
    asMember($stranger, $other);

    $this->get('/computers')->assertInertia(fn (Page $page) => $page->has('computers', 0));
    $this->post("/computers/{$this->agent->id}/commands", ['command' => 'ping_router', 'params' => ['host' => '192.168.1.1']])->assertNotFound();
    $this->delete("/computers/{$this->agent->id}")->assertNotFound();
});
