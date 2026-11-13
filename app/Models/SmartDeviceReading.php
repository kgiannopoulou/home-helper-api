<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One temperature or humidity reading (App\SmartHome\Readings).
 *
 * @property int $id
 * @property string $device_id
 * @property string $household_id
 * @property float $value
 * @property CarbonImmutable $recorded_at
 * @property-read SmartDevice $device
 */
class SmartDeviceReading extends Model
{
    use Prunable;

    public $timestamps = false;

    protected $guarded = ['id', 'household_id'];

    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.v';
    }

    protected function casts(): array
    {
        return [
            'value' => 'float',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SmartDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(SmartDevice::class, 'device_id');
    }

    /** @return Builder<SmartDeviceReading> */
    public function prunable(): Builder
    {
        return self::query()->where('recorded_at', '<', CarbonImmutable::now()->subDays((int) config('homehelper.smart_home.readings_keep_days')));
    }
}
