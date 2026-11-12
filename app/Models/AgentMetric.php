<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One minute of a computer's readings, for the charts on the Computers page.
 *
 * @property int $id
 * @property string $agent_id
 * @property CarbonImmutable $recorded_at
 * @property float|null $cpu_percent
 * @property float|null $memory_percent
 * @property float|null $disk_used_percent
 * @property float|null $temperature_c
 */
class AgentMetric extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $guarded = ['id'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'cpu_percent' => 'float',
            'memory_percent' => 'float',
            'disk_used_percent' => 'float',
            'temperature_c' => 'float',
        ];
    }

    /** Older than homehelper.agents.metrics_keep_days (php artisan model:prune, daily) */
    public function prunable(): Builder
    {
        return static::query()->where('recorded_at', '<', now()->subDays((int) config('homehelper.agents.metrics_keep_days')));
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
