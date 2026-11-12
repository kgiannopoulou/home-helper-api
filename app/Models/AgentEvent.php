<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that happened on a computer, or that the server noticed about it
 * (App\Agents\AgentEvents).
 *
 * @property string $id
 * @property string $agent_id
 * @property string $household_id
 * @property string $event_uuid
 * @property string $type
 * @property string $severity info|warning|critical
 * @property string $message
 * @property array<string, mixed>|null $data
 * @property string $source agent|server
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $received_at
 * @property-read Agent $agent
 */
final class AgentEvent extends Model
{
    use HasUlids;
    use MassPrunable;

    public $timestamps = false;

    protected $guarded = ['id', 'household_id'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Kept for 90 days (php artisan model:prune, daily)
     *
     * @return Builder<AgentEvent>
     */
    public function prunable(): Builder
    {
        return self::query()->where('occurred_at', '<', now()->subDays(90));
    }
}
