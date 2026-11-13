<?php

namespace App\SmartHome\Automation;

use App\Models\Household;
use App\Models\SmartDevice;
use App\Support\HomeTime;
use Carbon\CarbonImmutable;

/**
 * Whether a rule's conditions hold right now. Reads only Home Helper's copy
 * of the states and the clock: no call to Home Assistant, so a rule decides
 * in milliseconds and the same way in tests.
 */
final class Conditions
{
    /**
     * @param  list<array<string, mixed>>  $conditions
     * @param  array<string, SmartDevice>  $devices  by id
     * @return string|null why not, or null if they all hold
     */
    public static function failing(array $conditions, Household $household, array $devices, CarbonImmutable $now): ?string
    {
        $local = $now->setTimezone(HomeTime::zone());

        foreach ($conditions as $c) {
            $device = isset($c['device_id']) ? ($devices[$c['device_id']] ?? null) : null;
            $why = match ($c['type']) {
                'time' => self::between($local->format('H:i'), $c['after'], $c['before']) ? null : "not between {$c['after']} and {$c['before']}",
                'days' => in_array($local->isoWeekday(), $c['days'], true) ? null : 'not today',
                'sun' => self::sunIsUp($household, $now) === ($c['is'] === 'up') ? null : "the sun is not {$c['is']}",
                'state' => match (true) {
                    ! $device => 'a device in it is gone',
                    $device->state !== $c['is'] => "{$device->name} is not {$c['is']}",
                    default => null,
                },
                'numeric' => match (true) {
                    ! $device || $device->numericState() === null => 'no reading',
                    isset($c['above']) && $device->numericState() <= $c['above'] => "{$device->name} is not above {$c['above']}",
                    isset($c['below']) && $device->numericState() >= $c['below'] => "{$device->name} is not below {$c['below']}",
                    default => null,
                },
                default => 'unknown condition',
            };
            if ($why !== null) {
                return $why;
            }
        }

        return null;
    }

    /** "23:00" to "06:00" wraps midnight */
    public static function between(string $time, string $after, string $before): bool
    {
        return $after <= $before ? ($time >= $after && $time < $before) : ($time >= $after || $time < $before);
    }

    /**
     * From the household's location when it has one (PHP's own solar
     * tables, no network), otherwise 07:00-18:00 at home.
     */
    public static function sunIsUp(Household $household, CarbonImmutable $now): bool
    {
        if (! $household->hasLocation()) {
            return self::between($now->setTimezone(HomeTime::zone())->format('H:i'), '07:00', '18:00');
        }
        $sun = date_sun_info($now->getTimestamp(), (float) $household->latitude, (float) $household->longitude);
        $rise = $sun['sunrise'];
        $set = $sun['sunset'];
        if (! is_int($rise) || ! is_int($set)) {
            // Polar day or night
            return $rise === true;
        }

        return $now->getTimestamp() >= $rise && $now->getTimestamp() < $set;
    }
}
