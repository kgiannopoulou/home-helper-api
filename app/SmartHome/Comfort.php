<?php

namespace App\SmartHome;

use App\Models\SmartDevice;
use Illuminate\Support\Collection;

/**
 * How each room feels, from its temperature and humidity sensors
 * (docs/smart-home.md#temperature-and-humidity). Thresholds in
 * config/homehelper.php (smart_home.comfort), the usual indoor guidance:
 * 18-24 °C, 40-60 % relative humidity, mould likes it above 65 %.
 *
 * The dew point is there for the "why": at 21 °C and 70 % it is 15 °C, so a
 * cold window or outside wall below 15 °C gets wet.
 */
final class Comfort
{
    /**
     * @param  Collection<int, SmartDevice>  $devices  imported devices
     * @return list<array{room: string, temperature: ?float, humidity: ?float, dew_point: ?float, status: string, advice: ?string}>
     */
    public static function rooms(Collection $devices): array
    {
        $byRoom = $devices
            ->filter(fn (SmartDevice $d) => in_array($d->kind, Readings::KINDS, true) && $d->available && $d->numericState() !== null)
            ->groupBy(fn (SmartDevice $d) => $d->room !== null ? $d->room->name : ($d->area ?? 'No room'));

        $out = [];
        foreach ($byRoom as $room => $sensors) {
            $t = $sensors->firstWhere('kind', 'temperature')?->numericState();
            $h = $sensors->firstWhere('kind', 'humidity')?->numericState();
            [$status, $advice] = self::assess($t, $h);
            $out[] = [
                'room' => (string) $room,
                'temperature' => $t,
                'humidity' => $h,
                'dew_point' => $t !== null && $h !== null ? self::dewPoint($t, $h) : null,
                'status' => $status,
                'advice' => $advice,
            ];
        }
        usort($out, fn ($a, $b) => strcmp($a['room'], $b['room']));

        return $out;
    }

    /** @return array{0: string, 1: ?string} status: comfortable|cold|warm|humid|very_humid|dry */
    public static function assess(?float $temperature, ?float $humidity): array
    {
        $c = config('homehelper.smart_home.comfort');

        return match (true) {
            $humidity !== null && $humidity >= $c['humidity_very_high'] => ['very_humid', 'Mould risk: air it for 10 minutes or run the dehumidifier.'],
            $humidity !== null && $humidity >= $c['humidity_high'] => ['humid', 'Quite humid: open a window after showers and cooking.'],
            $temperature !== null && $temperature < $c['temperature_low'] => ['cold', 'Cold: below '.$c['temperature_low'].' °C.'],
            $temperature !== null && $temperature > $c['temperature_high'] => ['warm', 'Warm: above '.$c['temperature_high'].' °C.'],
            $humidity !== null && $humidity < $c['humidity_low'] => ['dry', 'Dry air: below '.$c['humidity_low'].' %.'],
            default => ['comfortable', null],
        };
    }

    /** Magnus formula, accurate to ~0.1 °C in the indoor range */
    public static function dewPoint(float $celsius, float $humidity): float
    {
        $a = 17.62;
        $b = 243.12;
        $gamma = log(max($humidity, 1) / 100) + ($a * $celsius) / ($b + $celsius);

        return round($b * $gamma / ($a - $gamma), 1);
    }
}
