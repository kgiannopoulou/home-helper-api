<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The household's Home Assistant (App\SmartHome\Connections, docs/smart-home.md).
 *
 * @property string $id
 * @property string $household_id
 * @property int|null $connected_by
 * @property string $base_url e.g. http://192.168.1.20:8123, no trailing slash
 * @property string $token long-lived access token, encrypted at rest
 * @property string $webhook_secret_hash sha256 of the secret Home Assistant sends back
 * @property string $status connected|unreachable|unauthorized|error
 * @property string|null $status_message
 * @property string|null $location_name
 * @property string|null $ha_version
 * @property string|null $time_zone
 * @property CarbonImmutable|null $last_ok_at
 * @property CarbonImmutable|null $last_error_at
 * @property CarbonImmutable|null $last_event_at
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Household $household
 * @property-read User|null $connector
 */
class SmartHomeConnection extends Model
{
    use HasUlids;

    protected $guarded = ['id', 'household_id'];

    protected $hidden = ['token', 'webhook_secret_hash'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_ok_at' => 'immutable_datetime',
            'last_error_at' => 'immutable_datetime',
            'last_event_at' => 'immutable_datetime',
            'last_synced_at' => 'immutable_datetime',
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
    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    /** @return HasMany<SmartDevice, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(SmartDevice::class, 'connection_id');
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    /** The bearer token Home Assistant sends with state changes: "<id>.<secret>" */
    public static function webhookToken(string $id, string $secret): string
    {
        return $id.'.'.$secret;
    }
}
