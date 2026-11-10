<?php

namespace App\Models;

use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A calendar event added in the app.
 *
 * @property string $id
 * @property string $household_id
 * @property string $title
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable|null $ends_at
 * @property bool $all_day
 * @property string|null $location
 */
#[Fillable(['user_id', 'title', 'starts_at', 'ends_at', 'all_day', 'location'])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'all_day' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
