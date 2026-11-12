<?php

use App\Agents\Enrollment;
use App\Enums\HouseholdRole;
use App\Models\Agent;
use App\Models\AgentEnrollmentCode;
use App\Models\Household;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

function ownedHousehold(): array
{
    $home = Household::factory()->create(['name' => 'Home']);
    $owner = User::factory()->create();
    $home->addMember($owner, HouseholdRole::Owner);

    return [$home, $owner];
}

function enrollBody(string $code, array $extra = []): array
{
    return $extra + ['code' => $code, 'name' => 'Living-room mini PC', 'hostname' => 'MINIPC', 'platform' => 'windows-x64', 'version' => '0.5.0', 'git_sha' => '64fe33f0a1b2'];
}

it('makes an 8-character code from an unambiguous alphabet and stores only its HMAC', function () {
    [$home, $owner] = ownedHousehold();
    [$code, $row] = app(Enrollment::class)->createCode($home, $owner, 'Mini PC');

    expect($code)->toMatch('/^[ACDEFGHJKMNPQRTUVWXY34679]{4}-[ACDEFGHJKMNPQRTUVWXY34679]{4}$/')
        ->and($row->code_hash)->toBe(Enrollment::hash($code))
        ->and(DB::table('agent_enrollment_codes')->where('code_hash', 'like', '%'.str_replace('-', '', $code).'%')->exists())->toBeFalse()
        ->and($row->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(9.9);
});

it('enrolls a computer with a code: agent id, secret, household', function () {
    [$home, $owner] = ownedHousehold();
    [$code] = app(Enrollment::class)->createCode($home, $owner);

    $response = $this->postJson('/api/agent/v1/enroll', enrollBody(strtolower(str_replace('-', ' ', $code))))
        ->assertCreated()
        ->assertJsonPath('household.name', 'Home')
        ->assertJsonPath('heartbeat_seconds', 30);

    $agent = Agent::findOrFail($response->json('agent_id'));
    expect($response->json('secret'))->toMatch('/^[0-9a-f]{64}$/')
        ->and($agent->secret)->toBe($response->json('secret'))
        ->and($agent->household_id)->toBe($home->id)
        ->and($agent->enrolled_by)->toBe($owner->id)
        ->and($agent->name)->toBe('Living-room mini PC')
        ->and($agent->platform)->toBe('windows-x64')
        // encrypted at rest
        ->and(DB::table('agents')->value('secret'))->not->toContain($response->json('secret'));
});

it('accepts a code once', function () {
    [$home, $owner] = ownedHousehold();
    [$code] = app(Enrollment::class)->createCode($home, $owner);

    $this->postJson('/api/agent/v1/enroll', enrollBody($code))->assertCreated();
    $this->postJson('/api/agent/v1/enroll', enrollBody($code))->assertStatus(422)->assertJsonPath('error', 'invalid_code');

    expect(Agent::count())->toBe(1)
        ->and(AgentEnrollmentCode::first()->used_at)->not->toBeNull();
});

it('refuses an expired or unknown code the same way', function () {
    [$home, $owner] = ownedHousehold();
    [$code] = app(Enrollment::class)->createCode($home, $owner);
    $this->travel(11)->minutes();

    $this->postJson('/api/agent/v1/enroll', enrollBody($code))->assertStatus(422)->assertJsonPath('error', 'invalid_code');
    $this->postJson('/api/agent/v1/enroll', enrollBody('AAAA-AAAA'))->assertStatus(422)->assertJsonPath('error', 'invalid_code');
    expect(Agent::count())->toBe(0);
});

it('voids a code whose creator is no longer the owner', function () {
    [$home, $owner] = ownedHousehold();
    [$code] = app(Enrollment::class)->createCode($home, $owner);
    $home->memberships()->where('user_id', $owner->id)->update(['role' => HouseholdRole::Member->value]);

    $this->postJson('/api/agent/v1/enroll', enrollBody($code))->assertStatus(422);
});

it('validates what the agent says about itself', function () {
    [$home, $owner] = ownedHousehold();
    [$code] = app(Enrollment::class)->createCode($home, $owner);

    $this->postJson('/api/agent/v1/enroll', enrollBody($code, ['platform' => 'Windows; rm -rf', 'version' => 'latest']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['platform', 'version']);
});

it('limits enrollment attempts per address', function () {
    RateLimiter::clear('agent-enroll');
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/agent/v1/enroll', enrollBody('AAAA-AAA'.$i))->assertStatus(422);
    }
    $this->postJson('/api/agent/v1/enroll', enrollBody('AAAA-AAAX'))->assertTooManyRequests();
});

it('makes a code from the command line for an owner, not for a member', function () {
    [$home, $owner] = ownedHousehold();
    $member = User::factory()->create();
    $home->addMember($member);

    $this->artisan('agent:pair', ['--email' => $owner->email])->assertSuccessful();
    expect(AgentEnrollmentCode::where('household_id', $home->id)->count())->toBe(1);

    $this->artisan('agent:pair', ['--email' => $member->email, '--household' => $home->id])->assertFailed();
});
