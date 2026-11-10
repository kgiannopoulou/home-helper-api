<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\PlantAlertType;
use App\Models\Concerns\BelongsToHousehold;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something CheckPlantCare told the household. Open until resolved_at is set.
 *
 * @property PlantAlertType $type
 * @property AlertSeverity $severity
 * @property string $title
 * @property string $message
 * @property CarbonImmutable $triggered_at
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable(['plant_id', 'type', 'severity', 'title', 'message', 'metadata', 'triggered_at', 'pushed_at', 'resolved_at'])]
class PlantAlert extends Model
{
    use BelongsToHousehold, HasUlids;

    protected function casts(): array
    {
        return [
            'type' => PlantAlertType::class,
            'severity' => AlertSeverity::class,
            'metadata' => 'array',
            'triggered_at' => 'datetime',
            'pushed_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plant, $this>
     */
    public function plant(): BelongsTo
    {
        return $this->belongsTo(Plant::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }
}
