<?php

namespace App\Memory;

use App\Enums\MemorySource;
use App\Models\Household;
use App\Models\MemoryChunk;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Search over the household's long-term memory (docs/memory.md#search).
 *
 * Hybrid: two rankings of the passages this person may see, merged with
 * reciprocal rank fusion (each passage scores Σ 1 / (60 + its rank)):
 *
 *   meaning    cosine similarity of embeddings, computed in PHP over the
 *              household's passages (hundreds to a few thousand rows)
 *   keywords   MySQL FULLTEXT, MATCH (title, text) AGAINST (… IN NATURAL LANGUAGE MODE)
 *
 * Keywords catch exact names and numbers ("E13", "Lidl") that embeddings blur;
 * embeddings catch the same thing said differently. Either alone can miss.
 */
class KnowledgeBase
{
    /** The constant in reciprocal rank fusion; 60 is the usual choice */
    private const RRF_K = 60;

    private const CANDIDATES = 30;

    public function __construct(private Embedder $embedder) {}

    /**
     * @param  list<MemorySource>|null  $types  only these kinds of memory
     * @return list<array{source_type: string, source_id: string, title: string, text: string, occurred_on: string|null, score: float, matched_by: list<string>}>
     */
    public function search(
        Household $household,
        User $user,
        string $query,
        int $limit = 6,
        ?array $types = null,
        ?CarbonImmutable $from = null,
        ?CarbonImmutable $to = null,
    ): array {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $visible = fn (): Builder => MemoryChunk::query()
            ->where('household_id', $household->id)
            ->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $user->id))
            ->when($types, fn (Builder $q) => $q->whereIn('source_type', array_map(fn (MemorySource $t) => $t->value, $types)))
            ->when($from, fn (Builder $q) => $q->where('occurred_on', '>=', $from->toDateString()))
            ->when($to, fn (Builder $q) => $q->where('occurred_on', '<=', $to->toDateString()));

        $rankings = [
            'meaning' => $this->byMeaning($visible(), $query),
            'keywords' => $this->byKeywords($visible(), $query),
        ];

        $scores = [];
        $matchedBy = [];
        foreach ($rankings as $leg => $ids) {
            foreach ($ids as $rank => $id) {
                $scores[$id] = ($scores[$id] ?? 0) + 1 / (self::RRF_K + $rank + 1);
                $matchedBy[$id][] = $leg;
            }
        }
        arsort($scores);

        $chunks = MemoryChunk::query()->whereIn('id', array_keys($scores))->get()->keyBy('id');
        $hits = [];
        $perSource = [];
        foreach ($scores as $id => $score) {
            $chunk = $chunks->get($id);
            $source = $chunk->source_type->value.':'.$chunk->source_id;
            // At most two passages from one note, so one long document doesn't fill the answer
            if (($perSource[$source] = ($perSource[$source] ?? 0) + 1) > 2) {
                continue;
            }
            $hits[] = [
                'source_type' => $chunk->source_type->value,
                'source_id' => $chunk->source_id,
                'title' => $chunk->title,
                'text' => $chunk->text,
                'occurred_on' => $chunk->occurred_on?->toDateString(),
                'score' => round($score * 1000, 2),
                'matched_by' => $matchedBy[$id],
            ];
            if (count($hits) >= $limit) {
                break;
            }
        }

        return $hits;
    }

    /**
     * Passage ids, most similar first.
     *
     * @param  Builder<MemoryChunk>  $chunks
     * @return list<int>
     */
    private function byMeaning(Builder $chunks, string $query): array
    {
        try {
            $q = $this->embedder->embed([$query], 'query')[0];
        } catch (EmbeddingFailed $e) {
            // Keyword search still works without the embedding service
            Log::warning('Memory search without embeddings', ['error' => $e->getMessage()]);

            return [];
        }

        $similar = [];
        $min = $this->embedder->minimumSimilarity();
        $chunks->where('embedder', $this->embedder->name())->select(['id', 'embedding'])
            ->chunkById(500, function ($rows) use ($q, $min, &$similar) {
                foreach ($rows as $row) {
                    $v = MemoryChunk::unpack($row->embedding);
                    $dot = 0.0;
                    foreach ($q as $i => $x) {
                        $dot += $x * ($v[$i] ?? 0.0);
                    }
                    if ($dot >= $min) {
                        $similar[$row->id] = $dot;
                    }
                }
            });
        arsort($similar);
        // Far behind the best match is noise, not a weaker match
        $best = reset($similar);
        $similar = array_filter($similar, fn (float $s) => $s >= $best * 0.5);

        return array_slice(array_keys($similar), 0, self::CANDIDATES);
    }

    /**
     * Passage ids, best keyword match first. InnoDB ignores words shorter than
     * 3 letters and its stop words; boolean operators in the query are stripped.
     *
     * @param  Builder<MemoryChunk>  $chunks
     * @return list<int>
     */
    private function byKeywords(Builder $chunks, string $query): array
    {
        $words = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $query) ?? '');
        if ($words === '') {
            return [];
        }

        return array_values($chunks->select('id')
            ->selectRaw('MATCH (title, text) AGAINST (? IN NATURAL LANGUAGE MODE) AS relevance', [$words])
            ->whereRaw('MATCH (title, text) AGAINST (? IN NATURAL LANGUAGE MODE) > 0', [$words])
            ->orderByDesc('relevance')->limit(self::CANDIDATES)
            ->pluck('id')->map(fn ($id) => (int) $id)->all());
    }
}
