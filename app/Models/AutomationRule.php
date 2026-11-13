<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * "When this happens, if these hold, do that" (App\SmartHome\Automation).
 *
 * @property string $id
 * @property string $household_id
 * @property int|null $created_by
 * @property string $name
 * @property bool $enabled
 * @property string|null $template the workflow it was made from (App\SmartHome\Automation\Templates)
 * @property array<string, mixed> $trigger
 * @property list<array<string, mixed>> $conditions
 * @property list<array<string, mixed>> $actions
 * @property int $cooldown_minutes
 * @property CarbonImmutable|null $last_fired_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Household $household
 */
class AutomationRule extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'household_id'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'trigger' => 'array',
            'conditions' => 'array',
            'actions' => 'array',
            'last_fired_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return HasMany<AutomationRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(AutomationRun::class, 'rule_id');
    }

    /**
     * The devices it mentions, in its trigger, conditions or actions
     *
     * @return list<string>
     */
    public function deviceIds(): array
    {
        return array_values(array_unique(array_filter([
            $this->trigger['device_id'] ?? null,
            ...array_map(fn ($c) => $c['device_id'] ?? null, $this->conditions),
            ...array_map(fn ($a) => $a['device_id'] ?? null, $this->actions),
        ])));
    }
}
