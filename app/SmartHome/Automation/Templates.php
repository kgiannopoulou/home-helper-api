<?php

namespace App\SmartHome\Automation;

use App\Models\Household;
use App\Models\SmartDevice;
use Illuminate\Support\Collection;

/**
 * Ready-made household workflows (docs/smart-home.md#first-workflows). Each
 * one names the kinds of device it needs ("a door", "a light"), picks the
 * household's best match, and builds ordinary rules from them: they go
 * through RuleValidator and run in RuleEngine like any other rule.
 */
final class Templates
{
    /**
     * key => name, description, the roles it needs (role => kinds, and words
     * that make a device a better match)
     */
    public const ALL = [
        'welcome_light' => [
            'name' => 'Light on when you come home after dark',
            'description' => 'The front door opens after sunset: the hall light comes on.',
            'needs' => ['door' => [['door'], ['front', 'entrance', 'main']], 'light' => [['light', 'switch'], ['hall', 'entrance', 'light']]],
        ],
        'dehumidifier' => [
            'name' => 'Dehumidifier after showers',
            'description' => 'Bathroom humidity above 70 %: the dehumidifier plug goes on. Below 60 %: off again.',
            'needs' => ['humidity' => [['humidity'], ['bath', 'shower']], 'plug' => [['switch'], ['dehumid', 'fan', 'plug']]],
        ],
        'good_night' => [
            'name' => 'Good night',
            'description' => 'At 00:30, every light Home Helper knows goes off.',
            'needs' => ['lights' => [['light', 'switch'], ['light', 'lamp', 'ceiling']]],
        ],
        'night_window' => [
            'name' => 'A window opens at night',
            'description' => 'A window or the balcony door opens between 23:00 and 06:00: everyone gets a notification.',
            'needs' => ['opening' => [['window', 'door'], ['window', 'balcony']]],
        ],
        'motion_light' => [
            'name' => 'Hall light follows motion after dark',
            'description' => 'Motion in the hall after sunset turns its light on; when it is clear again, off.',
            'needs' => ['motion' => [['motion'], ['hall', 'entrance']], 'light' => [['light', 'switch'], ['hall', 'entrance']]],
        ],
        'cold_window' => [
            'name' => 'Window open and the room is getting cold',
            'description' => 'The bedroom drops below 18 °C while its window is open: a reminder to close it.',
            'needs' => ['temperature' => [['temperature'], ['bed']], 'window' => [['window'], ['bed']]],
        ],
    ];

    /** Lights a "good night" rule turns off at most (a rule has five actions) */
    private const MAX_LIGHTS = 5;

    /**
     * Each template with the devices it would use, or why it can't be made
     *
     * @return list<array{key: string, name: string, description: string, ready: bool, devices: array<string, array<int, array{id: string, name: string}>>, missing: list<string>, added: bool}>
     */
    public static function suggestions(Household $household): array
    {
        $devices = SmartDevice::query()->where('household_id', $household->id)->active()->with('room')->get();
        $added = $household->automationRules()->whereNotNull('template')->pluck('template')->all();
        $out = [];

        foreach (self::ALL as $key => $t) {
            $picked = [];
            $missing = [];
            foreach ($t['needs'] as $role => [$kinds, $words]) {
                $matches = self::rank($devices, $kinds, $words);
                if ($matches->isEmpty()) {
                    $missing[] = $role;

                    continue;
                }
                $chosen = $role === 'lights' ? $matches->take(self::MAX_LIGHTS) : $matches->take(1);
                $picked[$role] = $chosen->map(fn (SmartDevice $d) => ['id' => $d->id, 'name' => $d->name])->values()->all();
            }
            // Two roles can't be the same device (a lamp is not its own trigger)
            $ids = collect($picked)->flatten(1)->pluck('id');
            $ready = $missing === [] && $ids->count() === $ids->unique()->count();

            $out[] = [
                'key' => $key,
                'name' => $t['name'],
                'description' => $t['description'],
                'ready' => $ready,
                'devices' => $picked,
                'missing' => $missing,
                'added' => in_array($key, $added, true),
            ];
        }

        return $out;
    }

    /**
     * The rules a template makes, as RuleValidator input
     *
     * @param  array<string, list<string>>  $devices  role => device ids
     * @return list<array<string, mixed>>
     */
    public static function rules(string $key, array $devices): array
    {
        $one = fn (string $role) => $devices[$role][0] ?? null;

        return match ($key) {
            'welcome_light' => [[
                'name' => 'Welcome light',
                'trigger' => ['type' => 'state', 'device_id' => $one('door'), 'to' => 'on'],
                'conditions' => [['type' => 'sun', 'is' => 'down']],
                'actions' => [['type' => 'device', 'device_id' => $one('light'), 'action' => 'turn_on']],
                'cooldown_minutes' => 5,
            ]],
            'dehumidifier' => [[
                'name' => 'Dehumidifier on',
                'trigger' => ['type' => 'numeric', 'device_id' => $one('humidity'), 'above' => 70],
                'conditions' => [],
                'actions' => [['type' => 'device', 'device_id' => $one('plug'), 'action' => 'turn_on']],
                'cooldown_minutes' => 10,
            ], [
                'name' => 'Dehumidifier off',
                'trigger' => ['type' => 'numeric', 'device_id' => $one('humidity'), 'below' => 60],
                'conditions' => [],
                'actions' => [['type' => 'device', 'device_id' => $one('plug'), 'action' => 'turn_off']],
                'cooldown_minutes' => 10,
            ]],
            'good_night' => [[
                'name' => 'Good night',
                'trigger' => ['type' => 'time', 'at' => '00:30'],
                'conditions' => [],
                'actions' => array_map(fn (string $id) => ['type' => 'device', 'device_id' => $id, 'action' => 'turn_off'], array_slice($devices['lights'] ?? [], 0, self::MAX_LIGHTS)),
                'cooldown_minutes' => 60,
            ]],
            'night_window' => [[
                'name' => 'Window opened at night',
                'trigger' => ['type' => 'state', 'device_id' => $one('opening'), 'to' => 'on'],
                'conditions' => [['type' => 'time', 'after' => '23:00', 'before' => '06:00']],
                'actions' => [['type' => 'notify', 'message' => 'A window opened at night. Is everything all right?']],
                'cooldown_minutes' => 15,
            ]],
            'motion_light' => [[
                'name' => 'Hall light on with motion',
                'trigger' => ['type' => 'state', 'device_id' => $one('motion'), 'to' => 'on'],
                'conditions' => [['type' => 'sun', 'is' => 'down']],
                'actions' => [['type' => 'device', 'device_id' => $one('light'), 'action' => 'turn_on']],
                'cooldown_minutes' => 0,
            ], [
                'name' => 'Hall light off when clear',
                'trigger' => ['type' => 'state', 'device_id' => $one('motion'), 'to' => 'off'],
                'conditions' => [['type' => 'sun', 'is' => 'down']],
                'actions' => [['type' => 'device', 'device_id' => $one('light'), 'action' => 'turn_off']],
                'cooldown_minutes' => 0,
            ]],
            'cold_window' => [[
                'name' => 'Close the window, it\'s getting cold',
                'trigger' => ['type' => 'numeric', 'device_id' => $one('temperature'), 'below' => 18],
                'conditions' => [['type' => 'state', 'device_id' => $one('window'), 'is' => 'on']],
                'actions' => [['type' => 'notify', 'message' => 'The room is below 18 °C and its window is open.']],
                'cooldown_minutes' => 60,
            ]],
            default => [],
        };
    }

    /**
     * Devices of these kinds, best name match first
     *
     * @param  Collection<int, SmartDevice>  $devices
     * @param  list<string>  $kinds
     * @param  list<string>  $words
     * @return Collection<int, SmartDevice>
     */
    private static function rank(Collection $devices, array $kinds, array $words): Collection
    {
        $text = fn (SmartDevice $d) => mb_strtolower($d->name.' '.($d->room !== null ? $d->room->name : '').' '.$d->area);

        return $devices
            ->filter(fn (SmartDevice $d) => in_array($d->kind, $kinds, true))
            // A switch is a light only if it says so: "Hallway light" yes, "Dehumidifier plug" no
            ->reject(fn (SmartDevice $d) => $d->kind === 'switch' && in_array('light', $kinds, true)
                && ! preg_match('/light|lamp|ceiling/', $text($d)))
            ->sortByDesc(function (SmartDevice $d) use ($words, $kinds) {
                $text = mb_strtolower($d->name.' '.($d->room !== null ? $d->room->name : '').' '.$d->area);
                $score = 0;
                foreach ($words as $w) {
                    $score += str_contains($text, $w) ? 10 : 0;
                }

                // The first kind listed is the better one ("light" before "switch")
                return $score - array_search($d->kind, $kinds, true);
            })
            ->values();
    }
}
