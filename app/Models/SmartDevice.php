<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Home Assistant entity Home Helper knows about (App\SmartHome\Discovery).
 *
 * @property string $id
 * @property string $household_id
 * @property string $connection_id
 * @property string|null $room_id
 * @property string $entity_id e.g. light.living_room_lamp
 * @property string $domain light|switch|sensor|binary_sensor|lock|cover|climate|fan
 * @property string $kind App\SmartHome\DeviceKind
 * @property string $name
 * @property string|null $area Home Assistant's area name
 * @property string|null $device_class
 * @property string|null $unit
 * @property string|null $state Home Assistant's state string: on, off, 21.5, locked, unavailable...
 * @property array<string, mixed>|null $ha_attributes Home Assistant's attributes (not `attributes`: Eloquent uses that name)
 * @property bool $available
 * @property bool $imported
 * @property CarbonImmutable|null $last_changed_at
 * @property CarbonImmutable|null $seen_at
 * @property CarbonImmutable|null $removed_at no longer in Home Assistant
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read SmartHomeConnection $connection
 * @property-read Room|null $room
 */
class SmartDevice extends Model
{
    use HasUlids;

    /** States Home Assistant uses for "can't reach it" */
    public const UNAVAILABLE = ['unavailable', 'unknown'];

    protected $guarded = ['id', 'household_id'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'ha_attributes' => 'array',
            'available' => 'boolean',
            'imported' => 'boolean',
            'last_changed_at' => 'immutable_datetime',
            'seen_at' => 'immutable_datetime',
            'removed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SmartHomeConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SmartHomeConnection::class, 'connection_id');
    }

    /** @return BelongsTo<Room, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * Imported and still in Home Assistant: what the dashboard, rules and the assistant see
     *
     * @param  Builder<SmartDevice>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('imported', true)->whereNull('removed_at');
    }

    public function isOn(): bool
    {
        return in_array($this->state, ['on', 'open', 'unlocked', 'heat', 'cool'], true);
    }

    /** The state as a number (sensors), or null */
    public function numericState(): ?float
    {
        return is_numeric($this->state) ? (float) $this->state : null;
    }

    /** Light brightness as 0-100, or null */
    public function brightnessPercent(): ?int
    {
        $b = $this->ha_attributes['brightness'] ?? null;

        return is_numeric($b) ? (int) round(((float) $b) * 100 / 255) : null;
    }
}
