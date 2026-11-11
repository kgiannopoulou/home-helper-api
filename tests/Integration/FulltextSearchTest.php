<?php

use App\Enums\HouseholdRole;
use App\Enums\NoteKind;
use App\Memory\Embedder;
use App\Memory\EmbeddingFailed;
use App\Memory\KnowledgeBase;
use App\Models\Household;
use App\Models\User;

/*
 * The keyword half of hybrid search: MySQL FULLTEXT on committed rows (this
 * suite truncates tables instead of rolling back a transaction, because
 * InnoDB only adds a row to its FULLTEXT index when its transaction commits).
 */

beforeEach(function () {
    $this->home = Household::factory()->create();
    $this->me = User::factory()->create();
    $this->home->addMember($this->me, HouseholdRole::Owner);
    $this->manual = $this->home->notes()->create(['user_id' => $this->me->id, 'kind' => NoteKind::Document, 'title' => 'Washing machine manual',
        'body' => "Error E13: no water coming in. Check the tap behind the machine.\n\nError E21: it can't drain. Clean the pump filter."]);
    $this->home->notes()->create(['user_id' => $this->me->id, 'title' => 'Shopping', 'body' => 'We buy olive oil from the Lidl on Patission street.']);
});

test('exact names and codes are found by keyword, and passages found both ways rank first', function () {
    $hits = app(KnowledgeBase::class)->search($this->home, $this->me, 'Lidl');

    expect($hits[0]['title'])->toBe('Shopping')
        ->and($hits[0]['matched_by'])->toContain('keywords');

    $both = app(KnowledgeBase::class)->search($this->home, $this->me, 'washing machine error water');
    expect($both[0]['title'])->toBe('Washing machine manual')
        ->and($both[0]['matched_by'])->toBe(['meaning', 'keywords']);
});

test('without the embedding service, search still answers from keywords', function () {
    app()->instance(Embedder::class, new class implements Embedder
    {
        public function embed(array $texts, string $purpose = 'document'): array
        {
            throw new EmbeddingFailed('down');
        }

        public function name(): string
        {
            return 'down';
        }

        public function minimumSimilarity(): float
        {
            return 0.5;
        }
    });

    $hits = app(KnowledgeBase::class)->search($this->home, $this->me, 'pump filter drain');

    expect($hits)->toHaveCount(1)
        ->and($hits[0]['source_id'])->toBe($this->manual->id)
        ->and($hits[0]['matched_by'])->toBe(['keywords']);
});
