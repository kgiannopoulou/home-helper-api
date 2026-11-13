<?php

namespace App\SmartHome;

use App\Models\SmartDevice;

/**
 * A device as the dashboard, the phone API and the assistant see it: names,
 * the room, the state in words, what it can do. Never the token or raw
 * Home Assistant internals.
 */
final class DevicePresenter
{
    /** @return array<string, mixed> */
    public static function toArray(SmartDevice $device): array
    {
        return [
            'id' => $device->id,
            'entity_id' => $device->entity_id,
            'name' => $device->name,
            'kind' => $device->kind,
            'room' => $device->room !== null ? $device->room->name : $device->area,
            'room_id' => $device->room_id,
            'state' => $device->state,
            'state_label' => Capabilities::stateLabel($device),
            'on' => $device->isOn(),
            'value' => $device->numericState(),
            'unit' => $device->unit,
            'brightness' => $device->brightnessPercent(),
            'available' => $device->available,
            'imported' => $device->imported,
            'sensitive' => $device->sensitive || $device->kind === 'lock',
            'capabilities' => Capabilities::of($device),
            'last_changed_at' => $device->last_changed_at?->toIso8601String(),
        ];
    }
}
