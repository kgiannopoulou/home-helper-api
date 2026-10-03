<?php

namespace App\Models;

use App\Enums\Intensity;
use App\Enums\WorkoutSource;
use App\Enums\WorkoutType;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\WorkoutFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A workout one person did.
 */
#[Fillable(['user_id', 'date', 'type', 'minutes', 'intensity', 'kcal', 'source', 'notes'])]
class Workout extends Model
{
    /** @use HasFactory<WorkoutFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'type' => WorkoutType::class,
            'minutes' => 'integer',
            'intensity' => Intensity::class,
            'kcal' => 'integer',
            'source' => WorkoutSource::class,
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
