<?php

namespace App\SmartHome;

use App\Models\SmartDevice;
use App\Models\SmartDeviceReading;
use App\SmartHome\Events\DeviceStateChanged;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Temperature and humidity over time (docs/smart-home.md#temperature-and-humidity).
 *
 * Recorded from DeviceStateChanged, so every path (webhook, sync) counts.
 * A sensor that reports every 10 seconds would fill the table, so a reading
 * within a minute of the last one replaces it: one row per sensor per minute
 * at most, the latest value.
 */
class Readings
{
    public const KINDS = ['temperature', 'humidity'];

    public function handle(DeviceStateChanged $event): void
    {
        $this->record($event->device);
    }

    public function record(SmartDevice $device, ?CarbonImmutable $at = null): ?SmartDeviceReading
    {
        $value = $device->numericState();
        if (! in_array($device->kind, self::KINDS, true) || $value === null || ! $device->imported) {
            return null;
        }
        $at ??= CarbonImmutable::now();

        $last = SmartDeviceReading::query()->where('device_id', $device->id)->latest('recorded_at')->first();
        if ($last && $last->recorded_at->diffInSeconds($at) < 60) {
            $last->forceFill(['value' => $value])->save();

            return $last;
        }

        $reading = new SmartDeviceReading(['device_id' => $device->id, 'value' => $value, 'recorded_at' => $at]);
        $reading->household_id = $device->household_id;
        $reading->save();

        return $reading;
    }

    /**
     * A point for every imported sensor whose last one is older than
     * reading_every_minutes, so a steady 21 °C still draws a line. Run after
     * each sync.
     *
     * @return int points added
     */
    public function topUp(string $householdId): int
    {
        $every = (int) config('homehelper.smart_home.reading_every_minutes');
        $sensors = SmartDevice::query()->where('household_id', $householdId)->active()->whereIn('kind', self::KINDS)->where('available', true)->get();
        $latest = SmartDeviceReading::query()->whereIn('device_id', $sensors->modelKeys())
            ->selectRaw('device_id, MAX(recorded_at) AS last_at')->groupBy('device_id')->pluck('last_at', 'device_id');
        $added = 0;
        foreach ($sensors as $sensor) {
            $last = $latest[$sensor->id] ?? null;
            if ($last === null || CarbonImmutable::parse((string) $last)->addMinutes($every)->isPast()) {
                $added += $this->record($sensor) ? 1 : 0;
            }
        }

        return $added;
    }

    /**
     * The last `hours` per sensor, averaged in `bucketMinutes` buckets, as
     * chart rows: [{at, <device id>: value, ...}]
     *
     * @param  Collection<int, SmartDevice>  $sensors
     * @return list<array<string, mixed>>
     */
    public function history(Collection $sensors, int $hours = 24, int $bucketMinutes = 15): array
    {
        if ($sensors->isEmpty()) {
            return [];
        }
        $since = CarbonImmutable::now()->subHours($hours);
        $bucket = $bucketMinutes * 60;

        $rows = SmartDeviceReading::query()
            ->whereIn('device_id', $sensors->modelKeys())
            ->where('recorded_at', '>=', $since)
            ->orderBy('recorded_at')
            ->get(['device_id', 'value', 'recorded_at']);

        $sums = [];
        foreach ($rows as $r) {
            $slot = intdiv($r->recorded_at->getTimestamp(), $bucket);
            $sums[$slot][$r->device_id][] = $r->value;
        }
        ksort($sums);

        $out = [];
        foreach ($sums as $slot => $byDevice) {
            $point = ['at' => CarbonImmutable::createFromTimestamp($slot * $bucket)->toIso8601String()];
            foreach ($byDevice as $id => $values) {
                $point[$id] = round(array_sum($values) / count($values), 1);
            }
            $out[] = $point;
        }

        return $out;
    }
}
