<?php

namespace App\SmartHome;

use App\Models\Household;
use App\Models\SmartDevice;
use Illuminate\Support\Collection;

/**
 * The house in one line, for the top of the Smart home page and the main
 * dashboard: lights on, what's open, the temperature range, the most humid
 * room, anything not answering. Read from Home Helper's copy of the states,
 * so it costs no call to Home Assistant.
 */
final class HomeSummary
{
    /**
     * @return array{connected: bool, status: string, lights_on: int, lights: int, open: list<string>, motion: list<string>, unlocked: list<string>, temperature: ?array{min: float, max: float}, humidity: ?array{max: float, room: ?string}, unavailable: list<string>}|null
     */
    public static function for(Household $household): ?array
    {
        $connection = $household->smartHomeConnection;
        if (! $connection) {
            return null;
        }
        /** @var Collection<int, SmartDevice> $devices */
        $devices = $household->smartDevices()->active()->with('room')->get();
        $available = $devices->where('available', true);
        $names = fn (Collection $c): array => array_values($c->map(fn (SmartDevice $d) => $d->name)->all());

        $lights = $devices->filter(fn (SmartDevice $d) => $d->kind === 'light' || ($d->kind === 'switch' && str_contains(mb_strtolower($d->name), 'light')));
        $temps = $available->where('kind', 'temperature')->map(fn (SmartDevice $d) => $d->numericState())->filter(fn ($v) => $v !== null);
        $humid = $available->where('kind', 'humidity')->filter(fn (SmartDevice $d) => $d->numericState() !== null)->sortByDesc(fn (SmartDevice $d) => $d->numericState())->first();

        return [
            'connected' => $connection->isConnected(),
            'status' => $connection->status,
            'lights_on' => $lights->filter(fn (SmartDevice $d) => $d->available && $d->state === 'on')->count(),
            'lights' => $lights->count(),
            'open' => $names($available->whereIn('kind', ['door', 'window'])->where('state', 'on')),
            'motion' => $names($available->where('kind', 'motion')->where('state', 'on')),
            'unlocked' => $names($available->where('kind', 'lock')->where('state', 'unlocked')),
            'temperature' => $temps->isEmpty() ? null : ['min' => (float) $temps->min(), 'max' => (float) $temps->max()],
            'humidity' => $humid ? ['max' => (float) $humid->numericState(), 'room' => $humid->room !== null ? $humid->room->name : $humid->area] : null,
            'unavailable' => $names($devices->where('available', false)),
        ];
    }
}
