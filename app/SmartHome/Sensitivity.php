<?php

namespace App\SmartHome;

use App\Models\SmartDevice;

/**
 * What needs the owner's approval (docs/smart-home.md#sensitive-actions).
 *
 * Only the risky direction: unlocking, not locking; switching a heater on,
 * not off. Turning something off or locking up is always allowed, so a rule
 * or a member can make the house safer without waiting for anyone.
 */
final class Sensitivity
{
    /** Names of things that heat, burn or flood when left on */
    private const RISKY_NAMES = '/coffee|kettle|oven|stove|hob|heater|radiator|iron|boiler|water ?heater|thermo|sauna|grill|toaster|washing|dishwasher|pump/i';

    /** Sensitive from the start: every lock, garage doors and gates, climate, risky plugs */
    public static function byDefault(SmartDevice $device): bool
    {
        return match ($device->kind) {
            'lock', 'climate' => true,
            'cover' => in_array($device->device_class, ['garage', 'gate', 'door'], true),
            'switch' => (bool) preg_match(self::RISKY_NAMES, $device->name),
            default => false,
        };
    }

    /** Locks can't be made "not sensitive": an unlocked door is the point */
    public static function canChange(SmartDevice $device): bool
    {
        return $device->kind !== 'lock';
    }

    public static function needsApproval(SmartDevice $device, string $action): bool
    {
        if (! $device->sensitive && $device->kind !== 'lock') {
            return false;
        }

        return match ($device->kind) {
            'lock' => $action === 'unlock',
            'cover' => in_array($action, ['open', 'toggle'], true),
            default => in_array($action, ['turn_on', 'toggle', 'set_brightness'], true),
        };
    }
}
