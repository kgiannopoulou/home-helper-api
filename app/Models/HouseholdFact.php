<?php

namespace App\Models;

use App\Enums\FactKind;
use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the household asked Home Helper to remember: a fact, a preference
 * or a routine (docs/memory.md#facts). Every member's assistant sees the
 * household's facts in its context; a private one only its author's.
 *
 * @property string $id
 * @property string $household_id
 * @property int|null $user_id
 * @property int|null $about_user_id
 * @property FactKind $kind
 * @property string $content
 * @property string $source person | assistant
 * @property bool $private
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $user
 * @property-read User|null $about
 */
#[Fillable(['user_id', 'about_user_id', 'kind', 'content', 'source', 'private'])]
class HouseholdFact extends Model
{
    use BelongsToHousehold, HasUlids;

    protected function casts(): array
    {
        return [
            'kind' => FactKind::class,
            'private' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function about(): BelongsTo
    {
        return $this->belongsTo(User::class, 'about_user_id');
    }

    /**
     * The household's facts this person may see: shared ones and their own private ones.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, Household $household, User $user): void
    {
        $query->where('household_id', $household->id)
            ->where(fn (Builder $q) => $q->where('private', false)->orWhere('user_id', $user->id));
    }

    public function visibleTo(User $user): bool
    {
        return $user->isMemberOf($this->household_id) && (! $this->private || $this->user_id === $user->id);
    }

    /** "Preference (about Alex): doesn't eat meat", as memory and the context write it */
    public function line(): string
    {
        return ucfirst($this->kind->value).($this->about ? " (about {$this->about->name})" : '').': '.$this->content;
    }
}
