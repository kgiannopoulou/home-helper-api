<?php

use App\Models\Household;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Laravel\Sanctum\Sanctum;

/*
 * A sweep over every route in routes/api.php, so a new endpoint can't ship
 * without protection: each one needs a token, and each one under a
 * household refuses someone who isn't a member. The per-module tests cover
 * rows of another household (404) and private personal logs.
 */

/** Routes anyone may call */
const PUBLIC_API_ROUTES = ['api.login'];

/**
 * @return list<array{string, string, string}> [name, method, uri]
 */
function apiRoutes(): array
{
    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/'))
        ->flatMap(fn (Route $r) => collect($r->methods())
            ->reject(fn ($m) => in_array($m, ['HEAD', 'OPTIONS']))
            ->map(fn ($m) => [$r->getName() ?? $r->uri(), $m, $r->uri()]))
        ->values()
        ->all();
}

/** Fills route parameters: the given household, and a well-formed id for the rest */
function fillUri(string $uri, Household $household): string
{
    return '/'.preg_replace(
        ['/\{household\}/', '/\{[^}]+\}/'],
        [$household->id, '01JAAAAAAAAAAAAAAAAAAAAAAA'],
        $uri,
    );
}

test('every API route needs a token, except login', function () {
    $home = Household::factory()->create();
    $checked = 0;

    foreach (apiRoutes() as [$name, $method, $uri]) {
        if (in_array($name, PUBLIC_API_ROUTES)) {
            continue;
        }
        $this->json($method, fillUri($uri, $home))
            ->assertUnauthorized()
            ->assertJsonPath('code', 'unauthenticated');
        $checked++;
    }

    // Guards against the sweep silently finding nothing
    expect($checked)->toBeGreaterThan(100);
});

test('every household route refuses someone who is not a member', function () {
    $home = Household::factory()->create();
    $home->addMember(User::factory()->create());
    Sanctum::actingAs(User::factory()->create());
    // Over a hundred requests from one user would hit the per-user limit
    $this->withoutMiddleware(ThrottleRequests::class);
    $checked = 0;

    foreach (apiRoutes() as [$name, $method, $uri]) {
        if (! str_contains($uri, '{household}')) {
            continue;
        }
        $response = $this->json($method, fillUri($uri, $home));
        expect($response->status())->toBe(403, "$method /$uri answered {$response->status()}");
        $checked++;
    }

    expect($checked)->toBeGreaterThan(100);
});

test('the public routes are only the ones we meant', function () {
    // A route outside auth:sanctum shows up here and has to be added on purpose
    $public = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/'))
        ->reject(fn (Route $r) => in_array('auth:sanctum', $r->gatherMiddleware()))
        ->map(fn (Route $r) => $r->getName())
        ->values()
        ->all();

    expect($public)->toBe(PUBLIC_API_ROUTES);
});

test('a member who is not the owner cannot invite', function () {
    $home = Household::factory()->create();
    $home->addMember($member = User::factory()->create());
    Sanctum::actingAs($member);

    $this->postJson("/api/households/{$home->id}/invites", ['email' => 'friend@example.com'])
        ->assertForbidden()
        ->assertJsonPath('code', 'forbidden');
});
