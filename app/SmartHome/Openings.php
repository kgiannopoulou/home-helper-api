<?php

namespace App\SmartHome;

use App\Models\SmartDevice;
use App\Models\SmartHomeEvent;
use App\SmartHome\Events\DeviceStateChanged;
use Carbon\CarbonImmutable;

/**
 * Doors, windows and motion (docs/smart-home.md#doors-and-windows).
 *
 * A contact sensor's binary_sensor is "on" when open. This turns state
 * changes into events people understand ("Front door opened", "closed after
 * 4 min"), and checks every minute for a door or window left open longer
 * than homehelper.smart_home.left_open_minutes: one warning per opening.
 *
 * Changes to or from "unavailable" are not openings: a sensor with a flat
 * battery didn't open the door. App\SmartHome\Notifications handles those.
 */
class Openings
{
    public const KINDS = ['door', 'window'];

    public function __construct(private HomeEvents $events) {}

    public function handle(DeviceStateChanged $event): void
    {
        $device = $event->device;
        if (! $device->imported || in_array($event->from, SmartDevice::UNAVAILABLE, true) || in_array($event->to, SmartDevice::UNAVAILABLE, true) || $event->from === null) {
            return;
        }

        if (in_array($device->kind, self::KINDS, true)) {
            if ($event->to === 'on') {
                $this->events->record($device->household_id, $device, "{$device->kind}.opened", 'info', "{$device->name} opened");
            } elseif ($event->to === 'off') {
                $openFor = $this->openFor($device);
                $this->events->record($device->household_id, $device, "{$device->kind}.closed", 'info',
                    "{$device->name} closed".($openFor !== null ? ' after '.self::duration($openFor) : ''),
                    $openFor !== null ? ['open_seconds' => $openFor] : []);
            }
        } elseif ($device->kind === 'motion' && $event->to === 'on') {
            $this->events->record($device->household_id, $device, 'motion.detected', 'info', 'Motion: '.$device->name);
        }
    }

    /**
     * The scheduled check (smart-home:check-openings, every minute)
     *
     * @return int warnings recorded
     */
    public function checkLeftOpen(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $limits = config('homehelper.smart_home.left_open_minutes');
        $warned = 0;

        $open = SmartDevice::query()->active()->whereIn('kind', self::KINDS)->where('state', 'on')->where('available', true)->get();
        foreach ($open as $device) {
            $limit = (int) ($limits[$device->kind] ?? 30);
            if (! $device->last_changed_at || $device->last_changed_at->addMinutes($limit)->greaterThan($now)) {
                continue;
            }
            if ($this->events->since($device, "{$device->kind}.left_open", $device->last_changed_at)) {
                continue;
            }
            $minutes = (int) $device->last_changed_at->diffInMinutes($now);
            $this->events->record($device->household_id, $device, "{$device->kind}.left_open", 'warning',
                "{$device->name} has been open for ".self::duration($minutes * 60), ['open_minutes' => $minutes]);
            $warned++;
        }

        return $warned;
    }

    /**
     * Doors and windows for the page: open now and for how long, openings today
     *
     * @param  iterable<SmartDevice>  $devices  imported devices
     * @return array<int, array{id: string, name: string, kind: string, room: ?string, open: bool, available: bool, since: ?string, openings_today: int}>
     */
    public function overview(iterable $devices, CarbonImmutable $startOfDay): array
    {
        $contacts = collect($devices)->filter(fn (SmartDevice $d) => in_array($d->kind, self::KINDS, true))->values();
        $counts = SmartHomeEvent::query()
            ->whereIn('device_id', $contacts->pluck('id'))
            ->whereIn('type', ['door.opened', 'window.opened'])
            ->where('occurred_at', '>=', $startOfDay)
            ->selectRaw('device_id, COUNT(*) AS n')->groupBy('device_id')->pluck('n', 'device_id');

        return $contacts->map(fn (SmartDevice $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'kind' => $d->kind,
            'room' => $d->room !== null ? $d->room->name : $d->area,
            'open' => $d->available && $d->state === 'on',
            'available' => $d->available,
            'since' => $d->last_changed_at?->toIso8601String(),
            'openings_today' => (int) ($counts[$d->id] ?? 0),
        ])->sortByDesc('open')->values()->all();
    }

    /** How long it has been open, from the last "opened" event */
    private function openFor(SmartDevice $device): ?int
    {
        $opened = SmartHomeEvent::query()->where('device_id', $device->id)->where('type', "{$device->kind}.opened")->latest('occurred_at')->first();

        return $opened ? (int) $opened->occurred_at->diffInSeconds(CarbonImmutable::now()) : null;
    }

    /** 45 → "45 s", 240 → "4 min", 5400 → "1 h 30 min" */
    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} s";
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return "{$minutes} min";
        }
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;

        return $m ? "{$h} h {$m} min" : "{$h} h";
    }
}
