<?php

namespace App\Models;

use App\Enums\PlantCareType;
use App\Models\Concerns\BelongsToHousehold;
use Database\Factories\PlantCareLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One thing done to a plant (watered 350 ml), or a health note (yellow leaves).
 *
 * @property PlantCareType $type
 */
#[Fillable(['plant_id', 'user_id', 'type', 'amount_ml', 'notes', 'performed_at'])]
class PlantCareLog extends Model
{
    /** @use HasFactory<PlantCareLogFactory> */
    use BelongsToHousehold, HasFactory, HasUlids, SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => PlantCareType::class,
            'amount_ml' => 'integer',
            'performed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Logged, edited or deleted, from the API or a phone's sync (which saves
        // deletions as a deleted_at): the plant's dates follow
        $refresh = function (PlantCareLog $log) {
            $log->plant?->refreshCareDates();
            if ($log->wasChanged('plant_id') && $log->getOriginal('plant_id')) {
                Plant::find($log->getOriginal('plant_id'))?->refreshCareDates();
            }
        };
        static::saved($refresh);
        static::deleted($refresh);
    }

    /**
     * @return BelongsTo<Plant, $this>
     */
    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
