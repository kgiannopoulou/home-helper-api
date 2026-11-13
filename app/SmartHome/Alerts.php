<?php

namespace App\SmartHome;

use App\Models\SmartDevice;
use App\Models\SmartDeviceReading;
use App\Models\SmartHomeEvent;
use App\SmartHome\Events\DeviceStateChanged;

/**
 * Device state changes that are worth an event besides doors and windows
 * (docs/smart-home.md#events):
 *
 *   device.unavailable / device.back   a device stopped or started answering
 *   humidity.high                      crossed homehelper.smart_home.comfort.humidity_very_high, upwards
 *   temperature.low                    crossed comfort.temperature_low, downwards
 *
 * Crossings, not levels: a bathroom sitting at 75 % is one event, not one
 * per reading. Back below the line (with a 3-point margin, so a sensor
 * wobbling at 70/71 doesn't count) arms it again.
 */
class Alerts
{
    private const MARGIN = 3.0;

    public function __construct(private HomeEvents $events) {}

    public function handle(DeviceStateChanged $event): void
    {
        $device = $event->device;
        if (! $device->imported) {
            return;
        }
        $wasDown = in_array($event->from, SmartDevice::UNAVAILABLE, true);
        $isDown = in_array($event->to, SmartDevice::UNAVAILABLE, true);

        if ($isDown && ! $wasDown && $event->from !== null) {
            $this->events->record($device->household_id, $device, 'device.unavailable', 'warning', "{$device->name} stopped answering");

            return;
        }
        if ($wasDown && ! $isDown) {
            $this->events->record($device->household_id, $device, 'device.back', 'info', "{$device->name} is answering again");

            return;
        }

        $from = is_numeric($event->from) ? (float) $event->from : null;
        $to = is_numeric($event->to) ? (float) $event->to : null;
        if ($to === null) {
            return;
        }
        $c = config('homehelper.smart_home.comfort');
        $room = $device->room !== null ? $device->room->name : ($device->area ?? $device->name);

        if ($device->kind === 'humidity' && $to >= $c['humidity_very_high'] && $this->armed($device, 'humidity.high', fn (float $v) => $v < $c['humidity_very_high'] - self::MARGIN, $from)) {
            $this->events->record($device->household_id, $device, 'humidity.high', 'warning', "{$room}: humidity {$to} %. Air it or run the dehumidifier.", ['value' => $to]);
        }
        if ($device->kind === 'temperature' && $to < $c['temperature_low'] && $this->armed($device, 'temperature.low', fn (float $v) => $v >= $c['temperature_low'] + self::MARGIN / 3, $from)) {
            $this->events->record($device->household_id, $device, 'temperature.low', 'warning', "{$room} is down to {$to} °C", ['value' => $to]);
        }
    }

    /**
     * Fire again only if the value has been back on the good side since the
     * last alert of this type (or there never was one)
     *
     * @param  callable(float): bool  $good
     */
    private function armed(SmartDevice $device, string $type, callable $good, ?float $from): bool
    {
        $last = SmartHomeEvent::query()->where('device_id', $device->id)->where('type', $type)->latest('occurred_at')->first();
        if (! $last || ($from !== null && $good($from))) {
            return true;
        }

        // A reading on the good side since the last alert re-arms it
        return SmartDeviceReading::query()
            ->where('device_id', $device->id)
            ->where('recorded_at', '>', $last->occurred_at)
            ->pluck('value')
            ->contains(fn ($v) => $good((float) $v));
    }
}
