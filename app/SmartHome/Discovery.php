<?php

namespace App\SmartHome;

use App\Models\Room;
use App\Models\SmartDevice;
use App\Models\SmartHomeConnection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Finds the household's devices in Home Assistant and keeps the list in step
 * (docs/smart-home.md#discovery-and-sync):
 *
 *   - every entity in a domain Home Helper understands becomes a row, new
 *     ones not imported until the owner says so
 *   - its Home Assistant area is matched to a Home Helper room by name
 *   - an entity that disappeared is marked removed (kept, so rules and
 *     history that mention it still make sense), and comes back if it returns
 *   - every state goes through StateChanges, so a change found by a sync is
 *     announced like one pushed by the webhook
 *
 * One call to /api/states and one template render, however many devices.
 */
class Discovery
{
    private const AREAS_TEMPLATE = <<<'JINJA'
        {%- set ns = namespace(out={}) -%}
        {%- for s in states if s.domain in DOMAINS and area_name(s.entity_id) -%}
        {%- set ns.out = dict(ns.out, **{s.entity_id: area_name(s.entity_id)}) -%}
        {%- endfor -%}
        {{ ns.out | to_json }}
        JINJA;

    public function __construct(private Connections $connections, private StateChanges $changes) {}

    /**
     * @return array{found: int, new: int, removed: int, changed: int}
     *
     * @throws HomeAssistantError
     */
    public function sync(SmartHomeConnection $connection): array
    {
        $client = $this->connections->client($connection);
        try {
            $states = $client->states();
        } catch (HomeAssistantError $e) {
            $this->connections->failed($connection, $e);
            throw $e;
        }
        $areas = $this->areas($client);
        $this->connections->succeeded($connection);

        $existing = SmartDevice::query()->where('connection_id', $connection->id)->get()->keyBy('entity_id');
        $rooms = $this->rooms($connection);
        $now = CarbonImmutable::now();
        $seen = [];
        $new = $changed = 0;

        foreach ($states as $s) {
            $entityId = (string) $s['entity_id'];
            if (! DeviceKind::isSupported($entityId) || ! preg_match('/^[a-z_]+\.[a-z0-9_]+$/', $entityId)) {
                continue;
            }
            $seen[] = $entityId;
            $attributes = is_array($s['attributes'] ?? null) ? $s['attributes'] : [];
            $domain = DeviceKind::domainOf($entityId);
            $deviceClass = isset($attributes['device_class']) && is_string($attributes['device_class']) ? mb_substr($attributes['device_class'], 0, 40) : null;
            $area = $areas[$entityId] ?? null;

            $device = $existing->get($entityId);
            if (! $device) {
                $device = new SmartDevice(['connection_id' => $connection->id, 'entity_id' => $entityId, 'imported' => false]);
                $device->household_id = $connection->household_id;
                $new++;
            }
            $device->forceFill([
                'domain' => $domain,
                'kind' => DeviceKind::of($domain, $deviceClass),
                'name' => mb_substr((string) ($attributes['friendly_name'] ?? $entityId), 0, 120),
                'device_class' => $deviceClass,
                'unit' => isset($attributes['unit_of_measurement']) ? mb_substr((string) $attributes['unit_of_measurement'], 0, 20) : null,
                'area' => $area ?? $device->area,
                'room_id' => $area !== null ? ($rooms[self::roomKey($area)] ?? null) : $device->room_id,
                'removed_at' => null,
            ]);
            if (! $device->exists) {
                // Found, not changed: no DeviceStateChanged for a device that just appeared
                $device->forceFill([
                    'state' => mb_substr((string) $s['state'], 0, 255),
                    'ha_attributes' => StateChanges::keep($attributes),
                    'available' => ! in_array($s['state'], SmartDevice::UNAVAILABLE, true),
                    'last_changed_at' => isset($s['last_changed']) ? CarbonImmutable::parse((string) $s['last_changed'])->min($now) : $now,
                    'seen_at' => $now,
                ])->save();

                continue;
            }
            if ($this->changes->apply($device, (string) $s['state'], $attributes, isset($s['last_changed']) ? (string) $s['last_changed'] : null, 'sync')) {
                $changed++;
            }
        }

        $removed = SmartDevice::query()
            ->where('connection_id', $connection->id)
            ->whereNull('removed_at')
            ->whereNotIn('entity_id', $seen ?: [''])
            ->update(['removed_at' => $now->format('Y-m-d H:i:s.v'), 'available' => false, 'updated_at' => $now->format('Y-m-d H:i:s.v')]);

        $connection->forceFill(['last_synced_at' => $now])->save();

        return ['found' => count($seen), 'new' => $new, 'removed' => $removed, 'changed' => $changed];
    }

    /**
     * entity_id => area name. Not fatal: an old Home Assistant without
     * area_name() just means no rooms until it's updated.
     *
     * @return array<string, string>
     */
    private function areas(HomeAssistant $client): array
    {
        try {
            $json = json_decode($client->render(str_replace('DOMAINS', (string) json_encode(DeviceKind::DOMAINS), self::AREAS_TEMPLATE)), true);
        } catch (HomeAssistantError $e) {
            Log::info('Home Assistant areas unavailable', ['error' => $e->getMessage()]);

            return [];
        }

        return is_array($json) ? array_map(fn ($a) => mb_substr((string) $a, 0, 120), array_filter($json, 'is_string')) : [];
    }

    /** @return array<string, string> normalized room name => room id */
    private function rooms(SmartHomeConnection $connection): array
    {
        return Room::query()->where('household_id', $connection->household_id)->get(['id', 'name'])
            ->mapWithKeys(fn (Room $r) => [self::roomKey($r->name) => $r->id])->all();
    }

    /** "Living Room" and "living room" are the same room */
    public static function roomKey(string $name): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($name)) ?? '';
    }
}
