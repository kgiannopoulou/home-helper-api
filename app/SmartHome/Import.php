<?php

namespace App\SmartHome;

use App\Models\Household;
use App\Models\SmartDevice;
use App\Models\User;
use App\Policies\SmartHomePolicy;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Choosing which discovered devices Home Helper uses (docs/smart-home.md#importing).
 *
 * A Home Assistant has dozens of entities nobody wants on a household
 * dashboard (backup sensors, sun times, a router's uptime). Nothing is
 * imported by itself: the owner picks lights, switches and sensors from the
 * discovered list, and can take them out again. Taking one out keeps its
 * row, so its history and the rules that mention it survive a re-import.
 */
class Import
{
    public function __construct(private Readings $readings) {}

    /**
     * Discovered, not imported, and of a kind Home Helper can use
     *
     * @return Collection<int, SmartDevice>
     */
    public function candidates(Household $household): Collection
    {
        return SmartDevice::query()
            ->where('household_id', $household->id)
            ->where('imported', false)
            ->whereNull('removed_at')
            ->whereIn('kind', DeviceKind::IMPORTABLE)
            ->orderBy('area')->orderBy('name')
            ->get();
    }

    /**
     * @param  list<string>  $deviceIds
     * @return int how many were imported
     *
     * @throws ValidationException
     */
    public function import(Household $household, User $user, array $deviceIds): int
    {
        $this->authorize($household, $user);
        $devices = SmartDevice::query()->where('household_id', $household->id)->whereIn('id', $deviceIds)->whereNull('removed_at')->get();

        $refused = $devices->reject(fn (SmartDevice $d) => in_array($d->kind, DeviceKind::IMPORTABLE, true));
        if ($refused->isNotEmpty() || $devices->count() !== count(array_unique($deviceIds))) {
            throw ValidationException::withMessages(['devices' => 'Only lights, switches, locks, temperature, humidity, door, window and motion sensors can be imported.']);
        }

        $count = SmartDevice::query()->whereIn('id', $devices->modelKeys())->where('imported', false)->update(['imported' => true]);
        // A first point for each sensor, so its chart starts now
        $this->readings->topUp($household->id);
        Log::info('Smart devices imported', ['household_id' => $household->id, 'user_id' => $user->id, 'count' => $count]);

        return $count;
    }

    public function remove(SmartDevice $device, User $user): void
    {
        $this->authorize($device->connection->household, $user);
        $device->forceFill(['imported' => false])->save();
    }

    private function authorize(Household $household, User $user): void
    {
        if (! (new SmartHomePolicy)->manage($user, $household)) {
            throw ValidationException::withMessages(['devices' => 'Only the household owner chooses the devices.']);
        }
    }
}
