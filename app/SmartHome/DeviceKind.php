<?php

namespace App\SmartHome;

/**
 * What a Home Assistant entity is, in household words. Home Assistant says
 * "binary_sensor with device_class door"; Home Helper says "door".
 */
final class DeviceKind
{
    /** Domains discovery keeps; everything else (sun, person, todo, tts...) is ignored */
    public const DOMAINS = ['light', 'switch', 'sensor', 'binary_sensor', 'lock', 'cover', 'climate', 'fan'];

    /** Kinds that can be imported (sensor/binary_sensor of other classes are listed but not imported by default) */
    public const IMPORTABLE = ['light', 'switch', 'temperature', 'humidity', 'door', 'window', 'motion', 'lock'];

    /** Kinds you can switch from Home Helper */
    public const CONTROLLABLE = ['light', 'switch', 'lock'];

    public static function of(string $domain, ?string $deviceClass): string
    {
        return match ($domain) {
            'light', 'switch', 'lock', 'cover', 'climate', 'fan' => $domain,
            'sensor' => match ($deviceClass) {
                'temperature' => 'temperature',
                'humidity' => 'humidity',
                default => 'sensor',
            },
            'binary_sensor' => match ($deviceClass) {
                'door', 'garage_door' => 'door',
                'window', 'opening' => 'window',
                'motion', 'occupancy', 'presence' => 'motion',
                default => 'binary_sensor',
            },
            default => 'other',
        };
    }

    public static function domainOf(string $entityId): string
    {
        return strstr($entityId, '.', true) ?: '';
    }

    public static function isSupported(string $entityId): bool
    {
        return in_array(self::domainOf($entityId), self::DOMAINS, true);
    }
}
