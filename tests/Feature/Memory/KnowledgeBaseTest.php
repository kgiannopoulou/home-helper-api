<?php

use App\Enums\FactKind;
use App\Enums\HouseholdRole;
use App\Enums\MemorySource;
use App\Enums\NoteKind;
use App\Memory\Embedder;
use App\Memory\EmbeddingFailed;
use App\Memory\HashingEmbedder;
use App\Memory\KnowledgeBase;
use App\Memory\MemoryIndexer;
use App\Memory\VoyageEmbedder;
use App\Models\Household;
use App\Models\MemoryChunk;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * Days 29, 32, 33: embeddings, indexing and search. The keyword half of the
 * search (MySQL FULLTEXT) can't see rows inside the test's transaction, so
 * here it is the meaning half that answers; tests/Integration/FulltextSearchTest
 * covers the keywords on committed rows.
 */

beforeEach(function () {
    $this->home = Household::factory()->create();
    $this->me = User::factory()->create(['name' => 'Maria']);
    $this->other = User::factory()->create(['name' => 'Alex']);
    $this->home->addMember($this->me, HouseholdRole::Owner);
    $this->home->addMember($this->other);
});

function note(Household $home, User $by, string $title, string $body, bool $private = false, NoteKind $kind = NoteKind::Note)
{
    return $home->notes()->create(['user_id' => $by->id, 'kind' => $kind, 'title' => $title, 'body' => $body, 'private' => $private]);
}

test('the local embedder: deterministic, unit length, and closer for texts that share word forms', function () {
    $e = new HashingEmbedder(256);
    [$a, $b, $c] = $e->embed(['Descaled the kettle with vinegar', 'When did we descale the kettle?', 'Paid the electricity bill']);
    $dot = fn (array $x, array $y) => array_sum(array_map(fn ($i, $j) => $i * $j, $x, $y));

    expect($e->embed(['Descaled the kettle with vinegar'])[0])->toBe($a)
        ->and(count($a))->toBe(256)
        ->and(round($dot($a, $a), 6))->toBe(1.0)
        ->and($dot($a, $b))->toBeGreaterThan(0.4)
        ->and($dot($a, $c))->toBeLessThan($e->minimumSimilarity())
        ->and(HashingEmbedder::terms('The fridges were descaling'))->toBe(['refrigerator', 'descal']);
});

test('passages: cut at paragraphs, long ones at sentences, never longer than the size', function () {
    $long = str_repeat('A sentence about the boiler. ', 60);
    $chunks = MemoryIndexer::chunks("Short intro.\n\nSecond paragraph.\n\n{$long}", 300);

    expect($chunks[0])->toStartWith("Short intro.\n\nSecond paragraph.\n\nA sentence")
        ->and(count($chunks))->toBeGreaterThan(4)
        ->and(max(array_map('mb_strlen', $chunks)))->toBeLessThanOrEqual(300)
        ->and(MemoryIndexer::chunks("  \n "))->toBe([]);
});

test('saving a note indexes it, editing replaces its passages, deleting forgets them', function () {
    $n = note($this->home, $this->me, 'Kettle', 'Descaled the kettle with vinegar on Sunday.');

    $chunks = MemoryChunk::where('source_id', $n->id)->get();
    expect($chunks)->toHaveCount(1)
        ->and($chunks[0]->source_type)->toBe(MemorySource::Note)
        ->and($chunks[0]->embedder)->toBe('local-hash-512')
        ->and($chunks[0]->user_id)->toBeNull()
        ->and(count(MemoryChunk::unpack($chunks[0]->embedding)))->toBe(512);

    $n->update(['body' => str_repeat("A new paragraph about the kettle and the limescale.\n\n", 40)]);
    expect(MemoryChunk::where('source_id', $n->id)->count())->toBeGreaterThan(1)
        ->and(MemoryChunk::where('source_id', $n->id)->pluck('text')->implode(' '))->not->toContain('vinegar');

    $n->delete();
    expect(MemoryChunk::where('source_id', $n->id)->count())->toBe(0);
});

test('search finds the passage by meaning, with its source and date', function () {
    note($this->home, $this->me, 'Kettle', 'Descaled the kettle with white vinegar. Do it every two months.');
    note($this->home, $this->me, 'Router', 'Unplug the router for 30 seconds if the internet drops.');
    $this->home->facts()->create(['user_id' => $this->me->id, 'kind' => FactKind::Routine, 'content' => 'The bins go out on Tuesday nights.']);

    $hits = app(KnowledgeBase::class)->search($this->home, $this->me, 'when did we descale the kettle');

    expect($hits[0]['title'])->toBe('Kettle')
        ->and($hits[0]['source_type'])->toBe('note')
        ->and($hits[0]['occurred_on'])->toBe(now()->toDateString())
        ->and($hits[0]['matched_by'])->toContain('meaning')
        ->and(collect($hits)->pluck('title'))->not->toContain('Router');

    $bins = app(KnowledgeBase::class)->search($this->home, $this->me, 'rubbish bin night', types: [MemorySource::Fact]);
    expect($bins)->toHaveCount(1)->and($bins[0]['text'])->toBe('Routine: The bins go out on Tuesday nights.');
});

test('private memory is only searched by its author, and another household is never searched', function () {
    note($this->home, $this->me, 'Present', 'The birthday present is hidden in the hall cupboard.', private: true);
    $elsewhere = Household::factory()->create();
    $elsewhere->addMember($this->other);
    note($elsewhere, $this->other, 'Present', 'The birthday present is hidden in the hall cupboard.');

    $mine = app(KnowledgeBase::class)->search($this->home, $this->me, 'birthday present hidden');
    $theirs = app(KnowledgeBase::class)->search($this->home, $this->other, 'birthday present hidden');

    expect($mine)->toHaveCount(1)
        ->and(MemoryChunk::where('household_id', $this->home->id)->sole()->user_id)->toBe($this->me->id)
        ->and($theirs)->toBe([]);
});

test('date filters use the day the memory is about', function () {
    $old = note($this->home, $this->me, 'Holiday 2025', 'Ten days on Naxos.');
    MemoryChunk::where('source_id', $old->id)->update(['occurred_on' => '2025-06-01']);
    note($this->home, $this->me, 'Holiday 2026', 'Ten days on Naxos again.');

    $kb = app(KnowledgeBase::class);
    expect(array_column($kb->search($this->home, $this->me, 'Naxos holiday', to: CarbonImmutable::parse('2025-12-31')), 'title'))->toBe(['Holiday 2025'])
        ->and(array_column($kb->search($this->home, $this->me, 'Naxos holiday', from: CarbonImmutable::parse('2026-01-01')), 'title'))->toBe(['Holiday 2026']);
});

test('one long document fills at most two places in the results', function () {
    note($this->home, $this->me, 'Manual', implode("\n\n", array_fill(0, 12, str_repeat('Washing machine error code E13 means no water. ', 18))), kind: NoteKind::Document);

    $hits = app(KnowledgeBase::class)->search($this->home, $this->me, 'washing machine error', limit: 6);

    expect($hits)->toHaveCount(2)->and($hits[0]['source_type'])->toBe('document');
});

test('Voyage AI: documents and queries embedded with their input type, in order, normalised', function () {
    Http::fake(['api.voyageai.com/*' => fn (Request $r) => Http::response(['data' => array_map(
        fn ($i) => ['index' => $i, 'embedding' => [3.0 * ($i + 1), 4.0 * ($i + 1)]],
        array_reverse(array_keys($r['input'])),
    )])]);

    $vectors = (new VoyageEmbedder('vk-test', 'voyage-4', 2))->embed(['first', 'second'], 'query');

    expect($vectors)->toBe([[0.6, 0.8], [0.6, 0.8]]);
    Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer vk-test')
        && $r['input_type'] === 'query' && $r['model'] === 'voyage-4' && $r['output_dimension'] === 2 && $r['input'] === ['first', 'second']);
});

test('Voyage AI: no key or an error becomes EmbeddingFailed; search still answers by keywords', function () {
    expect(fn () => (new VoyageEmbedder(null))->embed(['x']))->toThrow(EmbeddingFailed::class, 'VOYAGE_API_KEY');

    Http::fake(['api.voyageai.com/*' => Http::response(['detail' => 'Rate limited'], 429)]);
    expect(fn () => (new VoyageEmbedder('vk', 'voyage-4', 2))->embed(['x']))->toThrow(EmbeddingFailed::class, '429');

    app()->instance(Embedder::class, new VoyageEmbedder('vk', 'voyage-4', 2));
    expect(app(KnowledgeBase::class)->search($this->home, $this->me, 'anything'))->toBe([]);
});
