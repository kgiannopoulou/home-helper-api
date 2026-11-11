<?php

namespace App\Memory;

/**
 * Turns text into vectors whose dot product says how alike two texts are
 * (docs/memory.md#embeddings). Vectors are L2-normalised, so the dot product
 * is the cosine similarity.
 */
interface Embedder
{
    /**
     * @param  list<string>  $texts
     * @param  'document'|'query'  $purpose  stored passages and search queries are embedded differently by some models
     * @return list<list<float>> one vector per text, in order
     *
     * @throws EmbeddingFailed
     */
    public function embed(array $texts, string $purpose = 'document'): array;

    /** Stored with each chunk: "local-hash-512", "voyage-4-512". Vectors from different embedders are never compared. */
    public function name(): string;

    /** Below this cosine similarity a passage is not a match at all (it is noise for this embedder) */
    public function minimumSimilarity(): float;
}
