<?php

namespace App\SmartHome;

use App\Models\SmartDevice;
use App\Models\SmartHomeConnection;
use App\SmartHome\Events\DeviceStateChanged;
use Carbon\CarbonImmutable;

/**
 * Applies one state from Home Assistant to the copy Home Helper keeps
 * (docs/smart-home.md#state-changes). The same path for a webhook, a sync and
 * the reply to a button press, so a change is announced exactly once:
 *
 *   - an older state never overwrites a newer one (the webhook and a sync
 *     can arrive in either order): Home Assistant's last_changed decides
 *   - only a different state fires DeviceStateChanged; attribute changes
 *     (brightness, a battery level) are just saved
 */
class StateChanges
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return bool whether the state changed
     */
    public function apply(SmartDevice $device, ?string $state, array $attributes, ?string $lastChanged, string $source): bool
    {
        $changedAt = $lastChanged ? CarbonImmutable::parse($lastChanged)->min(CarbonImmutable::now()) : CarbonImmutable::now();
        if ($device->last_changed_at && $changedAt->lessThan($device->last_changed_at)) {
            return false;
        }

        $from = $device->state;
        $state = $state === null ? null : mb_substr($state, 0, 255);
        $changed = $from !== $state;

        $device->forceFill([
            'state' => $state,
            'ha_attributes' => self::keep($attributes),
            'available' => ! in_array($state, SmartDevice::UNAVAILABLE, true) && $state !== null,
            'last_changed_at' => $changed || ! $device->last_changed_at ? $changedAt : $device->last_changed_at,
            'seen_at' => CarbonImmutable::now(),
        ]);
        if (isset($attributes['friendly_name']) && is_string($attributes['friendly_name'])) {
            $device->name = mb_substr($attributes['friendly_name'], 0, 120);
        }
        $device->save();

        if ($changed) {
            DeviceStateChanged::dispatch($device, $from, $state, $source);
        }

        return $changed;
    }

    /** A pushed state: find the device by entity id. Unknown entities wait for the next sync. */
    public function applyPushed(SmartHomeConnection $connection, string $entityId, ?string $state, array $attributes, ?string $lastChanged): ?SmartDevice
    {
        $device = SmartDevice::query()->where('connection_id', $connection->id)->where('entity_id', $entityId)->first();
        if (! $device) {
            return null;
        }
        $this->apply($device, $state, $attributes, $lastChanged, 'webhook');

        return $device;
    }

    /**
     * The attributes worth keeping: small values only. Some integrations put
     * whole forecasts or images into attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function keep(array $attributes): array
    {
        $kept = [];
        foreach ($attributes as $key => $value) {
            if (count($kept) >= 40) {
                break;
            }
            $json = json_encode($value);
            if ($json !== false && strlen($json) <= 500) {
                $kept[mb_substr((string) $key, 0, 60)] = $value;
            }
        }

        return $kept;
    }
}
