<?php

namespace App\SmartHome;

use App\Models\SmartDevice;

/**
 * What a device can do, read from Home Assistant's attributes rather than
 * guessed from its name: a bulb is dimmable only if its supported color
 * modes say so, whatever the box said.
 */
final class Capabilities
{
    /** Home Assistant color modes that mean "has a brightness" (everything but onoff) */
    private const DIMMABLE_MODES = ['brightness', 'color_temp', 'hs', 'xy', 'rgb', 'rgbw', 'rgbww', 'white'];

    /**
     * @return array{switchable: bool, dimmable: bool, lockable: bool, reading: bool, contact: bool, motion: bool}
     */
    public static function of(SmartDevice $device): array
    {
        $modes = (array) ($device->ha_attributes['supported_color_modes'] ?? []);

        return [
            'switchable' => in_array($device->kind, ['light', 'switch'], true),
            'dimmable' => $device->kind === 'light' && array_intersect($modes, self::DIMMABLE_MODES) !== [],
            'lockable' => $device->kind === 'lock',
            'reading' => in_array($device->kind, ['temperature', 'humidity'], true),
            'contact' => in_array($device->kind, ['door', 'window'], true),
            'motion' => $device->kind === 'motion',
        ];
    }

    /** "On", "Off", "21.5 °C", "Open", "Locked", "Unavailable" */
    public static function stateLabel(SmartDevice $device): string
    {
        if (! $device->available) {
            return $device->removed_at ? 'Removed from Home Assistant' : 'Unavailable';
        }

        return match ($device->kind) {
            'temperature', 'humidity', 'sensor' => trim(($device->state ?? '–').' '.($device->unit ?? '')),
            'door', 'window' => $device->state === 'on' ? 'Open' : 'Closed',
            'motion' => $device->state === 'on' ? 'Motion' : 'Clear',
            'lock' => ucfirst((string) $device->state),
            'light' => $device->state === 'on'
                ? 'On'.(($b = $device->brightnessPercent()) !== null && self::of($device)['dimmable'] ? " · {$b}%" : '')
                : 'Off',
            default => ucfirst((string) $device->state),
        };
    }
}
