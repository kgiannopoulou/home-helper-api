<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something that happened in the house (App\SmartHome\HomeEvents).
 *
 * @property string $id
 * @property string $household_id
 * @property string|null $device_id
 * @property string $type door.opened|door.closed|door.left_open|window.*|motion.detected|humidity.high|temperature.low|device.unavailable|device.back|hub.offline|hub.online|...
 * @property string $severity info|warning|critical
 * @property string $message
 * @property array<string, mixed>|null $data
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $notified_at
 * @property-read SmartDevice|null $device
 */
class SmartHomeEvent extends Model
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
            'data' => 'array',
            'occurred_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SmartDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(SmartDevice::class, 'device_id');
    }

    /** @return Builder<SmartHomeEvent> */
    public function prunable(): Builder
    {
        return self::query()->where('occurred_at', '<', CarbonImmutable::now()->subDays((int) config('homehelper.smart_home.events_keep_days')));
    }
}
