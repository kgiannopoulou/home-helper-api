<?php

namespace App\Memory;

use Illuminate\Support\Str;

/**
 * Embeddings without a model: the "hashing trick" (docs/memory.md#embeddings).
 *
 * Each word is reduced to a stem ("descaling" → "descal"), a few household
 * synonyms are folded together (fridge → refrigerator), and the stem and its
 * character trigrams are hashed into a fixed number of dimensions with a
 * random sign. Similar wording gives similar vectors, so "when did we descale
 * the kettle" finds "Descaled the kettle with vinegar". It does not know
 * meaning ("heating" is not "boiler"); that is what VoyageEmbedder is for.
 *
 * Deterministic and free, so tests, CI and the offline demo use it.
 */
class HashingEmbedder implements Embedder
{
    private const STOPWORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'did', 'do', 'does', 'for', 'from', 'had', 'has', 'have',
        'how', 'i', 'in', 'is', 'it', 'its', 'me', 'my', 'of', 'on', 'or', 'our', 'so', 'that', 'the', 'their', 'them',
        'there', 'this', 'to', 'us', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with',
        'you', 'your', 'last', 'about', 'any', 'can', 'should', 'would', 'could',
    ];

    /** Words that mean the same thing in a household, folded onto one */
    private const SYNONYMS = [
        'fridge' => 'refrigerator', 'freezer' => 'refrigerator',
        'heater' => 'boiler', 'heating' => 'boiler', 'radiator' => 'boiler',
        'wifi' => 'router', 'internet' => 'router', 'password' => 'router',
        'washer' => 'washing', 'laundry' => 'washing',
        'vet' => 'veterinarian', 'doctor' => 'gp',
        'groceries' => 'shopping', 'supermarket' => 'shopping', 'shop' => 'shopping',
        'vegetarian' => 'meat', 'vegan' => 'meat',
        'holiday' => 'vacation', 'trip' => 'vacation',
        'bin' => 'rubbish', 'trash' => 'rubbish', 'recycling' => 'rubbish',
    ];

    public function __construct(private int $dimensions = 512) {}

    public function name(): string
    {
        return "local-hash-{$this->dimensions}";
    }

    public function minimumSimilarity(): float
    {
        return 0.12;
    }

    public function embed(array $texts, string $purpose = 'document'): array
    {
        return array_map($this->vector(...), $texts);
    }

    /** @return list<float> */
    private function vector(string $text): array
    {
        $v = array_fill(0, $this->dimensions, 0.0);
        $counts = [];
        foreach (self::terms($text) as $term) {
            $counts[$term] = ($counts[$term] ?? 0) + 1;
        }

        foreach ($counts as $term => $n) {
            $weight = 1 + log($n); // a word said five times isn't five times as relevant
            $this->add($v, "w:{$term}", 2.0 * $weight);
            $padded = "<{$term}>";
            for ($i = 0, $len = strlen($padded) - 2; $i < $len; $i++) {
                $this->add($v, 't:'.substr($padded, $i, 3), 0.5 * $weight);
            }
        }

        $norm = sqrt(array_sum(array_map(fn (float $x) => $x * $x, $v)));

        return array_values($norm > 0 ? array_map(fn (float $x) => $x / $norm, $v) : $v);
    }

    /** @param array<int, float> $v */
    private function add(array &$v, string $feature, float $weight): void
    {
        $h = crc32($feature);
        $sign = (crc32('s'.$feature) & 1) ? 1.0 : -1.0;
        $v[$h % $this->dimensions] += $sign * $weight;
    }

    /**
     * The stemmed words of a text, without stop words. Public for the tests.
     *
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        static $synonyms = null; // by stem, so "fridges" meets "fridge"
        if ($synonyms === null) {
            foreach (self::SYNONYMS as $word => $same) {
                $synonyms[self::stem($word)] = self::stem($same);
            }
        }

        $words = preg_split('/[^\p{L}\p{N}]+/u', Str::lower(Str::ascii($text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (strlen($w) < 2 || in_array($w, self::STOPWORDS, true)) {
                continue;
            }
            $stem = self::stem($w);
            $out[] = $synonyms[$stem] ?? $stem;
        }

        return $out;
    }

    /** A very small English stemmer: enough that plurals and -ing/-ed forms meet */
    private static function stem(string $w): string
    {
        if (strlen($w) <= 4 || ctype_digit($w)) {
            return $w;
        }
        foreach (['ing' => '', 'ed' => '', 'ies' => 'y', 'es' => '', 's' => '', 'e' => ''] as $suffix => $replace) {
            if (str_ends_with($w, $suffix) && strlen($w) - strlen($suffix) >= 3 && ! str_ends_with($w, 'ss')) {
                return substr($w, 0, -strlen($suffix)).$replace;
            }
        }

        return $w;
    }
}
