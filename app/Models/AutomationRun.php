<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One time a rule's trigger matched (App\SmartHome\Automation\RuleEngine).
 *
 * @property string $id
 * @property string $rule_id
 * @property string $household_id
 * @property string $trigger what set it off, in words
 * @property string $status done|partial|failed|skipped|awaiting_approval
 * @property string|null $reason why it was skipped
 * @property list<array<string, mixed>>|null $results one per action
 * @property CarbonImmutable $ran_at
 * @property-read AutomationRule $rule
 */
class AutomationRun extends Model
{
    use HasUlids, Prunable;

    public $timestamps = false;

    protected $guarded = ['id', 'household_id'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'results' => 'array',
            'ran_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<AutomationRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutomationRule::class, 'rule_id');
    }

    /** @return Builder<AutomationRun> */
    public function prunable(): Builder
    {
        return self::query()->where('ran_at', '<', CarbonImmutable::now()->subDays((int) config('homehelper.smart_home.events_keep_days')));
    }
}
