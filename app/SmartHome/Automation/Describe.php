<?php

namespace App\SmartHome\Automation;

use App\Models\AutomationRule;
use App\Models\SmartDevice;
use App\SmartHome\Notifications;

/**
 * A rule in words, for the page and the assistant: "When Front door opens,
 * if the sun is down: turn on Hallway light."
 */
final class Describe
{
    private const DAY = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /** @param  array<string, SmartDevice>  $devices  by id */
    public static function rule(AutomationRule $rule, array $devices): string
    {
        $name = fn (?string $id) => $id && isset($devices[$id]) ? $devices[$id]->name : 'a removed device';
        $t = $rule->trigger;

        $when = match ($t['type']) {
            'state' => 'When '.$name($t['device_id']).' '.self::stateWords($devices[$t['device_id']] ?? null, $t['to']),
            'numeric' => 'When '.$name($t['device_id']).(isset($t['above']) ? " goes above {$t['above']}" : " drops below {$t['below']}"),
            'event' => 'When '.mb_strtolower(Notifications::TYPES[$t['event']][1] ?? $t['event']).(isset($t['device_id']) ? ' ('.$name($t['device_id']).')' : ''),
            'time' => "At {$t['at']}".(isset($t['days']) ? ' on '.self::days($t['days']) : ''),
            default => 'When something happens',
        };

        $if = array_map(fn (array $c) => match ($c['type']) {
            'time' => "between {$c['after']} and {$c['before']}",
            'sun' => $c['is'] === 'down' ? 'after dark' : 'in daylight',
            'days' => 'on '.self::days($c['days']),
            'state' => $name($c['device_id']).' is '.$c['is'],
            'numeric' => $name($c['device_id']).(isset($c['above']) ? " is above {$c['above']}" : " is below {$c['below']}"),
            default => $c['type'],
        }, $rule->conditions);

        $do = array_map(fn (array $a) => match ($a['type']) {
            'notify' => "notify everyone (\"{$a['message']}\")",
            default => match ($a['action']) {
                'turn_on' => 'turn on '.$name($a['device_id']),
                'turn_off' => 'turn off '.$name($a['device_id']),
                'toggle' => 'switch '.$name($a['device_id']),
                'set_brightness' => 'set '.$name($a['device_id'])." to {$a['brightness']}%",
                'lock' => 'lock '.$name($a['device_id']),
                'unlock' => 'unlock '.$name($a['device_id']),
                default => $a['action'],
            },
        }, $rule->actions);

        return $when.($if ? ', if '.implode(' and ', $if) : '').': '.implode(', then ', $do).'.';
    }

    private static function stateWords(?SmartDevice $device, string $to): string
    {
        return match ([$device?->kind, $to]) {
            ['door', 'on'], ['window', 'on'] => 'opens',
            ['door', 'off'], ['window', 'off'] => 'closes',
            ['motion', 'on'] => 'sees motion',
            ['motion', 'off'] => 'is clear',
            default => "turns {$to}",
        };
    }

    /** @param  list<int>  $days */
    private static function days(array $days): string
    {
        return match ($days) {
            [1, 2, 3, 4, 5] => 'weekdays',
            [6, 7] => 'weekends',
            default => implode(', ', array_map(fn ($d) => self::DAY[$d] ?? '?', $days)),
        };
    }
}
