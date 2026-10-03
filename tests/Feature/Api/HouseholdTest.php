<?php

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\Invite;
use App\Models\User;
use App\Notifications\HouseholdInvite;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->owner = User::factory()->create(['name' => 'Konstantina']);
    $this->home = Household::factory()->create(['name' => 'Patission 12']);
    $this->home->addMember($this->owner, HouseholdRole::Owner);
});

/**
 * Invite someone as the owner and return the link from the email.
 */
function inviteLink(Household $home, string $email): string
{
    Notification::fake();
    test()->postJson("/api/households/{$home->id}/invites", ['email' => $email])->assertCreated();

    $url = null;
    Notification::assertSentTo(new AnonymousNotifiable, HouseholdInvite::class, function (HouseholdInvite $n, array $channels, AnonymousNotifiable $to) use ($email, &$url) {
        $url = $n->url;

        return $to->routes['mail'] === $email;
    });

    return $url;
}

test('creating a household makes you its owner', function () {
    Sanctum::actingAs($this->owner);

    $id = $this->postJson('/api/households', ['name' => 'Beach house', 'currency' => 'eur'])
        ->assertCreated()
        ->assertJsonPath('data.role', 'owner')
        ->assertJsonPath('data.currency', 'EUR')
        ->json('data.id');

    $this->getJson('/api/households')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Beach house');
    expect($this->owner->roleIn($id))->toBe(HouseholdRole::Owner);
});

test('validates a new household', function () {
    Sanctum::actingAs($this->owner);

    $this->postJson('/api/households', ['currency' => 'euro'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'currency']);
});

test('lists members with their roles, only to members', function () {
    $alex = User::factory()->create(['name' => 'Alex']);
    $this->home->addMember($alex);
    Sanctum::actingAs($alex);

    $this->getJson("/api/households/{$this->home->id}/members")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Alex')
        ->assertJsonPath('data.0.role', 'member')
        ->assertJsonPath('data.1.role', 'owner');

    Sanctum::actingAs(User::factory()->create());
    $this->getJson("/api/households/{$this->home->id}")->assertForbidden();
    $this->getJson("/api/households/{$this->home->id}/members")->assertForbidden();
});

test('an invited person joins with the signed link', function () {
    Sanctum::actingAs($this->owner);
    $url = inviteLink($this->home, 'alex@example.com');

    expect($url)->toContain('signature=')
        ->and(Invite::sole()->only('email', 'invited_by'))->toBe(['email' => 'alex@example.com', 'invited_by' => $this->owner->id]);

    $alex = User::factory()->create(['email' => 'Alex@example.com']);
    Sanctum::actingAs($alex);

    $this->postJson($url)
        ->assertOk()
        ->assertJsonPath('data.id', $this->home->id)
        ->assertJsonPath('data.role', 'member');

    expect($alex->isMemberOf($this->home))->toBeTrue()
        ->and(Invite::sole()->accepted_at)->not->toBeNull();

    // A link works once
    $this->postJson($url)->assertStatus(410);
});

test('an invite link does not work if changed, or for someone else', function () {
    Sanctum::actingAs($this->owner);
    $url = inviteLink($this->home, 'alex@example.com');

    Sanctum::actingAs(User::factory()->create(['email' => 'alex@example.com']));
    $this->postJson(str_replace('signature=', 'signature=0', $url))->assertForbidden();

    Sanctum::actingAs(User::factory()->create(['email' => 'mallory@example.com']));
    $this->postJson($url)->assertForbidden();

    expect($this->home->users()->count())->toBe(1);
});

test('an invite link expires', function () {
    Sanctum::actingAs($this->owner);
    $url = inviteLink($this->home, 'alex@example.com');

    $this->travel(8)->days();
    Sanctum::actingAs(User::factory()->create(['email' => 'alex@example.com']));

    $this->postJson($url)->assertForbidden();
});

test('only the owner can invite, and not existing members', function () {
    $alex = User::factory()->create(['email' => 'alex@example.com']);
    $this->home->addMember($alex);

    Sanctum::actingAs($alex);
    $this->postJson("/api/households/{$this->home->id}/invites", ['email' => 'maria@example.com'])->assertForbidden();

    Sanctum::actingAs($this->owner);
    $this->postJson("/api/households/{$this->home->id}/invites", ['email' => 'alex@example.com'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email');
    $this->postJson("/api/households/{$this->home->id}/invites", ['email' => 'not-an-email'])
        ->assertUnprocessable();
});
