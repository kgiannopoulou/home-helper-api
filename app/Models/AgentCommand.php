<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A command asked of a computer, from the request to the result
 * (App\Agents\Commands, agent/docs/commands.md).
 *
 * @property string $id
 * @property string $agent_id
 * @property string $household_id
 * @property int|null $requested_by
 * @property string $source dashboard|assistant
 * @property string $command_key
 * @property array<string, string> $params
 * @property bool $sensitive
 * @property string $status awaiting_confirmation|queued|sent|succeeded|failed|timed_out|rejected|expired|cancelled
 * @property int|null $confirmed_by
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $exit_code
 * @property string|null $output
 * @property bool $truncated
 * @property int|null $duration_ms
 * @property string|null $reason
 * @property CarbonImmutable $created_at
 * @property-read Agent $agent
 * @property-read User|null $requester
 * @property-read User|null $confirmer
 */
class AgentCommand extends Model
{
    use HasUlids;

    public const FINISHED = ['succeeded', 'failed', 'timed_out', 'rejected', 'expired', 'cancelled'];

    protected $guarded = ['id', 'household_id'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'sensitive' => 'boolean',
            'truncated' => 'boolean',
            'confirmed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Agent, $this> */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, self::FINISHED, true);
    }

    /** "ping_router host=192.168.1.1" */
    public function label(): string
    {
        $params = collect($this->params)->map(fn ($v, $k) => "{$k}={$v}")->implode(' ');

        return trim($this->command_key.' '.$params);
    }
}
