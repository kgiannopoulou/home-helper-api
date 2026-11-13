<?php

namespace App\SmartHome;

use App\Models\SmartDevice;
use App\Models\SmartHomeEvent;
use App\SmartHome\Events\HomeEventRecorded;
use Carbon\CarbonImmutable;

/**
 * The house's event log (docs/smart-home.md#events). One place records
 * events, so notifications and rules see every one of them, whoever noticed.
 */
class HomeEvents
{
    /** @param  array<string, mixed>  $data */
    public function record(string $householdId, ?SmartDevice $device, string $type, string $severity, string $message, array $data = [], ?CarbonImmutable $at = null): SmartHomeEvent
    {
        $event = new SmartHomeEvent([
            'device_id' => $device?->id,
            'type' => $type,
            'severity' => $severity,
            'message' => mb_substr($message, 0, 300),
            'data' => $data ?: null,
            'occurred_at' => $at ?? CarbonImmutable::now(),
        ]);
        $event->household_id = $householdId;
        $event->save();
        if ($device) {
            $event->setRelation('device', $device);
        }

        HomeEventRecorded::dispatch($event);

        return $event;
    }

    /** Has this device had this kind of event since then? (one "left open" per opening) */
    public function since(SmartDevice $device, string $type, CarbonImmutable $since): bool
    {
        return SmartHomeEvent::query()
            ->where('device_id', $device->id)
            ->where('type', $type)
            ->where('occurred_at', '>=', $since)
            ->exists();
    }
}
