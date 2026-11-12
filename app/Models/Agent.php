<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A computer in the household running home-helper-agent (agent/docs/design.md).
 * It authenticates with request signatures, not a Sanctum token: see
 * App\Http\Middleware\AuthenticateAgent.
 *
 * @property string $id
 * @property string $household_id
 * @property int|null $enrolled_by
 * @property string $name
 * @property string|null $hostname
 * @property string $platform
 * @property string $version
 * @property string|null $git_sha
 * @property string $secret 64 hex characters (256 bits), encrypted at rest
 * @property CarbonImmutable|null $last_seen_at
 * @property string|null $last_ip
 * @property CarbonImmutable|null $started_at
 * @property int|null $uptime_seconds
 * @property array<string, mixed>|null $metrics the latest heartbeat's readings
 * @property int $outbox_pending
 * @property int $outbox_dropped
 * @property array<string, mixed>|null $inventory
 * @property string|null $inventory_hash
 * @property CarbonImmutable|null $inventory_at
 * @property CarbonImmutable|null $revoked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Household $household
 */
class Agent extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'household_id'];

    protected $hidden = ['secret'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'last_seen_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'metrics' => 'array',
            'inventory' => 'array',
            'inventory_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Household, $this> */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /** @return BelongsTo<User, $this> */
    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    /** @return HasMany<AgentMetric, $this> */
    public function metricHistory(): HasMany
    {
        return $this->hasMany(AgentMetric::class);
    }

    /** @return HasMany<AgentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AgentEvent::class);
    }

    /** @return HasMany<AgentCommand, $this> */
    public function commands(): HasMany
    {
        return $this->hasMany(AgentCommand::class);
    }

    /** @return HasMany<AgentEnrollmentCode, $this> */
    public function enrollmentCodes(): HasMany
    {
        return $this->hasMany(AgentEnrollmentCode::class);
    }

    /** @param Builder<Agent> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /** The HMAC key: the 32 bytes behind the hex */
    public function secretBytes(): string
    {
        return (string) hex2bin($this->secret);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** Seen within the last few heartbeats (homehelper.agents.offline_after_heartbeats) */
    public function isOnline(?CarbonImmutable $now = null): bool
    {
        if ($this->isRevoked() || ! $this->last_seen_at) {
            return false;
        }
        $grace = (int) config('homehelper.agents.heartbeat_seconds') * (int) config('homehelper.agents.offline_after_heartbeats');

        return $this->last_seen_at->greaterThan(($now ?? CarbonImmutable::now())->subSeconds($grace));
    }
}
