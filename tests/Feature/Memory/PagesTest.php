<?php

use App\Enums\FactKind;
use App\Models\Household;
use App\Models\HouseholdFact;
use App\Models\Note;
use App\Models\RecommendationFeedback;
use App\Models\Todo;
use App\Models\User;
use App\Web\CurrentHousehold;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;

/*
 * The Memory and Today pages: what they are given, and what their forms do.
 */

beforeEach(function () {
    $this->home = Household::factory()->create();
    $this->user = User::factory()->create(['name' => 'Maria']);
    $this->other = User::factory()->create(['name' => 'Alex']);
    $this->home->addMember($this->user);
    $this->home->addMember($this->other);
    $this->actingAs($this->user)->withSession([CurrentHousehold::SESSION_KEY => $this->home->id]);
});

test('Memory: facts and notes you can see, members, and search results when asked', function () {
    $this->home->facts()->create(['user_id' => $this->user->id, 'kind' => FactKind::Routine, 'content' => 'Bins on Tuesday nights.']);
    $this->home->facts()->create(['user_id' => $this->other->id, 'kind' => FactKind::Fact, 'content' => 'Alex\'s secret.', 'private' => true]);
    $this->home->notes()->create(['user_id' => $this->user->id, 'title' => 'Kettle', 'body' => 'Descaled the kettle with vinegar.']);

    $this->get('/memory')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('memory')
        ->has('facts', 1)->where('facts.0.content', 'Bins on Tuesday nights.')
        ->has('notes', 1)->where('notes.0.title', 'Kettle')
        ->where('members', [['id' => $this->other->id, 'name' => 'Alex'], ['id' => $this->user->id, 'name' => 'Maria']])
        ->where('results', null));

    $this->get('/memory?q=descale+kettle')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('query', 'descale kettle')
        ->where('results.0.title', 'Kettle'));
});

test('Memory: remember, forget, write a note, upload a document, delete it', function () {
    Storage::fake('local');

    $this->post('/memory/facts', ['kind' => 'preference', 'content' => 'Prefers the market on Saturdays.', 'about_user_id' => $this->other->id, 'private' => '1'])
        ->assertRedirect('/memory');
    $fact = HouseholdFact::sole();
    expect($fact->only(['about_user_id', 'private', 'source']))->toBe(['about_user_id' => $this->other->id, 'private' => true, 'source' => 'person']);
    $this->delete("/memory/facts/{$fact->id}")->assertRedirect('/memory');
    expect(HouseholdFact::count())->toBe(0);

    $this->post('/memory/notes', ['title' => 'Holiday', 'body' => 'Naxos in June.'])->assertRedirect('/memory');
    $this->post('/memory/notes', ['file' => UploadedFile::fake()->createWithContent('router.txt', 'Unplug the router for 30 seconds.')])->assertRedirect('/memory');
    $this->post('/memory/notes', ['title' => ''])->assertSessionHasErrors(['title', 'body']);

    $doc = Note::where('kind', 'document')->sole();
    expect($doc->title)->toBe('router')->and($doc->body)->toBe('Unplug the router for 30 seconds.');
    $this->delete("/memory/notes/{$doc->id}")->assertRedirect('/memory');
    Storage::disk('local')->assertMissing($doc->file_path);

    // Someone else's private note is not yours to delete
    $theirs = $this->home->notes()->create(['user_id' => $this->other->id, 'title' => 'Mine', 'body' => 'x', 'private' => true]);
    $this->delete("/memory/notes/{$theirs->id}")->assertNotFound();
});

test('Today: the briefing, the timeline with its filters, the summaries; feedback is saved', function () {
    Todo::factory()->for($this->home)->create(['title' => 'Call the plumber', 'due_on' => now()->toDateString()]);
    Todo::factory()->for($this->home)->create(['title' => 'Descale the kettle', 'done_at' => now()->subDays(2)]);

    $this->get('/briefing')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('briefing')
        ->where('briefing.for', 'Maria')
        ->where('briefing.tasks.todos.0.title', 'Call the plumber')
        ->where('timeline.0.title', 'Descale the kettle')
        ->where('filters', ['kind' => null, 'q' => ''])
        ->has('kinds', 10)
        ->has('summaries', 0));

    $this->get('/briefing?kind=expense')->assertInertia(fn (AssertableInertia $page) => $page->has('timeline', 0)->where('filters.kind', 'expense'));
    $this->get('/briefing?kind=nope')->assertSessionHasErrors('kind');

    $this->from('/briefing')->post('/briefing/feedback', ['key' => 'shopping_day:2026-11-11', 'verdict' => 'dismissed'])->assertRedirect('/briefing');
    expect(RecommendationFeedback::sole()->only(['rule', 'verdict', 'user_id']))->toBe(['rule' => 'shopping_day', 'verdict' => 'dismissed', 'user_id' => $this->user->id]);
});
