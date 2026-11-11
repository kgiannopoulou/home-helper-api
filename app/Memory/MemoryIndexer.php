<?php

namespace App\Memory;

use App\Enums\MemorySource;
use App\Enums\NoteKind;
use App\Models\AiConversation;
use App\Models\HouseholdFact;
use App\Models\HouseholdSummary;
use App\Models\MemoryChunk;
use App\Models\Note;
use App\Models\VoiceSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Keeps memory_chunks in step with what it remembers (docs/memory.md#indexing).
 * Each source becomes one or more passages with an embedding; indexing the
 * same source again replaces its passages, and forget() removes them.
 *
 * Called from the models' observers through the IndexMemory job, and by
 * `php artisan memory:reindex` after the embedder changes.
 */
class MemoryIndexer
{
    public function __construct(private Embedder $embedder) {}

    public function index(Model $source): int
    {
        $passages = $this->passages($source);
        if ($passages === null) {
            return 0;
        }
        [$type, $meta, $texts] = $passages;

        $vectors = $texts === [] ? [] : $this->embedder->embed(array_map(fn (array $t) => $t['title']."\n".$t['text'], $texts));

        DB::transaction(function () use ($source, $type, $meta, $texts, $vectors) {
            MemoryChunk::query()->where('source_type', $type)->where('source_id', $source->getKey())->delete();
            $now = now();
            foreach ($texts as $i => $t) {
                MemoryChunk::query()->insert([
                    'household_id' => $meta['household_id'],
                    'user_id' => $meta['user_id'],
                    'source_type' => $type->value,
                    'source_id' => $source->getKey(),
                    'chunk' => $i,
                    'title' => mb_substr($t['title'], 0, 200),
                    'text' => $t['text'],
                    'occurred_on' => $meta['occurred_on'],
                    'embedding' => MemoryChunk::pack($vectors[$i]),
                    'embedder' => $this->embedder->name(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });

        return count($texts);
    }

    private static function remembered(AiConversation $conversation): bool
    {
        return $conversation->channel !== 'voice'
            || ! VoiceSettings::query()->where('user_id', $conversation->user_id)->where('remember', false)->exists();
    }

    public function forget(MemorySource $type, string $id): void
    {
        MemoryChunk::query()->where('source_type', $type)->where('source_id', $id)->delete();
    }

    /**
     * What a source looks like in memory: its type, who may see it, and its passages.
     *
     * @return array{0: MemorySource, 1: array{household_id: string, user_id: int|null, occurred_on: string|null}, 2: list<array{title: string, text: string}>}|null
     */
    private function passages(Model $source): ?array
    {
        return match (true) {
            $source instanceof Note => [
                $source->kind === NoteKind::Document ? MemorySource::Document : MemorySource::Note,
                ['household_id' => $source->household_id, 'user_id' => $source->private ? $source->user_id : null, 'occurred_on' => $source->updated_at->toDateString()],
                array_map(fn (string $text) => ['title' => $source->title, 'text' => $text], self::chunks($source->body, (int) config('homehelper.memory.chunk_chars'))),
            ],
            $source instanceof HouseholdFact => [
                MemorySource::Fact,
                ['household_id' => $source->household_id, 'user_id' => $source->private ? $source->user_id : null, 'occurred_on' => $source->created_at->toDateString()],
                [['title' => 'Remembered '.$source->kind->value, 'text' => $source->line()]],
            ],
            $source instanceof HouseholdSummary => [
                MemorySource::Summary,
                ['household_id' => $source->household_id, 'user_id' => null, 'occurred_on' => $source->starts_on->toDateString()],
                [['title' => 'Household summary: '.$source->label(), 'text' => $source->text]],
            ],
            // Conversations are private: only their person's searches find them
            $source instanceof AiConversation => [
                MemorySource::Conversation,
                ['household_id' => $source->household_id, 'user_id' => $source->user_id, 'occurred_on' => $source->updated_at->toDateString()],
                // A voice conversation stays out of memory if its person said so (docs/voice.md#privacy)
                $source->summary && self::remembered($source)
                    ? array_map(fn (string $text) => ['title' => 'Conversation: '.($source->title ?? 'untitled').' ('.$source->updated_at->format('j M Y').')', 'text' => $text], self::chunks($source->summary, (int) config('homehelper.memory.chunk_chars')))
                    : [],
            ],
            default => null,
        };
    }

    /**
     * Cuts text into passages of about $size characters, at paragraph breaks
     * where it can, then at sentence ends. Public for the tests.
     *
     * @return list<string>
     */
    public static function chunks(string $text, int $size = 900): array
    {
        $text = trim(preg_replace("/\r\n?/", "\n", $text) ?? $text);
        if ($text === '') {
            return [];
        }

        $pieces = [];
        foreach (preg_split("/\n\s*\n/", $text) ?: [] as $paragraph) {
            $paragraph = trim($paragraph);
            if (mb_strlen($paragraph) <= $size) {
                $pieces[] = $paragraph;

                continue;
            }
            // A long paragraph: by sentence, and a sentence longer than a chunk by force
            foreach (preg_split('/(?<=[.!?])\s+/', $paragraph) ?: [] as $sentence) {
                foreach (mb_str_split($sentence, max(1, $size)) as $part) {
                    $pieces[] = $part;
                }
            }
        }

        $chunks = [];
        $current = '';
        foreach ($pieces as $piece) {
            if ($current !== '' && mb_strlen($current) + mb_strlen($piece) + 2 > $size) {
                $chunks[] = $current;
                $current = '';
            }
            $current = $current === '' ? $piece : $current."\n\n".$piece;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
