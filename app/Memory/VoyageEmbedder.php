<?php

namespace App\Memory;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Voyage AI embeddings (https://docs.voyageai.com/reference/embeddings-api),
 * the embedding models Anthropic recommends, since Claude has no embeddings
 * endpoint of its own. Real semantic search: "is the heating serviced?" finds
 * "the boiler man came in March".
 *
 * Passages are embedded as input_type "document" and searches as "query".
 * Up to 128 texts per request.
 */
class VoyageEmbedder implements Embedder
{
    public const URL = 'https://api.voyageai.com/v1/embeddings';

    public function __construct(
        private ?string $apiKey,
        private string $model = 'voyage-4',
        private int $dimensions = 512,
        private int $timeout = 30,
    ) {}

    public function name(): string
    {
        return "{$this->model}-{$this->dimensions}";
    }

    public function minimumSimilarity(): float
    {
        return 0.3;
    }

    public function embed(array $texts, string $purpose = 'document'): array
    {
        if (! $this->apiKey) {
            throw new EmbeddingFailed('MEMORY_EMBEDDER=voyage needs VOYAGE_API_KEY.');
        }

        $vectors = [];
        foreach (array_chunk($texts, 128) as $batch) {
            try {
                $response = Http::withToken($this->apiKey)->acceptJson()->timeout($this->timeout)->retry(2, 500, throw: false)
                    ->post(self::URL, [
                        'input' => $batch,
                        'model' => $this->model,
                        'input_type' => $purpose,
                        'output_dimension' => $this->dimensions,
                    ]);
            } catch (ConnectionException $e) {
                throw new EmbeddingFailed('Voyage AI is not reachable: '.$e->getMessage(), previous: $e);
            }
            if (! $response->successful()) {
                throw new EmbeddingFailed("Voyage AI answered {$response->status()}: ".mb_substr((string) $response->json('detail', $response->body()), 0, 200));
            }

            $data = $response->json('data', []);
            usort($data, fn (array $a, array $b) => $a['index'] <=> $b['index']);
            foreach ($data as $row) {
                $vectors[] = self::normalise(array_values(array_map('floatval', $row['embedding'])));
            }
        }

        if (count($vectors) !== count($texts)) {
            throw new EmbeddingFailed('Voyage AI returned '.count($vectors).' embeddings for '.count($texts).' texts.');
        }

        return $vectors;
    }

    /**
     * @param  list<float>  $v
     * @return list<float>
     */
    private static function normalise(array $v): array
    {
        $norm = sqrt(array_sum(array_map(fn (float $x) => $x * $x, $v)));

        return $norm > 0 ? array_map(fn (float $x) => $x / $norm, $v) : $v;
    }
}
