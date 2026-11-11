<?php

namespace App\Models;

use App\Enums\NoteKind;
use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A note, or an uploaded document with its text extracted (docs/memory.md#notes-and-documents).
 * The text is cut into passages in memory_chunks, which is what the assistant searches.
 *
 * @property string $id
 * @property string $household_id
 * @property int|null $user_id
 * @property NoteKind $kind
 * @property string $title
 * @property string $body
 * @property string|null $file_path
 * @property string|null $file_name
 * @property string|null $mime
 * @property int|null $size
 * @property bool $private
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $user
 */
#[Fillable(['user_id', 'kind', 'title', 'body', 'file_path', 'file_name', 'mime', 'size', 'private'])]
class Note extends Model
{
    use BelongsToHousehold, HasUlids;

    protected function casts(): array
    {
        return [
            'kind' => NoteKind::class,
            'private' => 'boolean',
            'size' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<static> $query */
    public function scopeVisibleTo(Builder $query, Household $household, User $user): void
    {
        $query->where('household_id', $household->id)
            ->where(fn (Builder $q) => $q->where('private', false)->orWhere('user_id', $user->id));
    }

    public function visibleTo(User $user): bool
    {
        return $user->isMemberOf($this->household_id) && (! $this->private || $this->user_id === $user->id);
    }
}
