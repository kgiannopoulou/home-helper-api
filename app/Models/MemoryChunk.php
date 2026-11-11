<?php

namespace App\Models;

use App\Enums\MemorySource;
use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One searchable passage of long-term memory, with its embedding (docs/memory.md#search).
 *
 * @property int $id
 * @property string $household_id
 * @property int|null $user_id set: only this person's searches see it
 * @property MemorySource $source_type
 * @property string $source_id
 * @property int $chunk
 * @property string $title
 * @property string $text
 * @property CarbonImmutable|null $occurred_on
 * @property string $embedding packed float32
 * @property string $embedder
 */
#[Fillable(['household_id', 'user_id', 'source_type', 'source_id', 'chunk', 'title', 'text', 'occurred_on', 'embedding', 'embedder'])]
class MemoryChunk extends Model
{
    use BelongsToHousehold;

    protected function casts(): array
    {
        return [
            'source_type' => MemorySource::class,
            'occurred_on' => 'date',
        ];
    }

    /** @param list<float> $vector */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /** @return list<float> */
    public static function unpack(string $blob): array
    {
        return array_values(unpack('g*', $blob) ?: []);
    }
}
